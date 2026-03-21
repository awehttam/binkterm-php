# BinktermPHP Configuration Reference

This document covers all configuration files and environment variables for BinktermPHP.

**Most settings can be changed through the Admin web interface without editing files directly.**
Direct file editing is only necessary for initial setup, advanced options not yet exposed in the UI, or scripted/automated deployments.

After changing any configuration file, restart BBS services:
```bash
bash scripts/restart_daemons.sh
```

---

## Table of Contents

- [Configuration Files Overview](#configuration-files-overview)
- [.env — Environment Variables](#env--environment-variables)
- [config/binkp.json — Core BBS Settings](#configbinkpjson--core-bbs-settings)
  - [System Settings](#system-settings)
  - [Binkp Settings](#binkp-settings)
  - [Uplink Configuration](#uplink-configuration)
  - [Security Settings](#security-settings)
  - [Crashmail Settings](#crashmail-settings)
- [config/nodelists.json — Nodelist Sources](#confignodelistsjson--nodelist-sources)
- [config/bbs.json — BBS Feature Settings](#configbbsjson--bbs-feature-settings)
- [Other Config Files](#other-config-files)
- [Network Ports Reference](#network-ports-reference)
- [Welcome & Text Files](#welcome--text-files)

---

## Configuration Files Overview

| File | Purpose | Edited via |
|------|---------|------------|
| `.env` | Database, SMTP, daemon ports, feature flags | Text editor (initial setup) |
| `config/binkp.json` | System identity, uplinks, binkp daemon, security, crashmail | Admin UI → BinkP Config |
| `config/bbs.json` | BBS features (credits, file areas, registration, etc.) | Admin UI → BBS Settings |
| `config/nodelists.json` | Nodelist download sources | Admin UI → Nodelists |
| `config/mrc.json` | MRC chat relay server | Admin UI or text editor |
| `config/webdoors.json` | WebDoor game configuration | Admin UI → WebDoors |
| `config/dosdoors.json` | DOS door game configuration | Admin UI → DOS Doors |
| `config/nativedoors.json` | Native door configuration | Admin UI → Native Doors |
| `config/themes.json` | Theme/appearance settings | Admin UI → Appearance |
| `config/taglines.txt` | Pool of taglines appended to messages | Text editor |
| `config/weather.json` | Weather report API settings | Admin UI or text editor |

Examples for most files are provided as `config/*.example` — copy and edit as needed.

---

## .env — Environment Variables

The `.env` file is the primary low-level configuration file.  Copy `.env.example` to `.env` and fill in values before running setup.

### Database

```bash
DB_HOST=localhost
DB_PORT=5432
DB_NAME=binktest
DB_USER=binktest
DB_PASS=yourpassword
DB_SSL=false

# Optional SSL (uncomment if your PostgreSQL requires it)
#DB_SSL_CA=/path/to/ca-cert.pem
#DB_SSL_CERT=/path/to/client-cert.pem
#DB_SSL_KEY=/path/to/client-key.pem
```

### Site URL

```bash
SITE_URL=https://yourbbs.example.com
```

Used when generating share links, password-reset emails, and any absolute URL the server builds.  Must be the public-facing URL including scheme.  When behind a reverse proxy, set this explicitly — the server cannot reliably detect HTTPS from `$_SERVER`.

### BBS Directory Geocoding

```bash
# Enabled by default
# BBS_DIRECTORY_GEOCODING_ENABLED=true
# BBS_DIRECTORY_GEOCODER_URL=https://nominatim.openstreetmap.org/search
# BBS_DIRECTORY_GEOCODER_USER_AGENT=
# BBS_DIRECTORY_GEOCODER_EMAIL=
```

These settings control the best-effort geocoding used to place BBS Directory entries on the public map. Coordinates are looked up from the entry's `location` field when a directory entry is created, updated, or upserted. If geocoding fails, the entry still saves normally and simply will not appear on the map until coordinates are available.

### SMTP Email

```bash
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_SECURITY=tls          # tls, ssl, or empty
SMTP_USER=your@email.com
SMTP_PASS=yourpassword
SMTP_FROM_EMAIL=noreply@yourbbs.example.com
SMTP_FROM_NAME=MyBBS
SMTP_ENABLED=true
#SMTP_NOVERIFYCERT=true    # Uncomment to skip TLS cert verification
```

Used for new-user welcome emails, password resets, and sysop notifications.

### Admin Daemon

```bash
ADMIN_DAEMON_SECRET=change_me_to_a_random_string

# Unix socket (Linux — recommended for production)
ADMIN_DAEMON_SOCKET=unix:///path/to/binkterm/data/run/admin_daemon.sock
ADMIN_DAEMON_SOCKET_PERMS=0660

# TCP socket (Windows, or if Unix socket is not available)
# ADMIN_DAEMON_SOCKET=tcp://127.0.0.1:9065
```

The admin daemon provides an internal API between the web interface and system services.  Keep the Unix socket behind appropriate file permissions; the TCP socket should be bound to `127.0.0.1` only.

```bash
ADMIN_DAEMON_SCHEDULE_ENABLED=true
ADMIN_DAEMON_SCHEDULE_INTERVAL=60    # seconds between scheduler ticks
```

### Telnet Daemon

```bash
# TELNET_BIND_HOST=0.0.0.0
# TELNET_PORT=2323

# TLS is enabled by default on port 8023.  Set false to disable.
# TELNET_TLS=true
# TELNET_TLS_PORT=8023
# TELNET_TLS_CERT=/etc/ssl/certs/your-cert.pem
# TELNET_TLS_KEY=/etc/ssl/private/your-key.pem

# ZMODEM file transfers over the telnet BBS
# TERMINAL_FILE_TRANSFERS=true
# TELNET_SZ_BIN=/usr/bin/sz   # override path to sz binary (lrzsz)
# TELNET_RZ_BIN=/usr/bin/rz   # override path to rz binary (lrzsz)
# TELNET_ZMODEM_DEBUG=false   # log to data/logs/zmodem.log
```

### SSH Daemon

```bash
# SSH_PORT=2022
# SSH_BIND_HOST=0.0.0.0
```

See [docs/SSHServer.md](SSHServer.md) for full SSH daemon setup including key generation.

### Gemini Capsule Daemon

```bash
# GEMINI_BIND_HOST=0.0.0.0
# GEMINI_PORT=1965
# GEMINI_CERT_PATH=/etc/letsencrypt/live/yourdomain.com/fullchain.pem
# GEMINI_KEY_PATH=/etc/letsencrypt/live/yourdomain.com/privkey.pem
```

See [docs/GeminiCapsule.md](GeminiCapsule.md) for Gemini capsule hosting setup.

### Web Terminal (WebDoor)

```bash
TERMINAL_ENABLED=false             # Set true to enable the Terminal WebDoor
TERMINAL_HOST=your.ssh.server.com
TERMINAL_PORT=22
TERMINAL_TYPE=ssh                  # ssh or telnet
TERMINAL_PROXY_HOST=your.proxy.server.com
TERMINAL_PROXY_PORT=443
TERMINAL_TITLE=Terminal Gateway    # Label shown in the WebDoor
```

Requires a WebSocket-to-SSH proxy such as [Terminal Gateway](https://github.com/awehttam/terminalgateway).  Users must be authenticated in the web interface to access the terminal.

### DOS Doors

```bash
DOSDOOR_WS_PORT=6001
DOSDOOR_WS_BIND_HOST=127.0.0.1
# DOSDOOR_WS_URL=wss://bbs.example.com:6001   # explicit client URL
DOSDOOR_MAX_SESSIONS=10
DOSDOOR_DISCONNECT_TIMEOUT=0        # 0 = close door immediately on disconnect
DOSDOOR_CARRIER_LOSS_TIMEOUT=5000   # ms to wait before force-kill

# DOSBOX_EXECUTABLE=/usr/bin/dosbox-x
# DOOR_EMULATOR=dosbox              # dosbox or dosemu
# DOSDOOR_CONFIG=dosbox-bridge-production.conf
# DOSDOOR_DEBUG_KEEP_FILES=false
```

See [docs/DOSBox_Headless_Mode.md](DOSBox_Headless_Mode.md) and [docs/DOSDoors.md](DOSDoors.md).

### PubTerm (Native Door)

```bash
# PUBTERM_HOST=127.0.0.1
# PUBTERM_PORT=2323
# PUBTERM_TELNET_BIN=/usr/bin/telnet
# PUBTERM_PLINK_BIN=C:\Program Files\PuTTY\plink.exe   # Windows
```

### Appearance

```bash
# STYLESHEET=/css/dark.css    # default stylesheet for all users
# FAVICONSVG=/robot_favicon.svg
# FAVICONPNG=/robot_favicon.png
# FAVICONICO=/robot_favicon.ico
```

### Miscellaneous

```bash
# Virus scanning (ClamAV) — auto-detected if not set
# CLAMDSCAN=/usr/bin/clamdscan

# Slow-request profiling
PERF_LOG_ENABLED=false
PERF_LOG_SLOW_MS=500

# Echomail sort field (received = date_received, written = date_written)
# ECHOMAIL_ORDER_DATE=received

# Stale unprocessed inbound files are moved to data/inbound/unprocessed/ by default
# Files are only moved/deleted after they have been untouched for 24 hours
# Set this to true to delete stale unprocessed files instead of quarantining them
# BINKP_DELETE_UNPROCESSED_FILES=false

# Archive extractors for Fidonet bundles (JSON array)
# ARCMAIL_EXTRACTORS=["7z x -y -o{dest} {archive}","unzip -o {archive} -d {dest}"]

# i18n missing-key logging (development/QA)
# I18N_LOG_MISSING_KEYS=false
# I18N_MISSING_KEYS_LOG_FILE=data/logs/i18n_missing_keys.log

# File area rule action log
FILEAREA_RULE_ACTION_LOG=data/logs/filearea_rules.log

# ⚠️  DEVELOPMENT MODE — NEVER enable on a production system.
# Activates destructive diagnostic functions that can disrupt normal operation,
# including the ability to purge per-user QWK state (conference pointers,
# download log, message index, and deduplication hashes) via the web UI.
# Any feature gated on IS_DEV is intentionally undocumented elsewhere because
# it must not be accessible on a live system.
# IS_DEV=true
```

---

## config/binkp.json — Core BBS Settings

`config/binkp.json` defines your system's FTN identity, the binkp daemon, uplinks, and related services.  See `config/binkp.json.example` for an annotated reference.

**After editing this file, restart services.**

Minimal example:

```json
{
    "system": {
        "name": "My BBS",
        "address": "1:123/456.0",
        "sysop": "Sysop Name",
        "location": "Anytown, USA",
        "hostname": "bbs.example.com",
        "timezone": "America/New_York"
    },
    "binkp": {
        "port": 24554,
        "timeout": 300,
        "max_connections": 10,
        "bind_address": "0.0.0.0",
        "inbound_path": "data/inbound",
        "outbound_path": "data/outbound",
        "preserve_processed_packets": false,
        "preserve_sent_packets": false
    },
    "uplinks": [ ... ],
    "security": { ... },
    "crashmail": { ... }
}
```

### System Settings

| Field | Required | Description |
|-------|----------|-------------|
| `name` | Yes | Your system's display name |
| `address` | Yes | Your primary FTN address (`zone:net/node.point`) |
| `sysop` | Yes | Sysop's real name — **must match the real name on the sysop user account** so netmail addressed to "sysop" delivers correctly |
| `location` | No | Geographic location shown in system info |
| `hostname` | Yes | Your internet hostname or IP address |
| `website` | No | Website URL — included in FidoNet message origin lines when set |
| `timezone` | Yes | System timezone ([PHP timezone list](https://www.php.net/manual/en/timezones.php)) |

When `website` is configured, origin lines include it:
```
* Origin: My BBS <https://mybbs.example.com> (1:234/567)
```

### Binkp Settings

| Field | Default | Description |
|-------|---------|-------------|
| `port` | 24554 | TCP port for the binkp daemon |
| `timeout` | 300 | Connection timeout in seconds |
| `max_connections` | 10 | Maximum simultaneous inbound connections |
| `bind_address` | `0.0.0.0` | IP address to bind to (`0.0.0.0` = all interfaces) |
| `inbound_path` | `data/inbound` | Directory for incoming packets |
| `outbound_path` | `data/outbound` | Directory for outgoing packets |
| `preserve_processed_packets` | false | When true, moves processed packets to `data/inbound/keep/<Mon-DD-YYYY>/` instead of deleting them |
| `preserve_sent_packets` | false | When true, moves successfully sent outbound packets to `data/outbound/keep/<Mon-DD-YYYY>/` instead of deleting them |

### Uplink Configuration

Each entry in the `uplinks` array defines one hub/uplink connection.

| Field | Required | Description |
|-------|----------|-------------|
| `me` | Yes | Your FTN address as presented to this uplink |
| `address` | Yes | The uplink's FTN address |
| `hostname` | Yes | Uplink hostname or IP address |
| `port` | Yes | Uplink port (typically 24554) |
| `password` | Yes | Authentication password (shared secret) |
| `pkt_password` | No | Packet-level password (if different from session password) |
| `tic_password` | No | TIC file password for file echoes |
| `domain` | Yes | Network domain (e.g., `"fidonet"`, `"fsxnet"`, `"agoranet"`) |
| `networks` | Yes | Address patterns routed through this uplink (see below) |
| `poll_schedule` | No | Cron expression for automated polling, e.g. `"0 */4 * * *"` |
| `allow_markup` | No | Enable Markdown/StyleCodes for messages via this uplink |
| `send_domain_in_addr` | No | Include `@domain` suffix in the ADR address sent to this uplink |
| `enabled` | No | Whether uplink is active (default: `true`) |
| `default` | No | Whether this is the default uplink for unrouted messages |
| `compression` | No | Enable compression (not yet implemented) |
| `crypt` | No | Enable encryption (not yet implemented) |
| `binkp_zone` | No | DNS zone for crashmail fallback routing (e.g. `"binkp.net"`) |

**Network Patterns** — `networks` uses wildcard patterns:
```
"1:*/*"   → all Zone 1 (FidoNet)
"21:*/*"  → all Zone 21 (FSXNet)
"46:*/*"  → all Zone 46 (AgoraNet)
```

**Multiple networks example:**
```json
"uplinks": [
    {
        "me": "1:123/456.0",
        "address": "1:1/23",
        "domain": "fidonet",
        "networks": ["1:*/*", "2:*/*", "3:*/*", "4:*/*"],
        "hostname": "hub.fidonet.example.com",
        "port": 24554,
        "password": "fido_secret",
        "poll_schedule": "*/15 * * * *",
        "default": true,
        "enabled": true
    },
    {
        "me": "21:1/999",
        "address": "21:1/100",
        "domain": "fsxnet",
        "networks": ["21:*/*"],
        "hostname": "hub.fsxnet.example.com",
        "port": 24554,
        "password": "fsx_secret",
        "poll_schedule": "*/30 * * * *",
        "enabled": true
    }
]
```

### Security Settings

The `security` section controls insecure (passwordless) inbound binkp sessions.

| Field | Default | Description |
|-------|---------|-------------|
| `allow_insecure_inbound` | `false` | Allow incoming connections without password authentication |
| `insecure_inbound_receive_only` | `true` | Insecure sessions can only deliver mail, not pick up |
| `require_allowlist_for_insecure` | `false` | Only allow insecure sessions from nodes in the Admin allowlist |
| `max_insecure_sessions_per_hour` | `10` | Rate limit for insecure sessions per remote address |
| `allow_plaintext_fallback` | `true` | Allow plaintext fallback when CRAM-MD5 is available |

Insecure sessions are typically used for receiving mail from nodes that don't have your password configured.  The allowlist (Admin → Insecure Nodes) gives fine-grained control.

### Crashmail Settings

The `crashmail` section controls immediate direct delivery of netmail, bypassing normal hub routing.

| Field | Default | Description |
|-------|---------|-------------|
| `enabled` | `false` | Enable crashmail direct delivery |
| `max_attempts` | `3` | Maximum delivery attempts before marking failed |
| `retry_interval_minutes` | `15` | Minutes between retry attempts |
| `use_nodelist_for_routing` | `true` | Look up destination in nodelist for hostname/port |
| `fallback_port` | `24554` | Default port if not found in nodelist |
| `allow_insecure_crash_delivery` | `false` | Allow crashmail delivery without password |

**DNS Fallback (`binkp_zone`):** When a destination node cannot be found in the nodelist, crashmail can fall back to DNS.  Set `binkp_zone` on the matching uplink to enable it:

```json
{
    "me": "1:123/456.0",
    "binkp_zone": "binkp.net"
}
```

The hostname is derived from the FTN address using the standard convention:
```
f{node}.n{net}.z{zone}.{binkp_zone}

Examples:
  1:123/456  →  f456.n123.z1.binkp.net
  2:250/10   →  f10.n250.z2.binkp.net
```

Compatible with DNS-based registries such as [binkp.net](https://binkp.net).

---

## config/nodelists.json — Nodelist Sources

Defines sources for automatic nodelist downloads.  See `config/nodelists.json.example` for reference.

```json
{
    "sources": [
        {
            "name": "FidoNet",
            "domain": "fidonet",
            "url": "https://example.com/NODELIST.Z|DAY|",
            "enabled": true
        },
        {
            "name": "FSXNet",
            "domain": "fsxnet",
            "url": "https://bbs.nz/fsxnet/FSXNET.ZIP",
            "enabled": true
        }
    ]
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `name` | Yes | Display name for this nodelist source |
| `domain` | Yes | Network domain identifier (must match an uplink `domain`) |
| `url` | Yes | Download URL; supports date macros (see below) |
| `enabled` | No | Whether this source is active (default: `true`) |

**URL Date Macros:**

| Macro | Description | Example |
|-------|-------------|---------|
| `\|DAY\|` | Day of year (1–366) | `23` |
| `\|YEAR\|` | 4-digit year | `2026` |
| `\|YY\|` | 2-digit year | `26` |
| `\|MONTH\|` | 2-digit month | `01` |
| `\|DATE\|` | 2-digit day of month | `22` |

---

## config/bbs.json — BBS Feature Settings

`config/bbs.json` controls BBS-specific features: credits system, file areas, user registration, telnet/SSH settings, and more.  The recommended way to edit this is through Admin → BBS Settings in the web interface.

A documented example is provided in `config/bbs.json.example`.

---

## Other Config Files

| File | Purpose | Reference |
|------|---------|-----------|
| `config/mrc.json` | MRC multi-relay chat server connection | [docs/MRC_Chat.md](MRC_Chat.md) |
| `config/webdoors.json` | WebDoor game settings and enable/disable | [docs/WebDoors.md](WebDoors.md) |
| `config/dosdoors.json` | DOS door game drop files and node settings | [docs/DOSDoors.md](DOSDoors.md) |
| `config/nativedoors.json` | Native Linux/Windows door programs | [docs/NativeDoors.md](NativeDoors.md) |
| `config/themes.json` | Appearance system shell assignments | [docs/CUSTOMIZING.md](CUSTOMIZING.md) |
| `config/weather.json` | Weather report API key and defaults | [docs/Weather.md](Weather.md) |
| `config/lovlynet.json` | LovlyNet network registration | [docs/LovlyNet.md](LovlyNet.md) |
| `config/taglines.txt` | One tagline per line; randomly appended to messages | — |
| `config/filearea_rules.json` | Automated file area processing rules | [docs/FileAreas.md](FileAreas.md) |

---

## Network Ports Reference

| Service | Default Port | Protocol | Direction | Configured In |
|---------|-------------|----------|-----------|---------------|
| Web interface (via Apache/Caddy/Nginx) | `80`, `443` | HTTP/HTTPS | Inbound | Web server / reverse proxy |
| BinkP daemon | `24554` | TCP | In + Out | `config/binkp.json` → `binkp.port` |
| Telnet daemon (plain) | `2323` | TCP | Inbound | `.env` `TELNET_PORT` |
| Telnet daemon (TLS) | `8023` | TCP/TLS | Inbound | `.env` `TELNET_TLS_PORT` |
| SSH daemon | `2022` | SSH-2/TCP | Inbound | `.env` `SSH_PORT` |
| Gemini capsule daemon | `1965` | Gemini/TLS | Inbound | `.env` `GEMINI_PORT` |
| DOS door WebSocket bridge | `6001` | WebSocket | Inbound | `.env` `DOSDOOR_WS_PORT` |
| DOSBox bridge session range | `5000–5100` | TCP | Internal | Between bridge and emulator |
| Admin daemon (TCP fallback) | `9065` | TCP | localhost | `.env` `ADMIN_DAEMON_SOCKET` |
| PostgreSQL | `5432` | TCP | Internal | `.env` `DB_PORT` |
| MRC relay (remote) | `5000` / `5001` | TCP / TLS | Outbound | `config/mrc.json` |

**Tips:**
- Expose only the services you actually run.
- Bind internal services (admin daemon, DOSBox bridge, PostgreSQL) to `127.0.0.1`.
- Publish user-facing services through a reverse proxy (Caddy, Nginx, Apache) with TLS.

### Apache: enabling gzip compression for JSON

Apache's `mod_deflate` does not compress `application/json` by default, so API responses (including the i18n catalog) are sent uncompressed. Add the following to your VirtualHost or `.htaccess`:

```apache
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE application/json
    AddOutputFilterByType DEFLATE text/html text/css application/javascript text/plain image/svg+xml
    AddOutputFilterByType DEFLATE font/ttf font/opentype application/x-font-ttf application/x-font-otf
    # Note: woff and woff2 are already compressed internally — do not add them here
</IfModule>
```

Enable the module if not already active:

```bash
a2enmod deflate
systemctl reload apache2
```

---

## Welcome & Text Files

Customise user-facing text by creating optional files in `config/`.  Examples are provided as `config/*.example`.

| File | When shown |
|------|-----------|
| `config/terminal_welcome.txt` | Telnet/SSH BBS login screen (replaces default host:port message) |
| `config/newuser_welcome.txt` | Email sent to newly approved users |
| `config/welcome.txt` | General welcome message on the main page / login screen |

Files are plain text; newlines are preserved as written.
