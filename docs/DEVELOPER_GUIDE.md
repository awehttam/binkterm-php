# Developer Guide

## Welcome to BinktermPHP

BinktermPHP is a modern web-based interface for FidoNet messaging networks. It combines a traditional bulletin board system (BBS) experience with contemporary web technologies, allowing users to send and receive netmail (private messages) and echomail (forums) through the FidoNet Technology Network (FTN).

### What is FidoNet?

FidoNet is a worldwide network of BBSs that exchange mail and files using store-and-forward technology. Messages are packaged into "packets" and transmitted between systems using the binkp protocol. Unlike modern internet messaging, FidoNet operates asynchronously - messages are bundled, transmitted to upstream nodes (hubs), and then distributed across the network.

### Project Architecture

BinktermPHP is built as a dual-component system:

1. **Web Interface**: A PHP-based web application that users interact with via browser
2. **Binkp Mailer**: Background daemon processes that handle FidoNet packet transmission

#### Key Components

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

### Directory Structure

```
binkterm-php/
├── config/              Configuration files (bbs.json, database, uplinks)
├── data/                Runtime data (logs, packets, nodelists)
│   ├── inbound/         Received FTN packets
│   ├── outbound/        Packets queued for transmission
│   └── logs/            Application and packet logs
├── docs/                Documentation
├── public_html/         Web root (index.php, CSS, JS, webdoors)
├── routes/              HTTP route definitions (web, API, admin)
├── scripts/             CLI tools (binkp server, poller, maintenance)
├── src/                 Core PHP classes
│   ├── Admin/           Admin interface controllers
│   ├── Database.php     PDO connection singleton
│   ├── MessageHandler.php  Message processing and storage
│   ├── PacketProcessor.php FTN packet parsing
│   ├── Template.php     Twig template wrapper
│   ├── UserCredit.php   Credits/currency system
│   └── Version.php      Application version management
├── templates/           Twig templates
├── tests/               Debug/test scripts
└── vendor/              Composer dependencies (DO NOT EDIT)
```

### Development Workflow

#### Code Conventions

- **Variables/Functions**: camelCase (`$userName`, `sendMessage()`)
- **Classes**: PascalCase (`MessageHandler`, `UserCredit`)
- **Indentation**: 4 spaces (no tabs)
- **Database**: Use `Database::getInstance()->getPdo()` for connections

#### Database Migrations

- **Schema files**: `data/schema/vX.Y.Z_description.sql`
- **Initial setup**: Run `scripts/setup.php` for new installations
- **Upgrades**: Run `scripts/upgrade.php` to apply migrations
- **DO NOT** edit `postgres_schema.sql` directly - use migrations

#### Making Changes

1. **Read before editing**: Always use Read tool to view code before modifying
2. **Avoid over-engineering**: Only implement what's requested
3. **DRY principle**: Centralize repeated logic into classes
4. **Security**: Watch for SQL injection, XSS, command injection
5. **Feature parity**: Netmail and echomail features should be consistent

#### URL Construction

Always use the centralized `Config::getSiteUrl()` method when building full URLs:

```php
$url = \BinktermPHP\Config::getSiteUrl() . '/path';
```

This method:
- Checks `SITE_URL` environment variable first (handles reverse proxies correctly)
- Falls back to protocol detection using `$_SERVER` variables
- Returns base URL without trailing slash
- Prevents code duplication and ensures consistent behavior

#### Version Management

- **Application version**: Edit `src/Version.php` only
- **Auto-updates**: Tearlines, footer, API responses update automatically
- **Changelog**: Add entries to `templates/recent_updates.twig` for significant changes
- **Format**: Semantic versioning (MAJOR.MINOR.PATCH)

#### Styling Updates

When modifying `public_html/css/style.css`, also update theme files:
- `public_html/css/dark.css`
- `public_html/css/greenterm.css`
- `public_html/css/cyberpunk.css`

### AI-Assisted Development

BinktermPHP is actively developed with AI assistance using tools like Claude (Anthropic) and Codex (OpenAI).

#### CLAUDE.md - Project Instructions

The `CLAUDE.md` file in the project root contains comprehensive instructions for AI assistants working on this codebase. This file:

- Documents project structure, tech stack, and conventions
- Provides context about FidoNet protocols and BBS concepts
- Outlines coding patterns and best practices
- Explains critical workflows (database migrations, version management, credits system)
- Lists recent features and known issues
- Serves as a knowledge base for both AI tools and human developers

**For AI Tools**: When using Claude Code, Claude.ai, or Codex with this project, ensure the AI has access to `CLAUDE.md` for proper context. This file is the single source of truth for development guidelines.

