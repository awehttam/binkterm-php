# Project: binkterm-php

> **Note:** The internal/repository name is `binkterm-php`, but the product name is **BinktermPHP**. Use "BinktermPHP" in all user-facing text, documentation, and UI copy.

## Project Description

BinktermPHP is a multi-protocol BBS platform built around native FTN messaging. It provides a full browser-based community interface with a native BinkP mailer, a real-time event bus, and a door game framework — accessible from browsers, Telnet/SSH terminals, Gemini clients, QWK readers, AI assistants, and mesh radio nodes. No third-party mailer required.

 - **Product website**: https://lovelybits.org/binktermphp
 - **GitHub repo**: https://github.com/awehttam/binkterm-php
 - **Support BBS**: https://claudes.lovelybits.org
 - **Reddit**: https://www.reddit.com/r/BinktermPHP/
 - **Product Mascot**: Kludge the Corvid
 - **Default PR target branch**: `claudesbbs` — all pull requests must target this branch, not `main`
 - **New development branches**: When creating a new branch, always ask the user whether to branch from `claudesbbs` (main staging) or another development branch. Never assume `main`.

## Tech Stack

 - Frontend: jQuery, Bootstrap 5
 - Backend: PHP 8.2+ (requires minimum 8.2), SimpleRouter request library, Twig templates
 - Database: Postgres
 - Dependencies: Composer
 - Real-time: BinkStream (WebSocket + SSE via SharedWorker)


## Code Conventions

 - camelCase for variables and functions
 - PascalCase for components and classes
 - 4 space indents
 - **Markdown over HTML**: Prefer Markdown for any user-facing customizable text fields (admin settings, content panels, footers, etc.). Only use raw HTML when Markdown cannot express the needed formatting.
 - **Environment Variables**: Use `Config::env('VAR_NAME', 'default')` for application configuration values that come from the project's `.env` file. Direct `getenv()` reads are allowed for process/runtime environment inspection that is not part of BinktermPHP config resolution, such as searching the OS `PATH` inside a daemon helper. Do NOT use `$_ENV` directly for application config.
 - **Client-side storage**: Always use `UserStorage` (from `public_html/js/user-storage.js`) instead of `localStorage` directly. `UserStorage` automatically scopes keys by the logged-in user's ID so that different accounts on the same browser cannot share state. When not logged in it falls back to `sessionStorage` to avoid persisting anonymous state.

## Logging

**Never use `error_log()` for web/application logging** (except inside `src/Binkp/Logger.php` itself and `src/Admin/AdminDaemonClient.php` where it is the last-resort fallback). All web-side and shared application logging must go through `BinktermPHP\Binkp\Logger`. Use `getServerLogger()` in route files. Exception: self-contained daemon/runtime subsystems that do not run in the web request context, such as the Telnet/ZMODEM runtime under `telnet/src/`, may use targeted `error_log(..., 3, $file)` file logging when that is the established subsystem-local mechanism. When wiring up a new component, invoke the `/logging-guide` skill.

## Project Structure

 - src/ - main source code
 - routes/ - HTTP route definitions (api-routes.php, web-routes.php, admin-routes.php, etc.)
 - config/ - runtime configuration files (binkp.json, bbs.json, webdoors.json, etc.) and i18n catalogs
 - scripts/ - CLI tools (binkp_server, binkp_poll, maintenance scripts, etc.). See `scripts/CLAUDE.md` for CLI script rules.
 - templates/ - html templates. See `templates/CLAUDE.md` for template resolution rules.
 - docs/ - system documentation; often contains historical programming notes that give insight into how specific subsystems were designed and why. Read relevant docs/ files before working on a subsystem — they frequently contain architectural context not obvious from the code alone.
 - public_html/ - the web site files, static assets
 - tests/ - PHPUnit and Playwright test suites
 - vendor/ - 3rd party libraries managed by composer and should not be touched by Claude
 - data/ - runtime data (logs, inbound/outbound FTN packets)
 - telnet/ - the telnet BBS server (separate from the web interface). See `telnet/CLAUDE.md` for daemon include-list rules.
 - ssh/ - the SSH daemon; shares terminal-side classes with the telnet daemon. See `ssh/CLAUDE.md`.
 - dosbox-bridge/ - DOS door runtime data and bridge
 - tools/ - support and utility tools (e.g. support-bot)

## Credits

 - **CREDITS.md must be kept up to date**: When merging commits from a new contributor, add them to the Contributors table. When adding a new vendor library via composer, add it to the Third-Party Libraries section with its license and authors.

