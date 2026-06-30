//go:build !windows

package console

import (
	"os"
	"os/signal"
	"syscall"
)

// watchResize registers for SIGWINCH so the dashboard redraws on terminal resize.
// The returned function deregisters the signal and should be called via defer.
func watchResize(ch chan<- os.Signal) func() {
	signal.Notify(ch, syscall.SIGWINCH)
	return func() { signal.Stop(ch) }
}
