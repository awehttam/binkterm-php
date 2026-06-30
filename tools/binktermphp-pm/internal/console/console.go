// Package console renders a live ANSI dashboard for binktermphp-pm.
package console

import (
	"fmt"
	"os"
	"strings"
	"time"
	"unicode/utf8"

	"golang.org/x/term"

	"github.com/awehttam/binkterm-php/tools/binktermphp-pm/internal/healthcheck"
	"github.com/awehttam/binkterm-php/tools/binktermphp-pm/internal/supervisor"
)

// Controller is the interface the console uses to query and control services.
// It is implemented by LocalController (in-process) and IPCController (attach mode).
type Controller interface {
	Snapshots() []supervisor.ServiceSnapshot
	HealthSnapshots() []healthcheck.Snapshot
	GetLog(name string, n int) ([]string, error)
	Start(name string) error
	Stop(name string) error
	Restart(name string) error
	SetEnabled(name string, enabled bool) error
	// Shutdown is called when the user presses q. In local mode it stops the
	// process manager and all services. In attach/IPC mode it is a no-op — the
	// console simply closes and the daemon keeps running.
	Shutdown()
	// StopDaemon stops all services and shuts the daemon down. In local mode
	// this is identical to Shutdown. In attach/IPC mode it sends stop_all to
	// the running daemon then closes the console.
	StopDaemon()
	// IsAttached reports whether this console is connected to a remote daemon
	// via IPC. The header uses this to show the correct quit label.
	IsAttached() bool
}

const (
	keyUp    = 256 + iota
	keyDown
	keyCtrlC
)

type rowKind int

const (
	kindHealth  rowKind = iota
	kindService
)

type row struct {
	name string
	kind rowKind
}

type console struct {
	ctrl      Controller
	rows      []row
	selected  int
	logLines  int // height of the log pane (lines of output, excluding header)
	statusMsg string
	statusTTL int // decremented each ticker tick; message cleared when it reaches 0
}

// Run starts the interactive dashboard. It blocks until the user quits.
func Run(ctrl Controller) error {
	c := &console{ctrl: ctrl, logLines: 10}

	fd := int(os.Stdin.Fd())
	if !term.IsTerminal(fd) {
		return fmt.Errorf("stdin is not a terminal; use --daemon mode or binktermphp-ctl")
	}
	oldState, err := term.MakeRaw(fd)
	if err != nil {
		return fmt.Errorf("raw mode: %w", err)
	}
	defer func() {
		term.Restore(fd, oldState)
		fmt.Print("\033[?25h\033[2J\033[H") // show cursor, clear screen
	}()

	fmt.Print("\033[?25l") // hide cursor

	keyCh := make(chan int, 16)
	go readKeys(keyCh)

	sigCh := make(chan os.Signal, 1)
	defer watchResize(sigCh)()

	ticker := time.NewTicker(500 * time.Millisecond)
	defer ticker.Stop()

	c.refresh()

	for {
		select {
		case k := <-keyCh:
			switch k {
			case 'q', keyCtrlC:
				c.ctrl.Shutdown()
				return nil
			case 'Q':
				c.ctrl.StopDaemon()
				return nil
			case keyUp:
				if c.selected > 0 {
					c.selected--
				}
			case keyDown:
				if c.selected < len(c.rows)-1 {
					c.selected++
				}
			case 's':
				if c.selectedRow().kind == kindService {
					c.ctrl.Start(c.selectedRow().name)
				}
			case 'S':
				if c.selectedRow().kind == kindService {
					c.ctrl.Stop(c.selectedRow().name)
				}
			case 'r':
				if c.selectedRow().kind == kindService {
					c.ctrl.Restart(c.selectedRow().name)
				}
			case 'e':
				if r := c.selectedRow(); r.kind == kindService {
					c.toggleEnabled(r.name)
				}
			}
			c.refresh()
		case <-ticker.C:
			c.refresh()
		case <-sigCh:
			c.refresh()
		}
	}
}

func (c *console) selectedRow() row {
	if c.selected >= 0 && c.selected < len(c.rows) {
		return c.rows[c.selected]
	}
	return row{}
}

func (c *console) setStatus(msg string) {
	c.statusMsg = msg
	c.statusTTL = 6 // ~3 s at 500 ms tick rate
}

