//go:build !windows

package main

import (
	"log/slog"
	"os"
	"syscall"
)

var platformSignals = []os.Signal{syscall.SIGHUP, syscall.SIGUSR1}

func daemonSysProcAttr() *syscall.SysProcAttr {
	return &syscall.SysProcAttr{Setsid: true}
}

func handlePlatformSignal(sig os.Signal, reloadFn func() error, logger *slog.Logger) {
	switch sig {
	case syscall.SIGHUP:
		if err := reloadFn(); err != nil {
			logger.Error("reload failed", "error", err)
		}
	case syscall.SIGUSR1:
		logger.Info("SIGUSR1: log rotation not yet implemented")
	}
}
