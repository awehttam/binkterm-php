//go:build windows

package console

import "os"

// watchResize is a no-op on Windows; the dashboard redraws via the 500 ms ticker instead.
func watchResize(_ chan<- os.Signal) func() {
	return func() {}
}
