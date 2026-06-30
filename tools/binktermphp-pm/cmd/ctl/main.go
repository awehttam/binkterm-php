package main

import (
	"flag"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"text/tabwriter"

	"github.com/awehttam/binkterm-php/tools/binktermphp-pm/internal/config"
	"github.com/awehttam/binkterm-php/tools/binktermphp-pm/internal/ipc"
)

const usage = `Usage: binktermphp-ctl [--config PATH] <command> [args]

Commands:
  status               Show status of all services and health checks
  status <service>     Show status of one service
  start <service>      Start a stopped service
  stop <service>       Stop a running service
  restart <service>    Stop then start a service
  reload               Reload config/aio.json (SIGHUP equivalent)
  logs <service>       Tail recent log lines for a service
  stop-all             Stop all services and shut down the manager
`

func main() {
	cfgPath := flag.String("config", "config/aio.json", "path to aio.json")
	tail := flag.Int("tail", 50, "number of log lines to show (for logs command)")
	flag.Usage = func() { fmt.Fprint(os.Stderr, usage) }
	flag.Parse()

	args := flag.Args()
	if len(args) == 0 {
		fmt.Fprint(os.Stderr, usage)
		os.Exit(1)
	}

	socketPath := resolveSocket(*cfgPath)
	client := ipc.NewClient(socketPath)
	command := args[0]

	switch command {
	case "status":
		if len(args) >= 2 {
			runStatusOne(client, args[1])
		} else {
			runStatus(client)
		}

	case "start", "stop", "restart":
		if len(args) < 2 {
			fatalf("%s requires a service name", command)
		}
		var msg string
		var err error
		switch command {
		case "start":
			msg, err = client.Start(args[1])
		case "stop":
			msg, err = client.Stop(args[1])
		case "restart":
			msg, err = client.Restart(args[1])
		}
		dieOnErr(err)
		fmt.Println(msg)

	case "reload":
		dieOnErr(client.Reload())
		fmt.Println("Config reloaded.")

	case "logs":
		if len(args) < 2 {
			fatalf("logs requires a service name")
		}
		lines, err := client.Logs(args[1], *tail)
		dieOnErr(err)
		for _, l := range lines {
			fmt.Println(l)
		}

	case "stop-all":
		dieOnErr(client.StopAll())
		fmt.Println("Shutdown initiated.")

	default:
		fatalf("unknown command: %s\n\n%s", command, usage)
	}
}

func runStatus(client *ipc.Client) {
	data, err := client.Status()
	dieOnErr(err)

	w := tabwriter.NewWriter(os.Stdout, 0, 0, 2, ' ', 0)
	fmt.Fprintln(w, "SERVICE\tTYPE\tSTATUS\tPID\tUPTIME\tRESTARTS")

	for _, hc := range data.HealthChecks {
		fmt.Fprintf(w, "%s\t%s\t%s\t%s\t%s\t%s\n",
			hc.Name, "health", hc.Status, "-", "-", "-")
	}
	for _, svc := range data.Services {
		pid := "-"
		uptime := "-"
		if svc.PID != 0 {
			pid = strconv.Itoa(svc.PID)
		}
		if svc.UptimeS > 0 {
			uptime = ipc.FormatUptime(svc.UptimeS)
		}
		fmt.Fprintf(w, "%s\t%s\t%s\t%s\t%s\t%d\n",
			svc.Name, "service", svc.State, pid, uptime, svc.Restarts)
	}
	w.Flush()
}

func runStatusOne(client *ipc.Client, name string) {
	data, err := client.Status()
	dieOnErr(err)

	for _, hc := range data.HealthChecks {
		if hc.Name == name {
			fmt.Printf("%-12s %s\n", "name:", hc.Name)
			fmt.Printf("%-12s %s\n", "type:", "health")
			fmt.Printf("%-12s %s\n", "status:", hc.Status)
			if hc.LastCheck != "" {
				fmt.Printf("%-12s %s\n", "last check:", hc.LastCheck)
			}
			return
		}
	}
	for _, svc := range data.Services {
		if svc.Name == name {
			fmt.Printf("%-12s %s\n", "name:", svc.Name)
			fmt.Printf("%-12s %s\n", "type:", "service")
			fmt.Printf("%-12s %s\n", "state:", svc.State)
			if svc.PID != 0 {
				fmt.Printf("%-12s %d\n", "pid:", svc.PID)
			}
			if svc.UptimeS > 0 {
				fmt.Printf("%-12s %s\n", "uptime:", ipc.FormatUptime(svc.UptimeS))
			}
			fmt.Printf("%-12s %d\n", "restarts:", svc.Restarts)
			return
		}
	}
	fatalf("service not found: %s", name)
}

func resolveSocket(cfgPath string) string {
	cfg, err := config.Load(cfgPath)
	if err != nil {
		// Fall back to the default path relative to cwd.
		return "data/run/binktermphp-pm.sock"
	}
	abs, _ := filepath.Abs(cfgPath)
	rootDir := filepath.Dir(filepath.Dir(abs))
	return cfg.AbsPath(rootDir, cfg.Socket)
}

func dieOnErr(err error) {
	if err != nil {
		fatalf("%v", err)
	}
}

func fatalf(format string, args ...any) {
	fmt.Fprintf(os.Stderr, "binktermphp-ctl: "+format+"\n", args...)
	os.Exit(1)
}
