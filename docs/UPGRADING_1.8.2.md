# Upgrading to 1.8.2

Make sure you've made a backup of your database and files before upgrading.

## New Features

- **Telnet Bind Configuration** — The telnet daemon's bind host and port can now be set via `.env` variables (`TELNET_HOST`, `TELNET_PORT`), removing the need to edit the script directly.
- **Telnet Anti-Bot ESC Challenge** — New connections receive an ESC-key challenge before the login prompt, blocking automated scanners. Failed login attempts are now logged.
- **Activity Stats Timezone** — Dates and times on the activity statistics page are now displayed in the logged-in user's preferred timezone.
- **Persistent Echolist Filter** — The unread-only filter on the forum-style echo list is now persisted across sessions alongside the subscribed-only preference.

## Bug Fixes

### FTN / Messaging
- **Origin Line Restricted to Echomail** — Outgoing netmail packets no longer include a `* Origin:` line. Per FTS-0004, origin lines are an echomail convention; netmail routing is conveyed via kludge lines (`^AINTL`, `^AMSGID`, etc.).
- **Pipe Code Decimal Parsing** — Pipe colour codes are now parsed as decimal values, correcting a blink rendering bug introduced by treating them as octal/hex.

### Telnet Daemon
- **Door List Display** — The door list now shows the door name instead of its internal ID.
- **Multiplexor Log Timestamps** — All multiplexor log output now includes timestamps using local server time (previously UTC or missing entirely).
- **Multiplexor Idle Log Spam** — Suppressed repetitive idle-status entries in the multiplexor daemon log.

### Admin / Daemon
- **`reload_binkp_config` Response** — The admin daemon's `reload_binkp_config` command now correctly returns an array response, fixing a parsing error in the web interface.
- **Fresh Install Migrations** — Database migrations now run correctly during a fresh installation (previously only ran on upgrades).

## DOS Door Improvements

- Doorway launcher now passes user information via `DOOR.SYS` instead of command-line arguments.
- Registered version of Doorway receives the `/o` flag for correct operation.
- `DOORWAYU.EXE` (unregistered Doorway) is now bundled and used by default; installing `DOORWAY.EXE` alongside it will override automatically.
- Added a README for the built-in Admin door.

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
