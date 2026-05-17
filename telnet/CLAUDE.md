# Telnet Daemon

- `telnet/telnet_daemon.php` and `ssh/ssh_daemon.php` manually `require_once` telnet-side classes from `telnet/src/`. New classes under `telnet/src/` are **not** Composer-autoloaded for those daemons. When adding a class there, update the `require_once` lists in both daemon entrypoints as needed.
- Keep the SSH daemon include list in sync with the telnet daemon include list for shared terminal-side classes. If `telnet/telnet_daemon.php` gains a new `telnet/src/` include that SSH sessions also use, add the same include to `ssh/ssh_daemon.php`.

## Documentation

**Keep `docs/TerminalServer.md` up to date.** When adding, changing, or removing any terminal server feature (new menu actions, key handling, session flow, API endpoints used, screen slots, editor controls, or platform behaviour), update the relevant section of `docs/TerminalServer.md` as part of the same change. This document is the primary reference for the shared BBS session model and its features.

## Adding / Modifying / Removing Main Menu Actions

Menu actions are data-driven via the key map in `AppearanceConfig`. Every action touches **all** of the following — missing any one leaves the system in an inconsistent state:

1. **`src/AppearanceConfig.php`** — add/remove the action ID and its default key in `DEFAULT_TERM_MENU_KEYS`.
2. **`telnet/src/BbsSession.php`** — four touchpoints in `BbsSession::handle()`:
   - Key variable: `$<action>Key = $menuKeys['<action>'] ?? null;` (or gated on a feature flag if the action can be disabled)
   - `$keyToAction` foreach: add/remove the `'<action>' => $<action>Key` entry
   - Label variable + display row: add `$lbl<Action>` (from `ui.terminalserver.server.menu.<action>`) and place the `menuItemCol` call in the appropriate section of the `$rows` builder
   - Dispatch branch: `elseif ($action === '<action>') { ... }`
3. **`templates/admin/appearance.twig`** — add/remove the action in both `TERM_MENU_KEY_DEFAULTS` and `TERM_MENU_KEY_LABELS` JS objects in the "Terminal Main Menu Keys" script block.
4. **`config/i18n/*/common.php`** — add/remove `ui.admin.appearance.term_menu_keys.action.<action>` in **every** locale directory (admin UI label).
5. **`config/i18n/*/terminalserver.php`** — add/remove `ui.terminalserver.server.menu.<action>` in **every** locale directory (built-in text menu label).
6. **`docs/TerminalServer.md`** — update the default key table in the "Customizable Main Menu Keys" section.

The admin save route (`POST /api/admin/appearance/term-menu-keys`) derives its valid action list from `array_keys(AppearanceConfig::DEFAULT_TERM_MENU_KEYS)` automatically — no route change needed when adding an action.

## Data Access

The terminal server is a trusted first-party runtime component of BinktermPHP. Code under `telnet/src/` and `ssh/` does not need to treat the rest of the application as a strictly remote system, but it must still respect clear boundaries.

Daemon/runtime exceptions:

- Terminal-side logout should normally use the existing authenticated API logout flow so session teardown stays aligned with the web behavior.
- Subsystem-local daemon logging may use targeted `error_log(..., 3, $file)` file logging when it is intentionally isolated from the web logging stack, such as ZMODEM transfer logs under `telnet/src/`.
- Direct `getenv()` reads are acceptable for process/runtime inspection that is not part of `.env`-backed application config resolution, such as searching the OS `PATH` for external transfer helpers.

Choose the access path that best fits the feature:

- Prefer `TelnetUtils::apiRequest()` when terminal code is consuming an existing API-shaped application feature, especially user-facing flows that should behave the same way as the web client.
- Reuse shared business logic, domain services, and transport-agnostic utility classes from `src/` when that is the safest and most maintainable way to share behavior with the web side.
- Direct database access is acceptable, but it should stay narrow. Use it mainly for simple internal reads or small internal queries where introducing or extending an API or service would add unnecessary complexity.

Where both are practical, prefer reusing an existing shared service or domain class over writing new direct SQL in terminal code.

Authentication and session flows should normally use the existing API endpoints so terminal and web behavior remain consistent. Direct reuse of shared auth/session logic is acceptable only for clear internal-only cases and must not depend on browser-specific request handling.

Do not bypass important business rules. If validation, permissions, side effects, or invariants already live in a shared service or domain class, terminal code should use that logic instead of reimplementing the behavior with ad hoc SQL.

Terminal code may use shared internal classes from `src/` when they are transport-agnostic or encapsulate reusable business logic. Terminal code must not directly depend on web controllers, route handlers, Twig/view helpers, form handlers, middleware, or other code that assumes a browser-driven HTTP request lifecycle.

Calling authenticated local API endpoints through `TelnetUtils::apiRequest()` is acceptable, including endpoints protected by CSRF, because the terminal server handles that protocol explicitly.

```text
✅ TelnetUtils::apiRequest($base, 'GET', '/api/user/settings', null, $session);
✅ $doors = (new DoorManager())->getEnabledDoors();
✅ $db = Database::getInstance()->getPdo(); // narrow internal read/query when appropriate

⚠ Direct SQL writes that duplicate existing business logic are usually the wrong choice
⚠ New public API endpoints created only to let the termserver perform an internal-only action are usually the wrong choice

❌ Calling controllers, form handlers, or web-only helpers from terminal code
❌ Reimplementing business rules in terminal-side SQL when a shared service already owns them
```
