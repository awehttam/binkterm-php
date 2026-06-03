# Upgrading to 1.9.8

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Web Interface](#web-interface)
- [PGP Key Management](#pgp-key-management)
- [Developer / Infrastructure](#developer--infrastructure)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Web Interface

- The user-facing echoarea subscription manager at `/subscriptions` now uses a more compact filter layout modeled after `/echolist`, with network filtering and an option to show only interest groups that currently have message traffic.
- Subscribing or unsubscribing from an echoarea in `/subscriptions` now updates in place instead of reloading the page, preserving the current scroll position and active search/filter state.
- Pipe-code rendering now defaults to a new `decimal_relaxed` parser mode so decimal color codes such as `|01` still parse when immediately followed by uppercase text. The parser behavior can be overridden with the new `.env` setting `PIPE_CODE_PARSER_MODE`.
- User settings now include a PGP tab where users can upload multiple public keys, choose a preferred key, and browse the public keyserver.
- BBS-managed private key hosting is available behind a separate sysop toggle and is off by default.

### Developer / Infrastructure

- Realtime wake-up signaling now has a small transport abstraction around PostgreSQL `LISTEN/NOTIFY`. The current implementation is still PostgreSQL-only, but the direct `pg_*` calls are now concentrated in dedicated realtime classes instead of being spread across `BinkStream`, the AI bot daemon, and the admin daemon.
- Database bootstrap now has a minimal platform abstraction for DSN construction, session initialization, and base schema selection. PostgreSQL remains the only supported backend, but connection and setup behavior is no longer hardcoded in one place.
- `.env` may now include `DB_DRIVER=pgsql`. PostgreSQL is still the only supported value today. This setting exists to make future backend setup work easier to isolate if it is ever pursued.
- `.env` may now include `PIPE_CODE_PARSER_MODE` to control how BBS pipe color codes are recognized by the web renderer and terminal bulletin renderer.
- A new developer reference document, `docs/PostgreSQLDependencies.md`, tracks intentional PostgreSQL-specific dependencies and where they currently live.
- BinkP session logging now closes failed session rows more aggressively and retires orphaned `active` rows whose handler process has already exited, so the admin BinkP session view no longer treats dead pre-handshake sessions as long-running live connections.
- The `user_settings.theme` column now allows up to 300 characters instead of 20 so custom theme stylesheet paths and longer theme identifiers can be stored without truncation.

---

## Web Interface

### Subscription Manager

The `/subscriptions` page now presents its filtering tools in a compact filter panel instead of a long row of controls and per-interest buttons.

The updated page adds:

- a network filter for narrowing the visible echoareas by network
- a compact interest picker instead of a button wall
- an `Only show groups with messages` filter that limits the visible results to interest-grouped areas with message activity
- the search and sort controls inside the same filter panel for a tighter layout
- in-place subscribe/unsubscribe updates that do not reset the current search, filters, or scroll position

This change is user-facing only. It does not alter subscriptions, interest membership, or message access rules.

### PGP Key Management

The new PGP settings tab lets users manage multiple public keys on a single account. Users can upload armored public keys, choose a preferred key, and browse the public keyserver from the keyserver link in settings.

Two BBS-level flags control the feature:

- `Enable PGP` turns the user-facing PGP tab and public keyserver on or off
- `Allow BBS-managed private keys` controls whether users can generate and store a BBS-managed private key pair

Both settings default to off. After upgrading, sysops who want the feature must enable it in **Admin -> BBS Settings**.

If managed private keys are disabled, users can still upload public keys and select a primary key, but the private-key generator is hidden.

The compose page now also uses the public-key directory for netmail encryption lookups. When users enable `Encrypt this netmail`, the UI searches the keyserver using the recipient text and shows an explicit public-key selector before sending. That lookup can surface:

- the user's published PGP UID
- the key fingerprint
- the key label
- matching BBS usernames and real names
- saved address-book entries, including local-user matches surfaced by the address-book search API

If the compose autocomplete only shows saved contacts and not local users, make sure the address-book search route is returning both data sources. The current implementation exposes both through `GET /api/address-book?search=...` and the legacy `/api/address-book/search/{query}` alias.

## Developer / Infrastructure

### Realtime Signaling Abstraction

The realtime event path now uses dedicated transport and maintenance classes instead of inlining PostgreSQL signaling details directly into each caller.

This change does not alter the current supported backend. BinktermPHP still requires PostgreSQL, and BinkStream still uses PostgreSQL notifications today.

What changed:

- `src/Realtime/BinkStream.php` now publishes wake-up notifications through a dedicated publisher class
- `scripts/ai_bot_daemon.php` now listens through a dedicated PostgreSQL event listener class
- `src/Admin/AdminDaemonServer.php` now delegates `sse_events` cleanup to a maintenance service

This keeps the current PostgreSQL behavior while making future transport changes, such as Redis-backed wake-ups, easier to isolate.

### Database Bootstrap Abstraction

Database bootstrap now resolves platform-specific setup behavior through dedicated classes under `src/DatabasePlatform/`.

Current scope:

- DSN construction
- session initialization
- base schema path selection

PostgreSQL is still the only supported platform. The new `DB_DRIVER` setting should remain `pgsql`.

### Pipe Code Parser Mode

Pipe-code rendering now has a runtime parser-mode setting shared by the web ANSI renderer and the terminal bulletin renderer.

The new `.env` setting is:

```env
PIPE_CODE_PARSER_MODE=decimal_relaxed
```

Supported modes:

- `decimal_relaxed` - default. Greedily accepts two-digit decimal color codes such as `|01` even when the following text starts with an uppercase letter.
- `strict` - keeps the more conservative uppercase-boundary checks to reduce false positives in ordinary prose.
- `loose` - restores broader legacy matching for testing and comparison.

This change is primarily intended to improve compatibility with messages that contain decimal pipe color codes immediately followed by uppercase text, such as `|01A side of beans`, without forcing a code change when sysops want to compare parser behavior.

### User Theme Length Increase

The `user_settings.theme` column has been widened from `VARCHAR(20)` to `VARCHAR(300)`.

This supports longer stored theme values, including custom stylesheet paths that exceed the previous 20-character limit.

### BinkP Session Log Cleanup

The BinkP session log now treats abnormal session termination more defensively.

What changed:

- the inbound and outbound BinkP session wrappers now close the session log row when a PHP `Throwable` escapes the normal handshake or transfer flow
- the admin `active` BinkP session listing now retires older `active` rows whose recorded handler PID is no longer running
- `scripts/database_maintenance.php` now includes a stale-session cleanup pass before age-based BinkP log retention cleanup

This keeps the admin BinkP dashboard aligned with real process state when a remote peer connects, drops during handshake, and the handler process exits before the session log row was finalized.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically.
