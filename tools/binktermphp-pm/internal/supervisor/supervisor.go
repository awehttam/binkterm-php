// Package supervisor manages the lifecycle of BinktermPHP child processes.
package supervisor

import (
	"bufio"
	"fmt"
	"io"
	"log/slog"
	"os"
	"os/exec"
	"sync"
	"time"

	"github.com/awehttam/binkterm-php/tools/binktermphp-pm/internal/config"
	"github.com/awehttam/binkterm-php/tools/binktermphp-pm/internal/logring"
)

// State represents the lifecycle state of a managed service.
type State int

const (
	StateDisabled State = iota
	StateStopped
	StateStarting
	StateRunning
	StateStopping
	StateBackoff
)

func (s State) String() string {
	switch s {
	case StateDisabled:
		return "disabled"
	case StateStopped:
		return "stopped"
	case StateStarting:
		return "starting"
	case StateRunning:
		return "running"
	case StateStopping:
		return "stopping"
	case StateBackoff:
		return "backoff"
	default:
		return "unknown"
	}
}

// ServiceSnapshot is an immutable point-in-time view of a service.
type ServiceSnapshot struct {
	Name         string
	State        State
	PID          int
	StartedAt    time.Time
	Restarts     int
	BackoffDelay time.Duration
	Enabled      bool
	AlwaysOn     bool
}

// Uptime returns how long the service has been in the running state.
func (s ServiceSnapshot) Uptime() time.Duration {
	if s.State != StateRunning || s.StartedAt.IsZero() {
		return 0
	}
	return time.Since(s.StartedAt)
}

type managedService struct {
	cfg          config.Service
	state        State
	pid          int
	startedAt    time.Time
	restarts     int
	backoffDelay time.Duration
	Log          *logring.LogRing

	mu     sync.RWMutex
	cmd    *exec.Cmd
	stopCh chan struct{} // closed to signal an intentional stop
}

func (s *managedService) snapshot() ServiceSnapshot {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return ServiceSnapshot{
		Name:         s.cfg.Name,
		State:        s.state,
		PID:          s.pid,
		StartedAt:    s.startedAt,
		Restarts:     s.restarts,
		BackoffDelay: s.backoffDelay,
		Enabled:      s.cfg.Enabled,
		AlwaysOn:     s.cfg.AlwaysOn,
	}
}

// Supervisor starts, monitors, and restarts managed services.
type Supervisor struct {
	rootDir  string
	cfg      *config.Config
	services []*managedService
	byName   map[string]*managedService
	mu       sync.RWMutex
	logger   *slog.Logger
	maxDelay time.Duration
}

func New(rootDir string, cfg *config.Config, logger *slog.Logger) *Supervisor {
	maxDelay := time.Duration(cfg.RestartMaxDelayS) * time.Second
	if maxDelay <= 0 {
		maxDelay = 30 * time.Second
	}
	s := &Supervisor{
		rootDir:  rootDir,
		cfg:      cfg,
		byName:   make(map[string]*managedService),
		logger:   logger,
		maxDelay: maxDelay,
	}
	for _, sc := range cfg.Services {
		svc := newManagedService(sc)
		s.services = append(s.services, svc)
		s.byName[sc.Name] = svc
	}
	return s
}

func newManagedService(sc config.Service) *managedService {
	svc := &managedService{
		cfg:    sc,
		Log:    logring.New(500),
		stopCh: make(chan struct{}),
	}
	if sc.AlwaysOn || sc.Enabled {
		svc.state = StateStopped
	} else {
		svc.state = StateDisabled
	}
	return svc
}

// StartAll launches all enabled and always-on services.
func (s *Supervisor) StartAll() {
	for _, svc := range s.services {
		svc.mu.RLock()
		state := svc.state
		svc.mu.RUnlock()
		if state != StateDisabled {
			go s.runService(svc)
		}
	}
}

