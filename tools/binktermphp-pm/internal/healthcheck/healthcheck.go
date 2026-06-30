// Package healthcheck polls external services (TCP/HTTP/PostgreSQL) and reports their reachability.
package healthcheck

import (
	"bufio"
	"context"
	"database/sql"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	_ "github.com/lib/pq"

	"github.com/awehttam/binkterm-php/tools/binktermphp-pm/internal/config"
	"github.com/awehttam/binkterm-php/tools/binktermphp-pm/internal/logring"
)

type Status int

const (
	StatusUnknown Status = iota
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
	rootDir  string
	envOnce  sync.Once
	envVars  map[string]string
}

func New(cfg *config.Config, logger *slog.Logger, rootDir string) *Monitor {
	interval := time.Duration(cfg.HealthCheckIntervalS) * time.Second
	if interval <= 0 {
		interval = 15 * time.Second
	}
	m := &Monitor{
		byName:   make(map[string]*monitoredSvc),
		interval: interval,
		logger:   logger,
		client:   &http.Client{Timeout: 5 * time.Second},
		rootDir:  rootDir,
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
	case spec.PostgresEnv:
		reachable = m.probePostgresEnv()
	case spec.SiteVerify:
		reachable = m.probeSiteVerify()
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

// probePostgresEnv opens a real database connection using DB_* vars from the root .env
// and reports whether the connection succeeds.
func (m *Monitor) probePostgresEnv() bool {
	vars := m.getEnvVars()
	host := envOr(vars, "DB_HOST", "localhost")
	port := envOr(vars, "DB_PORT", "5432")
	dbname := vars["DB_NAME"]
	user := vars["DB_USER"]
	pass := vars["DB_PASS"]

	if dbname == "" || user == "" {
		m.logger.Warn("postgres_env health check: DB_NAME or DB_USER not set in .env")
		return false
	}

	dsn := fmt.Sprintf(
		"host=%s port=%s dbname=%s user=%s password=%s sslmode=disable connect_timeout=5",
		host, port, dbname, user, pass,
	)

	db, err := sql.Open("postgres", dsn)
	if err != nil {
		return false
	}
	defer db.Close()
	db.SetMaxOpenConns(1)

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	return db.PingContext(ctx) == nil
}

// probeSiteVerify performs a GET against SITE_URL/api/verify from the root .env
// and reports whether it returns a 2xx response.
func (m *Monitor) probeSiteVerify() bool {
	vars := m.getEnvVars()
	siteURL := strings.TrimRight(vars["SITE_URL"], "/")
	if siteURL == "" {
		m.logger.Warn("site_verify health check: SITE_URL not set in .env")
		return false
	}
	resp, err := m.client.Get(siteURL + "/api/verify")
	if err != nil {
		return false
	}
	resp.Body.Close()
	return resp.StatusCode >= 200 && resp.StatusCode < 300
}

// getEnvVars returns the cached .env key/value map, loading it once from rootDir/.env.
func (m *Monitor) getEnvVars() map[string]string {
	m.envOnce.Do(func() {
		m.envVars = parseEnvFile(filepath.Join(m.rootDir, ".env"))
	})
	return m.envVars
}

// parseEnvFile reads a .env file and returns a map of KEY → VALUE.
// Handles bare KEY=VALUE, KEY="VALUE", KEY='VALUE', and export KEY=VALUE.
// Lines starting with # and blank lines are ignored.
func parseEnvFile(path string) map[string]string {
	vars := make(map[string]string)
	f, err := os.Open(path)
	if err != nil {
		return vars
	}
	defer f.Close()

	scanner := bufio.NewScanner(f)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		// Strip optional "export " prefix.
		line = strings.TrimPrefix(line, "export ")
		eq := strings.IndexByte(line, '=')
		if eq < 0 {
			continue
		}
		key := strings.TrimSpace(line[:eq])
		val := strings.TrimSpace(line[eq+1:])
		// Strip surrounding quotes.
		if len(val) >= 2 {
			if (val[0] == '"' && val[len(val)-1] == '"') ||
				(val[0] == '\'' && val[len(val)-1] == '\'') {
				val = val[1 : len(val)-1]
			}
		}
		// Strip inline comment on unquoted values.
		if len(val) > 0 && val[0] != '"' && val[0] != '\'' {
			if idx := strings.IndexByte(val, '#'); idx >= 0 {
				val = strings.TrimSpace(val[:idx])
			}
		}
		vars[key] = val
	}
	return vars
}

func envOr(vars map[string]string, key, def string) string {
	if v, ok := vars[key]; ok && v != "" {
		return v
	}
	return def
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
