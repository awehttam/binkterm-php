# Architecture

This document describes BinktermPHP's system architecture: how components are organized, how they communicate, and how data flows through the system.

For the real-time event subsystem specifically, see [BinkStreamChannel.md](BinkStreamChannel.md).

---

## Table of Contents

1. [System Overview](#system-overview)
2. [Ecosystem Map](#ecosystem-map)
3. [Platform Relationships](#platform-relationships)
4. [Component Descriptions](#component-descriptions)
5. [FTN Packet Lifecycle](#ftn-packet-lifecycle)
6. [Daemon IPC Model](#daemon-ipc-model)
7. [Real-Time Event System](#real-time-event-system)
8. [Door Game Subsystem](#door-game-subsystem)
9. [AI Pipeline](#ai-pipeline)
   - [Provider Abstraction Layer](#provider-abstraction-layer)
   - [Message AI Assistant](#message-ai-assistant)
   - [AI Bot System](#ai-bot-system)
   - [Usage Tracking & Analytics](#usage-tracking--analytics)
   - [Cost Estimation & Budget Management](#cost-estimation--budget-management)

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

## Ecosystem Map

```text
Clients
├── Browser UI
├── Telnet / SSH
├── Gemini
├── QWK
├── AI / MCP Clients
└── Packet / Mesh Nodes

        │

Access & Realtime Layer
├── Web UI
├── Terminal Services
├── BinkStream
└── API / MCP

        │

Core Platform
├── FTN Networking
├── Message Areas
├── Netmail / Echomail
├── Doors Runtime
├── Chat
├── File Areas
├── User / Session System
├── Automation
└── AI Assistant

        │

External Networks
├── FidoNet / FTN Networks
├── Packet / Mesh Networks
├── Door Processes
└── External APIs
```

This map is intentionally simple: access methods vary, but they all feed the same platform. Browser UI, terminal sessions, PacketBBS bridges, and MCP clients are different ways into the same node rather than separate products bolted together later.

## Platform Relationships

- FTN networking feeds message areas, netmail, and file areas that are then surfaced through browser and terminal access methods.
- BinkStream is the shared realtime event infrastructure used by chat, notifications, admin widgets, and other live UI updates.
- WebDoors use platform identity, session, credits, and API services instead of acting like isolated browser games.
- MCP exposes selected platform capabilities to AI and automation clients without handing them unrestricted database access.
- PacketBBS extends the platform into low-bandwidth or mesh-style environments through a compact gateway instead of the full-screen UI.

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

These services make the node an FTN participant. Once packets are processed, the resulting messages land in the same message tables used by the browser UI, Telnet/SSH sessions, QWK exports, and the AI assistant.

### Terminal Access

`scripts/telnet_daemon.php` and `ssh/ssh_daemon.php` share the same session logic (`BbsSession`) and deliver identical BBS features. Both use the internal REST APIs for login, message retrieval, and other operations — they are the terminal UI layer and do not duplicate business logic. The web interface is considered first-class; terminal feature parity is desirable but not always required.

### Real-Time Server

`scripts/realtime_server.php` is the BinkStream WebSocket daemon. It shares the same event core (`src/Realtime/`) with the SSE endpoint at `GET /api/stream`. Browsers connect via a SharedWorker (`public_html/js/binkstream-worker-v2.js`) and receive events over whichever transport is available — WebSocket when the daemon is running, SSE as a fallback. See [BinkStreamChannel.md](BinkStreamChannel.md) for the full architecture.

BinkStream is not just for chat. It is the browser-side live-update layer for notifications, dashboard state, admin tools, and any other feature that needs the UI to react to platform activity in near real time.

### MCP Server

`mcp-server/server.js` is a Node.js process that implements the Model Context Protocol, giving AI assistants read-only access to echo areas and echomail. Authentication is per-user via bearer keys stored in `users_meta`. Access control mirrors the web interface: inactive areas and sysop-only areas are hidden for non-admin users. See [MCPServer.md](MCPServer.md).

The MCP server sits beside the web app, not above it. It is an access layer for selected capabilities, used by the built-in AI assistant as well as external MCP-compatible clients.

### Door Subsystem

The multiplexing bridge (`scripts/dosbox-bridge/multiplexing-server.js`) is a Node.js process that manages PTY sessions for native doors and DOSBox-X processes for DOS doors. Browser clients connect via WebSocket; the bridge multiplexes terminal I/O. WebDoors, JS-DOS Doors, and C64 Doors run entirely in the browser and require no bridge process. See [Doors.md](Doors.md).

WebDoors are the browser-native side of the door runtime. They reuse platform sessions and APIs directly, while classic DOS and native doors rely on the bridge for process execution and terminal transport.

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
    │  maps AREA: tag to echoareas row (auto-creates if unknown)
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

Once a message is stored, it becomes ordinary platform content. The browser UI, terminal services, digests, search, exports, and MCP tools all read from the same underlying message data.

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
    │  writes outbound .pkt file to data/outbound/ via BinkdProcessor::createOutboundPacket()
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

Daemons are independent processes. Currently they coordinate through shared infrastructure (the database and admin daemon) rather than direct peer connections; future daemons may add direct IPC channels.

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

### Workflow: how realtime updates move through BinkStream

1. A platform event happens, such as an inbound FTN message, a chat post, or an admin action.
2. PHP code, a trigger, or a daemon inserts a fat payload into `sse_events`.
3. `StreamService` fetches the new row and hands it to SSE or WebSocket delivery.
4. The SharedWorker receives the event once for the whole origin and fans it out to open tabs.
5. Page code updates the visible UI, notification state, or live widget.

---

## Door Game Subsystem

```
Browser
    │
    │  WebSocket to /dosdoor (via reverse proxy)
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

### Workflow: how a WebDoor fits into the platform

1. A user opens `/games` and launches a WebDoor inside an authenticated browser session.
2. The platform resolves the WebDoor manifest, applies sysop configuration, and serves the entry point.
3. The WebDoor uses platform-provided user, session, storage, credits, or leaderboard services as needed.
4. Any state it writes becomes part of the same node users and admins already manage through the rest of the platform.

---

## AI Pipeline

All AI features share a common provider abstraction and usage-tracking layer. The sections below describe the abstraction, the individual feature pipelines, the bot system, and the analytics infrastructure.

### Provider Abstraction Layer

`src/AI/AiProviderInterface.php` defines the contract every provider adapter must implement. `src/AI/AiService.php` is the entry point for all AI calls; it resolves the provider and model for a given request and delegates to the appropriate adapter.

**Supported providers**

| Provider | Adapter | Default model |
|---|---|---|
| OpenAI | `src/AI/Providers/OpenAIProvider.php` | `gpt-4o-mini` |
| Anthropic | `src/AI/Providers/AnthropicProvider.php` | `claude-sonnet-4-6` |

**Provider and model resolution** (`AiService::resolveProviderAndModel`)

Resolution walks this hierarchy, stopping at the first match:

1. Explicit provider/model on the `AiRequest` object
2. Feature-specific env var — `AI_<FEATURE>_PROVIDER` / `AI_<FEATURE>_MODEL`
3. Global default — `AI_DEFAULT_PROVIDER` / `AI_DEFAULT_MODEL`
4. First configured provider (OpenAI checked before Anthropic)

This means per-feature overrides are possible without touching defaults, and adding a second provider does not require code changes.

**Request / Response objects**

- `AiRequest` (`src/AI/AiRequest.php`) — carries provider, model, feature key, prompts, temperature, token limit, user ID, bot ID, and optional conversation history.
- `AiResponse` (`src/AI/AiResponse.php`) — carries the final text content, parsed JSON (for JSON-mode calls), provider request ID, finish reason, and an `AiUsage` object.
- `AiUsage` (`src/AI/AiUsage.php`) — input tokens, output tokens, cached input tokens, cache-write tokens, total tokens, and estimated cost in USD.

Both provider adapters normalize their wire responses to the same internal structure before returning an `AiResponse`, so callers never inspect provider-specific fields.

**Tool-use loop** (`src/AI/AgentService.php`)

For agentic features the loop runs up to five rounds:

```
AgentService::run()
    │
    ├─ provider->generateWithTools(messages, tools, systemPrompt, model)
    │       │
    │       └── returns {content, stop_reason, usage}
    │
    ├─ UsageRecorder::recordAgentRound()   ← metrics per round
    │
    ├─ extract tool_use blocks
    │       │
    │       └── McpClient::callTool() for each
    │
    ├─ append tool results to message history
    │
    └─ repeat until stop_reason == 'end_turn' or max rounds reached
```

`AgentResult` accumulates token counts and cost across all rounds and returns the final text, total usage, round count, and tool-call count.

---

### Message AI Assistant

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
    │  agentic tool-use loop (AgentService)
    │  ├── list_echoareas
    │  ├── get_echomail_message
    │  ├── get_echomail_thread
    │  └── search_echomail
    │
    ▼
AI provider (Anthropic / OpenAI)
    │
    │  final answer returned
    ▼
Browser (rendered in assistant modal)
    │
    └── BBS credits debited if ai_credits_per_milli_usd is configured
```

The system prompt instructs the model to fetch actual message content via MCP tools before answering, preventing hallucinated message content. The same MCP server used by the in-reader assistant is the one users can connect external AI clients to — see [MCPServer.md](MCPServer.md).

### Workflow: how MCP and the AI assistant interact with the platform

1. A user opens the reader assistant or an external client connects through MCP.
2. The request is authenticated with that user's MCP bearer key.
3. MCP tools read echo areas, messages, and threads using the same access rules as the web UI.
4. The model answers from fetched platform data instead of raw guesswork.
5. The final result returns to the browser assistant or external MCP client.

## Related Systems

- [BinkStream Back-Channel](BinkStreamChannel.md) — browser-facing realtime delivery
- [Joining and Configuring an FTN](FTNGuide.md) — node and uplink setup
- [WebDoors](WebDoors.md) — browser-native door runtime
- [PacketBBS Gateway](PacketBBS.md) — packet/mesh access method
- [MCP Server](MCPServer.md) — AI and automation integration layer
- [AI Assistant](AIAssistant.md) — reader-side AI workflow

Other features that call AiService directly (without the MCP tool-use loop):

| Feature key | Class | Purpose |
|---|---|---|
| `interest_generation` | `src/InterestGenerator.php` | Classifies messages into interest topics |
| `share_summary` | `src/AI/ShareSummaryGenerator.php` | Generates Open Graph descriptions for shared messages |
| `translation_catalog` | `scripts/create_translation_catalog.php` | Batch JSON translation of i18n catalogs |

---

### AI Bot System

AI bots are persistent, database-configured agents that respond to BBS events (currently local chat messages). Each bot runs as a separate system user and has its own provider, model, system prompt, context window size, and weekly spend budget.

```
ai_bot_daemon.php  (scripts/ai_bot_daemon.php)
    │
    │  listens on PostgreSQL NOTIFY channel
    │  refreshes bot list from ai_bots table every 60 s
    │
    ▼
LocalChatActivityHandler
    │
    │  BotMiddlewarePipeline::run()
    │  ├── SlashCommandMiddleware      (static /command replies)
    │  ├── FilePromptInjectorMiddleware (inject file content)
    │  ├── UrlPromptInjectorMiddleware  (fetch + inject URL, cached)
    │  ├── RegexFilterMiddleware        (pattern-based rewriting)
    │  └── RagPromptInjectorMiddleware  (sqlite-vec knowledge base)
    │
    ▼
AiBot::isUnderBudget()  →  AiService::generateText()
    │
    └── response posted as bot user's chat message
```

**Key tables**

- `ai_bots` — bot identity, system prompt, provider/model override, weekly budget, active flag
- `ai_bot_activities` — per-bot activity types (`local_chat`) with JSONB config
- `ai_requests.bot_id` — links every AI call back to the bot that made it

Weekly budget (`ai_bots.weekly_budget_usd`) resets Sunday 00:00 UTC. When the budget is exhausted the bot stops responding until the next reset; no AI call is made.

See [AIBots.md](AIBots.md) for configuration and middleware authoring.

---

### Usage Tracking & Analytics

Every AI call — whether from a feature or a bot — is recorded in `ai_requests` before the result is returned to the caller.

**`ai_requests` columns**

| Column | Purpose |
|---|---|
| `provider`, `model` | Which provider and model handled the request |
| `feature` | Feature key (e.g. `message_ai_assistant`, `interest_generation`) |
| `operation` | `generate_text`, `generate_json`, or `generate_with_tools` |
| `status` | `success` or `error` |
| `user_id`, `bot_id` | Who initiated the request (nullable) |
| `input_tokens`, `output_tokens`, `cached_input_tokens`, `cache_write_tokens` | Token accounting per request |
| `estimated_cost_usd` | Computed from `AiPricing` at call time |
| `duration_ms` | Wall-clock time for the provider call |
| `http_status`, `error_code`, `error_message` | Populated on error |
| `metadata_json` | JSONB extras (agent round, tool-call count, stop reason) |

**Recording** (`src/AI/UsageRecorder.php`)

- `recordSuccess()` — called after every successful provider response
- `recordFailure()` — called on any `AiException`; captures HTTP status and provider error code
- `recordAgentRound()` — called once per agentic loop round, storing per-round metadata in `metadata_json`

**Admin dashboard** (`GET /admin/ai-usage`)

`src/AI/AiUsageReport.php` aggregates `ai_requests` for periods of 1 day, 7 days, 30 days, or all time:

- Summary — total requests, total cost, failure count, aggregate token counts
- By feature — cost and request count ordered by spend
- By provider/model — cost and request count per provider+model combination
- Recent failures — last 20 errors with provider, model, error code, and message
- Recent requests — last 20 requests with status, tokens, and cost

See [AIProviders.md](AIProviders.md) for provider setup and dashboard usage.

---

### Cost Estimation & Budget Management

**Pricing** (`src/AI/AiPricing.php`) reads token rates from environment variables (USD per 1,000,000 tokens). Model-specific rates take priority over provider-wide fallbacks. Four rate buckets are supported: `INPUT`, `OUTPUT`, `CACHED_INPUT`, `CACHE_WRITE`. Models with no configured rate default to zero cost until rates are added.

**BBS credit integration** — The message AI assistant converts estimated cost to BBS credits using `credits.ai_credits_per_milli_usd` (credits per $0.001 USD). Credits are debited server-side via `UserCredit::debit()` after the AI call succeeds. If the user has insufficient credits the request returns 402 before the AI call is made.

**Bot weekly budgets** — Each bot has a `weekly_budget_usd` cap. `AiBot::isUnderBudget()` sums `estimated_cost_usd` from `ai_requests` for the current Sunday–Saturday UTC week and aborts the AI call if the cap is reached.
