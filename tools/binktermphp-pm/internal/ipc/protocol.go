// Package ipc defines the newline-delimited JSON protocol used between
// binktermphp-pm and binktermphp-ctl over a Unix domain socket.
package ipc

import "encoding/json"

// Request is sent from ctl → pm.
type Request struct {
	ID     string          `json:"id"`
	Method string          `json:"method"`
	Params json.RawMessage `json:"params,omitempty"`
}

// Response is sent from pm → ctl.
type Response struct {
	ID      string          `json:"id"`
	OK      bool            `json:"ok"`
	Message string          `json:"message,omitempty"`
	Error   string          `json:"error,omitempty"`
	Data    json.RawMessage `json:"data,omitempty"`
}

// ServiceParams is the params body for start / stop / restart.
type ServiceParams struct {
	Service string `json:"service"`
}

// SetEnabledParams is the params body for set_enabled.
type SetEnabledParams struct {
	Service string `json:"service"`
	Enabled bool   `json:"enabled"`
}

// LogsParams is the params body for the logs command.
type LogsParams struct {
	Service string `json:"service"`
	N       int    `json:"n"`
}

// StatusData is the data payload for the status response.
type StatusData struct {
	Services     []ServiceInfo     `json:"services"`
	HealthChecks []HealthCheckInfo `json:"health_checks"`
}

// ServiceInfo is one service row in a StatusData response.
type ServiceInfo struct {
	Name     string `json:"name"`
	State    string `json:"state"`
	PID      int    `json:"pid,omitempty"`
	UptimeS  int64  `json:"uptime_s,omitempty"`
	Restarts int    `json:"restarts"`
	Enabled  bool   `json:"enabled"`
	AlwaysOn bool   `json:"always_on"`
}

// HealthCheckInfo is one health-check row in a StatusData response.
type HealthCheckInfo struct {
	Name      string `json:"name"`
	Status    string `json:"status"`
	LastCheck string `json:"last_check,omitempty"`
}

// LogsData is the data payload for the logs response.
type LogsData struct {
	Lines []string `json:"lines"`
}
