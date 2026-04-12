# Telnet Daemon

Telnet is one access method for the shared BinktermPHP Terminal Server. The
post-login feature set (netmail, echomail, file areas, doors, polls, shoutbox,
editor behavior, and menu flow) is documented in:

- [BinktermPHP Terminal Server](TerminalServer.md)

This document covers Telnet-specific transport setup, daemon operation, and
troubleshooting.

## Features

- **Telnet option negotiation** — IAC-based negotiation for NAWS (terminal size),
  echo control, and suppress-go-ahead
- **Pre-login ESC anti-bot challenge** — filters automated scanners before the
  login prompt is shown
- **Optional ANSI login screen** — place an ANSI art file at
  `telnet/screens/login.ans` to display it instead of the default login banner
  (sent as raw ANSI with CRLF normalization)

## Requirements

In addition to the [base Terminal Server requirements](TerminalServer.md#requirements),
the Telnet daemon requires:

- PHP extension: `sockets` — for the raw TCP listener

## Usage

### Starting the Daemon

Run the daemon with default settings (0.0.0.0:2323):

```bash
php telnet/telnet_daemon.php
```

### Command Line Options

Specify custom host and port:

```bash
php telnet/telnet_daemon.php --host=0.0.0.0 --port=2323
```

Specify API base URL:

```bash
php telnet/telnet_daemon.php --api-base=http://127.0.0.1
```

For HTTPS with a self-signed certificate:

```bash
php telnet/telnet_daemon.php --api-base=https://your-host --insecure
```

Enable debug logging (shows API URLs, screen dimensions, login attempts, and
misc debugging information to console and telnet sessions):

```bash
php telnet/telnet_daemon.php --debug
```

### Available Options

| Option | Default | Description |
|--------|---------|-------------|
| `--host` | `0.0.0.0` | IP address to bind to (use `127.0.0.1` for localhost only) |
| `--port` | `2323` | TCP port to listen on |
| `--api-base` | `SITE_URL` | Base URL for API requests (e.g., `http://localhost`) |
| `--insecure` | (off) | Accept self-signed SSL certificates for API calls |
| `--debug` | (off) | Enable verbose debug logging to console |

## Running as a Service

### Systemd

Create a systemd service file for automatic startup:

```bash
sudo nano /etc/systemd/system/binkterm-telnet.service
```

```ini
[Unit]
Description=BinktermPHP Telnet Daemon
After=network.target

[Service]
Type=simple
User=yourusername
Group=yourusername
WorkingDirectory=/path/to/binkterm
ExecStart=/usr/bin/php /path/to/binkterm/telnet/telnet_daemon.php
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable binkterm-telnet
sudo systemctl start binkterm-telnet
sudo systemctl status binkterm-telnet
```

### Cron (Alternative)

```bash
@reboot /usr/bin/php /path/to/binkterm/telnet/telnet_daemon.php --daemon
```

## Connecting

Users can connect using any telnet client:

### Command Line Telnet

```bash
telnet your-bbs-hostname 2323
```

### PuTTY (Windows)

```bash
putty -telnet your-bbs-hostname -P 2323
```

Or configure a saved session:
1. Host Name: `your-bbs-hostname`
2. Port: `2323`
3. Connection type: Telnet

### SyncTERM (Recommended)

SyncTERM provides the best experience with full ANSI color support:

1. Add new connection
2. Connection Type: Telnet
3. Address: `your-bbs-hostname`
4. Port: `2323`

## Client Compatibility

- Works best with PuTTY and SyncTERM
- Echo handling varies by telnet client
- Some clients may not properly support NAWS negotiation
- ANSI color support depends on terminal emulator capabilities

## Security Considerations

### Authentication

- Users authenticate with their BinktermPHP web credentials
- Passwords are transmitted to the API over HTTP(S)
- Consider using HTTPS for API connections in production

### Network Security

- Daemon listens on all interfaces (`0.0.0.0`) by default
- Use `--host=127.0.0.1` to restrict to localhost only
- Consider firewall rules to restrict access by IP
- Monitor logs for suspicious login activity

### Fail2ban

The telnet daemon writes to `data/logs/telnetd.log`. Example fail2ban
configuration files are included for banning IPs that repeatedly fail the
pre-login ESC anti-bot challenge:

- `docs/fail2ban/filter.d/binkterm-telnet-esc.conf`
- `docs/fail2ban/jail.d/binkterm-telnet-esc.local.example`

Example install steps on Linux:

```bash
sudo cp docs/fail2ban/filter.d/binkterm-telnet-esc.conf /etc/fail2ban/filter.d/
sudo cp docs/fail2ban/jail.d/binkterm-telnet-esc.local.example /etc/fail2ban/jail.d/binkterm-telnet-esc.local
sudo sed -i 's#/path/to/binkterm#/var/www/binkterm-php#' /etc/fail2ban/jail.d/binkterm-telnet-esc.local
sudo systemctl restart fail2ban
sudo fail2ban-client status binkterm-telnet-esc
```

The provided filter matches log lines like:

```text
[2026-03-19 02:12:09] [1441153] [INFO] Bot/timeout on ESC challenge from 66.205.238.49:45072 — connection dropped
```

## Troubleshooting

### Connection Issues

**Problem**: Cannot connect to telnet daemon

**Solutions**:
1. Verify daemon is running: `ps aux | grep telnet_daemon`
2. Check port is listening: `netstat -an | grep 2323`
3. Check firewall rules: `sudo ufw status`
4. Try localhost connection: `telnet localhost 2323`

### API Connection Issues

**Problem**: "Failed to authenticate" or API errors

**Solutions**:
1. Verify web interface is accessible
2. Check API base URL setting
3. Test API manually: `curl http://localhost/api/auth/login`
4. Enable debug mode: `--debug` flag
5. Check API logs in web server error log

### Screen Display Issues

**Problem**: Message lists overflow or don't fit screen

**Solutions**:
1. Verify terminal supports NAWS negotiation
2. Check debug output for detected screen dimensions
3. Try a different terminal emulator (SyncTERM recommended)
4. Manually resize terminal window to trigger NAWS update

### Editor Issues

**Problem**: Arrow keys not working or inserting strange characters

**Solutions**:
1. Verify terminal type is set correctly (ANSI or VT100)
2. Try a different terminal emulator
3. Check telnet client configuration
4. Use SyncTERM for best compatibility

### Login Rate Limiting

**Problem**: "Too many failed login attempts"

**Solutions**:
1. Wait 60 seconds for rate limit to expire
2. Check logs for the IP address being rate limited
3. Verify correct username and password
4. Contact administrator if a legitimate account is locked

## Development Notes

### Debug Mode

```bash
php telnet/telnet_daemon.php --debug
```

Debug output includes API URL, detected screen dimensions, messages-per-page
calculation, connection events, login attempt tracking, and API
request/response details.

### Signal Handling

- **SIGCHLD** — Reaps zombie child processes (forked connections)
- **SIGTERM** — Graceful shutdown with cleanup
- **SIGINT** — Graceful shutdown on Ctrl+C

### Connection Flow

1. Client connects to daemon socket
2. Daemon forks child process (Linux/macOS) or handles directly (Windows)
3. Child performs telnet negotiation (NAWS, echo control)
4. Child displays ANSI login screen (`telnet/screens/login.ans`) or default banner
5. Pre-login ESC anti-bot challenge is presented
6. User authenticates via API
7. Main menu displayed with message counts
8. User navigates menus and performs actions
9. Connection closed and child exits
10. Parent reaps zombie process via SIGCHLD

### Code Structure

- `telnet/telnet_daemon.php` — Main daemon script
- Telnet protocol implementation with IAC negotiation
- ANSI escape code support for colors and cursor control

## Contributing

When contributing to the telnet daemon:

1. Test with multiple terminal emulators (PuTTY, SyncTERM, standard telnet)
2. Verify Windows compatibility (single connection mode)
3. Test with different screen sizes (24 rows, 40 rows, etc.)
4. Follow existing code conventions
5. Add debug logging for new features
6. Update this documentation

## See Also

- [Terminal Server](TerminalServer.md) — shared feature set used by all terminal access methods
- [SSH Server](SSHServer.md) — encrypted SSH-2 alternative to Telnet

## License

Same as BinktermPHP — BSD License. See `LICENSE.md` for details.