// toggleEnabled flips the enabled flag for the named service and updates the status bar.
func (c *console) toggleEnabled(name string) {
	snaps := c.ctrl.Snapshots()
	for _, ss := range snaps {
		if ss.Name != name {
			continue
		}
		if ss.AlwaysOn {
			c.setStatus(name + ": always-on service cannot be disabled")
			return
		}
		want := !ss.Enabled
		if err := c.ctrl.SetEnabled(name, want); err != nil {
			c.setStatus(name + ": " + err.Error())
			return
		}
		if want {
			c.setStatus(name + ": enabled")
		} else {
			c.setStatus(name + ": disabled")
		}
		return
	}
	c.setStatus(name + ": not found")
}

func (c *console) refresh() {
	w, h, err := term.GetSize(int(os.Stdout.Fd()))
	if err != nil || w < 40 || h < 8 {
		return
	}

	hSnaps := c.ctrl.HealthSnapshots()
	sSnaps := c.ctrl.Snapshots()

	// Rebuild row list.
	c.rows = c.rows[:0]
	for _, hs := range hSnaps {
		c.rows = append(c.rows, row{name: hs.Name, kind: kindHealth})
	}
	for _, ss := range sSnaps {
		c.rows = append(c.rows, row{name: ss.Name, kind: kindService})
	}
	if c.selected >= len(c.rows) {
		c.selected = len(c.rows) - 1
	}
	if c.selected < 0 {
		c.selected = 0
	}

	// Layout: 1 header + 1 col-header + service rows + 1 log-header + logLines + 1 status bar
	logPaneH := c.logLines + 1
	listH := h - 3 - logPaneH
	if listH < 1 {
		listH = 1
	}

	// Build index maps for quick lookup.
	healthMap := make(map[string]healthcheck.Snapshot, len(hSnaps))
	for _, hs := range hSnaps {
		healthMap[hs.Name] = hs
	}
	serviceMap := make(map[string]supervisor.ServiceSnapshot, len(sSnaps))
	for _, ss := range sSnaps {
		serviceMap[ss.Name] = ss
	}

	var b strings.Builder
	b.WriteString("\033[H") // cursor home (no full clear — avoids flicker)

	// ── Header ──────────────────────────────────────────────────────────────
	qLabel := "[q]uit"
	if c.ctrl.IsAttached() {
		qLabel = "[q]detach  [Q]stop daemon"
	}
	hdr := padRight(truncVis(" BinktermPHP Process Manager  "+qLabel+"  [↑↓]select  [s]tart  [S]top  [r]estart  [e]nable", w), w)
	b.WriteString("\033[7m" + hdr + "\033[0m\r\n")

	// ── Column header ────────────────────────────────────────────────────────
	cols := fmt.Sprintf("  %-22s %-8s %-12s %-8s %-10s %s",
		"SERVICE", "TYPE", "STATUS", "PID", "UPTIME", "RESTARTS")
	b.WriteString("\033[1m" + truncVis(cols, w) + "\033[0m\r\n")

	// ── Service list (scrollable) ────────────────────────────────────────────
	scrollStart := 0
	if c.selected >= listH {
		scrollStart = c.selected - listH + 1
	}

	rendered := 0
	for i, r := range c.rows {
		if i < scrollStart {
			continue
		}
		if rendered >= listH {
			break
		}

		sel := i == c.selected
		var line string

		if r.kind == kindHealth {
			hs := healthMap[r.name]
			statusColor := colorForHealth(hs.Status, sel)
			line = fmt.Sprintf("  %-22s %-8s %-12s %-8s %-10s %s",
				truncVis(r.name, 22), "health",
				statusColor+hs.Status.String()+"\033[0m",
				"-", "-", "-")
		} else {
			ss := serviceMap[r.name]
			pid := "-"
			uptime := "-"
			if ss.State == supervisor.StateRunning {
				pid = fmt.Sprintf("%d", ss.PID)
				uptime = fmtDuration(ss.Uptime())
			}
			statusColor := colorForState(ss.State, sel)
			line = fmt.Sprintf("  %-22s %-8s %-12s %-8s %-10s %d",
				truncVis(r.name, 22), "service",
				statusColor+ss.State.String()+"\033[0m",
				pid, uptime, ss.Restarts)
		}

		if sel {
			b.WriteString("\033[7m" + padRight(truncVis(stripAnsi(line), w), w) + "\033[0m\r\n")
		} else {
			b.WriteString(truncVis(line, w) + clearEOL() + "\r\n")
		}
		rendered++
	}
	// Blank out any leftover rows from a previous render.
	for rendered < listH {
		b.WriteString(clearEOL() + "\r\n")
		rendered++
	}

	// ── Log pane header ──────────────────────────────────────────────────────
	logName := ""
	if sel := c.selectedRow(); sel.name != "" {
		logName = sel.name
	}
	logHdr := padRight(truncVis(" Logs: "+logName, w), w)
	b.WriteString("\033[7m" + logHdr + "\033[0m\r\n")

	// ── Log lines ────────────────────────────────────────────────────────────
	if logName != "" {
		lines, _ := c.ctrl.GetLog(logName, c.logLines)
		for _, l := range lines {
			b.WriteString(truncVis(l, w) + clearEOL() + "\r\n")
		}
		for i := len(lines); i < c.logLines; i++ {
			b.WriteString(clearEOL() + "\r\n")
		}
	} else {
		for i := 0; i < c.logLines; i++ {
			b.WriteString(clearEOL() + "\r\n")
		}
	}

	// ── Status bar ───────────────────────────────────────────────────────────
	if c.statusMsg != "" {
		b.WriteString(truncVis(" "+c.statusMsg, w) + clearEOL())
		c.statusTTL--
		if c.statusTTL <= 0 {
			c.statusMsg = ""
		}
	} else {
		b.WriteString(clearEOL())
	}

	fmt.Print(b.String())
}

