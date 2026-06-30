package main

import (
	"context"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"syscall"
	"time"

	"github.com/awehttam/binkterm-php/tools/binktermphp-pm/internal/config"
	"github.com/awehttam/binkterm-php/tools/binktermphp-pm/internal/console"
	"github.com/awehttam/binkterm-php/tools/binktermphp-pm/internal/healthcheck"
	"github.com/awehttam/binkterm-php/tools/binktermphp-pm/internal/ipc"
	"github.com/awehttam/binkterm-php/tools/binktermphp-pm/internal/supervisor"
)

func main() {
	cfgPath := flag.String("config", "config/aio.json", "path to aio.json")
	daemon := flag.Bool("daemon", false, "run in background as a daemon")
	pidFile := flag.String("pid-file", "", "override pid_file from config")
	attach := flag.Bool("attach", false, "attach console to a running daemon")
	flag.Parse()

	// ── Attach mode ──────────────────────────────────────────────────────────
	if *attach {
		cfg, err := config.Load(*cfgPath)
		if err != nil {
			fatalf("load config: %v", err)
		}
		rootDir := rootDirOf(*cfgPath)
		ctrl := newIPCController(clientFromCfg(cfg, rootDir))
		if err := console.Run(ctrl); err != nil {
			fatalf("%v", err)
		}
		return
	}

	// ── Daemon mode: re-exec self as a background child ───────────────────────
	if *daemon && os.Getenv("BINKPM_DAEMON") == "" {
		cfg, err := config.Load(*cfgPath)
		if err != nil {
			fatalf("load config: %v", err)
		}
		rootDir := rootDirOf(*cfgPath)
		logPath := cfg.AbsPath(rootDir, cfg.LogFile)
		if err := os.MkdirAll(filepath.Dir(logPath), 0755); err != nil {
			fatalf("create log dir: %v", err)
		}
		logFd, err := os.OpenFile(logPath, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
		if err != nil {
			fatalf("open log file: %v", err)
		}
		self, _ := os.Executable()
		// Build args without --daemon so the child runs in foreground.
		args := filterArg(os.Args[1:], "--daemon")
		child := &exec.Cmd{
			Path:        self,
			Args:        append([]string{self}, args...),
			Stdin:       nil,
			Stdout:      logFd,
			Stderr:      logFd,
			Env:         append(os.Environ(), "BINKPM_DAEMON=1"),
			SysProcAttr: daemonSysProcAttr(),
		}
		if err := child.Start(); err != nil {
			fatalf("start daemon: %v", err)
		}
		child.Process.Release()
		fmt.Printf("binktermphp-pm started in background (PID %d)\n", child.Process.Pid)
		fmt.Printf("Log: %s\n", logPath)
		return
	}

	// ── Normal / daemon-child startup ────────────────────────────────────────
	cfg, err := config.Load(*cfgPath)
	if err != nil {
		fatalf("load config: %v", err)
	}
	rootDir := rootDirOf(*cfgPath)

	logPath := cfg.AbsPath(rootDir, cfg.LogFile)
	logger := buildLogger(logPath)

	socketPath := cfg.AbsPath(rootDir, cfg.Socket)

	// If the socket already exists and a manager is already running,
	// automatically switch to attach mode for console users.
	if os.Getenv("BINKPM_DAEMON") == "" && socketFileAlive(cfg, rootDir) {
		fmt.Println("binktermphp-pm is already running — attaching to running instance.")
		ctrl := newIPCController(clientFromCfg(cfg, rootDir))
		if err := console.Run(ctrl); err != nil {
			fatalf("%v", err)
		}
		return
	}

	pidPath := cfg.AbsPath(rootDir, cfg.PidFile)
	if *pidFile != "" {
		pidPath = *pidFile
	}
	writePid(pidPath)
	defer os.Remove(pidPath)

	sup := supervisor.New(rootDir, cfg, logger)
	hc := healthcheck.New(cfg, logger, rootDir)

	hcCtx, hcCancel := context.WithCancel(context.Background())
	defer hcCancel()
	go hc.Start(hcCtx)

	shutdownCh := make(chan struct{})

	reloadFn := func() error {
		newCfg, err := config.Load(*cfgPath)
		if err != nil {
			return err
		}
		sup.Reload(newCfg)
		logger.Info("config reloaded")
		return nil
	}
	stopAllFn := func() {
		select {
		case <-shutdownCh:
		default:
			close(shutdownCh)
		}
	}
	setEnabledFn := func(name string, enabled bool) error {
		if err := sup.SetEnabled(name, enabled); err != nil {
			return err
		}
		// Load a fresh copy from disk so stale in-memory state (e.g. after a reload)
		// doesn't overwrite fields we didn't intend to change.
		freshCfg, err := config.Load(*cfgPath)
		if err != nil {
			return fmt.Errorf("read config for update: %w", err)
		}
		for i := range freshCfg.Services {
			if freshCfg.Services[i].Name == name {
				freshCfg.Services[i].Enabled = enabled
				break
			}
		}
		if err := config.Save(*cfgPath, freshCfg); err != nil {
			return fmt.Errorf("save config: %w", err)
		}
		logger.Info("service enabled state changed", "service", name, "enabled", enabled)
		return nil
	}

	ipcSrv := ipc.NewServer(socketPath, cfg.SocketGroup, cfg.IpcSecret, sup, hc, logger, reloadFn, stopAllFn, setEnabledFn)
	go func() {
		if err := ipcSrv.Listen(); err != nil {
			logger.Error("ipc server error", "error", err)
		}
	}()
	if cfg.IpcAddr != "" {
		go func() {
			if err := ipcSrv.ListenTCP(cfg.IpcAddr); err != nil {
				logger.Error("ipc tcp server error", "error", err)
			}
		}()
	}
	defer ipcSrv.Close()

	sup.StartAll()
	logger.Info("binktermphp-pm started", "pid", os.Getpid(), "services", len(cfg.Services))

	// Signal handling.
	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, append([]os.Signal{syscall.SIGTERM, syscall.SIGINT}, platformSignals...)...)
	go func() {
		for sig := range sigCh {
			switch sig {
			case syscall.SIGTERM, syscall.SIGINT:
				stopAllFn()
			default:
				handlePlatformSignal(sig, reloadFn, logger)
			}
		}
	}()

	// Console mode (foreground, not daemon child).
	interactive := false
	if os.Getenv("BINKPM_DAEMON") == "" {
		ctrl := &localController{sup: sup, hc: hc, shutdownCh: shutdownCh, setEnabledFn: setEnabledFn}
		if err := console.Run(ctrl); err != nil {
			// Non-terminal fallback: just wait for shutdown signal.
			logger.Info("console unavailable, running headless", "reason", err)
			<-shutdownCh
		} else {
			interactive = true
		}
	} else {
		// Daemon child: block until shutdown is signalled.
		<-shutdownCh
	}

	logger.Info("shutting down")

	var onStopped func(name string, killed bool)
	if interactive {
		fmt.Println("Stopping services...")
		onStopped = func(name string, killed bool) {
			if killed {
				fmt.Printf("  [killed] %s\n", name)
			} else {
				fmt.Printf("  [ok]     %s\n", name)
			}
		}
	}

	sup.StopAll(10*time.Second, onStopped)

	if interactive {
		fmt.Println("Shutdown complete.")
	}

	signal.Stop(sigCh)
}

