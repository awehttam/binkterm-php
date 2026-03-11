# Door Games - Sysop Documentation

BinktermPHP supports three types of door games, each suited to different use cases.

| Type | Description | Doc |
|------|-------------|-----|
| **DOS Doors** | Classic DOS games (LORD, TradeWars, etc.) running under DOSBox-X | [DOSDoors.md](DOSDoors.md) |
| **Native Doors** | Linux/Windows binaries or scripts running via PTY | [NativeDoors.md](NativeDoors.md) |
| **WebDoors** | Browser-based HTML5/PHP games embedded in an iframe | [WebDoors.md](WebDoors.md) |

WebDoors run entirely in the browser and require no additional server-side components. DOS Doors and Native Doors both require the **multiplexing bridge** described below.

---

## Multiplexing Bridge

DOS Doors and Native Doors share a single long-running Node.js bridge process (`scripts/dosbox-bridge/multiplexing-server.js`). The bridge:

- Listens for WebSocket connections from browsers on a single port (default: 6001)
- Authenticates sessions against the database
- For **DOS Doors**: launches DOSBox and multiplexes TCP I/O
- For **Native Doors**: spawns the door executable via `node-pty` and multiplexes PTY I/O

```
[Browser] ──→ wss://bbs.example.com:6001 ──→ [Multiplexing Bridge]
                       (WebSocket)              (Node.js Process)
                                                  ├──→ TCP ←── DOSBox → DOS Door
                                                  └──→ PTY  ←─ Native Binary
```

Both door types use the same bridge process, the same WebSocket port, and the same session database table. You only need to run one bridge instance to support both.

---

## Prerequisites

### Node.js

Node.js 18.x or newer is required. Tested with Node.js 24.

- Linux: `sudo apt install nodejs` or download from https://nodejs.org/
- Windows/macOS: Download from https://nodejs.org/

Verify: `node --version`

### Bridge Dependencies

Install Node.js dependencies from the bridge directory:

```bash
cd scripts/dosbox-bridge
npm install
```

This installs:
- `ws` — WebSocket server
- `iconv-lite` — CP437 ↔ UTF-8 encoding (DOS doors)
- `pg` — PostgreSQL client for session authentication
- `node-pty` — PTY support for native doors
- `dotenv` — reads `.env` configuration

### Database Schema

The bridge reads session records from the database. Tables are created by `scripts/setup.php`:

```bash
php scripts/setup.php
```

---

## File Structure

```
binkterm-php/
├── scripts/
│   └── dosbox-bridge/
│       ├── multiplexing-server.js          # WebSocket multiplexing bridge server
│       └── emulator-adapters.js            # DOSBox/emulator backend adapters
└── data/
    ├── run/
    │   └── multiplexing-server.pid         # PID file (daemon mode)
    └── logs/
        └── multiplexing-server.log         # Bridge log (daemon mode)
```