func readKeys(ch chan<- int) {
	buf := make([]byte, 16)
	for {
		n, err := os.Stdin.Read(buf)
		if err != nil || n == 0 {
			return
		}
		switch {
		case n == 1 && buf[0] == 3:
			ch <- keyCtrlC
		case n == 1:
			ch <- int(buf[0])
		case n >= 3 && buf[0] == 27 && buf[1] == '[':
			switch buf[2] {
			case 'A':
				ch <- keyUp
			case 'B':
				ch <- keyDown
			}
		}
	}
}

// ── ANSI helpers ─────────────────────────────────────────────────────────────

func clearEOL() string { return "\033[K" }

func colorForState(s supervisor.State, selected bool) string {
	if selected {
		return ""
	}
	switch s {
	case supervisor.StateRunning:
		return "\033[32m"
	case supervisor.StateBackoff:
		return "\033[33m"
	case supervisor.StateDisabled:
		return "\033[90m"
	default:
		return ""
	}
}

func colorForHealth(s healthcheck.Status, selected bool) string {
	if selected {
		return ""
	}
	switch s {
	case healthcheck.StatusReachable:
		return "\033[32m"
	case healthcheck.StatusUnreachable:
		return "\033[31m"
	default:
		return "\033[90m"
	}
}

// truncVis truncates s to at most maxW visible rune positions.
// It is ANSI-escape-aware: escape sequences are passed through without
// counting toward the visible width.
func truncVis(s string, maxW int) string {
	if maxW <= 0 {
		return ""
	}
	var b strings.Builder
	vis := 0
	inEsc := false
	for _, r := range s {
		if inEsc {
			b.WriteRune(r)
			if r >= 0x40 && r <= 0x7E { // final byte of CSI sequence
				inEsc = false
			}
			continue
		}
		if r == '\033' {
			b.WriteRune(r)
			inEsc = true
			continue
		}
		if vis >= maxW {
			break
		}
		b.WriteRune(r)
		vis++
	}
	return b.String()
}

// stripAnsi removes ANSI escape sequences for width calculation on selected rows.
func stripAnsi(s string) string {
	var b strings.Builder
	inEsc := false
	for _, r := range s {
		if inEsc {
			if r >= 0x40 && r <= 0x7E {
				inEsc = false
			}
			continue
		}
		if r == '\033' {
			inEsc = true
			continue
		}
		b.WriteRune(r)
	}
	return b.String()
}

// padRight pads s with spaces so its visible width equals w.
func padRight(s string, w int) string {
	vis := utf8.RuneCountInString(s)
	if vis >= w {
		return s
	}
	return s + strings.Repeat(" ", w-vis)
}

func fmtDuration(d time.Duration) string {
	if d <= 0 {
		return "-"
	}
	h := int(d.Hours())
	m := int(d.Minutes()) % 60
	s := int(d.Seconds()) % 60
	if h > 0 {
		return fmt.Sprintf("%dh %02dm", h, m)
	}
	if m > 0 {
		return fmt.Sprintf("%dm %02ds", m, s)
	}
	return fmt.Sprintf("%ds", s)
}
