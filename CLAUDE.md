# Project: binkterm-php

## Project Description

A modern web interface and mailer tool that receives and sends Fidonet message packets using its own binkp Fidonet mailer. The project provides users with a delightful, modern web experience that allows them to send and receive netmail (private messages) and echomail (forums) with the help of binkp.

 - **Product website**: https://lovelybits.org/binktermphp
 - **GitHub repo**: https://github.com/awehttam/binkterm-php
 - **Support BBS**: https://claudes.lovelybits.org

## Tech Stack

 - Frontend: jQuery, Bootstrap 5
 - Backend: PHP, SimpleRouter request library, Twig templates
 - Database: Postgres


## Code Conventions

 - camelCase for variables and functions
 - PascalCase for components and classes
 - 4 space indents
 - **Environment Variables**: Always use `Config::env('VAR_NAME', 'default')` to read from .env file. Do NOT use `getenv()` or `$_ENV` directly.
 - **Client-side storage**: Always use `UserStorage` (from `public_html/js/user-storage.js`) instead of `localStorage` directly. `UserStorage` automatically scopes keys by the logged-in user's ID so that different accounts on the same browser cannot share state. When not logged in it falls back to `sessionStorage` to avoid persisting anonymous state.

## Logging

**Never use `error_log()` for application logging** (except inside `src/Binkp/Logger.php` itself and `src/Admin/AdminDaemonClient.php` where it is the last-resort fallback). All logging must go through `BinktermPHP\Binkp\Logger`, which writes to named files under `data/logs/` and falls back to UDP via the admin daemon when the web process cannot write the log file directly.

### Log files

| File | Purpose |
|---|---|
| `server.log` | General application, web requests, background tasks |
| `packets.log` | FTN packet processing |
| `dosdoor.log` | Door game session setup (DoorSessionManager, door routes) |
| `multiplexing-server.log` | Door execution activity (the multiplexing bridge) |
| `binkp_server.log` | Binkp server daemon |
| `binkp_poll.log` | Binkp polling daemon |
| `binkp_scheduler.log` | Binkp scheduler |
| `admin_daemon.log` | Admin daemon |
| `mrc_daemon.log` | MRC daemon |
| `crashmail.log` | CrashMail processing |

### Patterns by context

**In a class with a constructor** — inject or create a Logger property:
```php
private \BinktermPHP\Binkp\Logger $logger;

public function __construct()
{
    $this->logger = new \BinktermPHP\Binkp\Logger(
        \BinktermPHP\Config::getLogPath('server.log'),
        \BinktermPHP\Binkp\Logger::LEVEL_INFO,
        false
    );
}
```
Then call `$this->logger->info(...)`, `->warning(...)`, `->error(...)`, etc.

**In a route file or static context** — use the `getServerLogger()` helper from `src/functions.php`:
```php
getServerLogger()->error("Something went wrong: " . $e->getMessage());
```
This returns a shared Logger instance writing to `server.log`. Route files that need `dosdoor.log` should define their own local helper (see `getDoorLogger()` in `routes/door-routes.php`).

**In a CLI script** — `src/functions.php` must be included (all CLI scripts should already include it). Then use `getServerLogger()` or create a Logger inline for script-specific log files.

### Log levels

- `debug()` — detailed diagnostic info, only useful when actively debugging
- `info()` — normal operational events (session started, file created, email sent)
- `warning()` — unexpected but recoverable situations
- `error()` — failures that need attention but didn't crash the process
- `critical()` — severe failures

### Adding a new log file

If you introduce a new log file (e.g., `myfeature.log`):
1. Use `Config::getLogPath('myfeature.log')` when constructing the Logger.
2. Add the filename to the `UDP_ALLOWED_LOG_FILES` allowlist in `src/Admin/AdminDaemonServer.php` so the UDP fallback can write to it when the web process can't.

