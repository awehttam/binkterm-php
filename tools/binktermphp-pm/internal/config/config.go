package config

import (
	"encoding/json"
	"os"
	"path/filepath"
)

type Config struct {
	Socket               string        `json:"socket"`
	PidFile              string        `json:"pid_file"`
	LogFile              string        `json:"log_file"`
	RestartMaxDelayS     int           `json:"restart_max_delay_s"`
	HealthCheckIntervalS int           `json:"health_check_interval_s"`
	Services             []Service     `json:"services"`
	HealthChecks         []HealthCheck `json:"health_checks"`
}

type Service struct {
	Name          string   `json:"name"`
	Command       []string `json:"command"`
	AlwaysOn      bool     `json:"always_on"`
	Enabled       bool     `json:"enabled"`
	RestartPolicy string   `json:"restart_policy"` // "always", "on-failure", "never"
}

type HealthCheck struct {
	Name  string          `json:"name"`
	Check HealthCheckSpec `json:"check"`
}

type HealthCheckSpec struct {
	TCP  string `json:"tcp,omitempty"`
	HTTP string `json:"http,omitempty"`
}

func Defaults() *Config {
	return &Config{
		Socket:               "data/run/binktermphp-pm.sock",
		PidFile:              "data/run/binktermphp-pm.pid",
		LogFile:              "data/logs/binktermphp-pm.log",
		RestartMaxDelayS:     30,
		HealthCheckIntervalS: 15,
	}
}

func Load(path string) (*Config, error) {
	cfg := Defaults()
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	if err := json.Unmarshal(data, cfg); err != nil {
		return nil, err
	}
	return cfg, nil
}

// Save writes cfg to path as indented JSON, atomically replacing the file.
func Save(path string, cfg *Config) error {
	data, err := json.MarshalIndent(cfg, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(path, data, 0644)
}

// AbsPath resolves a config-relative path against the BinktermPHP root directory.
func (c *Config) AbsPath(rootDir, rel string) string {
	if filepath.IsAbs(rel) {
		return rel
	}
	return filepath.Join(rootDir, rel)
}
