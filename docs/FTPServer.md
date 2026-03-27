# FTP Server

BinktermPHP includes a standalone passive FTP daemon for QWK packet exchange and
file-area access.

This document covers daemon startup, `.env` configuration, passive FTP network
requirements, and how to expose the service on standard FTP port `21` without
running the daemon as root.

## Features

- Standalone daemon entrypoint: `scripts/ftp_daemon.php`
- Disabled by default
- Normal BBS username/password login
- Anonymous FTP login for public file areas only
- Virtual filesystem for:
  - `/qwk/download/<BBSID>.QWK`
  - `/qwk/upload/*.REP` or `/qwk/upload/*.ZIP`
  - `/incoming/<AREA>/...`
  - `/fileareas/...`
- Per-user upload/download logging
- Passive FTP (`PASV` / `EPSV`)
- Single-process `stream_select()` loop, so it works on Windows too

## Requirements

- PHP 8.1+
- BinktermPHP installed and configured
- File Areas enabled if you want `/incoming` or `/fileareas`
- QWK enabled if you want `/qwk/download` or `/qwk/upload`

## Default Ports

| Purpose | Default |
|---|---|
| FTP control port | `2121` |
| Passive port range | `2122`-`2149` |

The daemon intentionally does **not** bind to privileged port `21` by default.
That avoids needing to run the service as `root`.

## Enable It

The FTP daemon is disabled by default. Set this in `.env`:

```ini
FTPD_ENABLED=true
```

If `FTPD_ENABLED=false`, `scripts/ftp_daemon.php` exits immediately.

## Configuration

Add or review these settings in `.env`:

```ini
FTPD_ENABLED=true
FTPD_BIND_HOST=0.0.0.0
FTPD_PORT=2121
FTPD_PUBLIC_HOST=bbs.example.com
FTPD_PASSIVE_PORT_START=2122
FTPD_PASSIVE_PORT_END=2149
```

### Variables

| Variable | Default | Description |
|---|---|---|
| `FTPD_ENABLED` | `false` | Enables the standalone FTP daemon |
| `FTPD_BIND_HOST` | `0.0.0.0` | Control-socket bind address |
| `FTPD_PORT` | `2121` | FTP control port |
| `FTPD_PUBLIC_HOST` | empty | Hostname or IPv4 address advertised in passive replies |
| `FTPD_PASSIVE_PORT_START` | `2122` | First passive data port |
| `FTPD_PASSIVE_PORT_END` | `2149` | Last passive data port |

### `FTPD_PUBLIC_HOST`

Set `FTPD_PUBLIC_HOST` when:

- the daemon is behind NAT
- the daemon binds to `0.0.0.0`
- clients connect through a public hostname or public IP different from the
  machine's local address

Example:

```ini
FTPD_PUBLIC_HOST=ftp.example.com
```

If this is wrong, clients often log in successfully but fail on `LIST`, `RETR`,
or `STOR`.

## Starting the Daemon

Foreground:

```bash
php scripts/ftp_daemon.php
```

Background daemon mode:

```bash
php scripts/ftp_daemon.php --daemon
```

Defaults:

- PID file: `data/run/ftpd.pid`
- Log file: `data/logs/ftpd.log`

Show options:

```bash
php scripts/ftp_daemon.php --help
```

## Command Line Options

| Option | Default | Description |
|---|---|---|
| `--host=HOST` | `FTPD_BIND_HOST` or `0.0.0.0` | FTP bind host |
| `--port=PORT` | `FTPD_PORT` or `2121` | FTP control port |
| `--public-host=HOST` | `FTPD_PUBLIC_HOST` | Host/IP advertised in passive replies |
| `--pasv-start=PORT` | `FTPD_PASSIVE_PORT_START` or `2122` | First passive data port |
| `--pasv-end=PORT` | `FTPD_PASSIVE_PORT_END` or `2149` | Last passive data port |
| `--daemon` | off | Background daemon mode |
| `--pid-file=FILE` | `data/run/ftpd.pid` | PID file path |
| `--log-file=FILE` | `data/logs/ftpd.log` | Log file path |
| `--log-level=LEVEL` | `INFO` | Log level |
| `--no-console` | off | Disable console logging |

## Running It on Windows

The FTP daemon uses a `stream_select()` event loop and does not require
`pcntl_fork` for normal operation.

Run it in the foreground on Windows:

```powershell
php scripts/ftp_daemon.php
```

`--daemon` is Unix-oriented and requires `pcntl_fork`.

## Running as a Service

### systemd