## Project Structure

 - src/ - main source code
 - scripts/ - CLI tools (binkp_server, binkp_poll, maintenance scripts, etc.)
   - **IMPORTANT**: All PHP scripts in scripts/ directory must include shebang line `#!/usr/bin/env php` at the top
   - **IMPORTANT**: CLI scripts must include `src/functions.php` after autoload to access global functions like `generateTzutc()`: `require_once __DIR__ . '/../src/functions.php';`
   - **IMPORTANT**: Do not use `PHP_BINARY` from web requests to launch CLI scripts. Under php-fpm it points at the FPM SAPI, not the CLI interpreter. Invoke executable scripts directly via their shebang, or use a real CLI `php` path when you explicitly need the interpreter.
   - Scripts should be made executable with `chmod +x` and marked as executable in git with `git update-index --chmod=+x scripts/filename.php`
 - templates/ - html templates
   - **IMPORTANT**: Template resolution order is `templates/custom/` → `templates/shells/<activeShell>/` → `templates/`. The active shell (`web` or `bbs-menu`) has its own `base.twig` at `templates/shells/web/base.twig` and `templates/shells/bbs-menu/base.twig` which take priority over `templates/base.twig`. When adding nav links or modifying shared layout, you must update **both** `templates/base.twig` AND `templates/shells/web/base.twig` (and `bbs-menu` if applicable).
 - docs/ - system documentation; often contains historical programming notes that give insight into how specific subsystems were designed and why. Read relevant docs/ files before working on a subsystem — they frequently contain architectural context not obvious from the code alone.
 - public_html/ - the web site files, static assets
 - tests/ - test scripts used in debugging and troubleshooting
 - vendor/ - 3rd party libraries managed by composer and should not be touched by Claude
 - data/ - runtime data (binkp.json, nodelists.json, logs, inbound/outbound packets)
 - telnet/ - the telnet BBS server (separate from the web interface)
   - **IMPORTANT**: `telnet/telnet_daemon.php` and `ssh/ssh_daemon.php` manually `require_once` telnet-side classes from `telnet/src/`. New classes under `telnet/src/` are **not** Composer-autoloaded for those daemons. When adding a class there, update the `require_once` lists in both daemon entrypoints as needed.
   - **IMPORTANT**: Keep the SSH daemon include list in sync with the telnet daemon include list for shared terminal-side classes. If `telnet/telnet_daemon.php` gains a new `telnet/src/` include that SSH sessions also use, add the same include to `ssh/ssh_daemon.php`.

## Credits

 - **CREDITS.md must be kept up to date**: When merging commits from a new contributor, add them to the Contributors table. When adding a new vendor library via composer, add it to the Third-Party Libraries section with its license and authors.

