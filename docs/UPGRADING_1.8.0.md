# Upgrading to 1.8.0

Make sure you've made a backup of your database and files before upgrading.

## New Features

- **DOS Door Integration** — Classic BBS door games now playable in the browser via DOSBox-X and a multiplexing WebSocket bridge. Sessions are scoped per-door so multiple doors can be open in separate tabs simultaneously. See [docs/DOSDoors.md](DOSDoors.md) for full setup instructions.
- **Activity Tracking & Statistics** — A new admin statistics page surfaces usage analytics: popular echoareas, door plays, file activity, nodelist lookups, top users, and hourly distribution. Configurable period filter (7d / 30d / 90d / all time).
- **Referral System** — Users receive a personal referral link and earn BBS credits when a referred user is approved.
- **WebDoor SDK** — Shared client/server SDK for WebDoors. Games like Blackjack and CWN now automatically update the credit balance shown in the top navigation.
- **Pipecode Color Support** — Pipe codes in messages are converted to ANSI color sequences.

## Messaging

- **Bottom Kludge Storage** — PATH, SEEN-BY, and VIA lines are now stored separately in a `bottom_kludges` column rather than inline with message text, for both echomail and netmail.
- **Outbound VIA Kludge** — VIA is now correctly placed in the bottom kludge block during outbound message transmission.
- **Netmail Download** — Press `d` while reading a netmail to download the message as a file.
- **Insecure Netmail Warning** — Netmails received via an insecure BinkP session are now flagged with a visible warning.
- **Echomail Duplicate Prevention** — MSGID is now checked on incoming echomail to prevent duplicate entries (required for `%rescan` support).
- **Configurable Echomail Landing Page** — The default landing page is now the forum-style echo list. Sysops can set the system-wide default (reader or echo list), and users can override their own preference.
- **Echo List Filtering** — The forum-style echo list can now be filtered to show subscribed areas only, or areas with unread messages.

## Nodelist

- **Custom BinkP Port Fix** — Node entries with a custom BinkP port no longer bleed that port into the SSH/Telnet/HTTPS links in the node popup; standard ports are used for those protocols.
- **IPv6 Support** — IPv6 addresses in nodelist entries now parse correctly.

## BinkP / Networking

- **Insecure Session Fix** — Corrected an issue where incoming insecure BinkP sessions would fail.
- **Plaintext Auth Restored** — Reverts a 1.7.9 change that enforced CRYPT-MD5 even when plaintext was specified in configuration. Plaintext sessions work again.

## UI / UX

- **Dashboard Loading Indicators** — Netmail and echomail stat widgets on the admin dashboard now show a loading indicator while fetching counts.
- **Ad Generator Gradient Borders** — The BBS advertisement generator now supports gradient border styles.
- **Blackjack Leaderboard** — Now tracks credits won from winning hands only (losses do not subtract). Score is independent of BBS credits earned elsewhere. Leaderboard resets each calendar month.

## Database

- **UTC Timestamp Normalisation** — All timestamps are now stored as `TIMESTAMPTZ` with a connection default of UTC. Previously the system used whatever timezone Postgres or PHP defaulted to. Run `php scripts/setup.php` to apply the migration.

---

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

```bash
wget https://raw.githubusercontent.com/awehttam/binkterm-php-installer/main/binkterm-installer.phar
php binkterm-installer.phar
scripts/restart_daemons.sh
```

---

## DOS Door Integration

BinktermPHP now supports classic DOS door games playable directly in the browser. The system works by running DOSBox-X (a DOS emulator) on the server for each active session. A Node.js multiplexing bridge connects DOSBox's emulated serial port (COM1) over TCP to a WebSocket, which the browser connects to via an xterm.js terminal. When a user launches a door, a DOOR.SYS drop file is generated with their user information, DOSBox-X starts headlessly and runs the door game, and the browser terminal becomes the user's interface to the game. Sessions are isolated per-door, so multiple users (or tabs) can each have their own concurrent game session.

Door games are installed under `dosbox-bridge/dos/doors/` and described by a `dosdoor.jsn` manifest file (8.3 filename for DOS compatibility). The manifest declares the game name, executable, launch command, node limits, and optional access restrictions such as `admin_only`. The multiplexing bridge runs as a persistent daemon (`node scripts/dosbox-bridge/multiplexing-server.js --daemon`) and must be running for door games to work.

For full setup instructions, configuration options, and how to add new door games, see [docs/DOSDoors.md](DOSDoors.md).

## UTC Timestamp Normalisation

This release normalises all timestamps in the PostgreSQL database to UTC. Previously the system would use either the Postgres or PHP default timezone when inserting or updating data. Now UTC is used consistently. The migration converts existing columns to `TIMESTAMPTZ` and sets the connection default timezone to UTC.
