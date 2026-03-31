# Developer Guide

## Table of Contents

- [Welcome to BinktermPHP](#welcome-to-binkterm-php)
- [Project Architecture](#project-architecture)
- [Directory Structure](#directory-structure)
- [Development Workflow](#development-workflow)
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

BinktermPHP is built around several cooperating components:

1. **Web Interface**: A PHP-based web application that users interact with via browser
2. **Binkp Mailer**: Background daemon processes that handle FidoNet packet transmission
3. **Telnet Daemon**: Background daemon that provides a Telnet BBS interface
4. **SSH Daemon**: Background daemon that provides an SSH-2 BBS interface (`ssh/ssh_daemon.php`)
5. **Admin Daemon**: Background daemon that manages configuration, logging, and triggered tasks (`scripts/admin_daemon.php`)

### Key Components

- **Frontend**: jQuery + Bootstrap 5 for responsive, modern UI
- **Backend**: PHP with SimpleRouter for routing, Twig for templating
- **Database**: PostgreSQL for persistent storage
- **Mailer**: Custom PHP binkp protocol implementation for FidoNet connectivity
- **Authentication**: Simple username/password with long-lived cookies

### Core Concepts

#### Users and Identity

- **Usernames**: System login identifiers (must be unique, case-insensitive)
- **Real Names**: Display names used in FidoNet messages (must be unique, case-insensitive)
- Both usernames and real names are unique across the system to prevent impersonation

#### Message Types

- **Netmail**: Private point-to-point messages between FidoNet users
- **Echomail**: Public messages posted to echoareas (forums/conferences)
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
├── config/              Configuration files (bbs.json, database, uplinks, i18n overrides)
│   └── i18n/            Translation catalogs (en/, es/, fr/)
├── data/                Runtime data (logs, packets, nodelists)
│   ├── inbound/         Received FTN packets
│   ├── outbound/        Packets queued for transmission
│   └── logs/            Application and packet logs
├── database/
│   └── migrations/      Database migrations (vX.Y.Z_description.sql or .php)
├── docs/                Documentation
├── native-doors/        Native door installations and drop files
├── public_html/         Web root (index.php, CSS, JS, webdoors)
│   └── webdoors/        WebDoor game installations
├── routes/              HTTP route definitions (web, API, admin)
├── scripts/             CLI tools (binkp server, poller, maintenance)
├── src/                 Core PHP classes
│   ├── Admin/           Admin interface controllers and AdminDaemonClient
│   ├── Antivirus/       Pluggable antivirus scanner backends
│   ├── Binkp/           BinkP protocol implementation
│   ├── FileArea/        File area rule processing
│   ├── Database.php     PDO connection singleton
│   ├── MessageHandler.php  Message processing and storage
│   ├── Template.php     Twig template wrapper
│   ├── UserCredit.php   Credits/currency system
│   └── Version.php      Application version management
├── ssh/                 SSH-2 daemon
├── telnet/              Telnet BBS server
├── templates/           Twig templates
│   └── shells/          Shell-specific base templates (web/, bbs-menu/)
├── tests/               Debug/test scripts
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

- **Migration files**: `database/migrations/vX.Y.Z_description.sql` (or `.php`)
- **Apply migrations**: Run `php scripts/setup.php` — this runs both migrations and other upgrade tasks
- **DO NOT** edit `postgres_schema.sql` directly — use migrations
- **Version numbering**: Before creating a migration, check the highest existing version with `ls database/migrations/ | sort -V | tail -5`. The new file must be one increment higher — do not guess or reuse a version from a different branch of the version tree
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

### Logging

Use `AdminDaemonClient::log($level, $message, $context)` for application-level log messages from web-context PHP code. This routes messages through the admin daemon's structured log rather than the PHP error log. The static method handles connection, logging, and cleanup in one call:

```php
\BinktermPHP\Admin\AdminDaemonClient::log('INFO', 'Something happened', ['key' => 'value']);
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

- **FAQ**: See `FAQ.md` for common questions and troubleshooting
- **Antivirus**: See `docs/AntiVirus.md` for virus scanning setup and configuration
- **WebDoor API**: See `docs/WebDoors.md` for game integration
- **Door Games**: See `docs/Doors.md` for the multiplexing bridge and door types
- **Localization**: See `docs/Localization.md` for the full i18n workflow
- **Upgrade Guides**: Check `docs/UPGRADING_x.x.x.md` files for version-specific changes