## Important Notes
 - User authentication is simple username and password with long lived cookie
 - Both usernames and Real Names are considered unique. Two users cannot have the same username or real name
 - The web interface should use ajax requests by api for queries
 - This is for FTN style networks and forums
 - Always write out schema changes. A database will need to be created from scratch and schema/migrations are how it needs to be done. Migration scripts follow the naming convention v<VERSION>_<description>.sql, eg: v1.7.5_description.sql
 - When adding features to netmail and echomail, keep in mind feature parity. Ask for clarification about whether a feature is appropriate to both
 - **User settings parity**: When adding a new setting to `user_settings`, try to keep parity between the web UI/API flow and the term server flow. Check both the web-side handlers (for example `src/MessageHandler.php`, `routes/api-routes.php`, `public_html/js/app.js`) and the term-side settings path (`telnet/src/SettingsHandler.php`) so the setting is available and persisted consistently where appropriate.
 - **Premium features**: When adding, changing, or removing any registered-only / premium feature gated by `License::isValid()` or `License::hasFeature()`, update the "Currently Implemented Premium Features" table in `docs/proposals/PremiumFeatures.md` and remove it from the future ideas list if it was listed there.
 - Leave the vendor directory alone. It's managed by composer only
 - **Composer Dependencies**: When adding a new required package to composer.json, the UPGRADING_x.x.x.md document for that version MUST include instructions to run `composer install` before `php scripts/setup.php`. Without this, the upgrade will fail because `vendor/autoload.php` is loaded before setup.php runs.
 - **Upgrade docs TOC**: When creating or maintaining an `UPGRADING_x.y.z.md` document, always add or update its table of contents so the headings in that file remain navigable and in sync with the document.
 - **Upgrade doc format**: `UPGRADING_x.y.z.md` documents must start with a table of contents. The first table of contents entry must be a summary of changes section. That summary section must group changes by major feature area. After the summary of changes, include fuller descriptions of the changes, also grouped by major feature area.
 - **Upgrade doc voice**: Write UPGRADING documents as if the reader has no prior exposure to the development work, branch discussions, or the problems being fixed. Every change must be described self-contained — no phrases like "the previous issue with X", "as discussed", or "the fix for the problem where...". State what changed, why it matters, and what action the upgrader needs to take.
 - When updating style.css, also update the theme stylesheets: amber.css, dark.css, greenterm.css, and cyberpunk.css
 - **Theme-safe background colors**: Never use Bootstrap 5.3+ utility classes like `bg-body-tertiary` or `bg-body-secondary` — they have no theme overrides and will render incorrectly on dark/amber/greenterm/cyberpunk themes. Use `bg-light` instead, which all themes override via `.bg-light { background-color: var(--theme-var) !important; }`.
 - Database migrations are handled through scripts/setup.php. setup.php will also call upgrade.php which handles other upgrade related tasks.
 - Migrations can be SQL or PHP. Use the naming convention vX.Y.Z_description (e.g., v1.9.1.6_migrate_file_area_dirs.sql or .php). See `docs/DEVELOPER_GUIDE.md` for PHP migration patterns (direct execution vs callable).
 - **Migration version numbers**: Before creating a new migration, always check the highest existing version in `database/migrations/` (e.g., `ls database/migrations/ | sort -V | tail -5`). The new migration must be one increment higher than the highest version found — do NOT guess or use a version from a different branch of the version tree. For example, if the latest is `v1.11.0.5_*`, the next is `v1.11.0.6_*`, not `v1.10.19_*`.
 - **No duplicate indexes**: Do NOT create an explicit `CREATE INDEX` on a column that already has a `UNIQUE` constraint — PostgreSQL automatically creates a unique index for every `UNIQUE` constraint, which serves lookups identically to a plain index.
 - setup.php must be called when upgrading - this is to ensure certain things like file permissions are correct.
 - See FAQ.md for common questions and troubleshooting
 - To get a database connection use `$db = Database::getInstance()->getPdo()`
 - Don't edit postgres_schema.sql unless specifically instructed to. Database changes are typically migration based.
 - Avoid duplicating code. Whenever possible centralize methods using a class.
 - **Git Workflow**: Do NOT stage or commit changes until explicitly instructed. Changes should be tested first before committing to git.
 - When writing out a proposal document state in the preamble that the proposal is a draft, was generated by AI and may not have been reviewed for accuracy.
 - When writing proposal or other documentation files, use repo-relative paths like `src/Foo.php` or `docs/Bar.md` in the document text; do not use full filesystem paths.
 - **Documentation index**: When adding a new documentation file to `docs/` (excluding `docs/proposals/`), update `docs/index.md` to include it in the appropriate section in operational priority order. When creating a new `UPGRADING_x.y.z.md` file, also add it to the **Upgrading** section at the bottom of `docs/index.md`, newest-first.
 - **Service Worker Cache**: When making changes to CSS or JavaScript files, or when updating i18n language strings in `config/i18n/`, increment the CACHE_NAME version in public_html/sw.js (e.g., 'binkcache-v2' to 'binkcache-v3') to force clients to download fresh copies. The service worker caches static assets and the i18n catalog (`/api/i18n/catalog`) to bypass aggressive browser caching on mobile devices.
 - **BinkStream SharedWorker Build**: When making changes to `public_html/js/binkstream-worker-v2.js`, increment `WORKER_BUILD` in `public_html/js/binkstream-client.js`. This constant is embedded in both the worker URL (`?v=N`) and the worker name (`binkstream-vN`). Because SharedWorkers are shared across tabs and survive page reloads, the browser will keep running the old worker code until all tabs are closed — bumping `WORKER_BUILD` forces a new worker instance immediately without requiring users to close every tab.
 - **Timezone Display**: Dates and times are generally stored as UTC in the database. When presenting them to users, translate them to the user's own timezone unless there is a specific reason to show raw UTC.
 - **date_written vs date_received**: On `echomail` and `netmail`, `date_written` is derived from the FTN packet header (local time converted to UTC via the TZUTC kludge) and reflects when the sender composed the message — it can be wrong or in the future if the remote sysop's clock is incorrect. `date_received` is set server-side via `NOW() AT TIME ZONE 'UTC'` and is always reliable. Future-dated `date_written` values are hidden from message list queries until they are no longer in the future. When displaying dates to users, prefer `date_received` for ordering/display by default; show `date_written` with a tooltip that also includes `date_received` so discrepancies are visible.
 - **Charset columns**: The `message_charset` column on `echomail` and `netmail` stores the canonical iconv-compatible charset name (e.g. `CP437`, `UTF-8`) as normalized by `BinkpConfig::normalizeCharset()`. The raw `CHRS` value from the original FTN packet is preserved as-is in the `kludge_lines` column and may differ (e.g. `IBMPC`, `ASCII`). Always use `message_charset` for encoding/decoding operations and pre-selecting the charset UI; read `kludge_lines` only when you need the original wire value.
 - Write phpDoc blocks when possible

