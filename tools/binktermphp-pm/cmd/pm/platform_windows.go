//go:build windows

package main

import (
	"log/slog"
	"os"
	"syscall"
)

var platformSignals []os.Signal

func daemonSysProcAttr() *syscall.SysProcAttr { return nil }

func handlePlatformSignal(_ os.Signal, _ func() error, _ *slog.Logger) {}
