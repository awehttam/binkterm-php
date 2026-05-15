# Developer Guide

## Table of Contents

- [Welcome to BinktermPHP](#welcome-to-binkterm-php)
- [Project Architecture](#project-architecture)
- [Directory Structure](#directory-structure)
- [Development Workflow](#development-workflow)
- [API Documentation Generator](#api-documentation-generator)
- [Localization (i18n)](#localization-i18n)
- [Credits System](#credits-system-overview)
- [Optional Components & Daemons](#optional-components--daemons)
- [Getting Help](#getting-help)

---

## Welcome to BinktermPHP

BinktermPHP is a modern web-based interface for FidoNet messaging networks. It combines a traditional bulletin board system (BBS) experience with contemporary web technologies, allowing users to send and receive netmail (private messages) and echomail (forums) through the FidoNet Technology Network (FTN).

### What is FidoNet?

FidoNet is a worldwide network of BBSs that exchange mail and files using store-and-forward technology. Messages are packaged into "packets" and transmitted between systems using the binkp protocol. Unlike modern internet messaging, FidoNet operates asynchronously - messages are bundled, transmitted to upstream nodes (hubs), and then distributed across the network.

---

## Project Architecture

BinktermPHP is a multi-layered platform. A PHP web application sits at the center, surrounded by a constellation of cooperating daemons that handle FTN networking, real-time event delivery, terminal access, AI integration, and door games.

### System Overview

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

### Daemon Reference

All daemons write PID files to `data/run/` and logs to `data/logs/`. Use `restart_daemons.sh` (Linux) or `start_daemons_windows.ps1` (Windows) to start them all at once.

| Daemon | Script | Purpose | Required |
|--------|--------|---------|----------|
| Admin Daemon | `scripts/admin_daemon.php` | Logging relay, config writes, post-session triggers. Other daemons depend on it for structured logging. | Yes |
| Binkp Server | `scripts/binkp_server.php` | Accepts incoming binkp connections from uplinks | If receiving mail |
| Binkp Scheduler | `scripts/binkp_scheduler.php` | Schedules automatic uplink polling | If polling uplinks |
| Realtime Server | `scripts/realtime_server.php` | WebSocket server for BinkStream; browsers fall back to SSE if not running | Optional |
| Telnet Daemon | `scripts/telnet_daemon.php` | Telnet BBS terminal interface | Optional |
| SSH Daemon | `ssh/ssh_daemon.php` | SSH-2 BBS terminal interface | Optional |
| MRC Daemon | `scripts/mrc_daemon.php` | MRC multi-relay chat protocol relay | Optional |
| Gemini Daemon | `scripts/gemini_daemon.php` | Gemini protocol capsule server | Optional |
| Multiplexing Bridge | `scripts/dosbox-bridge/multiplexing-server.js` | PTY / DOSBox-X bridge for DOS and native door games (Node.js) | Optional |
| MCP Server | `mcp-server/server.js` | MCP protocol server for AI assistant access (Node.js) | Optional |

### Key Components

- **Frontend**: jQuery + Bootstrap 5 for responsive, modern UI
- **Backend**: PHP with SimpleRouter for routing, Twig for templating
- **Database**: PostgreSQL for persistent storage
- **Mailer**: Custom PHP binkp protocol implementation for FTN connectivity
- **Authentication**: Simple username/password with long-lived cookies
- **Real-time**: BinkStream — WebSocket with SSE fallback, SharedWorker fan-out across tabs. See [BinkStreamChannel.md](BinkStreamChannel.md) for the full architecture.

For full data-flow diagrams including the FTN packet lifecycle, daemon IPC model, door game subsystem, and AI pipeline, see [ARCHITECTURE.md](ARCHITECTURE.md).

### Core Concepts

#### Users and Identity

- **Usernames**: System login identifiers (must be unique, case-insensitive)
- **Real Names**: Display names used in FidoNet messages (must be unique, case-insensitive)
- Both usernames and real names are unique across the system to prevent impersonation

#### Message Types

- **Netmail**: Private point-to-point messages between FidoNet users
- **Echomail**: Public messages posted to echo areas (forums/conferences)
- Messages use FTN-standard formats with kludge lines (@msgid, @reply, etc.)

#### Network Routing

- **Uplinks**: Remote FidoNet nodes that relay messages to/from the wider network
- **Domains**: FTN addressing uses zone:net/node.point format (e.g., 21:1/999)
- **Local Echoareas**: Areas marked `is_local=true` are not transmitted to uplinks
- **Multi-Network Support**: System can connect to multiple independent FTN networks

#### Packet Processing

- **Inbound**: FTN packets arrive via binkp, are unpacked, and stored in database
- **Outbound**: Messages are bundled into packets and queued for transmission
- **Polling**: Background process (`scripts/binkp_poll.php`) connects to uplinks on schedule
- **Server**: Daemon (`scripts/binkp_server.php`) accepts incoming connections from other nodes

#### Terminal Daemons

- **Telnet** (`scripts/telnet_daemon.php`) and **SSH** (`ssh/ssh_daemon.php`) share the same session logic (`BbsSession`) and deliver identical BBS features.
- Both use the REST APIs for login, message retrieval, etc. — they focus on the terminal UI rather than duplicating business logic.
- The web interface is considered "first class"; terminal feature parity is desirable but not always required.
- When testing, use SyncTerm alongside PuTTY and other clients to catch client-specific behaviour.

#### Admin Daemon

The admin daemon (`scripts/admin_daemon.php`) is a long-running process that handles:
- Server-side logging from web-context code (use `AdminDaemonClient::log($level, $message)`)
- Configuration reads/writes for settings that require daemon coordination
- Post-session packet processing triggers

Prefer `AdminDaemonClient::log()` over `error_log()` for application-level log messages so they appear in the structured daemon log rather than the PHP error log.

---

## Directory Structure

```
binkterm-php/
├── config/              Configuration files (bbs.json, binkp.json, lovlynet.json, etc.)
│   └── i18n/            Translation catalogs (en/, es/, fr/)
├── data/                Runtime data (written at run time, not in git)
│   ├── inbound/         Received FTN packets (pre-processing)
│   ├── outbound/        Packets queued for transmission
│   ├── logs/            Structured log files per subsystem
│   ├── nodelists/       Imported FTN nodelist files
│   ├── run/             PID files for all daemons
│   └── webdoors/        Per-door persistent data (save files, state)
├── database/
│   └── migrations/      Database migrations (vYYYYMMDDHHMMSS_description.sql or .php)
├── docker/              Docker entrypoint and supervisord config
├── docs/                Documentation
├── dosbox-bridge/       DOSBox-X production configs and maintenance scripts
├── mcp-server/          MCP protocol server (Node.js) for AI assistant access
├── native-doors/        Native door installations and drop files
├── public_html/         Web root
│   ├── css/             Stylesheets (style.css + theme files)
│   ├── js/              JavaScript (app.js, binkstream-client.js, etc.)
│   ├── webdoors/        WebDoor game installations
│   │   └── _doorsdk/    Shared WebDoor PHP/JS SDK
│   └── index.php        Front controller
├── routes/              HTTP route definitions (web, API, admin, door, webdoor)
├── scripts/             CLI tools (binkp server, poller, daemons, maintenance)
│   └── dosbox-bridge/   Multiplexing bridge server (Node.js) for DOS/native doors
├── src/                 Core PHP classes
│   ├── AI/              AI provider abstraction and assistant logic
│   ├── Admin/           Admin controllers and AdminDaemonClient
│   ├── Antivirus/       Pluggable antivirus scanner backends
│   ├── Binkp/           BinkP protocol implementation and logging
│   ├── Chat/            Shoutbox and Matterbridge integration
│   ├── FileArea/        File area rule processing and TIC handling
│   ├── I18n/            Internationalization / translation support
│   ├── Mrc/             MRC multi-relay chat protocol
│   ├── PacketBbs/       PacketBBS / mesh radio gateway
│   ├── Qwk/             QWK offline mail reader support
│   ├── Realtime/        BinkStream WebSocket / SSE event delivery
│   ├── Robots/          Echomail robot processors (AREAS.BBS, BBS directory)
│   ├── Database.php     PDO connection singleton
│   ├── MessageHandler.php  Message processing and storage
│   ├── Template.php     Twig template wrapper
│   ├── UserCredit.php   Credits/currency system
│   ├── Version.php      Application version constant
│   └── functions.php    Global helper functions (getServerLogger, etc.)
├── ssh/                 SSH-2 daemon (ssh_daemon.php + src/)
├── telnet/              Telnet BBS daemon (telnet_daemon.php + src/)
├── templates/           Twig templates
│   ├── custom/          Local overrides (not in git — highest priority)
│   └── shells/          Shell-specific base templates (web/, bbs-menu/)
├── tests/               PHPUnit unit tests and Playwright browser tests
├── tools/               Developer utilities (support-bot, etc.)
└── vendor/              Composer dependencies (DO NOT EDIT)
```

---

## Development Workflow

### Code Conventions

- **Variables/Functions**: camelCase (`$userName`, `sendMessage()`)
- **Classes**: PascalCase (`MessageHandler`, `UserCredit`)
- **Indentation**: 4 spaces (no tabs)
- **Database**: Use `Database::getInstance()->getPdo()` for connections
- **Environment variables**: Always use `Config::env('VAR_NAME', 'default')` — never `getenv()` or `$_ENV` directly

### Database Migrations

- **Migration files**: `database/migrations/vYYYYMMDDHHMMSS_description.sql` (or `.php`)
- **Apply migrations**: Run `php scripts/setup.php` — this runs both migrations and other upgrade tasks
- **DO NOT** edit `postgres_schema.sql` directly — use migrations
- **Migration IDs**: Use timestamp IDs in UTC to avoid collisions between parallel development branches. Prefer `php scripts/migration.php create "description"` so the filename is generated consistently. Legacy `vX.Y.Z_description` migrations are still supported for existing files, but new migrations should use timestamps.
- Migrations can be SQL files or PHP files. Two PHP patterns are supported:

**Pattern 1: Direct Execution** — for simple SQL operations. The file executes on include, uses `$db` from scope, and returns `true`.

```php
<?php
$db->exec("CREATE TABLE IF NOT EXISTS example (id SERIAL PRIMARY KEY, name VARCHAR(100) NOT NULL)");
return true;
```

**Pattern 2: Callable** — for migrations needing helper functions, loops, or complex logic. Return a closure that receives `$db`.

```php
<?php
function generateCode(): string { /* ... */ }

return function($db) {
    $stmt = $db->query("SELECT id FROM users WHERE referral_code IS NULL");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $user) {
        $db->prepare("UPDATE users SET referral_code = ? WHERE id = ?")->execute([generateCode(), $user['id']]);
    }
    return true;
};

### Making Changes

1. **Read before editing**: Always read the file before modifying it
2. **Avoid over-engineering**: Only implement what's requested
3. **DRY principle**: Centralize repeated logic into classes
4. **Security**: Watch for SQL injection, XSS, command injection
5. **Feature parity**: Netmail and echomail features should generally be consistent — clarify when unsure

### URL Construction

Always use the centralized `Config::getSiteUrl()` method when building full URLs:

```php
$url = \BinktermPHP\Config::getSiteUrl() . '/path';
```

This method:
- Checks `SITE_URL` environment variable first (handles reverse proxies correctly)
- Falls back to protocol detection using `$_SERVER` variables
- Returns base URL without trailing slash
- Prevents code duplication and ensures consistent behavior

### Version Management

- **Application version**: Edit `src/Version.php` only
- **Auto-updates**: Tearlines, footer, API responses update automatically
- **Format**: Semantic versioning (MAJOR.MINOR.PATCH)
- **Database versions** are independent of the application version and use the migration file naming scheme

### Styling Updates

When modifying `public_html/css/style.css`, also update all theme files:
- `public_html/css/amber.css`
- `public_html/css/dark.css`
- `public_html/css/greenterm.css`
- `public_html/css/cyberpunk.css`

### Service Worker Cache

When changing CSS, JavaScript files, or i18n catalog strings, increment the `CACHE_NAME` version in `public_html/sw.js` (e.g. `binkcache-v42` → `binkcache-v43`) to force clients to download fresh copies.

### Templates

Template resolution order is `templates/custom/` → `templates/shells/<activeShell>/` → `templates/`. When adding nav links or modifying shared layout, update **both** `templates/base.twig` **and** `templates/shells/web/base.twig` (and `bbs-menu` if applicable).

### In-App Documentation Browser

The admin panel includes a Markdown doc viewer served by `src/Web/DocsController.php`. It renders files from `docs/` and a hardcoded allowlist of root-level Markdown files (`FAQ.md`, `README.md`, `REGISTER.md`, `CONTRIBUTING.md`, `CREDITS.md`). Files outside `docs/` that are not in this allowlist cannot be served — this is intentional to prevent arbitrary file reads.

**Linking from `docs/` to a root-level file**

Use a `../` relative path, which renders correctly on GitHub and in the doc viewer:

```markdown
See [REGISTER.md](../REGISTER.md) for registration details.
```

For the doc viewer to follow such a link, the bare filename must appear in **two places** in `DocsController.php`:

1. **`$specialBases` in `resolveDocPath()`** — maps the name to the real filesystem path so the viewer can read the file.
2. **The `in_array` list in `rewriteLinks()`** — rewrites `../NAME.md` links to `/admin/docs/view/NAME` so they route through the viewer instead of producing a broken link.

Both lists must be updated together when adding a new root-level file to the allowlist. Only add files that are safe to expose to all authenticated users.

**Linking from root-level files into `docs/`**

Links in root-level files (e.g. `README.md`) that point into `docs/` use normal `docs/Foo.md`-style paths. `rewriteLinks()` strips the `docs/` prefix automatically and routes them through the viewer.

### Logging

Use `AdminDaemonClient::log($level, $message, $context)` for application-level log messages from web-context PHP code. This routes messages through the admin daemon's structured log rather than the PHP error log. The static method handles connection, logging, and cleanup in one call:

```php
\BinktermPHP\Admin\AdminDaemonClient::log('INFO', 'Something happened', ['key' => 'value']);
```

---

## API Documentation Generator

`scripts/generate_api_docs.php` parses the SimpleRouter route files and produces developer-facing API reference documentation. It uses PHP's built-in tokenizer (not regex) so it correctly handles nested group prefixes, string-interpolation braces, and PHPDoc comment extraction.

### Output formats

| Format | Flag | Use case |
|--------|------|----------|
| Markdown | `--format=markdown` (default) | GitHub wiki, `docs/API.md`, readable reference |
| OpenAPI 3.0 YAML | `--format=openapi` | Swagger UI, Postman, code-gen tooling |

### Route sets

| Set | File | Contents |
|-----|------|----------|
| `api` | `routes/api-routes.php` | Public API (default) |
| `admin` | `routes/admin-routes.php` | Admin-only endpoints |
| `door` | `routes/door-routes.php` | Door / terminal session endpoints |
| `webdoor` | `routes/webdoor-routes.php` | WebDoor game API |
| `all` | all of the above | Full surface area |

### Basic usage

Generate static Markdown for the public API (no AI, no cost):

```bash
php scripts/generate_api_docs.php --output=docs/API.md
```

Generate OpenAPI YAML for all routes:

```bash
php scripts/generate_api_docs.php --routes=all --format=openapi --output=docs/openapi.yaml
```

### AI-enriched documentation

Without `--ai`, the output contains only what the tokenizer can extract statically: HTTP method, path, auth requirement, and any PHPDoc or inline comment directly above the route definition. With `--ai`, the script sends batches of route code snippets to a configured AI provider and back-fills:

- One-sentence summary
- 2–4 sentence developer description
- Path, query, and request-body parameter tables
- Response field schema
- Notable error responses

Requirements: at least one of `ANTHROPIC_API_KEY` or `OPENAI_API_KEY` must be set in `.env`. The script defaults to `claude-haiku-4-5-20251001` (Anthropic) or `gpt-4o-mini` (OpenAI) to keep costs low; override with `--model`.

```bash
# AI-enriched public API docs using Anthropic
php scripts/generate_api_docs.php --ai --provider=anthropic --output=docs/API.md

# AI-enriched admin API as OpenAPI YAML
php scripts/generate_api_docs.php --routes=admin --ai --format=openapi --output=docs/openapi.yaml

# Larger batch size to reduce API calls (at the cost of longer prompts)
php scripts/generate_api_docs.php --routes=all --ai --ai-batch-size=15 --output=docs/API.md
```

### All options

```
--routes=SETS         Comma-separated sets to document (default: api)
--format=FORMAT       markdown or openapi (default: markdown)
--output=FILE         Write to FILE instead of stdout
--ai                  Enable AI enrichment
--provider=NAME       anthropic or openai (default: whichever is configured)
--model=MODEL         Override AI model
--ai-batch-size=N     Routes per AI request (default: 8)
--help                Show usage
```

---

## Localization (i18n)

All user-facing UI text must go through the translation system — do not hardcode strings in templates or JavaScript.

### Translation Catalogs

Catalogs live in `config/i18n/<locale>/common.php` and `config/i18n/<locale>/errors.php`. Current supported locales: `en`, `es`, `fr`.

### Twig

```twig
{{ t('ui.some.key', {}, 'common') }}
{{ t('ui.polls.cost', {'cost': poll_cost}, 'common') }}
```

### JavaScript

```js
window.t('ui.some.key', {}, 'Fallback text')
```

### API Errors

API responses must use structured errors:
```php
apiError('errors.feature.something_failed', 'Human fallback', 500);
```

Frontend resolves display text with `window.getApiErrorMessage(payload, fallback)`.

### Required checks before committing

```bash
php scripts/check_i18n_error_keys.php
php scripts/check_i18n_hardcoded_strings.php
```

See `docs/Localization.md` for the full workflow.

---

## Key Features

- **Multi-Network Support**: Connect to multiple FTN networks simultaneously
- **File Areas**: FTN TIC file distribution with pluggable antivirus scanning (ClamAV, VirusTotal)
- **WebDoors**: Drop-in game/application system (see `docs/WebDoors.md`)
- **Native Doors & DOS Doors**: PTY and DOSBox-backed door games (see `docs/Doors.md`)
- **Credits System**: Configurable in-world currency (see below)
- **Webshare**: Share echomail messages via secure links with expiration
- **Gateway Tokens**: SSO-like authentication for external services
- **ANSI Support**: JavaScript-based ANSI art renderer for messages
- **BBS Directory**: Public directory of BBS systems auto-populated via Echomail Robots

---

## Credits System Overview

The credits system is a configurable in-world currency tied to the `users.credit_balance`
column and the `user_transactions` ledger. Configuration lives in `config/bbs.json`
under the `credits` section. Admins can edit these values from the BBS Settings page
and changes apply immediately (no daemon restart required).

### Key Components

- `src/UserCredit.php`
  - `getBalance($userId)` reads the user balance.
  - `transact(...)` performs an atomic ledger transaction.
  - `credit(...)` / `debit(...)` are safe wrappers that return `true/false`.
  - `processDaily($userId)` awards daily login credits (guarded by user meta).

- Admin configuration:
  - Stored in `config/bbs.json`
  - Validated and saved via `routes/admin-routes.php` (BBS Settings API)
  - UI in `templates/admin/bbs_settings.twig`

### Configuration Defaults

Defaults are loaded when config values are missing:

- `enabled`: `true`
- `symbol`: `"$"` (or blank if explicitly configured blank)
- `daily_amount`: `25`
- `daily_login_delay_minutes`: `5`
- `approval_bonus`: `300`
- `netmail_cost`: `1`
- `echomail_reward`: `3`

### Using Credits in Code

**Reading balances:**
```php
UserCredit::getBalance($userId);
```

**Charging or awarding credits** (preferred — returns `true`/`false`, no exceptions):
```php
UserCredit::debit($userId, $amount, $description);
UserCredit::credit($userId, $amount, $description);
```

For strict error handling, call `transact()` directly and handle exceptions.

**Security:** Credit balance modifications must only occur server-side. JavaScript requests business actions (play game, buy item); the server decides whether credits are involved and handles all transactions internally. Never expose credit-specific endpoints to client code.

### Credits Disabled Behavior

When `credits.enabled` is `false`, `transact()` throws, and `credit()`/`debit()` return `false`. Handle gracefully:

- **Optional rewards** (e.g. echomail rewards): attempt `credit()`; if `false`, log and continue.
- **Hard requirements** (e.g. netmail cost): if `debit()` returns `false`, abort and return an error to the user.
- **Games**: if credits are disabled, either disallow play with a clear message, or run in a for-fun mode that skips all credit calls.

### Troubleshooting Credits

- If balances aren't updating, verify `credits.enabled` in `bbs.json` is `true`, and that the migration creating `user_transactions` and `users.credit_balance` ran.
- If symbols don't match, check `credits.symbol` in `bbs.json`. An empty string is valid and results in no symbol display.

---

## Optional Components & Daemons

Several features require additional background daemons or services beyond the core web interface and binkp mailer. These are optional — only run the ones that apply to the features you want to offer.

### Door Games

BinktermPHP supports three door game types. See [Doors.md](Doors.md) for shared setup (multiplexing bridge, WebSocket configuration, reverse proxy) and type-specific documentation below.

| Type | Description | Doc |
|------|-------------|-----|
| **WebDoors** | HTML5/JavaScript games in a browser iframe — no extra server-side process required | [WebDoors.md](WebDoors.md) |
| **Native Doors** | Linux binaries or Windows executables launched via PTY | [NativeDoors.md](NativeDoors.md) |
| **DOS Doors** | Classic DOS games running under DOSBox-X | [DOSDoors.md](DOSDoors.md) |

#### Multiplexing Bridge (Node.js)

Native Doors and DOS Doors both require the multiplexing bridge: `scripts/dosbox-bridge/multiplexing-server.js`. See [Doors.md](Doors.md) for full setup, environment variables, reverse proxy configuration, and service/daemon installation.

```bash
cd scripts/dosbox-bridge
npm install      # installs ws, pg, node-pty, iconv-lite, dotenv

# Development
node scripts/dosbox-bridge/multiplexing-server.js

# Production (daemon mode)
node scripts/dosbox-bridge/multiplexing-server.js --daemon
```

PID file: `data/run/multiplexing-server.pid` — Log: `data/logs/multiplexing-server.log`

---

### MRC Chat Daemon (`scripts/mrc_daemon.php`)

Provides **Multi Relay Chat** — a real-time chat network for FTN BBSs. Maintains a persistent connection to an MRC server, relays messages, and keeps room state in the database. See [MRC_Chat.md](MRC_Chat.md) for configuration and setup.

```bash
php scripts/mrc_daemon.php --daemon
```

PID file: `data/run/mrc_daemon.pid`

---

### Gemini Daemon (`scripts/gemini_daemon.php`)

Serves BBS content (user capsules, echomail) over the [Gemini protocol](https://geminiprotocol.net/) via TLS on port 1965. See [GeminiCapsule.md](GeminiCapsule.md) for TLS certificate setup and configuration.

```bash
php scripts/gemini_daemon.php --daemon
```

PID file: `data/run/gemini_daemon.pid` — Log: `data/logs/gemini_daemon.log`

---

### Terminal Servers

The Telnet and SSH daemons provide a classic BBS terminal interface alongside the web UI. See [TerminalServer.md](TerminalServer.md) for Telnet and [SSHServer.md](SSHServer.md) for SSH setup.

---

### Echomail Robots (`scripts/echomail_robots.php`)

Processes robot command messages posted to special echo areas (e.g. AREAS.BBS subscription management, BBS directory updates). See [Robots.md](Robots.md) for available robots and configuration.

---

### Other Background Scripts

Maintenance and scheduling scripts typically run via cron or the admin daemon's task scheduler. See [MAINTENANCE.md](MAINTENANCE.md) and [CLI.md](CLI.md) for full details.

| Script | Purpose |
|--------|---------|
| `scripts/binkp_scheduler.php` | Schedules automatic uplink polling |
| `scripts/binkp_poll.php` | Polls a single uplink on demand |
| `scripts/echomail_maintenance.php` | Prunes old messages per area retention settings |
| `scripts/chat_cleanup.php` | Removes expired MRC chat history |
| `scripts/logrotate.php` | Rotates application log files |
| `scripts/database_maintenance.php` | Periodic database VACUUM and upkeep |
| `scripts/update_nodelists.php` | Downloads and imports FTN nodelist files |
| `scripts/activity_digest.php` | Sends periodic activity digest emails |
| `scripts/rss_poster.php` | Posts RSS feed items as echomail |
| `scripts/weather_report.php` | Posts weather reports as echomail |

The `restart_daemons.sh` (Linux) and `start_daemons_windows.cmd` / `start_daemons_windows.ps1` (Windows) scripts start or restart all long-running daemons in one step.

---

## Getting Help

- **FAQ**: See [FAQ.md](../FAQ.md) for common questions and troubleshooting
- **Architecture**: See [ARCHITECTURE.md](ARCHITECTURE.md) for system diagrams and data-flow documentation
- **Data Model**: See [DATA_MODEL.md](DATA_MODEL.md) for a conceptual overview of key database tables
- **Contributing**: See `CONTRIBUTING.md` in the project root for git workflow, PR process, and the pre-commit checklist
- **Antivirus**: See [AntiVirus.md](AntiVirus.md) for virus scanning setup and configuration
- **WebDoor Tutorial**: See [WebDoor-Tutorial.md](WebDoor-Tutorial.md) to build your first WebDoor end-to-end
- **WebDoor API**: See [WebDoors.md](WebDoors.md) for the full WebDoor specification
- **Door Games**: See [Doors.md](Doors.md) for the multiplexing bridge and door types
- **Localization**: See [Localization.md](Localization.md) for the full i18n workflow
- **Upgrade Guides**: Check the `docs/UPGRADING_x.x.x.md` files for version-specific changes