// StopAll gracefully stops all running services, waiting up to timeout.
// onStopped is called for each service once it has stopped; killed is true if
// the process had to be force-killed. Pass nil to suppress progress callbacks.
func (s *Supervisor) StopAll(timeout time.Duration, onStopped func(name string, killed bool)) {
	var wg sync.WaitGroup
	for _, svc := range s.services {
		svc.mu.RLock()
		st := svc.state
		svc.mu.RUnlock()
		if st == StateRunning || st == StateStarting || st == StateBackoff {
			wg.Add(1)
			go func(svc *managedService) {
				defer wg.Done()
				killed := s.stopService(svc, timeout)
				if onStopped != nil {
					onStopped(svc.cfg.Name, killed)
				}
			}(svc)
		}
	}
	wg.Wait()
}

// Start enables and starts a named service.
func (s *Supervisor) Start(name string) error {
	svc, ok := s.byName[name]
	if !ok {
		return fmt.Errorf("unknown service: %s", name)
	}
	svc.mu.Lock()
	st := svc.state
	if st == StateRunning || st == StateStarting {
		svc.mu.Unlock()
		return fmt.Errorf("service %s is already running", name)
	}
	svc.state = StateStopped
	svc.stopCh = make(chan struct{})
	svc.mu.Unlock()
	go s.runService(svc)
	return nil
}

// Stop gracefully stops a named service and prevents automatic restart.
func (s *Supervisor) Stop(name string) error {
	svc, ok := s.byName[name]
	if !ok {
		return fmt.Errorf("unknown service: %s", name)
	}
	svc.mu.RLock()
	st := svc.state
	svc.mu.RUnlock()
	if st == StateStopped || st == StateDisabled {
		return fmt.Errorf("service %s is not running", name)
	}
	_ = s.stopService(svc, 10*time.Second)
	return nil
}

// Restart stops then starts a named service.
func (s *Supervisor) Restart(name string) error {
	svc, ok := s.byName[name]
	if !ok {
		return fmt.Errorf("unknown service: %s", name)
	}
	svc.mu.RLock()
	st := svc.state
	svc.mu.RUnlock()
	if st == StateRunning || st == StateStarting || st == StateBackoff {
		_ = s.stopService(svc, 10*time.Second)
	}
	svc.mu.Lock()
	svc.state = StateStopped
	svc.stopCh = make(chan struct{})
	svc.mu.Unlock()
	go s.runService(svc)
	return nil
}

// Snapshots returns a point-in-time view of all services.
func (s *Supervisor) Snapshots() []ServiceSnapshot {
	s.mu.RLock()
	defer s.mu.RUnlock()
	out := make([]ServiceSnapshot, len(s.services))
	for i, svc := range s.services {
		out[i] = svc.snapshot()
	}
	return out
}

// GetLog returns recent stdout/stderr lines for a named service.
func (s *Supervisor) GetLog(name string, n int) ([]string, error) {
	svc, ok := s.byName[name]
	if !ok {
		return nil, fmt.Errorf("unknown service: %s", name)
	}
	return svc.Log.Tail(n), nil
}

// Reload applies a new config: starts newly-enabled services, stops removed/disabled ones.
func (s *Supervisor) Reload(newCfg *config.Config) {
	s.mu.Lock()
	defer s.mu.Unlock()

	newSet := make(map[string]config.Service)
	for _, sc := range newCfg.Services {
		newSet[sc.Name] = sc
	}

	// Stop services removed from config or now disabled.
	for _, svc := range s.services {
		sc, exists := newSet[svc.cfg.Name]
		svc.mu.RLock()
		st := svc.state
		svc.mu.RUnlock()
		if !exists || (!sc.AlwaysOn && !sc.Enabled) {
			if st != StateStopped && st != StateDisabled {
				go func(svc *managedService) { _ = s.stopService(svc, 10*time.Second) }(svc)
			}
		}
	}

	// Add and start services new to the config.
	for _, sc := range newCfg.Services {
		if _, known := s.byName[sc.Name]; !known {
			svc := newManagedService(sc)
			s.services = append(s.services, svc)
			s.byName[sc.Name] = svc
			if sc.AlwaysOn || sc.Enabled {
				go s.runService(svc)
			}
		}
	}

	s.cfg = newCfg
}