## PostgreSQL Gotchas

 - **Boolean handling:** When binding boolean values to prepared statements, convert them to strings `'true'` or `'false'` instead of using PHP boolean values. Example: `$isActive ? 'true' : 'false'`
 - **Insert IDs:** Do not use `PDO::lastInsertId()`. On PostgreSQL it calls `lastval()` which returns the last sequence value used in the **current session** — from *any* sequence, not necessarily the row you just inserted. Always use `RETURNING id` and fetch the returned row directly:
   ```php
   // WRONG — do not rely on session-wide lastval()/lastInsertId()
   $stmt->execute();
   $id = $this->db->lastInsertId();

   // CORRECT — fetch the inserted row's id directly
   $stmt->execute();
   $row = $stmt->fetch(\PDO::FETCH_ASSOC);
   $id = $row ? (int)$row['id'] : 0;
   ```

## Internationalization (i18n) & Encoding Policy
- **Strict UTF-8 (No BOM):** All i18n catalogs and source files must be saved as UTF-8 without a Byte Order Mark.
- **Accent Handling:** When editing French or other accented catalogs, use literal characters (e.g., 'é', 'à') only. Never use HTML entities (e.g., &eacute;) or Unicode escape sequences unless explicitly requested.
- **No Emojis/4-Byte Characters:** Strictly prohibit the use of Emojis or any character outside the Basic Multilingual Plane (U+0000 to U+FFFF). These break legacy FTN/BBS terminal rendering.
- **Verification Step:** Before completing a translation task, verify that you haven't "double-encoded" characters (e.g., ensuring 'é' doesn't become 'Ã©').

## Localization (i18n) Workflow

The project uses key-based localization for both Twig and JavaScript. Translation catalogs live in:
- `config/i18n/<locale>/common.php`
- `config/i18n/<locale>/errors.php`

Current locale folders under `config/i18n/` must be kept in sync when adding or renaming keys. Do not update only the language you are actively reading; French keys are easy to miss, so check all locale directories every time.

### Core Rules
- Never hardcode new user-facing UI text in templates/JS when adding or changing features.
- Add a translation key first, then use it from Twig/JS.
- Keep every locale in `config/i18n/` in sync for every new key in normal feature work, including `fr` when present.
- Prefer stable key names by page/feature area, e.g. `ui.settings.*`, `ui.polls.*`, `errors.polls.*`.
- Do not change existing key names unless required (avoid breaking references).

### Twig Translation
- Use the global Twig function: `t(key, params, namespace)`.
- Default namespace is `common`; pass `'errors'` when needed.
- Example:
```twig
{{ t('ui.settings.title', {}, 'common') }}
{{ t('ui.polls.create.submit', {'cost': poll_cost}, 'common') }}
```

### JavaScript Translation
- Use `window.t(key, params, fallback)` (or a local wrapper like `uiT`).
- Use placeholders in strings and pass params object:
```js
window.t('ui.polls.create.submit', { cost: 25 }, 'Create Poll ({cost} credits)')
```
- `window.i18n` supports lazy namespace loading via:
  - `loadI18nNamespaces([...])`
  - endpoint: `GET /api/i18n/catalog?ns=common,errors&locale=<locale>`
- JS should always include a fallback string for resilience.
- Treat fallback strings as localized UI text too: if you introduce a new fallback in JS/Twig, add the matching `ui.*` or `errors.*` key to every locale directory first instead of inventing a raw English string inline.
- Do not pass new raw English strings directly to helpers like `apiError(...)`, `getApiErrorMessage(...)`, `window.t(...)`, `uiT(...)`, toast helpers, or modal helpers; `php scripts/check_i18n_hardcoded_strings.php` will flag those.

### API Errors and `error_code`
- API responses should use structured errors via `apiError(error_code, message, status, extra)` and return:
  - `error_code` (translation key)
  - `error` (human fallback)
- Frontend should resolve display text with `window.getApiErrorMessage(payload, fallback)`.
- Do not rely on matching raw error message text in frontend logic.
- When wiring frontend error handling, prefer an existing translation key for the fallback; if none exists, add one before writing the JS/Twig.
- For new API errors:
  1. Add/choose `errors.*` key in route code.
  2. Add that key to every locale's `errors.php` file under `config/i18n/`.
  3. Use `getApiErrorMessage` in UI handling.

