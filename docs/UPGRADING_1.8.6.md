# Upgrading to 1.8.6

Make sure you've made a backup of your database and files before upgrading.

## Summary of Changes

**Improvements**
- PWA manifest: added app shortcuts for Doors (`/games`) and Files (`/files`)
- Door documentation reorganised: new `docs/Doors.md` entry point covers all door types and shared multiplexing bridge setup (including `--daemon` mode); `docs/DOSDoors.md` and `docs/NativeDoors.md` updated to reference it
- Anonymous (guest) native door access: sysops can now allow unauthenticated users to launch specific native doors by setting `allow_anonymous: true` and `guest_max_sessions: N` in `config/nativedoors.json`. Requires migration v1.10.17.2 (run via `setup.php`).
- New native door: **PubTerm (Public Terminal)** — connects anonymous users to the BBS via telnet. Disabled by default; enable in Admin → Native Doors. Configure target host/port via `PUBTERM_HOST` and `PUBTERM_PORT` in `.env` (defaults to `127.0.0.1:2323`). Linux uses `telnet -E -K`; Windows uses PuTTY `plink` (install via `winget install PuTTY.PuTTY`, or set `PUBTERM_PLINK_BIN` in `.env`). When enabled, a "Connect via Telnet" button appears on the login page.
- New **Guest Doors** page (`/guest-doors`) listing all anonymous-accessible native doors. Enable via Admin → BBS Settings → Enable Guest Doors Page. When enabled, a Guest Doors link appears in the navigation for logged-out users.
- Native door manifests now support a `platform` field in `requirements` (e.g. `["linux", "windows"]`). The admin UI shows a warning badge if a door's platform requirements don't match the server OS.
- Native door manifests now support `launch_command_windows` for platform-specific launch commands on Windows.

**Bug Fixes**
- Fixed 30–45 second delay when sending echomail or netmail. The immediate outbound poll triggered after sending was blocking the HTTP response on non-PHP-FPM setups (Apache mod_php, nginx without FPM). The admin daemon now spawns the poll in the background so the response returns as soon as the message is saved. **Requires admin daemon restart** — see upgrade instructions below.

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