**For Human Developers**: Review `CLAUDE.md` to understand the patterns and conventions used throughout the codebase. If you're working with AI tools, keep this file updated as the project evolves.

#### Working with AI Assistants

When using AI tools to develop BinktermPHP:

1. **Provide Context**: Reference `CLAUDE.md` and relevant documentation files
2. **Be Specific**: Clearly describe the feature, bug, or change needed
3. **Review Thoroughly**: AI-generated code should be tested and reviewed before committing
4. **Update Documentation**: Keep `CLAUDE.md`, this guide, and other docs in sync with changes
5. **Follow Conventions**: Ensure AI-generated code adheres to project coding standards
6. **Test First, Commit Later**: Per git workflow guidelines, test changes before staging/committing

#### Common AI Development Patterns

- **Feature Development**: Ask AI to read existing patterns first, then implement similarly
- **Bug Fixes**: Provide error logs and relevant code context for accurate diagnosis
- **Refactoring**: Specify the scope carefully to avoid over-engineering
- **Documentation**: AI can help generate proposal documents (marked as AI-generated drafts)
- **Code Review**: Use AI to explain unfamiliar code sections or suggest improvements

#### Important Notes

- AI-generated proposal documents should state they are drafts and AI-generated
- Always verify AI suggestions against actual codebase behavior
- The `vendor/` directory is managed by Composer - exclude from AI modifications
- Database migrations require special care - review schema changes thoroughly
- Security-sensitive code (authentication, packet processing) needs extra scrutiny

### Key Features

- **Multi-Network Support**: Connect to multiple FTN networks simultaneously
- **Webdoors**: Drop-in game/application API (see `docs/WebDoors.md`)
- **Credits System**: Configurable in-world currency (detailed below)
- **Webshare**: Share echomail messages via secure links with expiration
- **Gateway Tokens**: SSO-like authentication for external services
- **ANSI Support**: JavaScript-based ANSI art renderer for messages

### Getting Help

- **FAQ**: See `FAQ.md` for common questions and troubleshooting
- **WebDoor API**: See `docs/WebDoors.md` for game integration
- **Upgrade Guides**: Check `UPGRADING_x.x.x.md` files for version-specific changes

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

If `symbol` is set to an empty string explicitly in `bbs.json`, the UI will display no
symbol (just the number).

## Using Credits in Code

### Reading Balances

Use the public API:

- `UserCredit::getBalance($userId)`

### Charging or Awarding Credits (Preferred)

Use the safe wrappers to avoid exceptions bubbling up:

- `UserCredit::debit($userId, $amount, $description, $otherPartyId = null, $type = UserCredit::TYPE_PAYMENT)`
- `UserCredit::credit($userId, $amount, $description, $otherPartyId = null, $type = UserCredit::TYPE_SYSTEM_REWARD)`

These call `transact()` internally and return `true`/`false`. If you need strict error
handling, call `transact()` directly and handle exceptions.

### Transactions and Ledger

All balance changes must be recorded in `user_transactions`. `transact()` updates the
balance and inserts a ledger row in one database transaction.

## Credits Disabled Behavior

When `credits.enabled` is `false`, `transact()` throws, and `credit()`/`debit()` return
`false`. Your features should handle this gracefully.

Recommended patterns:

- **Optional rewards** (e.g. echomail rewards):
  - Attempt `credit()`; if `false`, log and continue.
- **Hard requirements** (e.g. netmail cost):
  - If `debit()` returns `false`, abort the action and return an error to the user.
- **Games**:
  - If credits are disabled, either:
    - Disallow play and show a clear message, or
    - Run in a read-only/for-fun mode that does not affect balances.

## Games + Webdoors

Games that integrate with credits should:

1. Check `credits.enabled` before requiring a balance.
2. Use `UserCredit::credit()` and `UserCredit::debit()` for payouts and wagers.
3. Use the configured symbol from `bbs.json` when displaying amounts.

Example flow (Blackjack):

- On load: verify credits are enabled, load balance, and show symbol.
- On win/loss: call `credit()` or `debit()` and update the displayed balance.

If credits are disabled, a game should either:

- Display a message and exit, or
- Run without wagering and skip all credit calls.

## Troubleshooting

- If balances aren’t updating, verify:
  - `credits.enabled` in `bbs.json` is `true`
  - The migration creating `user_transactions` and `users.credit_balance` ran
  - You’re using `UserCredit::credit()` / `debit()` or `transact()`

- If symbols don’t match:
  - Check `credits.symbol` in `bbs.json`
  - Empty string is valid and results in no symbol display
