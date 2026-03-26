# MCP Server

BinktermPHP includes an optional [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server that gives AI assistants read-only access to your echomail database. It is located in `mcp-server/` and runs as a separate Node.js process.

**Requires a registered license.** The server will not start without a valid `data/license.json`.

---

## Table of Contents

- [How It Works](#how-it-works)
- [Setup](#setup)
- [Starting and Stopping](#starting-and-stopping)
- [User Keys](#user-keys)
- [Connecting an AI Assistant](#connecting-an-ai-assistant)
- [Available Tools](#available-tools)
- [Access Control](#access-control)
- [Configuration Reference](#configuration-reference)
- [Reverse Proxy Setup](#reverse-proxy-setup)
- [Boot Startup](#boot-startup)
- [Logging](#logging)

---

## How It Works

The MCP server exposes a set of tools over HTTP that AI assistants can call to query echomail areas and messages. Each user generates their own personal bearer key from **Settings → AI**. The server looks up that key in the database and enforces the same access rules as the web interface — inactive areas and sysop-only areas are hidden for non-admin users.

Authentication is per-user, not per-installation. There are no shared static API keys.

---

## Setup

Install Node.js dependencies (one time, from the project root):

```bash
cd mcp-server
npm install
cd ..
```

No separate `.env` file is needed. The server reads the main BinktermPHP `.env` file automatically.

The MCP server listens on port `3740` by default. Add this to your main `.env` only if you want to override the default port:

```
MCP_SERVER_PORT=3740
```

---

## Starting and Stopping

**Manually (foreground):**

```bash
node mcp-server/server.js [--bind=<host>] [--pid-file=<path>]
node mcp-server/server.js --help
```

**Manually (background / daemon):**

```bash
node mcp-server/server.js --daemon --bind=127.0.0.1 --pid-file=data/run/mcp-server.pid
```

`--daemon` forks the process to the background, redirects stdout/stderr to the log file, and exits the parent. The PID of the background process is written to the pid file. `restart_daemons.sh` already handles detaching internally so `--daemon` is not needed there.

Pass `--bind=127.0.0.1` when running behind a reverse proxy so the server only accepts connections from localhost:

```bash
node mcp-server/server.js --bind=127.0.0.1
```

**Via the daemon script** (restart only if already running):

```bash
./scripts/restart_daemons.sh mcp_server
./scripts/restart_daemons.sh --start mcp_server
./scripts/restart_daemons.sh --stop  mcp_server
```

The server writes a PID file to `data/run/mcp-server.pid` and logs to `data/logs/mcp-server.log`.

---

## User Keys

The key management UI in **Settings → AI** is only shown when `MCP_SERVER_URL` is set in `.env`. Without it, users see a "not currently enabled" message and cannot generate keys.

Each user generates their own bearer key from **Settings → AI**. Keys are stored in the `users_meta` table under keyname `mcp_serverkey`.

- A key can be regenerated at any time; the old key is immediately invalidated.
- Revoking a key removes it entirely. Any AI client using it will receive `401 Unauthorized`.
- The full key is shown only once at generation time. It cannot be retrieved again — only regenerated.

Users who are admins on the BBS will have admin-level access through their key (sysop-only areas are visible). Regular users see only public active areas.

---

## Connecting an AI Assistant

### Claude Code

Add an entry to `.mcp.json` in your project root, or to the global Claude Code settings:

```json
{
  "mcpServers": {
    "binkterm": {
      "type": "http",
      "url": "http://your-bbs-hostname:3740/mcp",
      "headers": {
        "Authorization": "Bearer <your-key-from-settings>"
      }
    }
  }
}
```

### Other MCP Clients

Any client that supports Streamable HTTP MCP transport can connect. Pass the bearer key in the `Authorization` header or as the `X-API-Key` header:

```
Authorization: Bearer <key>
X-API-Key: <key>
```

The MCP endpoint is `POST /mcp` (and `GET /mcp` for SSE streaming). On a default direct setup, the full URL is typically `https://your-bbs-hostname:3740/mcp`. A health check with no authentication is available at `GET /health`.

---

## Available Tools

| Tool | Description |
|------|-------------|
| `list_echoareas` | List active echo areas, optionally filtered by domain |
| `get_echoarea` | Details for a specific area by tag |
| `get_echomail_messages` | Paginated messages from an area, with sender/subject/date filters |
| `get_echomail_message` | Full text and metadata of a single message by ID |
| `search_echomail` | Cross-area keyword search against subject and message body |
| `get_echomail_thread` | Complete conversation thread from any message in it |

### `list_echoareas`

| Parameter | Type | Description |
|-----------|------|-------------|
| `domain` | string (optional) | Filter by network domain, e.g. `fidonet` |

Returns tag, domain, description, moderator, message count, and flags for each area.

### `get_echoarea`

| Parameter | Type | Description |
|-----------|------|-------------|
| `tag` | string | Echo area tag, e.g. `GENERAL` (case-insensitive) |
| `domain` | string (optional) | Disambiguate when multiple networks share a tag |

### `get_echomail_messages`

| Parameter | Type | Description |
|-----------|------|-------------|
| `tag` | string | Echo area tag |
| `domain` | string (optional) | Network domain |
| `limit` | integer (optional) | Number of messages (default 25, max 100) |
| `offset` | integer (optional) | Pagination offset |
| `from_name` | string (optional) | Filter by sender (partial, case-insensitive) |
| `to_name` | string (optional) | Filter by recipient (partial, case-insensitive) |
| `subject` | string (optional) | Filter by subject (partial, case-insensitive) |
| `since` | string (optional) | ISO 8601 datetime — only messages received after this |

Returns a message preview (first 500 characters of body). Use `get_echomail_message` for the full text.

### `get_echomail_message`

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | integer | Echomail message ID |

Returns the full message text, kludge lines, tearline, origin line, reply-to ID, and echoarea tag.

### `search_echomail`

| Parameter | Type | Description |
|-----------|------|-------------|
| `query` | string | Search term (minimum 2 characters) |
| `tag` | string (optional) | Limit to a specific echo area |
| `domain` | string (optional) | Limit to a specific network domain |
| `from_name` | string (optional) | Filter by sender |
| `since` | string (optional) | ISO 8601 datetime lower bound |
| `limit` | integer (optional) | Max results (default 20, max 50) |

Searches subject and message body with a case-insensitive `ILIKE` match.

### `get_echomail_thread`

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | integer | ID of any message in the thread (root or reply) |

Walks up to the thread root, then returns all replies in order with depth level. Full message text is included for each message.

---

## Access Control

All queries enforce the following rules regardless of the tool used:

- **Inactive areas** (`is_active = FALSE`) are never returned.
- **Sysop-only areas** (`is_sysop_only = TRUE`) are hidden for non-admin users. Admin users (BBS `is_admin = TRUE`) can see them.
- Users can only access data via their own key — there is no mechanism to query as another user.

---

## Configuration Reference

All configuration is read from the main BinktermPHP `.env` file. No separate config file is needed.

| Variable | Default | Description |
|----------|---------|-------------|
| `MCP_SERVER_URL` | (unset) | **Required to enable user key management.** The public-facing base URL of the MCP server, e.g. `https://mcp.yourbbs.example` or `http://yourbbs.example:3740`. Shown to users in **Settings → AI** so they can configure their MCP client. When unset, the AI settings tab shows a "not enabled" message and key generation is disabled. |
| `MCP_SERVER_PORT` | `3740` | Port the MCP server listens on (`MCP_PORT` also accepted) |
| `MCP_BIND_HOST` | (all interfaces) | IP address to bind to. Set to `127.0.0.1` when using a reverse proxy. Overridden by `--bind` CLI flag. |
| `MCP_TRUSTED_PROXIES` | `127.0.0.1,::1,::ffff:127.0.0.1` | Comma-separated list of proxy IP addresses whose `X-Forwarded-For` header is trusted for real-IP logging. |
| `DB_HOST` | `localhost` | PostgreSQL host |
| `DB_PORT` | `5432` | PostgreSQL port |
| `DB_NAME` | | Database name |
| `DB_USER` | | Database user |
| `DB_PASS` | | Database password (note: `DB_PASS`, not `DB_PASSWORD`) |
| `DB_SSLMODE` | (unset) | Set to any value to enable SSL for the DB connection |
| `LICENSE_FILE` | `data/license.json` | Path to the license file |

---

## Reverse Proxy Setup

Running the MCP server behind a reverse proxy (nginx, Caddy, Apache) is the recommended way to expose it over HTTPS. The Node.js process binds to localhost only; the proxy handles TLS termination.

### Why use a proxy?

- Terminates TLS so clients see a valid (or self-signed) certificate.
- Keeps the Node process off a public port.
- Lets you share port 443 with your existing web server via path or subdomain routing.

### Binding to localhost

Start the server with `--bind=127.0.0.1`, or set `MCP_BIND_HOST=127.0.0.1` in `.env`:

```bash
node mcp-server/server.js --bind=127.0.0.1 --pid-file=data/run/mcp-server.pid
```

The daemon script respects `MCP_BIND_HOST` automatically when it is set in `.env`.

### nginx example (subdomain)

```nginx
server {
    listen 443 ssl;
    server_name mcp.yourbbs.example;

    ssl_certificate     /etc/letsencrypt/live/yourbbs.example/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourbbs.example/privkey.pem;

    location / {
        proxy_pass         http://127.0.0.1:3740;
        proxy_http_version 1.1;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;

        # SSE / streaming support
        proxy_buffering    off;
        proxy_read_timeout 300s;
    }
}
```

### nginx example (sub-path on existing server)

```nginx
location /mcp-server/ {
    proxy_pass         http://127.0.0.1:3740/;
    proxy_http_version 1.1;
    proxy_set_header   Host              $host;
    proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header   X-Forwarded-Proto $scheme;
    proxy_buffering    off;
    proxy_read_timeout 300s;
}
```

When using a sub-path, clients must point their MCP URL at the sub-path, e.g. `https://yourbbs.example/mcp-server/mcp`.

### Caddy example

```caddyfile
mcp.yourbbs.example {
    reverse_proxy 127.0.0.1:3740 {
        flush_interval -1
    }
}
```

Caddy obtains and renews TLS certificates automatically via ACME/Let's Encrypt.

### Connecting the AI client over HTTPS

Update your `.mcp.json` to use the HTTPS URL provided by the proxy:

```json
{
  "mcpServers": {
    "binkterm": {
      "type": "http",
      "url": "https://mcp.yourbbs.example/mcp",
      "headers": {
        "Authorization": "Bearer <your-key>"
      }
    }
  }
}
```

### Self-signed certificates

If your proxy uses a self-signed certificate, Claude Code (which is Node.js-based) will reject it by default. Add the certificate to Claude Code's trust chain via `NODE_EXTRA_CA_CERTS`:

```json
{
  "env": {
    "NODE_EXTRA_CA_CERTS": "/path/to/your-cert.pem"
  }
}
```

Place this in `.claude/settings.json` (project-scoped) or `~/.claude/settings.json` (global). To extract the certificate from a running server:

```bash
openssl s_client -connect mcp.yourbbs.example:443 -showcerts </dev/null 2>/dev/null \
  | openssl x509 -outform PEM > mcp-cert.pem
```

---

## Boot Startup

### systemd (recommended)

Create `/etc/systemd/system/binkterm-mcp.service`:

```ini
[Unit]
Description=BinktermPHP MCP Server
After=network.target postgresql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/binkterm
ExecStart=/usr/bin/node mcp-server/server.js --bind=127.0.0.1 --pid-file=data/run/mcp-server.pid
Restart=on-failure
RestartSec=5s
StandardOutput=append:/path/to/binkterm/data/logs/mcp-server.log
StandardError=append:/path/to/binkterm/data/logs/mcp-server.log

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
systemctl daemon-reload
systemctl enable binkterm-mcp
systemctl start binkterm-mcp
```

Use `Type=simple` (not `forking`) — do **not** pass `--daemon` when running under systemd, as systemd manages the process lifecycle itself.

### cron @reboot

```cron
@reboot /usr/bin/node /path/to/binkterm/mcp-server/server.js --daemon --bind=127.0.0.1 --pid-file=/path/to/binkterm/data/run/mcp-server.pid
```

Use `--daemon` here so the process detaches from the cron environment and survives the cron session ending.

---

## Logging

The server logs to `data/logs/mcp-server.log`. Each line is timestamped and tagged with a level (`INFO`, `WARN`, `ERROR`):

```
[2026-03-24T03:00:00.000Z] [INFO]  License OK — licensee: Your Name, tier: registered
[2026-03-24T03:00:00.012Z] [INFO]  Listening on 127.0.0.1:3740
[2026-03-24T03:00:00.013Z] [INFO]  PID 12345 written to data/run/mcp-server.pid
[2026-03-24T03:00:01.500Z] [INFO]  POST /mcp 200 (42ms) [127.0.0.1]
[2026-03-24T03:00:01.501Z] [WARN]  Encoding error — retrying query with SQL_ASCII client encoding
[2026-03-24T03:00:02.000Z] [ERROR] DB query error: ...
```

Every HTTP request is logged with method, path, status code, response time, and client IP. Database errors are logged at `ERROR` level with the PostgreSQL error message. Log output is also written to stdout when running in the foreground.
