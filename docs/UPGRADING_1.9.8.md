# Upgrading to 1.9.8

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Web Interface](#web-interface)
- [Developer / Infrastructure](#developer--infrastructure)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Web Interface

- The user-facing echoarea subscription manager at `/subscriptions` now uses a more compact filter layout modeled after `/echolist`, with network filtering and an option to show only interest groups that currently have message traffic.
- Subscribing or unsubscribing from an echoarea in `/subscriptions` now updates in place instead of reloading the page, preserving the current scroll position and active search/filter state.
- The FTP daemon now accepts QWK reply packets uploaded directly to the FTP root (`/`) as well as `/qwk/upload/`, improving compatibility with clients that do not change into the upload subdirectory before sending `.REP` or `.ZIP` files.
- Echo area deletion now offers a message-handling choice for populated areas. Sysops can delete the remaining messages or move them into another local echo area before removing the area itself.

### Developer / Infrastructure

- Realtime wake-up signaling now has a small transport abstraction around PostgreSQL `LISTEN/NOTIFY`. The current implementation is still PostgreSQL-only, but the direct `pg_*` calls are now concentrated in dedicated realtime classes instead of being spread across `BinkStream`, the AI bot daemon, and the admin daemon.
- Database bootstrap now has a minimal platform abstraction for DSN construction, session initialization, and base schema selection. PostgreSQL remains the only supported backend, but connection and setup behavior is no longer hardcoded in one place.
- `.env` may now include `DB_DRIVER=pgsql`. PostgreSQL is still the only supported value today. This setting exists to make future backend setup work easier to isolate if it is ever pursued.
- A new developer reference document, `docs/PostgreSQLDependencies.md`, tracks intentional PostgreSQL-specific dependencies and where they currently live.

## External Delivery

External delivery now follows a stricter split between local-only areas and networked areas:

- `is_local = true` means the area is never propagated through any external transport layer.
- Local-only areas do not spool to FTN uplinks.
- Local-only areas do not fan out to inter-BBS QWK mailboxes.
- The only QWK behavior still allowed for a local-only area is the logged-in user's own offline-reader workflow on this BBS: the area can appear in that user's personal QWK packet, and replies uploaded by that same user can be imported back into the same local area.

For non-local areas, external delivery is transport-specific:

- FTN spooling is used only when the area's domain is backed by an FTN network type.
- QWK fanout is used when the area has QWK conference subscriptions.
- A single non-local area may participate in both transports if it is configured that way.

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

### FTP Root REP Uploads

The FTP daemon now accepts `.REP` and `.ZIP` uploads dropped directly into the FTP root (`/`) in addition to the existing `/qwk/upload/` path. Previously, uploads to the root were rejected, blocking QWK client software — such as Synchronet's `qnet-ftp.js` — that stores the reply packet in the current working directory without issuing a `CWD` command first.

Clients that already target `/qwk/upload/` are unaffected. Clients that upload to root now have their packet routed through the same REP import pipeline as a `/qwk/upload/` transfer, including the same conference-map validation and deduplication checks.

### Echo Area Deletion Handling

The Echo Areas admin page now allows populated areas to be deleted without manual SQL cleanup. When deleting an area that still contains echomail, the dialog offers two explicit choices:

- delete the messages together with the area
- move the messages into another local echo area before deleting the original area

The move option is a local reassignment only. It does not re-gate, re-spool, or republish the historical messages into the destination area’s outbound network paths.

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

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically.