### Required Validation After i18n Changes
- Run:
  - `php scripts/check_i18n_hardcoded_strings.php`
  - `php scripts/check_i18n_error_keys.php`
- Goal:
  - no new hardcoded string violations
  - no missing `errors.*` catalog keys used by `apiError(...)`
  - no locale folders left behind when adding keys, especially `config/i18n/fr/`

### Practical Checklist for New UI/API Work
1. Add new `ui.*`/`errors.*` keys to every locale under `config/i18n/` (`en`, `es`, `fr`, etc.).
2. Replace literals in Twig with `t(...)`.
3. Replace JS literals with `window.t(...)` (or `uiT(...)`) fallbacks, but do not invent a new inline fallback unless the corresponding catalog key was added first.
4. Ensure API errors return `error_code`.
5. Run both i18n check scripts before commit.

## URL Construction

Always use `Config::getSiteUrl()` for full URL construction — it correctly handles HTTPS proxies via the `SITE_URL` env var:

```php
$url = \BinktermPHP\Config::getSiteUrl() . '/path/to/resource';
```

## Admin Daemon

**The web server process cannot write config files.** Any feature that needs to write `config/lovlynet.json`, `config/binkp.json`, `data/bbs.json`, or similar **must** do so via a command to the admin daemon (`scripts/admin_daemon.php`) — never by calling `file_put_contents()` directly from a route or controller.

When adding new configuration settings, you may need to add or update admin daemon commands. Clarify this before implementing.

## Credits System Workflow

When adding new `UserCredit` credit/reward types, update these five places (see `docs/CreditSystem.md` for full details):
1. Code defaults in `src/UserCredit.php` (`getCreditsConfig()` → `$defaults` array)
2. `bbs.json.example` credits section
3. Admin twig template: `templates/admin/bbs_settings.twig` (form field + `loadBbsSettings()` + `saveBbsCredits()`)
4. Admin API endpoint: POST `/admin/api/bbs-settings` in `routes/admin-routes.php`
5. `README.md` credits documentation

Configuration priority: `data/bbs.json` > `bbs.json.example` > code defaults in `src/UserCredit.php`.

### Credit Transaction Security

**CRITICAL**: Credit balance modifications must ONLY occur server-side in PHP. JavaScript requests business actions; the server decides whether credits are involved and performs all transactions internally.

```text
❌ POST /api/credits/deduct   (never expose credit endpoints to JS)
✅ POST /api/webdoor/game/buy-item  (server handles credits internally, returns new balance)
```

JS may display the balance value returned by the server and communicate it to parent windows via `postMessage`. It must never calculate or request credit modifications.

## WebDoors

WebDoors are HTML5/JavaScript games embedded in the BBS. See `docs/WebDoors.md` for the full specification.

**Rules when working on WebDoors:**
- Each WebDoor must include a valid `webdoor.json` manifest.
- WebDoors must include the SDK as their first require: `require_once __DIR__ . '/../_doorsdk/php/helpers.php';` — do NOT require `vendor/autoload.php` directly.
- **API Independence**: Each WebDoor implements its own API routes. Do NOT add WebDoor functionality to `routes/api-routes.php` or `routes/web-routes.php` unless explicitly instructed. WebDoor APIs belong in their own route files (e.g. `routes/webdoor-netrealm-routes.php`) or `WebDoorController`.
- When changing the WebDoor system (not individual games), update `docs/WebDoors.md`.

## Version Management

Application version (`src/Version.php`) and database migration versions (`database/migrations/`) are independent. Database versions can be `1.2.3` or `1.2.3.4`; application versions use `1.2.3`.

### How to Update the Version

#### 1. Update the Version Constant
Edit `src/Version.php` and change the `VERSION` constant:
```php
private const VERSION = '1.4.3';  // Update this line
```
Everything else (tearlines, footer, API responses, Twig templates) picks it up automatically.

#### 2. Update composer.json
```json
{
    "name": "binkterm-php/fidonet-web",
    "version": "1.4.3",
    ...
}
```

#### 3. Commit — do NOT create a tag
```bash
git add src/Version.php composer.json
git commit -m "Bump version to 1.4.3"
git push origin main
```

#### 4. Create UPGRADING doc
Create `docs/UPGRADING_x.x.x.md` with upgrade instructions. Link it from `README.md` and the Upgrading section of `docs/index.md`.
