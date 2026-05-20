# Developer Guide

## Table of Contents

- [Welcome to BinktermPHP](#welcome-to-binkterm-php)
- [Project Architecture](#project-architecture)
- [Directory Structure](#directory-structure)
- [Development Workflow (AI Assisted)](#development-workflow-ai-assisted)
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

## Development Workflow (AI Assisted)

### AI Workflows

**Claude is the primary AI development tool for this repository.** Project-specific workflows, guardrails, and subsystem instructions are written first for Claude-based tooling, and other AI agents are expected to follow the same conventions.

**`CLAUDE.md` at the repo root** is the starting point for project-wide guidance. It covers global coding conventions, architecture notes, documentation requirements, logging rules, i18n policy, cache-bump reminders, and workflow-specific instructions that apply across the whole codebase.

**Subsystem `CLAUDE.md` files** live in specific directories such as `scripts/`, `templates/`, `telnet/`, and `ssh/`. These add local rules for that part of the project. When working inside one of those subsystems, read the nearest `CLAUDE.md` in addition to the root file rather than treating the root file as the only source of guidance.

**Registered skills** are stored in `.claude/commands/` as reusable workflow documents that Claude can invoke by name. Current project skills are:

- `/bump-version`
- `/logging-guide`
- `/new-migration`
- `/new-webdoor`
- `/tackleissue`
- `/usercredits-workflow`

These skills are also listed in the root `CLAUDE.md` and announced at session start via `.claude/session-start.php`. When adding a new skill, register it in all three places so the workflow stays discoverable and consistent.

**OpenAI Codex compatibility shim**: the repository includes an `AGENTS.md` file at the root that acts as a compatibility shim for tools that follow the `AGENTS.md` convention instead of Claude's native instruction loading. In practice, that shim points Codex back to the Claude-authored project instructions so the same rules and workflows still apply.

**Claude skills vs. Codex phrasing**: the files in `.claude/commands/` are registered as slash-invoked skills for Claude tooling, but they are not registered as native skills in Codex. In Claude, a workflow may be invoked as `/tackleissue 123`. In Codex, use plain language that asks for the same workflow, for example `let's tackle issue #123` rather than `/tackleissue 123`.

### Code Conventions

- **Variables/Functions**: camelCase (`$userName`, `sendMessage()`)
- **Classes**: PascalCase (`MessageHandler`, `UserCredit`)
- **Constants**: UPPER_SNAKE_CASE (`MAX_CONNECTIONS`, `DEFAULT_TIMEOUT`)
- **Indentation**: 4 spaces (no tabs)
- **Database**: Use `Database::getInstance()->getPdo()` for connections
- **Environment variables**: Always use `Config::env('VAR_NAME', 'default')` — never `getenv()` or `$_ENV` directly
- **Client-side storage**: Use `UserStorage` (`public_html/js/user-storage.js`) instead of `localStorage` directly — it scopes keys per user and falls back to `sessionStorage` when not logged in
- **Web interface queries**: Use AJAX requests via the API rather than full page reloads
- **Vendor directory**: Never modify files under `vendor/` — managed entirely by Composer
- **Writing config files**: Web routes and controllers must not write configuration or runtime files directly. Use the admin daemon (`AdminDaemonClient`) when web code needs to save settings or write project files
- **UTC timestamps**: Store timestamps in UTC. Prefer `TIMESTAMPTZ` columns with `DEFAULT NOW()`; if writing to a `TIMESTAMP WITHOUT TIME ZONE` column use `NOW() AT TIME ZONE 'UTC'`. Convert to the user's time zone only in the UI or terminal output
- **Security**: Validate and sanitize all user input and external data. Use prepared statements for all database queries. Never trust user input without validation, expose sensitive configuration, or store passwords in plain text

### Database Migrations

- **Migration files**: `database/migrations/vYYYYMMDDHHMMSS_description.sql` (or `.php`)
- **Apply migrations**: Run `php scripts/setup.php` — this runs both migrations and other upgrade tasks
- **DO NOT** edit `postgres_schema.sql` directly — use migrations
- **Migration IDs**: Use timestamp IDs in UTC to avoid collisions between parallel development branches. Prefer `php scripts/migration.php create "description"` so the filename is generated consistently. Legacy `vX.Y.Z_description` migrations are still supported for existing files, but new migrations should use timestamps.
- **Creating a new migration**: Run `php scripts/migration.php create "description"` to generate the file, then invoke the `/new-migration` skill — it covers the authoritative ID format, the SQL vs PHP choice, the no-duplicate-index rule, and the `setup.php` reminder.
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
```

### Migration Best Practices

- Use transactions where appropriate to keep the schema in a consistent state on failure
- Include rollback procedures in comments for non-transactional operations
- Test with realistic data volumes before submitting
- Document any manual steps required that cannot run inside `setup.php`
- Do not add a separate non-unique index for a column that already has a `UNIQUE` constraint — PostgreSQL creates an index automatically for unique constraints

### Making Changes

1. **Avoid over-engineering**: Only implement what's requested
2. **DRY principle**: Centralize repeated logic into classes
3. **Security**: Watch for SQL injection, XSS, command injection
4. **Feature parity**: Netmail and echomail features should generally be consistent — clarify when unsure

### Pre-commit Checklist

- [ ] If you added or changed user-facing text in Twig, JavaScript, or API errors, update every locale under `config/i18n/` and run:
  ```bash
  php scripts/check_i18n_hardcoded_strings.php
  php scripts/check_i18n_error_keys.php
  ```
- [ ] If you changed CSS, JavaScript, or i18n catalogs, increment `CACHE_NAME` in `public_html/sw.js`
- [ ] If you changed `binkstream-worker-v2.js`, increment `WORKER_BUILD` in `binkstream-client.js`
- [ ] If you updated `public_html/css/style.css`, update the four theme stylesheets (`amber.css`, `dark.css`, `greenterm.css`, `cyberpunk.css`)
- [ ] If you added a file under `docs/` (outside `docs/proposals/`), update `docs/index.md`
- [ ] If you added, removed, or modified routes in `routes/api-routes.php`, update `docs/API.md`
- [ ] If your change requires a database migration, run `php scripts/setup.php` to verify it applies cleanly
- [ ] No sensitive data or credentials committed

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

The preferred method is the `/bump-version` skill, which walks through all steps in order. For reference, a manual bump touches these files:

- **`src/Version.php`** — the `VERSION` constant; everything else (tearlines, footer, API responses, Twig templates) reads from this automatically.
- **`composer.json`** — the top-level `"version"` field must be kept in sync.
- **`docs/UPGRADING_X.Y.Z.md`** — create from `docs/UPGRADING_TEMPLATE.md`; do not pre-populate from git history.
- **`docs/index.md`** and **`README.md`** — link the new UPGRADING doc (newest-first in the Upgrading section).

Other notes:
- **Format**: Semantic versioning (MAJOR.MINOR.PATCH)
- **Git tags** are created by the release maintainer when publishing a GitHub release — do not create them as part of a routine version bump commit.
- **Database versions** are independent of the application version and use the migration file naming scheme.
- **New composer dependency**: if the bump adds a required package, the UPGRADING doc must instruct upgraders to run `composer update` before `php scripts/setup.php`.

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

### Proposals Directory

`docs/proposals/` is an informal scratchpad for ideas, design notes, and implementation feedback. Writing a proposal is recommended — it helps with project knowledge capture and gives AI coding assistants useful context about intent and design decisions. It is not part of the operational documentation and is excluded from the in-app doc browser and from `docs/index.md`.

A few things to keep in mind when reading or writing proposals:

- **May be outdated**: Proposals capture ideas relative to the version of BinktermPHP at the time they were written. A proposal may describe a subsystem that has since changed significantly, or a feature that was implemented differently than originally planned.
- **May not be implemented**: Many proposals describe ideas that were never built, were superseded, or are still under consideration.
- **Purpose**: To capture ideas and implementation notes while they are fresh, and to gather feedback before or during development. They are a communication tool, not a specification.

When writing a proposal, include a preamble noting that it is a draft generated or reviewed at a particular point in time. Do not treat a proposal as an authoritative reference for how the system currently works — read the code and the operational docs for that.

### Logging

Use `AdminDaemonClient::log($level, $message, $context)` for application-level log messages from web-context PHP code. This routes messages through the admin daemon's structured log rather than the PHP error log. The static method handles connection, logging, and cleanup in one call:

```php
\BinktermPHP\Admin\AdminDaemonClient::log('INFO', 'Something happened', ['key' => 'value']);
```

---

## API Documentation Generator

`scripts/generate_api_docs.php` parses the SimpleRouter route files and produces developer-facing API reference documentation. It uses PHP's built-in tokenizer (not regex) so it correctly handles nested group prefixes, string-interpolation braces, and PHPDoc comment extraction.

**Maintaining `docs/API.md`**: The repository's `docs/API.md` is the canonical REST API reference and must be kept up to date as routes are added, modified, or removed. Update it by hand for incremental changes. Do not use the generator to overwrite `docs/API.md` — the script is intended for local exploration and bulk regeneration by maintainers, not as a substitute for keeping the committed doc current.

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
php scripts/generate_api_docs.php --output=mydocs/API.md
```

Generate OpenAPI YAML for all routes:

```bash
php scripts/generate_api_docs.php --routes=all --format=openapi --output=mydocs/openapi.yaml
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
php scripts/generate_api_docs.php --ai --provider=anthropic --output=mydocs/API.md

# AI-enriched admin API as OpenAPI YAML
php scripts/generate_api_docs.php --routes=admin --ai --format=openapi --output=mydocs/openapi.yaml

# Larger batch size to reduce API calls (at the cost of longer prompts)
php scripts/generate_api_docs.php --routes=all --ai --ai-batch-size=15 --output=mydocs/API.md
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

The `restart_daemons.sh` (Linux) and `start_daemons_windows.cmd` / `start_daemons_windows.ps1` (Windows) scripts start or restart all long-running daemons in one step.

---

## Doc Maintenance Checklist

When adding features, **you must update the corresponding documentation file** for each subsystem touched. The table below maps subsystems to their docs.

| When you… | Update this doc |
|---|---|
| Add or remove a script in `scripts/` | `docs/CLI.md` |
| Add a new env var to `.env.example` | `docs/CONFIGURATION.md` |
| Add a new admin daemon command | `docs/AdminDaemon.md` |
| Publish a new event through `sse_events` | `docs/BinkStreamChannel.md` |
| Add new database tables or change core entity relationships | `docs/DATA_MODEL.md` |
| Add, remove, or modify routes in `routes/api-routes.php` | `docs/API.md` |
| Add or change a PacketBBS command, chat feature, output profile, or bridge API endpoint | `docs/PacketBBS.md` |
| Add a new documentation file to `docs/` (excluding `docs/proposals/`) | `docs/index.md` (in operational priority order) |
| Create a new `UPGRADING_x.y.z.md` file | `docs/index.md` Upgrading section (newest-first) and `README.md` |
| Add a root-level Markdown file that a `docs/` page links to with `../` | Two places in `src/Web/DocsController.php`: `$specialBases` in `resolveDocPath()` and the `in_array` list in `rewriteLinks()` |

---

## Getting Help

- **FAQ**: See [FAQ.md](../FAQ.md) for common questions and troubleshooting
- **Architecture**: See [ARCHITECTURE.md](ARCHITECTURE.md) for system diagrams and data-flow documentation
- **Data Model**: See [DATA_MODEL.md](DATA_MODEL.md) for a conceptual overview of key database tables
- **Contributing**: See `CONTRIBUTING.md` in the project root for git workflow, development setup, testing, and the PR process
- **Antivirus**: See [AntiVirus.md](AntiVirus.md) for virus scanning setup and configuration
- **WebDoor Tutorial**: See [WebDoor-Tutorial.md](WebDoor-Tutorial.md) to build your first WebDoor end-to-end
- **WebDoor API**: See [WebDoors.md](WebDoors.md) for the full WebDoor specification
- **Door Games**: See [Doors.md](Doors.md) for the multiplexing bridge and door types
- **Localization**: See [Localization.md](Localization.md) for the full i18n workflow
- **Upgrade Guides**: Check the `docs/UPGRADING_x.x.x.md` files for version-specific changes
