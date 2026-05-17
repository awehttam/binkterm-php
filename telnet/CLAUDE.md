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

**Terminal-side code must use the REST API for all data access.** Code under `telnet/src/` and `ssh/` must never access the database directly, never `require` web-side classes from `src/`, and never call web-side helpers. All reads and writes go through HTTP calls to the local API using `TelnetUtils::apiRequest()`.

```text
❌ $db = Database::getInstance()->getPdo();    (direct DB — forbidden)
❌ BulletinManager::getUnreadCount($userId);   (web-side class — forbidden)
✅ TelnetUtils::apiRequest($base, 'GET', '/api/...', null, $session);
```

When a feature needs data that the existing API does not expose, add or extend an endpoint on the web side first, then call it from the terminal side.