For door-type-specific layouts see:
- [DOSDoors.md — File Structure](DOSDoors.md#file-structure) — `dosbox-bridge/dos/`, door installations, drop file directories
- [NativeDoors.md — File Structure](NativeDoors.md#file-structure) — `native-doors/doors/`, drop files, runtime config

---

## Configuration

The bridge reads settings from your `.env` file. Shared settings relevant to both door types:

```bash
# WebSocket port for the multiplexing bridge (default: 6001)
DOSDOOR_WS_PORT=6001

# WebSocket bind address (default: 127.0.0.1)
# Use 127.0.0.1 behind a reverse proxy (recommended for production)
# Use 0.0.0.0 for direct access during development
DOSDOOR_WS_BIND_HOST=127.0.0.1

# WebSocket URL seen by browsers (optional - auto-detected if not set)
# Set this if you are behind an SSL-terminating reverse proxy
# DOSDOOR_WS_URL=wss://bbs.example.com:6001
# DOSDOOR_WS_URL=wss://bbs.example.com/dosdoor

# Maximum simultaneous door sessions across all door types (default: 10)
# Each session uses one node number (1 to MAX_SESSIONS)
DOSDOOR_MAX_SESSIONS=10

# Comma-separated list of proxy IP addresses whose X-Forwarded-For header is trusted
# for client IP resolution in logs. Only connections arriving from one of these IPs
# will have their remote address replaced by the forwarded value. (default: 127.0.0.1)
# DOSDOOR_TRUSTED_PROXIES=127.0.0.1,10.0.0.1
```

For DOS-door-specific settings (DOSBox executable, disconnect timeout, etc.) see [DOSDoors.md](DOSDoors.md#configuration).

---

## Running the Bridge

### Development / Testing

```bash
node scripts/dosbox-bridge/multiplexing-server.js
```

Expected output:

```
=== DOSBox Door Bridge - Multiplexing Server ===
WebSocket Port: 6001
Bind Address: 127.0.0.1
TCP Port Range: 5000-5100
Database: binktest@localhost:5432/binktest

[WS] Server listening on 127.0.0.1:6001
[WS] Waiting for connections...

Bridge server started successfully!
Press Ctrl+C to stop.
```

### Production — Daemon mode

The bridge has built-in daemon support via the `--daemon` flag:

```bash
node scripts/dosbox-bridge/multiplexing-server.js --daemon
```

This forks a detached background process and exits immediately, returning the shell prompt. Output:

```
Starting in daemon mode (PID: 12345)
PID file: /path/to/binkterm-php/data/run/multiplexing-server.pid
Log file: /path/to/binkterm-php/data/logs/multiplexing-server.log
```

The daemon:
- Writes its PID to `data/run/multiplexing-server.pid`
- Logs to `data/logs/multiplexing-server.log`
- Detects a stale PID file and cleans it up automatically on start
- Refuses to start a second instance if one is already running
- Responds to `SIGTERM` / `SIGINT` for clean shutdown

**Start on boot (cron):**

```bash
crontab -e
# Add:
@reboot cd /path/to/binkterm && /usr/bin/node scripts/dosbox-bridge/multiplexing-server.js --daemon
```

**Stop the daemon:**

```bash
kill $(cat data/run/multiplexing-server.pid)
```

### Production — Linux (systemd)

A service unit example is provided at `docs/dosdoor-bridge.service.example`.

1. **Copy the service file:**
   ```bash
   sudo cp docs/dosdoor-bridge.service.example /etc/systemd/system/dosdoor-bridge.service
   ```

2. **Edit the service file** and update:
   - `User=` — the user that runs BinktermPHP (e.g. `binkterm`)
   - `WorkingDirectory=` — full path to the BinktermPHP directory
   - `ExecStart=` — full paths to `node` and `multiplexing-server.js`

3. **Enable and start:**
   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable dosdoor-bridge
   sudo systemctl start dosdoor-bridge
   ```

4. **Check status:**
   ```bash
   sudo systemctl status dosdoor-bridge
   sudo journalctl -u dosdoor-bridge -f
   ```

### Production — Windows (NSSM)

Use [NSSM](https://nssm.cc/) to run the bridge as a Windows service:

```cmd
nssm install DoorBridge "C:\Program Files\nodejs\node.exe" "C:\path\to\binktest\scripts\dosbox-bridge\multiplexing-server.js"
nssm set DoorBridge AppDirectory "C:\path\to\binktest"
nssm start DoorBridge
```

---

## Reverse Proxy

If your BBS is served over HTTPS, browsers will require the WebSocket connection to also be secure (`wss://`). Two common approaches:

**Option A — Expose the bridge on a separate port with SSL:**
Configure your reverse proxy to terminate SSL on a dedicated port (e.g. 6001) and forward to the bridge on `127.0.0.1:6001`. Set `DOSDOOR_WS_URL=wss://bbs.example.com:6001`.

**Caddy** (`Caddyfile`):
```caddy
bbs.example.com:6001 {
    reverse_proxy 127.0.0.1:6001 {
        header_up Upgrade {http.upgrade}
        header_up Connection "Upgrade"
    }
}
```

**NGINX** (`nginx.conf` / site config):
```nginx
server {
    listen 6001 ssl;
    server_name bbs.example.com;

    ssl_certificate     /etc/ssl/certs/bbs.example.com.crt;
    ssl_certificate_key /etc/ssl/private/bbs.example.com.key;

    location / {
        proxy_pass http://127.0.0.1:6001;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 3600;
    }
}
```

**Apache** (requires `mod_proxy`, `mod_proxy_http`, `mod_proxy_wstunnel`, and `mod_ssl`):
```apache
Listen 6001
<VirtualHost *:6001>
    ServerName bbs.example.com

    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/bbs.example.com.crt
    SSLCertificateKeyFile /etc/ssl/private/bbs.example.com.key

    ProxyPass / ws://127.0.0.1:6001/
    ProxyPassReverse / ws://127.0.0.1:6001/
</VirtualHost>
```

**Option B — Path-based proxy:**
Forward a path to the bridge. Set `DOSDOOR_WS_URL=wss://bbs.example.com/doorplayersocket`.

The path `/doorplayersocket` is recommended — it is descriptive enough to be self-documenting and unlikely to conflict with any existing application routes.

nginx example for Option B:
```nginx
location /doorplayersocket {
    proxy_pass http://127.0.0.1:6001;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3600;
}
```

Apache example for Option B (requires `mod_proxy`, `mod_proxy_http`, and `mod_proxy_wstunnel`):
```apache
# Enable required modules if not already active:
#   a2enmod proxy proxy_http proxy_wstunnel

ProxyPass /doorplayersocket ws://127.0.0.1:6001/
ProxyPassReverse /doorplayersocket ws://127.0.0.1:6001/
```

If your VirtualHost uses `SSLProxyEngine`, add:
```apache
SSLProxyEngine on
```

---

## Door Type Details

- [DOS Doors](DOSDoors.md) — Setup, DOSBox configuration, adding door games, drop file format, troubleshooting
- [Native Doors](NativeDoors.md) — Manifest format, environment variables, platform notes, test doors
- [WebDoors](WebDoors.md) — Manifest format, iframe integration, BBS API, credits system
