// Package healthcheck polls external services (TCP/HTTP) and reports their reachability.
package healthcheck

import (
	"context"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"sync"
	"time"

	"github.com/awehttam/binkterm-php/tools/binktermphp-pm/internal/config"
	"github.com/awehttam/binkterm-php/tools/binktermphp-pm/internal/logring"
)

type Status int

const (
	StatusUnknown     Status = iota
	StatusReachable
	StatusUnreachable
)

func (s Status) String() string {
	switch s {
	case StatusReachable:
		return "reachable"
	case StatusUnreachable:
		return "unreachable"
	default:
		return "unknown"
	}
}

type Snapshot struct {
	Name      string
	Status    Status
	LastCheck time.Time
}

type monitoredSvc struct {
	cfg       config.HealthCheck
	status    Status
	lastCheck time.Time
	log       *logring.LogRing
	mu        sync.RWMutex
}

func (m *monitoredSvc) snapshot() Snapshot {
	m.mu.RLock()
	defer m.mu.RUnlock()
	return Snapshot{Name: m.cfg.Name, Status: m.status, LastCheck: m.lastCheck}
}

// Monitor runs health probes for all configured external services.
type Monitor struct {
	services []*monitoredSvc
	byName   map[string]*monitoredSvc
	interval time.Duration
	logger   *slog.Logger
	client   *http.Client
}

func New(cfg *config.Config, logger *slog.Logger) *Monitor {
	interval := time.Duration(cfg.HealthCheckIntervalS) * time.Second
	if interval <= 0 {
		interval = 15 * time.Second
	}
	m := &Monitor{
		byName:   make(map[string]*monitoredSvc),
		interval: interval,
		logger:   logger,
		client:   &http.Client{Timeout: 5 * time.Second},
	}
	for _, hc := range cfg.HealthChecks {
		svc := &monitoredSvc{cfg: hc, status: StatusUnknown, log: logring.New(100)}
		m.services = append(m.services, svc)
		m.byName[hc.Name] = svc
	}
	return m
}

// Start begins polling all health checks. It blocks until ctx is cancelled.
func (m *Monitor) Start(ctx context.Context) {
	if len(m.services) == 0 {
		return
	}
	// Run an immediate check before entering the ticker loop.
	for _, svc := range m.services {
		m.probe(svc)
	}
	ticker := time.NewTicker(m.interval)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			for _, svc := range m.services {
				m.probe(svc)
			}
		}
	}
}

func (m *Monitor) probe(svc *monitoredSvc) {
	var reachable bool
	spec := svc.cfg.Check
	switch {
	case spec.TCP != "":
		reachable = probeTCP(spec.TCP, 5*time.Second)
	case spec.HTTP != "":
		reachable = m.probeHTTP(spec.HTTP)
	}

	status := StatusUnreachable
	if reachable {
		status = StatusReachable
	}

	now := time.Now()
	logLine := fmt.Sprintf("[%s] %s: %s", now.Format("15:04:05"), svc.cfg.Name, status)

	svc.mu.Lock()
	prev := svc.status
	svc.status = status
	svc.lastCheck = now
	svc.mu.Unlock()
	svc.log.Append(logLine)

	if prev != status {
		m.logger.Info("health check status changed",
			"name", svc.cfg.Name,
			"status", status.String(),
		)
	}
}

func probeTCP(addr string, timeout time.Duration) bool {
	conn, err := net.DialTimeout("tcp", addr, timeout)
	if err != nil {
		return false
	}
	conn.Close()
	return true
}

func (m *Monitor) probeHTTP(url string) bool {
	resp, err := m.client.Get(url)
	if err != nil {
		return false
	}
	resp.Body.Close()
	return resp.StatusCode < 500
}

// Snapshots returns a point-in-time copy of all health check statuses.
func (m *Monitor) Snapshots() []Snapshot {
	snaps := make([]Snapshot, len(m.services))
	for i, svc := range m.services {
		snaps[i] = svc.snapshot()
	}
	return snaps
}

// GetLog returns recent probe result lines for the named health check.
func (m *Monitor) GetLog(name string, n int) ([]string, error) {
	svc, ok := m.byName[name]
	if !ok {
		return nil, fmt.Errorf("unknown health check: %s", name)
	}
	return svc.log.Tail(n), nil
}
