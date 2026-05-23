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
- **Dual listener (plain + TLS)** — listens on a plain-text port (default 2323)
  and a TLS-encrypted port (default 8023) simultaneously; TLS cert is auto-generated
  on first run if not provided
- **Optional ANSI login screen** — place an ANSI art file at
  `telnet/screens/login.ans` to display it instead of the default login banner
  (sent as raw ANSI with CRLF normalization)
- **Connection rate limiting** — rejects repeated rapid connections from a single
  IP before forking a child process, preventing flood attacks from exhausting
  process table slots

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
| `--port` | `2323` | Plain-text TCP port to listen on |
| `--no-tls` | (off) | Disable the TLS listener (TLS is enabled by default) |
| `--tls-port` | `8023` | TCP port for the TLS listener |
| `--tls-cert` | auto-generated | Path to TLS certificate PEM file |
| `--tls-key` | auto-generated | Path to TLS private key PEM file |
| `--api-base` | `SITE_URL` | Base URL for API requests (e.g., `http://localhost`) |
| `--insecure` | (off) | Accept self-signed SSL certificates for API calls |
| `--debug` | (off) | Enable verbose debug logging to console |
| `--daemon` | (off) | Run as a background daemon process |
| `--pid-file` | `data/run/telnetd.pid` | Path to write the daemon PID file |

### Environment Variable Equivalents

Command-line options take precedence over `.env` values.

| `.env` variable | Default | Description |
|---|---|---|
| `TELNET_BIND_HOST` | `0.0.0.0` | Bind address (equivalent to `--host`) |
| `TELNET_PORT` | `2323` | Plain-text port (equivalent to `--port`) |
| `TELNET_TLS` | `true` | Set to `false` to disable TLS entirely |
| `TELNET_TLS_PORT` | `8023` | TLS listener port (equivalent to `--tls-port`) |
| `TELNET_TLS_CERT` | (empty) | Path to TLS cert PEM (equivalent to `--tls-cert`) |
| `TELNET_TLS_KEY` | (empty) | Path to TLS key PEM (equivalent to `--tls-key`) |

## TLS Support

The Telnet daemon runs two listeners simultaneously by default: a plain-text listener on port 2323 and a TLS-encrypted listener on port 8023. Both listeners expose the same BBS session.

On first start the daemon checks for a certificate and key at `data/telnetd.crt` and `data/telnetd.key`. If they do not exist, a self-signed certificate is generated automatically (using `ext-openssl` or the `openssl` CLI as a fallback) and stored there.

To disable TLS:

```bash
php telnet/telnet_daemon.php --no-tls
```

Or in `.env`:

```ini
TELNET_TLS=false
```

To use your own certificate instead of the auto-generated one:

```bash
php telnet/telnet_daemon.php --tls-cert=/etc/ssl/mycert.pem --tls-key=/etc/ssl/mykey.pem
```

Or in `.env`:

```ini
TELNET_TLS_CERT=/etc/ssl/mycert.pem
TELNET_TLS_KEY=/etc/ssl/mykey.pem
```

TLS connections are logged with the cipher suite and key size (e.g., `TLS connection from 1.2.3.4 [TLSv1.2 AES128-GCM-SHA256 128-bit]`).

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

### UTF-8 Capable Terminal Clients

If you want a terminal with strong UTF-8 support, use a client such as ZOC or
PuTTY.

#### ZOC

1. Add a new Telnet session
2. Address: `your-bbs-hostname`
3. Port: `2323`

#### PuTTY

1. Host Name: `your-bbs-hostname`
2. Port: `2323`
3. Connection type: Telnet
4. Under `Window -> Translation`, select a UTF-8 character set if needed

## Client Compatibility

- UTF-8 behavior varies by terminal emulator and font configuration
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

### Connection Rate Limiting

The daemon tracks the number of TCP connections accepted from each remote IP
within a rolling fixed window. If an IP exceeds the limit, the connection is
accepted at the socket level (to avoid a half-open backlog), a short error
message is written to the client, and the socket is closed — no child process
is forked. Rejections are logged to `data/logs/telnetd.log`.