// ── localController wires the console to the in-process supervisor ────────────

type localController struct {
	sup          *supervisor.Supervisor
	hc           *healthcheck.Monitor
	shutdownCh   chan struct{}
	setEnabledFn func(string, bool) error
}

func (c *localController) IsAttached() bool                        { return false }
func (c *localController) Snapshots() []supervisor.ServiceSnapshot { return c.sup.Snapshots() }
func (c *localController) HealthSnapshots() []healthcheck.Snapshot  { return c.hc.Snapshots() }
func (c *localController) GetLog(name string, n int) ([]string, error) {
	lines, err := c.sup.GetLog(name, n)
	if err != nil {
		return c.hc.GetLog(name, n)
	}
	return lines, nil
}
func (c *localController) Start(name string) error              { return c.sup.Start(name) }
func (c *localController) Stop(name string) error               { return c.sup.Stop(name) }
func (c *localController) Restart(name string) error            { return c.sup.Restart(name) }
func (c *localController) SetEnabled(name string, enabled bool) error {
	return c.setEnabledFn(name, enabled)
}
func (c *localController) Shutdown() {
	select {
	case <-c.shutdownCh:
	default:
		close(c.shutdownCh)
	}
}
func (c *localController) StopDaemon() { c.Shutdown() }

// ── ipcController wires the console to a remote manager via IPC ──────────────

type ipcController struct {
	client *ipc.Client
	cached ipc.StatusData
}