// SetEnabled changes the enabled flag on a service and immediately starts or stops
// it to match. AlwaysOn services cannot be disabled.
func (s *Supervisor) SetEnabled(name string, enabled bool) error {
	svc, ok := s.byName[name]
	if !ok {
		return fmt.Errorf("unknown service: %s", name)
	}
	if svc.cfg.AlwaysOn {
		return fmt.Errorf("service %s is always-on and cannot be disabled", name)
	}

	svc.mu.Lock()
	svc.cfg.Enabled = enabled
	st := svc.state
	svc.mu.Unlock()

	if enabled && st == StateDisabled {
		svc.mu.Lock()
		svc.state = StateStopped
		svc.stopCh = make(chan struct{})
		svc.mu.Unlock()
		go s.runService(svc)
	} else if !enabled {
		switch st {
		case StateRunning, StateStarting, StateBackoff:
			go func() {
				_ = s.stopService(svc, 10*time.Second)
				svc.mu.Lock()
				svc.state = StateDisabled
				svc.mu.Unlock()
			}()
		case StateStopped:
			svc.mu.Lock()
			svc.state = StateDisabled
			svc.mu.Unlock()
		}
	}
	return nil
}

// stopService sends SIGTERM to the process, waits up to timeout, then SIGKILLs.
// Returns true if the process had to be force-killed.
func (s *Supervisor) stopService(svc *managedService, timeout time.Duration) bool {
	svc.mu.Lock()
	if svc.state == StateStopped || svc.state == StateDisabled {
		svc.mu.Unlock()
		return false
	}
	svc.state = StateStopping
	// Signal the run loop not to restart.
	select {
	case <-svc.stopCh:
	default:
		close(svc.stopCh)
	}
	cmd := svc.cmd
	svc.mu.Unlock()

	killed := false
	if cmd != nil && cmd.Process != nil {
		s.logger.Info("stopping service", "name", svc.cfg.Name, "pid", cmd.Process.Pid)
		cmd.Process.Signal(os.Interrupt)

		done := make(chan struct{})
		go func() { cmd.Wait(); close(done) }()
		select {
		case <-done:
		case <-time.After(timeout):
			s.logger.Warn("force killing service", "name", svc.cfg.Name)
			cmd.Process.Kill()
			<-done
			killed = true
		}
	}

	svc.mu.Lock()
	svc.state = StateStopped
	svc.pid = 0
	svc.cmd = nil
	svc.mu.Unlock()
	svc.Log.Append("[pm] stopped")
	return killed
}

