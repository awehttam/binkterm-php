# Architecture

This document describes BinktermPHP's system architecture: how components are organized, how they communicate, and how data flows through the system.

For the real-time event subsystem specifically, see [BinkStreamChannel.md](BinkStreamChannel.md).

---

## Table of Contents

1. [System Overview](#system-overview)
2. [Component Descriptions](#component-descriptions)
3. [FTN Packet Lifecycle](#ftn-packet-lifecycle)
4. [Daemon IPC Model](#daemon-ipc-model)
5. [Real-Time Event System](#real-time-event-system)
6. [Door Game Subsystem](#door-game-subsystem)
7. [AI Pipeline](#ai-pipeline)

---

## System Overview

BinktermPHP is a multi-layered platform. A PHP web application sits at the center, surrounded by cooperating daemons that handle FTN networking, real-time event delivery, terminal access, AI integration, and door games. All daemons share a single PostgreSQL database; inter-process coordination happens through the database and the admin daemon rather than direct connections.

```
┌─────────────────────────────────────────────────────────────────┐
│                          CLIENT LAYER                            │
│  Browser (WebSocket / SSE)                                       │
│  Telnet / SSH terminals                                          │
│  PacketBBS / Mesh Radio nodes                                    │
│  AI clients via MCP                                              │
│  QWK offline readers                                             │
└──────────────────────────────┬──────────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────────┐
│                         ACCESS LAYER                             │
│  PHP web application  (public_html/index.php)                    │
│  realtime_server      BinkStream WebSocket daemon                │
│  telnet_daemon / ssh_daemon                                      │
│  mcp-server           (Node.js)                                  │
└──────────────────────────────┬──────────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────────┐
│                         SERVICE LAYER                            │
│  admin_daemon         logging relay, config writes, triggers     │
│  mrc_daemon           MRC chat relay                             │
│  gemini_daemon        Gemini protocol capsule                    │
│  multiplexing-server  door PTY / DOSBox-X bridge  (Node.js)     │
└──────────────────────────────┬──────────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────────┐
│                      FTN NETWORKING LAYER                        │
│  binkp_server         accepts incoming binkp connections         │
│  binkp_scheduler      manages polling schedule                   │
│  binkp_poll           polls a single uplink on demand            │
└──────────────────────────────┬──────────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────────┐
│                          DATA LAYER                              │
│  PostgreSQL           all persistent data                        │
│  data/inbound/        received FTN packets (pre-processing)      │
│  data/outbound/       queued FTN packets (post-processing)       │
│  data/logs/           structured log files per subsystem         │
└─────────────────────────────────────────────────────────────────┘
```

---

## Component Descriptions

### Web Application

The PHP web application (`public_html/index.php`) handles all browser requests. Routes are defined in `routes/` and dispatched via SimpleRouter. Twig templates in `templates/` render HTML. Business logic lives in `src/`.

The web process is stateless between requests. Session state is stored in the database and looked up via the `binktermphp_session` cookie.

Template resolution follows a three-layer override order: `templates/custom/` → `templates/shells/<activeShell>/` → `templates/`. When modifying shared layout, both `templates/base.twig` and `templates/shells/web/base.twig` must be updated.

### Admin Daemon

`scripts/admin_daemon.php` is the system coordination hub. It is the only process that can safely write config files such as `config/binkp.json` and `config/lovlynet.json` — the web server runs as a different user and cannot write these files directly. Web-context code sends commands to the admin daemon through `AdminDaemonClient` rather than calling `file_put_contents()` directly.

The admin daemon also acts as a logging relay. Web processes that cannot write log files directly (due to file ownership) send log messages via UDP; the daemon writes them to the appropriate file under `data/logs/`.

### BinkP Mailer

Three processes handle FTN packet exchange:

- `scripts/binkp_server.php` — listens on the binkp port (default 24554) and accepts inbound connections from uplinks and other nodes
- `scripts/binkp_poll.php` — connects outbound to a single uplink to exchange queued mail
- `scripts/binkp_scheduler.php` — manages the polling schedule and triggers `binkp_poll.php` at configured intervals

### Terminal Access

`scripts/telnet_daemon.php` and `ssh/ssh_daemon.php` share the same session logic (`BbsSession`) and deliver identical BBS features. Both use the internal REST APIs for login, message retrieval, and other operations — they are the terminal UI layer and do not duplicate business logic. The web interface is considered first-class; terminal feature parity is desirable but not always required.

### Real-Time Server

`scripts/realtime_server.php` is the BinkStream WebSocket daemon. It shares the same event core (`src/Realtime/`) with the SSE endpoint at `GET /api/stream`. Browsers connect via a SharedWorker (`public_html/js/binkstream-worker-v2.js`) and receive events over whichever transport is available — WebSocket when the daemon is running, SSE as a fallback. See [BinkStreamChannel.md](BinkStreamChannel.md) for the full architecture.

### MCP Server

`mcp-server/server.js` is a Node.js process that implements the Model Context Protocol, giving AI assistants read-only access to echo areas and echomail. Authentication is per-user via bearer keys stored in `users_meta`. Access control mirrors the web interface: inactive areas and sysop-only areas are hidden for non-admin users. See [MCPServer.md](MCPServer.md).

### Door Subsystem

The multiplexing bridge (`scripts/dosbox-bridge/multiplexing-server.js`) is a Node.js process that manages PTY sessions for native doors and DOSBox-X processes for DOS doors. Browser clients connect via WebSocket; the bridge multiplexes terminal I/O. WebDoors, JS-DOS Doors, and C64 Doors run entirely in the browser and require no bridge process. See [Doors.md](Doors.md).

---

## FTN Packet Lifecycle

### Inbound (receiving mail)

```
Remote node
    │
    │  binkp TCP connection (port 24554)
    ▼
scripts/binkp_server.php
    │
    │  authenticates session
    │  exchanges packet files
    │  writes received files to data/inbound/
    │
    ▼
Packet processor
    │
    │  unpacks .pkt files
    │  extracts echomail and netmail messages
    │  validates MSGID, deduplicates
    │  resolves echo area subscriptions
    │  stores messages in echomail / netmail tables
    │
    ▼
PostgreSQL
    │
    │  INSERT INTO sse_events (new_message / new_netmail)
    │
    ▼
BinkStream → browser notification
```

Packets that reference an echo area that does not yet exist are handled by auto-creating the area. TIC files (for file areas) are processed separately, validated, and stored under the appropriate file area directory.

### Outbound (sending mail)

```
User action (web UI or terminal)
    │
    │  POST /api/messages/echomail  (or netmail)
    ▼
src/MessageHandler.php
    │
    │  validates and stores message in echomail / netmail table
    │  marks message for outbound delivery to uplink(s)
    │
    ▼
Packet bundler
    │
    │  bundles pending messages into .pkt files
    │  compresses into FTN bundles
    │  writes to data/outbound/<uplink>/
    │
    ▼
scripts/binkp_poll.php  (scheduled by binkp_scheduler or triggered post-session)
    │
    │  connects outbound to configured uplink(s)
    │  transmits outbound packets via binkp protocol
    │
    ▼
Uplink node → FTN network
```

---

## Daemon IPC Model

Daemons are independent processes that communicate through shared infrastructure rather than direct peer connections.

**Shared database** — All daemons read and write PostgreSQL. The `sse_events` table is the primary inter-process event bus: any daemon or web request can insert a row, and BinkStream delivers it to connected browsers without any additional coordination.

**Admin daemon as coordinator** — The web process cannot write config files directly (file ownership). Config writes go through `AdminDaemonClient`, which sends commands to the admin daemon. The admin daemon is also the logging relay: web-context log messages that cannot be written directly are forwarded via UDP.

**PID files** — Each daemon writes a PID file to `data/run/`. These are used by `restart_daemons.sh` (Linux) and `start_daemons_windows.ps1` (Windows) to detect running daemons and by the admin panel's service status display.

**No direct daemon-to-daemon connections** — Daemons do not call each other's APIs. Coordination happens through the database and the admin daemon. This means any daemon can be restarted independently without affecting the others.

---

## Real-Time Event System

BinkStream is the browser-facing real-time channel. The full architecture is documented in [BinkStreamChannel.md](BinkStreamChannel.md). The short version:

1. Any code (PHP route, database trigger, or daemon) inserts a row into `sse_events`.
2. `src/Realtime/StreamService.php` polls `sse_events` and delivers events to open connections.
3. The browser's SharedWorker (`public_html/js/binkstream-worker-v2.js`) maintains one active transport for the whole origin and fans events out to all open tabs via `MessagePort`.
4. Page code subscribes with `window.BinkStream.on('event_type', handler)`.

No changes to transport code are needed when adding a new event type — only an insert into `sse_events` and a client-side subscription.

---

## Door Game Subsystem

```
Browser
    │
    │  WebSocket to /ws-doors (via reverse proxy)
    ▼
multiplexing-server.js (Node.js)
    │
    ├── Native Door ──► PTY ──► Linux / Windows binary
    │                               │
    │                               └── ANSI / CP437 output → browser
    │
    └── DOS Door ─────► DOSBox-X process
                            │
                            ├── shared real filesystem
                            │   (game data, inter-node files)
                            │
                            └── ANSI / CP437 output → browser
```

The bridge allocates node numbers, generates drop files (`DOOR.SYS`), and manages the process pool. Each concurrent door session is one entry in the bridge's active session table. Because all DOSBox-X instances mount the same real host directories, multi-player door games (LORD, TradeWars 2002) work exactly as on a physical BBS — no special synchronization layer needed.

**Browser-side doors** (WebDoors, JS-DOS Doors, C64 Doors) run entirely in the browser via `<iframe>` or canvas emulation. No server process is spawned. The server handles only session tracking, credit accounting, and (for JS-DOS) save-file sync.

---

## AI Pipeline

```
User (echomail reader)
    │
    │  POST /api/messages/ai-assist
    │  { prompt, message_id, message_type }
    ▼
src/AI/MessageAiAssistant.php
    │
    │  ensures user has MCP bearer key
    │  (auto-generates one if missing)
    │
    ▼
mcp-server/server.js
    │
    │  agentic tool-use loop
    │  ├── list_echoareas
    │  ├── get_echomail_message
    │  ├── get_echomail_thread
    │  └── search_echomail
    │
    ▼
AI provider (Anthropic / OpenAI)
    │
    │  final answer streamed back
    ▼
Browser (rendered in assistant modal)
    │
    └── credits debited if AI credit charging is configured
```

The system prompt instructs the model to fetch actual message content via MCP tools before answering, preventing hallucinated message content. The same MCP server used by the in-reader assistant is the one users can connect external AI clients to via [MCPServer.md](MCPServer.md).