```ini
[Unit]
Description=BinktermPHP FTP Daemon
After=network.target

[Service]
Type=simple
User=yourusername
Group=yourusername
WorkingDirectory=/path/to/binktest
ExecStart=/usr/bin/php /path/to/binktest/scripts/ftp_daemon.php
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Then:

```bash
sudo systemctl daemon-reload
sudo systemctl enable binkterm-ftpd
sudo systemctl start binkterm-ftpd
```

### Cron (`@reboot`)

```cron
@reboot /usr/bin/php /path/to/binktest/scripts/ftp_daemon.php --daemon
```

## Virtual Filesystem

### Authenticated Users

- `/qwk/download/<BBSID>.QWK` generates the user's QWK packet on demand
- `/qwk/upload/*.REP` or `/qwk/upload/*.ZIP` imports REP replies
- `/incoming/<AREA>/...` uploads files to writable file areas
- `/fileareas/...` browses and downloads approved files

### Anonymous Users

Anonymous login is supported on registered systems with:

- username: `anonymous` or `ftp`
- password: any string

Anonymous users are restricted to:

- `/fileareas/...` only
- file areas marked `is_public`

Anonymous users cannot:

- access `/qwk/...`
- upload to `/incoming/...`
- upload REP packets

If the system is not registered, anonymous login is rejected and only normal
authenticated BBS users can log in over FTP.

## Port 21 Without Running as Root

Linux only allows `root` to bind directly to ports below `1024`, including FTP
port `21`.

Because of that, the recommended deployment is:

- run `scripts/ftp_daemon.php` as a normal user on `2121`
- redirect external port `21` to internal port `2121`
- forward the passive range to the daemon unchanged

## `iptables` Redirect Rules

### Local Redirect on the Same Host

Use this when the machine itself receives traffic on port `21` and the FTP
daemon runs locally on `2121`.

```bash
sudo iptables -t nat -A PREROUTING -p tcp --dport 21 -j REDIRECT --to-ports 2121
```

If clients on the same host connect to the machine's own public IP, you may
also need:

```bash
sudo iptables -t nat -A OUTPUT -p tcp -d YOUR.SERVER.IP --dport 21 -j REDIRECT --to-ports 2121
```

Replace `YOUR.SERVER.IP` with the machine's actual IP.

### Port Forward From a Router or Firewall

If the daemon sits behind a router/NAT device, configure these forwards:

- external TCP `21` -> internal TCP `2121`
- external TCP `2122`-`2149` -> internal TCP `2122`-`2149`

Then set:

```ini
FTPD_PUBLIC_HOST=your.public.hostname
```

or:

```ini
FTPD_PUBLIC_HOST=203.0.113.10
```

## `nftables` Redirect Rules

### Local Redirect on the Same Host

The equivalent `nftables` redirect rule looks like this:

```bash
sudo nft add table ip nat
sudo nft 'add chain ip nat prerouting { type nat hook prerouting priority dstnat; }'
sudo nft add rule ip nat prerouting tcp dport 21 redirect to :2121
```

If clients on the same host connect to the machine's own public IP, you may
also need an output-chain redirect:

```bash
sudo nft 'add chain ip nat output { type nat hook output priority -100; }'
sudo nft add rule ip nat output ip daddr YOUR.SERVER.IP tcp dport 21 redirect to :2121
```

Replace `YOUR.SERVER.IP` with the machine's actual IP.

### Port Forward From a Router or Firewall

If the daemon sits behind a router/NAT device, the forwarding requirements are
the same as with `iptables`:

- external TCP `21` -> internal TCP `2121`
- external TCP `2122`-`2149` -> internal TCP `2122`-`2149`

### Persisting `iptables`

Rules added with `iptables` are not persistent by default.

Common persistence methods:

- Debian/Ubuntu: `iptables-persistent`
- RHEL/Alma/Rocky: firewalld rich/direct rules, or a saved ruleset restored at boot
- custom boot script or systemd unit

Example on Debian/Ubuntu:

```bash
sudo apt-get install iptables-persistent
sudo netfilter-persistent save
```

### Persisting `nftables`

On systems that use `nftables` natively, save the running ruleset and ensure it
is loaded at boot.

Example:

```bash
sudo sh -c 'nft list ruleset > /etc/nftables.conf'
sudo systemctl enable nftables
sudo systemctl restart nftables
```

## Firewall Checklist

Allow inbound TCP for:

- `21` if you are exposing the standard FTP port externally
- `2121` if clients connect directly to the daemon without redirect
- `2122`-`2149` for passive transfers

If passive ports are not reachable, login may succeed but directory listings and
file transfers will fail.

## Logging

The daemon logs to:

```text
data/logs/ftpd.log
```

Logged events include:

- user logins
- anonymous logins
- uploads
- downloads
- transfer byte counts
- success/failure status

## Troubleshooting

**Daemon exits immediately with "FTPD is disabled"**
- Set `FTPD_ENABLED=true` in `.env`

**Login works but `LIST` or downloads fail**
- verify the passive port range is forwarded and allowed through firewalls
- verify `FTPD_PUBLIC_HOST` matches the address clients actually use

**Works on LAN but not from the Internet**
- check router/NAT port forwards
- check external firewall rules
- confirm passive ports are forwarded, not just the control port

**Cannot bind to port 21**
- expected when not running as `root`
- keep the daemon on `2121` and use redirect/forwarding rules instead

**Windows background mode fails**
- run in foreground on Windows
- `--daemon` requires Unix `pcntl_fork`

## See Also

- [Configuration](CONFIGURATION.md)
- [QWK Offline Mail Upgrade Notes](UPGRADING_1.8.9.md#ftp-access)
