package ipc

import (
	"bufio"
	"encoding/json"
	"fmt"
	"log/slog"
	"net"
	"os"
	"path/filepath"
	"time"

	"github.com/awehttam/binkterm-php/tools/binktermphp-pm/internal/healthcheck"
	"github.com/awehttam/binkterm-php/tools/binktermphp-pm/internal/supervisor"
)

// Server listens on a Unix socket and dispatches JSON-RPC commands.
type Server struct {
	socketPath    string
	listener      net.Listener
	sup           *supervisor.Supervisor
	hc            *healthcheck.Monitor
	logger        *slog.Logger
	reloadFn      func() error
	stopAllFn     func()
	setEnabledFn  func(string, bool) error
}

func NewServer(
	socketPath string,
	sup *supervisor.Supervisor,
	hc *healthcheck.Monitor,
	logger *slog.Logger,
	reloadFn func() error,
	stopAllFn func(),
	setEnabledFn func(string, bool) error,
) *Server {
	return &Server{
		socketPath:   socketPath,
		sup:          sup,
		hc:           hc,
		logger:       logger,
		reloadFn:     reloadFn,
		stopAllFn:    stopAllFn,
		setEnabledFn: setEnabledFn,
	}
}

// Listen binds the socket and accepts connections until Close is called.
func (s *Server) Listen() error {
	os.Remove(s.socketPath)
	if err := os.MkdirAll(dirOf(s.socketPath), 0755); err != nil {
		return err
	}
	ln, err := net.Listen("unix", s.socketPath)
	if err != nil {
		return fmt.Errorf("ipc listen %s: %w", s.socketPath, err)
	}
	os.Chmod(s.socketPath, 0600)
	s.listener = ln
	s.logger.Info("ipc listening", "socket", s.socketPath)
	for {
		conn, err := ln.Accept()
		if err != nil {
			return nil // listener closed
		}
		go s.handle(conn)
	}
}

func (s *Server) Close() {
	if s.listener != nil {
		s.listener.Close()
		os.Remove(s.socketPath)
	}
}

func dirOf(p string) string { return filepath.Dir(p) }

func (s *Server) handle(conn net.Conn) {
	defer conn.Close()
	conn.SetDeadline(time.Now().Add(30 * time.Second))
	scanner := bufio.NewScanner(conn)
	for scanner.Scan() {
		var req Request
		if err := json.Unmarshal(scanner.Bytes(), &req); err != nil {
			writeResp(conn, Response{ID: "?", OK: false, Error: "invalid JSON"})
			return
		}
		writeResp(conn, s.dispatch(req))
	}
}

func writeResp(conn net.Conn, resp Response) {
	data, _ := json.Marshal(resp)
	conn.Write(append(data, '\n'))
}

func (s *Server) dispatch(req Request) Response {
	switch req.Method {
	case "status":
		return s.handleStatus(req.ID)
	case "start":
		return s.handleServiceOp(req.ID, req.Params, s.sup.Start)
	case "stop":
		return s.handleServiceOp(req.ID, req.Params, s.sup.Stop)
	case "restart":
		return s.handleServiceOp(req.ID, req.Params, s.sup.Restart)
	case "logs":
		return s.handleLogs(req.ID, req.Params)
	case "reload":
		return s.handleReload(req.ID)
	case "stop_all":
		return s.handleStopAll(req.ID)
	case "set_enabled":
		return s.handleSetEnabled(req.ID, req.Params)
	default:
		return Response{ID: req.ID, OK: false, Error: "unknown method: " + req.Method}
	}
}

func (s *Server) handleStatus(id string) Response {
	snaps := s.sup.Snapshots()
	hSnaps := s.hc.Snapshots()

	data := StatusData{
		Services:     make([]ServiceInfo, len(snaps)),
		HealthChecks: make([]HealthCheckInfo, len(hSnaps)),
	}
	for i, sn := range snaps {
		info := ServiceInfo{
			Name:     sn.Name,
			State:    sn.State.String(),
			Restarts: sn.Restarts,
			Enabled:  sn.Enabled,
			AlwaysOn: sn.AlwaysOn,
		}
		if sn.State == supervisor.StateRunning {
			info.PID = sn.PID
			info.UptimeS = int64(sn.Uptime().Seconds())
		}
		data.Services[i] = info
	}
	for i, hs := range hSnaps {
		info := HealthCheckInfo{Name: hs.Name, Status: hs.Status.String()}
		if !hs.LastCheck.IsZero() {
			info.LastCheck = hs.LastCheck.UTC().Format(time.RFC3339)
		}
		data.HealthChecks[i] = info
	}
	raw, _ := json.Marshal(data)
	return Response{ID: id, OK: true, Data: raw}
}

func (s *Server) handleServiceOp(id string, params json.RawMessage, fn func(string) error) Response {
	var p ServiceParams
	if err := json.Unmarshal(params, &p); err != nil || p.Service == "" {
		return Response{ID: id, OK: false, Error: "missing service name"}
	}
	if err := fn(p.Service); err != nil {
		return Response{ID: id, OK: false, Error: err.Error()}
	}
	return Response{ID: id, OK: true, Message: "ok: " + p.Service}
}

func (s *Server) handleLogs(id string, params json.RawMessage) Response {
	var p LogsParams
	if err := json.Unmarshal(params, &p); err != nil || p.Service == "" {
		return Response{ID: id, OK: false, Error: "missing service name"}
	}
	n := p.N
	if n <= 0 {
		n = 50
	}
	lines, err := s.sup.GetLog(p.Service, n)
	if err != nil {
		lines, err = s.hc.GetLog(p.Service, n)
	}
	if err != nil {
		return Response{ID: id, OK: false, Error: err.Error()}
	}
	raw, _ := json.Marshal(LogsData{Lines: lines})
	return Response{ID: id, OK: true, Data: raw}
}

func (s *Server) handleReload(id string) Response {
	if s.reloadFn == nil {
		return Response{ID: id, OK: false, Error: "reload not configured"}
	}
	if err := s.reloadFn(); err != nil {
		return Response{ID: id, OK: false, Error: err.Error()}
	}
	return Response{ID: id, OK: true, Message: "config reloaded"}
}

func (s *Server) handleStopAll(id string) Response {
	if s.stopAllFn != nil {
		go s.stopAllFn()
	}
	return Response{ID: id, OK: true, Message: "shutting down"}
}

func (s *Server) handleSetEnabled(id string, params json.RawMessage) Response {
	var p SetEnabledParams
	if err := json.Unmarshal(params, &p); err != nil || p.Service == "" {
		return Response{ID: id, OK: false, Error: "missing service name"}
	}
	if s.setEnabledFn == nil {
		return Response{ID: id, OK: false, Error: "set_enabled not configured"}
	}
	if err := s.setEnabledFn(p.Service, p.Enabled); err != nil {
		return Response{ID: id, OK: false, Error: err.Error()}
	}
	state := "disabled"
	if p.Enabled {
		state = "enabled"
	}
	return Response{ID: id, OK: true, Message: p.Service + " " + state}
}