The window is **fixed per IP**: the first connection from an IP starts the
clock; the counter resets only after `TELNET_RATE_LIMIT_WINDOW` seconds have
elapsed since that first connection, not since the most recent one.

| `.env` variable | Default | Description |
|---|---|---|
| `TELNET_RATE_LIMIT_MAX` | `5` | Maximum connections allowed from one IP per window. Set to `0` to disable rate limiting entirely. |
| `TELNET_RATE_LIMIT_WINDOW` | `60` | Window duration in seconds. |

The defaults allow 5 connections per minute per IP, which is sufficient for
any legitimate user. Adjust `TELNET_RATE_LIMIT_MAX` downward if you are seeing
active floods, or set it to `0` on private/LAN-only installs.

### Fail2ban

The telnet daemon writes to `data/logs/telnetd.log`. Example fail2ban
configuration files are provided in `docs/fail2ban/` for banning IPs that
trigger the connection rate limiter:

- `docs/fail2ban/filter.d/binkterm-telnet-ratelimit.conf`
- `docs/fail2ban/jail.d/binkterm-telnet-ratelimit.local.example`

Example install steps on Linux:

```bash
sudo cp docs/fail2ban/filter.d/binkterm-telnet-ratelimit.conf /etc/fail2ban/filter.d/
sudo cp docs/fail2ban/jail.d/binkterm-telnet-ratelimit.local.example /etc/fail2ban/jail.d/binkterm-telnet-ratelimit.local
sudo sed -i 's#/path/to/binkterm#/var/www/binkterm-php#' /etc/fail2ban/jail.d/binkterm-telnet-ratelimit.local
sudo systemctl restart fail2ban
sudo fail2ban-client status binkterm-telnet-ratelimit
```

The filter matches log lines like:

```text
[2026-03-19 02:12:09] [1441153] [INFO] Rate limit exceeded for 66.205.238.49 - connection rejected
```

The daemon suppresses duplicate log lines within a rate-limit window, so only
one line is written per offending IP per window. The provided jail uses
`maxretry = 1` so fail2ban bans immediately on the first logged rejection.

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
3. Try a different terminal emulator with solid UTF-8 support, such as ZOC or PuTTY
4. Manually resize terminal window to trigger NAWS update

### Editor Issues

**Problem**: Arrow keys not working or inserting strange characters

**Solutions**:
1. Verify terminal type is set correctly (ANSI or VT100)
2. Try a different terminal emulator
3. Check telnet client configuration
4. Try ZOC or PuTTY with UTF-8 enabled

### Connection Rate Limiting

**Problem**: Legitimate users receive "Too many connections from your IP. Please try again later."

**Solutions**:
1. Wait for the current window to expire (default: 60 seconds from the first connection in the window) then reconnect
2. Check `data/logs/telnetd.log` for lines containing "Rate limit exceeded" to confirm which IP is being blocked
3. Raise `TELNET_RATE_LIMIT_MAX` in `.env` if the default of 5 connections per minute is too restrictive for your users
4. Set `TELNET_RATE_LIMIT_MAX=0` in `.env` and restart the daemon to disable rate limiting entirely on private/LAN installs

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

1. Client connects to the plain-text or TLS listener
2. Parent checks per-IP connection rate limit — closes socket immediately if exceeded
3. Daemon forks child process (Linux/macOS) or handles directly (Windows)
4. If TLS: child performs TLS handshake; on failure, connection is dropped
5. Child performs telnet option negotiation (NAWS, TTYPE, echo control)
6. Child probes for ANSI color support via TTYPE; enables color automatically if supported
7. Child displays ANSI/Sixel login screen or default banner
8. User sees pre-login menu (Login / Register / Reset password / QWK / Quit)
9. User authenticates via API (up to 3 attempts)
10. Main menu displayed with message counts
11. User navigates menus and performs actions
12. Connection closed and child exits
13. Parent reaps zombie process via SIGCHLD

### Code Structure

- `telnet/telnet_daemon.php` — Main daemon script
- Telnet protocol implementation with IAC negotiation
- ANSI escape code support for colors and cursor control

## Contributing

When contributing to the telnet daemon:

1. Test with multiple terminal emulators (PuTTY, ZOC, standard telnet)
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
