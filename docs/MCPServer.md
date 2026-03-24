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

Add the port to your main `.env` if you want a non-default port:

```
MCP_SERVER_PORT=3740
```

---

## Starting and Stopping

**Manually:**

```bash
node mcp-server/server.js
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

Each user generates their own bearer key from **Settings → AI → MCP Server Bearer Key**. Keys are stored in the `users_meta` table under keyname `mcp_serverkey`.

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

The MCP endpoint is `POST /mcp` (and `GET /mcp` for SSE streaming). A health check with no authentication is available at `GET /health`.

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
| `MCP_SERVER_PORT` | `3740` | Port the MCP server listens on (`MCP_PORT` also accepted) |
| `DB_HOST` | `localhost` | PostgreSQL host |
| `DB_PORT` | `5432` | PostgreSQL port |
| `DB_NAME` | | Database name |
| `DB_USER` | | Database user |
| `DB_PASS` | | Database password (note: `DB_PASS`, not `DB_PASSWORD`) |
| `DB_SSLMODE` | (unset) | Set to any value to enable SSL for the DB connection |
| `LICENSE_FILE` | `data/license.json` | Path to the license file |

---

## Logging

The server logs to `data/logs/mcp-server.log`. Each line is timestamped:

```
[2026-03-24T03:00:00.000Z] [INFO] License OK — licensee: Your Name, tier: registered
[2026-03-24T03:00:00.012Z] [INFO] Listening on port 3740
[2026-03-24T03:00:00.013Z] [INFO] PID 12345 written to data/run/mcp-server.pid
```

Log output is also written to stdout, so it appears in the terminal when running manually.
