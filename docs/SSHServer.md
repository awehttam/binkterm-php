# SSH Server

BinktermPHP includes a built-in pure-PHP SSH-2 server that provides the same
BBS terminal experience as the Telnet daemon over an encrypted connection.  No
external SSH daemon (OpenSSH, Dropbear, etc.) is required.

SSH is one access method for the shared BinktermPHP Terminal Server. The
post-login feature set (netmail, echomail, file areas, doors, polls, shoutbox,
editor behavior, and menu flow) is documented in:

- [BinktermPHP Terminal Server](TerminalServer.md)

This document focuses on SSH-specific transport, daemon setup, and
troubleshooting.

## Features

- **SSH-2 protocol** — pure PHP implementation using only `ext-openssl` and
  `ext-gmp`; no additional Composer dependencies
- **Password authentication** — credentials verified against the BBS API,
  same as Telnet and the web interface
- **Direct login** — correct SSH credentials skip the BBS login screen and
  land directly on the main menu
- **Login-screen fallback** — failed SSH auth (after all attempts) drops the
  user to the normal BBS login/register screen rather than disconnecting
- **Auto-generated host key** — a 3072-bit RSA host key is created on first
  run and stored in `data/ssh/ssh_host_rsa_key`
- **Terminal size** — PTY dimensions negotiated via `pty-req` are passed
  through to the BBS session
- **Multi-connection** — forks per connection on Linux/macOS; single-connection
  on Windows (no `pcntl_fork`)
- **Daemon mode** — supports `--daemon` flag and PID file management

## Requirements

- PHP 8.1+
- PHP extensions: `curl`, `openssl`, `gmp`, `pcntl` (Linux/macOS for multiple
  concurrent connections)
- BinktermPHP web API reachable (defaults to `SITE_URL` from `.env`)

## Starting the Daemon

```bash
php ssh/ssh_daemon.php
```

The daemon listens on `0.0.0.0:2022` by default.

## Command Line Options

| Option | Default | Description |
|--------|---------|-------------|
| `--host=ADDR` | `0.0.0.0` | IP address to bind to |
| `--port=PORT` | `2022` | TCP port to listen on |
| `--api-base=URL` | `SITE_URL` | Base URL for BBS API requests |
| `--debug` | off | Verbose logging to console |
| `--daemon` | off | Run as a background daemon |
| `--pid-file=FILE` | `data/run/sshd.pid` | Path to write the PID file |
| `--insecure` | off | Skip SSL certificate verification for API calls |

## Environment Variables (`.env`)

| Variable | Default | Description |
|----------|---------|-------------|
| `SSH_BIND_HOST` | `0.0.0.0` | Bind address |
| `SSH_PORT` | `2022` | Listening port |

Command-line arguments take precedence over `.env` values.

## Running as a Service

### Systemd

```ini
[Unit]
Description=BinktermPHP SSH Daemon
After=network.target

[Service]
Type=simple
User=yourusername
Group=yourusername
WorkingDirectory=/path/to/binktest
ExecStart=/usr/bin/php /path/to/binktest/ssh/ssh_daemon.php
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable binkterm-ssh
sudo systemctl start binkterm-ssh
```

### Cron (`@reboot`)

```bash
@reboot /usr/bin/php /path/to/binktest/ssh/ssh_daemon.php --daemon
```

## Connecting

### PuTTY

1. Host Name: `your-bbs-hostname`
2. Port: `2022`
3. Connection type: **SSH**

### OpenSSH client

```bash
ssh -p 2022 your-bbs-hostname
```

### SyncTERM

1. Add new connection
2. Connection Type: **SSH**
3. Address: `your-bbs-hostname`
4. Port: `2022`

### ZOC

1. New session → Connection type: **SSH2**
2. Host: `your-bbs-hostname`, Port: `2022`

## Authentication Behaviour

| Scenario | Result |
|----------|--------|
| Correct username + password | SSH auth succeeds → logged in, main menu shown immediately |
| Wrong password (all attempts) | SSH channel still opened → BBS login screen shown |
| Protocol error / client disconnect | Connection closed |

## Host Key

On first start the daemon generates a 3072-bit RSA private key using
`ext-openssl` and stores it at `data/ssh/ssh_host_rsa_key` (mode `0600`).
Subsequent starts reuse the same key so clients do not see a host-key-changed
warning.

A bundled `config/ssh_openssl.cnf` is used for key generation so the process
works on Windows without requiring a system-wide OpenSSL installation or the
`OPENSSL_CONF` environment variable.

To replace the host key, delete `data/ssh/ssh_host_rsa_key` and restart the
daemon.

## Supported Algorithms

| Category | Algorithm |
|----------|-----------|
| Key exchange | `diffie-hellman-group14-sha256` |
| Host key | `rsa-sha2-256` |
| Encryption (both directions) | `aes128-ctr` |
| MAC (both directions) | `hmac-sha2-256` |
| Compression | `none` |
| Auth method | `password` |

These choices favour maximum compatibility with current SSH clients (PuTTY,
OpenSSH, SyncTERM, ZOC) while using only standard PHP crypto primitives.

## Architecture

```
ssh_daemon.php          — entry point, argument parsing
ssh/src/
  SshServer.php         — accept loop, per-connection fork, daemon/PID support
  SshSession.php        — SSH-2 wire protocol (KEX, auth, channel setup, crypto)
  SshStreamWrapper.php  — PHP stream wrapper used on Windows (no pcntl_fork)
config/ssh_openssl.cnf  — bundled OpenSSL config for host key generation
data/ssh/               — runtime: host key
data/run/sshd.pid       — PID file (daemon mode)
data/logs/sshd.log      — log file
```

On Linux/macOS the server forks twice per connection: one process runs the SSH
bridge (decrypts/encrypts) and a second runs `BbsSession` on a plain socket
pair.  On Windows (no `pcntl_fork`) a PHP stream wrapper transparently handles
SSH crypto inline so `BbsSession` runs in the same process.

`BbsSession` is shared with the Telnet daemon — the SSH server passes
`$isSsh = true` to skip Telnet negotiation and the ESC anti-bot challenge.

## Security Considerations

- The SSH layer provides transport encryption; credentials never travel in
  plaintext over the network
- Host key fingerprint should be communicated to users out-of-band (e.g. on
  your BBS website) so they can verify on first connect
- The daemon listens on all interfaces (`0.0.0.0`) by default; use
  `--host=127.0.0.1` or firewall rules to restrict access if needed
- Use `--insecure` only in development; production should use proper TLS
  certificates for the BBS API

## Troubleshooting

**Cannot connect**
- Check the daemon is running: `ps aux | grep ssh_daemon`
- Check the port is listening: `ss -tlnp | grep 2022`
- Verify firewall rules allow the port

**Host key warning on reconnect**
- The host key changed (e.g. `data/ssh/` was deleted). Clients must accept the
  new fingerprint or clear the old entry from `~/.ssh/known_hosts`.

**"Failed to generate SSH host key" on startup**
- Ensure `ext-openssl` is enabled in `php.ini`
- On Windows, verify `config/ssh_openssl.cnf` is present

**PHP Fatal: pcntl not available (Windows)**
- Expected on Windows. The daemon handles one connection at a time via the
  stream wrapper fallback. For multi-connection support use Linux/macOS.

## See Also

- [Telnet Daemon](TelnetServer.md) — unencrypted / TLS telnet alternative