func newIPCController(client *ipc.Client) *ipcController {
	return &ipcController{client: client}
}

func (c *ipcController) IsAttached() bool { return true }
func (c *ipcController) Snapshots() []supervisor.ServiceSnapshot {
	data, err := c.client.Status()
	if err == nil {
		c.cached = data
	}
	snaps := make([]supervisor.ServiceSnapshot, len(c.cached.Services))
	for i, si := range c.cached.Services {
		snaps[i] = supervisor.ServiceSnapshot{
			Name:     si.Name,
			State:    parseState(si.State),
			PID:      si.PID,
			Restarts: si.Restarts,
			Enabled:  si.Enabled,
			AlwaysOn: si.AlwaysOn,
		}
	}
	return snaps
}

func (c *ipcController) HealthSnapshots() []healthcheck.Snapshot {
	snaps := make([]healthcheck.Snapshot, len(c.cached.HealthChecks))
	for i, hi := range c.cached.HealthChecks {
		snaps[i] = healthcheck.Snapshot{
			Name:   hi.Name,
			Status: parseHealthStatus(hi.Status),
		}
	}
	return snaps
}

func (c *ipcController) GetLog(name string, n int) ([]string, error) {
	return c.client.Logs(name, n)
}
func (c *ipcController) Start(name string) error {
	_, err := c.client.Start(name)
	return err
}
func (c *ipcController) Stop(name string) error {
	_, err := c.client.Stop(name)
	return err
}
func (c *ipcController) Restart(name string) error {
	_, err := c.client.Restart(name)
	return err
}
func (c *ipcController) SetEnabled(name string, enabled bool) error {
	return c.client.SetEnabled(name, enabled)
}
// Shutdown in IPC/attach mode is intentionally a no-op: pressing q just closes
// the console view; the daemon keeps running. Use Q or binktermphp-ctl stop-all
// to shut down the daemon.
func (c *ipcController) Shutdown() {}
func (c *ipcController) StopDaemon() { _ = c.client.StopAll() }

// ── helpers ───────────────────────────────────────────────────────────────────

func parseState(s string) supervisor.State {
	switch s {
	case "running":
		return supervisor.StateRunning
	case "starting":
		return supervisor.StateStarting
	case "stopping":
		return supervisor.StateStopping
	case "backoff":
		return supervisor.StateBackoff
	case "disabled":
		return supervisor.StateDisabled
	default:
		return supervisor.StateStopped
	}
}

func parseHealthStatus(s string) healthcheck.Status {
	switch s {
	case "reachable":
		return healthcheck.StatusReachable
	case "unreachable":
		return healthcheck.StatusUnreachable
	default:
		return healthcheck.StatusUnknown
	}
}

func rootDirOf(cfgPath string) string {
	abs, err := filepath.Abs(cfgPath)
	if err != nil {
		return "."
	}
	// config is at <root>/config/aio.json → root is two levels up.
	return filepath.Dir(filepath.Dir(abs))
}

func buildLogger(logPath string) *slog.Logger {
	var w *os.File
	if err := os.MkdirAll(filepath.Dir(logPath), 0755); err == nil {
		f, err := os.OpenFile(logPath, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
		if err == nil {
			w = f
		}
	}
	if w == nil {
		w = os.Stderr
	}
	return slog.New(slog.NewJSONHandler(w, nil))
}

func writePid(path string) {
	if err := os.MkdirAll(filepath.Dir(path), 0755); err != nil {
		return
	}
	os.WriteFile(path, []byte(fmt.Sprintf("%d\n", os.Getpid())), 0644)
}

func clientFromCfg(cfg *config.Config, rootDir string) *ipc.Client {
	if cfg.IpcAddr != "" {
		return ipc.NewTCPClient(cfg.IpcAddr, cfg.IpcSecret)
	}
	return ipc.NewClient(cfg.AbsPath(rootDir, cfg.Socket), cfg.IpcSecret)
}

func socketFileAlive(cfg *config.Config, rootDir string) bool {
	_, err := clientFromCfg(cfg, rootDir).Status()
	return err == nil
}

func filterArg(args []string, drop string) []string {
	out := make([]string, 0, len(args))
	for _, a := range args {
		if a != drop {
			out = append(out, a)
		}
	}
	return out
}

func fatalf(format string, args ...any) {
	fmt.Fprintf(os.Stderr, "binktermphp-pm: "+format+"\n", args...)
	os.Exit(1)
}