## Important Notes
 - **Admin UI over config files**: In documentation and user-facing instructions, always direct users to configure settings through the BBS admin web interface rather than by manually editing JSON config files (`binkp.json`, `bbs.json`, `webdoors.json`, etc.). Only fall back to describing direct file edits when the feature genuinely has no admin UI, or when writing developer/contributor documentation where direct file access is appropriate. Some settings that were historically configured in `binkp.json` per-uplink (e.g. `allow_markup`, `allow_media`, `default_charset`, `posting_name_policy`) have since moved to **Admin → Networks**; the uplink-level flags are kept only for backwards compatibility. Always document the Networks UI as the current path.
 - User authentication is simple username and password with long lived cookie
 - Both usernames and Real Names are considered unique. Two users cannot have the same username or real name
 - The web interface should use ajax requests by api for queries
 - This is for FTN style networks and forums
 - When adding features to netmail and echomail, keep in mind feature parity. Ask for clarification about whether a feature is appropriate to both
 - **User settings parity**: When adding a new setting to `user_settings`, try to keep parity between the web UI/API flow and the term server flow. Check both the web-side handlers (for example `src/MessageHandler.php`, `routes/api-routes.php`, `public_html/js/app.js`) and the term-side settings path (`telnet/src/SettingsHandler.php`) so the setting is available and persisted consistently where appropriate.
 - **Premium features**: When adding, changing, or removing any registered-only / premium feature, gate it with `License::isValid()`. Do **not** use `License::hasFeature()` — it is not yet implemented. Update the "Currently Implemented Premium Features" table in `docs/proposals/PremiumFeatures.md` and remove it from the future ideas list if it was listed there.
 - Leave the vendor directory alone. It's managed by composer only
 - When updating style.css, also update the theme stylesheets: amber.css, dark.css, greenterm.css, and cyberpunk.css
 - **Theme-safe background colors**: Never use Bootstrap 5.3+ utility classes like `bg-body-tertiary` or `bg-body-secondary` — they have no theme overrides and will render incorrectly on dark/amber/greenterm/cyberpunk themes. Use `bg-light` instead, which all themes override via `.bg-light { background-color: var(--theme-var) !important; }`.
 - **Service Worker Cache**: When making changes to CSS or JavaScript files, or when updating i18n language strings in `config/i18n/`, increment the CACHE_NAME version in public_html/sw.js (e.g., 'binkcache-v2' to 'binkcache-v3') to force clients to download fresh copies. The service worker caches static assets and the i18n catalog (`/api/i18n/catalog`) to bypass aggressive browser caching on mobile devices.
 - **BinkStream SharedWorker Build**: When making changes to `public_html/js/binkstream-worker-v2.js`, increment `WORKER_BUILD` in `public_html/js/binkstream-client.js`. This constant is embedded in both the worker URL (`?v=N`) and the worker name (`binkstream-vN`). Because SharedWorkers are shared across tabs and survive page reloads, the browser will keep running the old worker code until all tabs are closed — bumping `WORKER_BUILD` forces a new worker instance immediately without requiring users to close every tab.
 - **Timezone Display**: Dates and times are generally stored as UTC in the database. When presenting them to users, translate them to the user's own timezone unless there is a specific reason to show raw UTC.
 - **date_written vs date_received**: On `echomail` and `netmail`, `date_written` is derived from the FTN packet header (local time converted to UTC via the TZUTC kludge) and reflects when the sender composed the message — it can be wrong or in the future if the remote sysop's clock is incorrect. `date_received` is set server-side via `NOW() AT TIME ZONE 'UTC'` and is always reliable. Future-dated `date_written` values are hidden from message list queries until they are no longer in the future. When displaying dates to users, prefer `date_received` for ordering/display by default; show `date_written` with a tooltip that also includes `date_received` so discrepancies are visible.
 - **Charset columns**: The `message_charset` column on `echomail` and `netmail` stores the canonical iconv-compatible charset name (e.g. `CP437`, `UTF-8`) as normalized by `BinkpConfig::normalizeCharset()`. The raw `CHRS` value from the original FTN packet is preserved as-is in the `kludge_lines` column and may differ (e.g. `IBMPC`, `ASCII`). Always use `message_charset` for encoding/decoding operations and pre-selecting the charset UI; read `kludge_lines` only when you need the original wire value.
 - See FAQ.md for common questions and troubleshooting
 - To get a database connection use `$db = Database::getInstance()->getPdo()`
 - Don't edit postgres_schema.sql unless specifically instructed to. Database changes are migration-based; invoke the `/new-migration` skill when creating a migration.
 - Avoid duplicating code. Whenever possible centralize methods using a class.
 - **Git Workflow**: Do NOT stage or commit changes until explicitly instructed. Changes should be tested first before committing to git.
 - When writing out a proposal document state in the preamble that the proposal is a draft, was generated by AI and may not have been reviewed for accuracy.
 - When writing proposal or other documentation files, use repo-relative paths like `src/Foo.php` or `docs/Bar.md` in the document text; do not use full filesystem paths.
 - **Doc maintenance**: When adding features that touch a subsystem with a dedicated `docs/` file, **you must update that file**. Consult the **Doc Maintenance Checklist** in `docs/DEVELOPER_GUIDE.md` for the full list of subsystem→doc pairings. When adding a new documentation file to `docs/` (excluding `docs/proposals/`), update `docs/index.md` to include it in operational priority order; when creating a new `UPGRADING_x.y.z.md` file, also add it to the Upgrading section of `docs/index.md`, newest-first.
 - For version bump steps and UPGRADING doc format, invoke the `/bump-version` skill.
 - **UPGRADING doc content rules**: Do not include sentences stating that no configuration is needed, no sysop action is required, or no migration is required. These are filler — omit them entirely.
 - When creating or modifying a WebDoor, invoke the `/new-webdoor` skill.
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
- **ASCII quotes only in PHP catalog files:** PHP string delimiters must be ASCII single quotes (U+0027). **U+2018 `'` and U+2019 `'` (curly/smart quotes) must never appear anywhere in a catalog file** — not as delimiters, not as apostrophes in values. This is an absolute rule with no exceptions. PHP 8 treats these characters as Unicode identifier bytes, so a curly quote used as a key delimiter passes `php -l` cleanly but throws a fatal `Undefined constant` at runtime. A curly quote used as an apostrophe inside a value is "technically valid" PHP today but is fragile: the Edit tool can reproduce it as a delimiter when it rewrites the surrounding context, even on lines whose values contain no apostrophe at all.
- **Apostrophes in catalog values must be escaped:** Any apostrophe inside a PHP string value must be written as `\'`. Never use a raw ASCII `'` (terminates the string early) or a curly quote U+2019 (see rule above). This applies to all locales, especially French (`l\'`, `d\'`, `qu\'`) and Italian (`l\'`, `all\'`, `dell\'`). When editing any catalog line, always write apostrophes as `\'` in your replacement regardless of how they appeared before.
- **Lint every catalog file you touch:** After writing or editing any `config/i18n/` PHP file, run `php scripts/check_i18n_syntax.php --locale=<locale>` to confirm it passes all three checks (parse, runtime-include, and curly-quote byte scan) before committing. `php -l` alone is not sufficient — it does not catch the curly-quote delimiter class of error.