// runService is the goroutine that runs a service and handles restart policy.
func (s *Supervisor) runService(svc *managedService) {
	for {
		select {
		case <-svc.stopCh:
			svc.mu.Lock()
			svc.state = StateStopped
			svc.mu.Unlock()
			return
		default:
		}

		exitCode, runErr := s.runOnce(svc)

		select {
		case <-svc.stopCh:
			svc.mu.Lock()
			svc.state = StateStopped
			svc.pid = 0
			svc.cmd = nil
			svc.mu.Unlock()
			return
		default:
		}

		svc.mu.RLock()
		st := svc.state
		svc.mu.RUnlock()
		if st == StateStopping {
			svc.mu.Lock()
			svc.state = StateStopped
			svc.pid = 0
			svc.cmd = nil
			svc.mu.Unlock()
			return
		}

		policy := svc.cfg.RestartPolicy
		if policy == "" {
			if svc.cfg.AlwaysOn {
				policy = "always"
			} else {
				policy = "on-failure"
			}
		}

		shouldRestart := false
		switch policy {
		case "always":
			shouldRestart = true
		case "on-failure":
			shouldRestart = exitCode != 0 || runErr != nil
		case "never":
			shouldRestart = false
		}

		if !shouldRestart {
			svc.mu.Lock()
			svc.state = StateStopped
			svc.pid = 0
			svc.cmd = nil
			svc.mu.Unlock()
			s.logger.Info("service stopped cleanly, not restarting",
				"name", svc.cfg.Name, "exit_code", exitCode, "policy", policy)
			return
		}

		svc.mu.Lock()
		svc.restarts++
		delay := svc.backoffDelay
		if delay == 0 {
			delay = time.Second
		} else {
			delay = time.Duration(float64(delay) * 1.5)
			if delay > s.maxDelay {
				delay = s.maxDelay
			}
		}
		svc.backoffDelay = delay
		svc.state = StateBackoff
		svc.pid = 0
		svc.cmd = nil
		svc.mu.Unlock()

		s.logger.Info("service exited, backing off before restart",
			"name", svc.cfg.Name, "exit_code", exitCode, "backoff", delay.String())
		svc.Log.Append(fmt.Sprintf("[pm] backing off %s before restart", delay))

		select {
		case <-svc.stopCh:
			svc.mu.Lock()
			svc.state = StateStopped
			svc.mu.Unlock()
			return
		case <-time.After(delay):
		}
	}
}

// runOnce executes the service command, captures output, and returns when it exits.
func (s *Supervisor) runOnce(svc *managedService) (int, error) {
	if len(svc.cfg.Command) == 0 {
		return 1, fmt.Errorf("empty command for service %s", svc.cfg.Name)
	}

	cmd := exec.Command(svc.cfg.Command[0], svc.cfg.Command[1:]...)
	cmd.Dir = s.rootDir

	stdout, err := cmd.StdoutPipe()
	if err != nil {
		return 1, err
	}
	stderr, err := cmd.StderrPipe()
	if err != nil {
		return 1, err
	}

	svc.mu.Lock()
	svc.state = StateStarting
	svc.mu.Unlock()

	if err := cmd.Start(); err != nil {
		svc.Log.Append(fmt.Sprintf("[pm] failed to start: %v", err))
		s.logger.Error("failed to start service", "name", svc.cfg.Name, "error", err)
		return 1, err
	}

	svc.mu.Lock()
	svc.cmd = cmd
	svc.pid = cmd.Process.Pid
	svc.startedAt = time.Now()
	svc.state = StateRunning
	svc.mu.Unlock()

	s.logger.Info("service started", "name", svc.cfg.Name, "pid", cmd.Process.Pid)
	svc.Log.Append(fmt.Sprintf("[pm] started (pid %d)", cmd.Process.Pid))

	// Capture stdout and stderr into the log ring.
	var wg sync.WaitGroup
	capture := func(r io.Reader) {
		defer wg.Done()
		sc := bufio.NewScanner(r)
		for sc.Scan() {
			svc.Log.Append(sc.Text())
		}
	}
	wg.Add(2)
	go capture(stdout)
	go capture(stderr)

	// Reset back-off counter after staying healthy for 60 s.
	healthyTimer := time.AfterFunc(60*time.Second, func() {
		svc.mu.Lock()
		if svc.state == StateRunning {
			svc.backoffDelay = 0
		}
		svc.mu.Unlock()
	})

	wg.Wait()
	waitErr := cmd.Wait()
	healthyTimer.Stop()

	exitCode := 0
	if waitErr != nil {
		if exitErr, ok := waitErr.(*exec.ExitError); ok {
			exitCode = exitErr.ExitCode()
		} else {
			exitCode = 1
		}
	}

	svc.Log.Append(fmt.Sprintf("[pm] exited (code %d)", exitCode))
	s.logger.Info("service exited", "name", svc.cfg.Name, "exit_code", exitCode)
	return exitCode, waitErr
}
