package ipc

import (
	"bufio"
	"encoding/json"
	"fmt"
	"net"
	"time"
)

// Client connects to a running binktermphp-pm over its Unix socket or TCP address.
type Client struct {
	network string // "unix" or "tcp"
	addr    string
	secret  string
}

// NewClient creates a client that connects via Unix domain socket.
func NewClient(socketPath, secret string) *Client {
	return &Client{network: "unix", addr: socketPath, secret: secret}
}

// NewTCPClient creates a client that connects via TCP (for platforms without Unix socket support).
func NewTCPClient(addr, secret string) *Client {
	return &Client{network: "tcp", addr: addr, secret: secret}
}

func (c *Client) send(method string, params any) (Response, error) {
	conn, err := net.DialTimeout(c.network, c.addr, 5*time.Second)
	if err != nil {
		return Response{}, fmt.Errorf("cannot connect to %s — is binktermphp-pm running?", c.addr)
	}
	defer conn.Close()
	conn.SetDeadline(time.Now().Add(15 * time.Second))

	scanner := bufio.NewScanner(conn)

	if c.secret != "" {
		authMsg, _ := json.Marshal(struct {
			Auth string `json:"auth"`
		}{Auth: c.secret})
		if _, err := conn.Write(append(authMsg, '\n')); err != nil {
			return Response{}, fmt.Errorf("auth write: %w", err)
		}
		if !scanner.Scan() {
			return Response{}, fmt.Errorf("no auth response from server")
		}
		var authResp Response
		if err := json.Unmarshal(scanner.Bytes(), &authResp); err != nil || !authResp.OK {
			return Response{}, fmt.Errorf("ipc authentication failed")
		}
	}

	var rawParams json.RawMessage
	if params != nil {
		rawParams, _ = json.Marshal(params)
	}
	data, _ := json.Marshal(Request{ID: "1", Method: method, Params: rawParams})
	if _, err := conn.Write(append(data, '\n')); err != nil {
		return Response{}, err
	}

	if !scanner.Scan() {
		return Response{}, fmt.Errorf("no response from server")
	}
	var resp Response
	if err := json.Unmarshal(scanner.Bytes(), &resp); err != nil {
		return Response{}, fmt.Errorf("bad response: %w", err)
	}
	return resp, nil
}

func (c *Client) Status() (StatusData, error) {
	resp, err := c.send("status", nil)
	if err != nil {
		return StatusData{}, err
	}
	if !resp.OK {
		return StatusData{}, fmt.Errorf("%s", resp.Error)
	}
	var data StatusData
	return data, json.Unmarshal(resp.Data, &data)
}

func (c *Client) Start(service string) (string, error)   { return c.serviceOp("start", service) }
func (c *Client) Stop(service string) (string, error)    { return c.serviceOp("stop", service) }
func (c *Client) Restart(service string) (string, error) { return c.serviceOp("restart", service) }

func (c *Client) serviceOp(method, service string) (string, error) {
	resp, err := c.send(method, ServiceParams{Service: service})
	if err != nil {
		return "", err
	}
	if !resp.OK {
		return "", fmt.Errorf("%s", resp.Error)
	}
	return resp.Message, nil
}

func (c *Client) Logs(service string, n int) ([]string, error) {
	resp, err := c.send("logs", LogsParams{Service: service, N: n})
	if err != nil {
		return nil, err
	}
	if !resp.OK {
		return nil, fmt.Errorf("%s", resp.Error)
	}
	var data LogsData
	if err := json.Unmarshal(resp.Data, &data); err != nil {
		return nil, err
	}
	return data.Lines, nil
}

func (c *Client) SetEnabled(service string, enabled bool) error {
	resp, err := c.send("set_enabled", SetEnabledParams{Service: service, Enabled: enabled})
	if err != nil {
		return err
	}
	if !resp.OK {
		return fmt.Errorf("%s", resp.Error)
	}
	return nil
}

func (c *Client) Reload() error {
	resp, err := c.send("reload", nil)
	if err != nil {
		return err
	}
	if !resp.OK {
		return fmt.Errorf("%s", resp.Error)
	}
	return nil
}

func (c *Client) StopAll() error {
	resp, err := c.send("stop_all", nil)
	if err != nil {
		return err
	}
	if !resp.OK {
		return fmt.Errorf("%s", resp.Error)
	}
	return nil
}

// FormatUptime converts uptime seconds into a human-readable string.
func FormatUptime(seconds int64) string {
	if seconds <= 0 {
		return "-"
	}
	h := seconds / 3600
	m := (seconds % 3600) / 60
	s := seconds % 60
	if h > 0 {
		return fmt.Sprintf("%dh %02dm", h, m)
	}
	if m > 0 {
		return fmt.Sprintf("%dm %02ds", m, s)
	}
	return fmt.Sprintf("%ds", s)
}