## Localization (i18n) Workflow

The project uses key-based localization for both Twig and JavaScript. Translation catalogs live in:
- `config/i18n/<locale>/common.php`
- `config/i18n/<locale>/errors.php`

Current locale folders under `config/i18n/` must be kept in sync when adding or renaming keys. Do not update only the language you are actively reading; French keys are easy to miss, so check all locale directories every time.

**Adding a new language:** New locales are typically added by dropping a `config/i18n/<code>/` directory with translated `common.php` and `errors.php` files. When this happens, also add the locale code and its native display name to the `$names` map in `Translator::getLocaleName()` (`src/I18n/Translator.php`) so it appears correctly in language selectors across both the web and terminal interfaces.

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
6. **API route changes**: When adding, removing, or modifying routes in `routes/api-routes.php`, update `docs/API.md` to reflect the change.

## URL Construction

Always use `Config::getSiteUrl()` for full URL construction — it correctly handles HTTPS proxies via the `SITE_URL` env var:

```php
$url = \BinktermPHP\Config::getSiteUrl() . '/path/to/resource';
```

## Admin Daemon

**The web server process cannot write config files.** Any feature that needs to write `config/lovlynet.json`, `config/binkp.json`, `data/bbs.json`, or similar **must** do so via a command to the admin daemon (`scripts/admin_daemon.php`) — never by calling `file_put_contents()` directly from a route or controller.

When adding new configuration settings, you may need to add or update admin daemon commands. Clarify this before implementing.

## Credit Transaction Security

**CRITICAL**: Credit balance modifications must ONLY occur server-side in PHP. JavaScript requests business actions; the server decides whether credits are involved and performs all transactions internally.

```text
❌ POST /api/credits/deduct   (never expose credit endpoints to JS)
✅ POST /api/webdoor/game/buy-item  (server handles credits internally, returns new balance)
```

JS may display the balance value returned by the server and communicate it to parent windows via `postMessage`. It must never calculate or request credit modifications.

When adding a new UserCredit credit/reward type, invoke the `/usercredits-workflow` skill.

## Skills

When working within any subdirectory, check for the presence of a `CLAUDE.md` file in that directory and follow its instructions in addition to this file.

The following project-scoped skills are available in `.claude/commands/`. When adding a new skill file, add it to this list.

- **`/bump-version`** — version bump steps, UPGRADING doc format and voice rules, composer dependency note
- **`/new-migration`** — migration ID format (authoritative), SQL vs PHP choice, no-duplicate-index rule, setup.php reminder
- **`/usercredits-workflow`** — 5-place checklist for adding new UserCredit types
- **`/logging-guide`** — log file table, per-context code patterns, log levels, adding a new log file
- **`/new-webdoor`** — manifest requirement, SDK require path, API independence rule
- **`/tackleissue <issue#>`** — assign, plan, implement, and close a GitHub issue
- **`/newftn`** — prompt for FTN details and create a migration to insert or update the network
