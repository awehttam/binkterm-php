# Upgrading to 1.8.6

> **Note:** This release introduces localization (i18n) support across the
> entire application — templates, admin panel, API error responses, JavaScript
> UI, and outgoing emails. Localization touches virtually every part of the
> system. Testing has been performed, but some areas may have been missed.
> If you encounter any text that appears untranslated, displays a raw key
> (e.g. `ui.some.key`), or behaves unexpectedly after upgrading, please report
> it at **https://github.com/awehttam/binkterm-php/issues**.

⚠️ Make sure you've made a backup of your database and files before upgrading.

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
- Multiplexing server log lines are now prefixed with `[sessionId|username|ip]` for every session-scoped event, including emulator adapter output (DOSBox, DOSEMU, Native), DB updates, and drop file writes. This makes it straightforward to correlate all activity for a specific user or connection in the log.
- New **Language Overrides** editor in Admin → BBS Settings → Language Overrides. Sysops can customize individual phrases for any locale and catalog without editing the base translation files. Overrides are stored as JSON in `config/i18n/overrides/<locale>/<namespace>.json` and are applied transparently on top of the base catalog at runtime.

- New **SSH-2 server** (`ssh/ssh_daemon.php`) — pure-PHP SSH daemon using only `ext-openssl` and `ext-gmp`, no new Composer dependencies. Provides the same BBS terminal experience as the telnet daemon over an encrypted connection. Default port `2022` (configurable via `SSH_PORT` in `.env`). Correct SSH credentials skip the BBS login screen; failed auth drops to the login/register screen instead of disconnecting. Host key is auto-generated on first run at `data/ssh/ssh_host_rsa_key`. See `docs/SSHServer.md` for full documentation.
- Telnet/SSH BBS session logic extracted into a shared `BbsSession` class so both transports benefit from the same improvements.
- Fixed TLS telnet on Linux: TLS handshake now correctly runs in the child process after `pcntl_fork()`, preventing the parent's `fclose()` from sending an SSL `close_notify` that destroyed the child's TLS session.

**Bug Fixes**
- Fixed service worker caching: static assets (CSS, JS, fonts) are now served from the SW cache on every navigation with no redundant network requests. Switched from stale-while-revalidate to cache-first strategy; theme stylesheets and FontAwesome fonts are pre-cached at install time. The `sw.js` script now has a dedicated `Cache-Control: no-cache` header in `.htaccess` per the Service Worker spec recommendation.
- Fixed 30–45 second delay when sending echomail or netmail. The immediate outbound poll triggered after sending was blocking the HTTP response on non-PHP-FPM setups (Apache mod_php, nginx without FPM). The admin daemon now spawns the poll in the background so the response returns as soon as the message is saved. **Requires admin daemon restart** — see upgrade instructions below.
- Fixed echomail and netmail posting identity guideline showing in English regardless of user locale on initial page load. The server-rendered (correctly translated) text is now preserved until the user selects an echo area or enters an address.
- Fixed Markdown blockquotes (`>`) not rendering in the UPGRADING doc viewer. Blockquotes now display with a left border accent at normal body font size.

## Localization (i18n) Support

- Translation catalogs now support broader UI/API coverage across web pages and admin tools. Ships with English (`en`) and Spanish (`es`). See `docs/Localization.md` for a full technical reference and translation contributor workflow.
- **Note:** The Spanish (`es`) translation was generated by AI and has not been independently reviewed for accuracy. It may contain errors, awkward phrasing, or incorrect terminology. Community corrections are welcome via pull request.
- API responses are now expected to use `error_code` / `message_code` (with optional params), so clients can localize consistently per user locale.
- JavaScript translations use lazy catalog loading (`/api/i18n/catalog`). Pages that render text dynamically must initialize after user settings + i18n catalogs are loaded to avoid English fallback text.
- The **telnet and ssh daemons** (through BbsSession) now support localization. All user-facing strings in the telnet server, shell menus, message editor, echomail/netmail browsers, polls, shoutbox, and door launcher are translated via the new `telnet` catalog namespace (`config/i18n/<locale>/telnet.php`). The daemon defaults to the system locale (`I18N_DEFAULT_LOCALE`) pre-login and switches to the user's saved locale immediately after a successful login.
- The **telnet daemon** now supports TLS encryption (experimental). TLS is enabled by default on port 8023 with an auto-generated self-signed certificate stored in `data/telnet/`. Set `TELNET_TLS=false` in `.env` to disable, or provide your own certificate via `TELNET_TLS_CERT` and `TELNET_TLS_KEY`. Use `--no-tls` on the command line to disable for a single run.- `scripts/create_translation_catalog.php` now supports **Anthropic Claude** in addition to OpenAI for automated locale generation. Provider is auto-detected from the presence of `ANTHROPIC_API_KEY` or `OPENAI_API_KEY` in `.env`, or set explicitly with `--provider=claude|openai`. Default Claude model is `claude-sonnet-4-6`; default OpenAI model is `gpt-4o-mini`. See `docs/Localization.md` for full usage.
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

