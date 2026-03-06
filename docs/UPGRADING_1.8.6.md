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

- Multiplexing server (`scripts/dosbox-bridge/multiplexing-server.js`) now supports **SIGHUP config reload**. Send `kill -HUP $(cat data/run/multiplexing-server.pid)` to reload `.env` values without restarting. The following settings reload live: `DOSDOOR_DISCONNECT_TIMEOUT`, `DOSDOOR_DEBUG_KEEP_FILES`, `DOSDOOR_CARRIER_LOSS_TIMEOUT`. Settings that require a full restart: `DOSDOOR_WS_PORT`, `DOSDOOR_WS_BIND_HOST`, `DOSDOOR_TRUSTED_PROXIES`, `DB_*`.
- Multiplexing server now resolves the real client IP from the `X-Forwarded-For` header when the connection originates from a trusted proxy. Set `DOSDOOR_TRUSTED_PROXIES` in `.env` to a comma-separated list of proxy IPs (default: `127.0.0.1`). Connections from unlisted addresses always use the raw socket IP.

**Bug Fixes**
- Fixed 30–45 second delay when sending echomail or netmail. The immediate outbound poll triggered after sending was blocking the HTTP response on non-PHP-FPM setups (Apache mod_php, nginx without FPM). The admin daemon now spawns the poll in the background so the response returns as soon as the message is saved. **Requires admin daemon restart** — see upgrade instructions below.
- Fixed echomail and netmail posting identity guideline showing in English regardless of user locale on initial page load. The server-rendered (correctly translated) text is now preserved until the user selects an echo area or enters an address.

## Localization (i18n) Support

- Translation catalogs now support broader UI/API coverage across web pages and admin tools. Ships with English (`en`) and Spanish (`es`). See `docs/Localization.md` for a full technical reference and translation contributor workflow.
- API responses are now expected to use `error_code` / `message_code` (with optional params), so clients can localize consistently per user locale.
- JavaScript translations use lazy catalog loading (`/api/i18n/catalog`). Pages that render text dynamically must initialize after user settings + i18n catalogs are loaded to avoid English fallback text.
- New CI checks enforce i18n quality:
  - `php scripts/check_i18n_error_keys.php` validates error key coverage.
  - `php scripts/check_i18n_hardcoded_strings.php` blocks new hardcoded UI strings not in the allowlist.

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

