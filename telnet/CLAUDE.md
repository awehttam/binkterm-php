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

## Reusable UI Widgets

**Always use existing `TelnetUtils` widgets before writing custom UI.** The utility class provides a set of high-level, tested components for all common terminal UI patterns. Duplicating their logic in handler code creates inconsistency and bugs.

| Need | Use |
|------|-----|
| Full-screen scrollable message reader (Ctrl-K help overlay built in) | `TelnetUtils::runMessageViewer()` |
| Paginated selectable list with keyboard nav | `TelnetUtils::runSelectableList()` |
| Pre-built message list with compose/read actions | `TelnetUtils::runMessageList()` |
| Scrollable kludge/header overlay | `TelnetUtils::runKludgeViewer()` (called internally by viewer) |
| Sixel image viewer | `TelnetUtils::showSixelImageViewer()` |
| Message header box (From/To/Date/Subj) | `TelnetUtils::buildMessageHeaderBox()` |
| Status bar from segments array | `TelnetUtils::buildStatusBar()` |
| Centered confirmation dialog overlay | `TelnetUtils::showConfirmDialog()` |
| Centered alert/notice dialog (Enter to dismiss, color-coded info/error) | `TelnetUtils::showAlertDialog($conn, $state, $server, $title, $message, $style)` — `$style` is `'info'` (blue) or `'error'` (red) |
| Centered "please wait" overlay (draws immediately, no key read; overdraw with showAlertDialog when done) | `TelnetUtils::showWorkingOverlay($conn, $state, $server, $message)` |
| ANSI-wrapped text lines | `TelnetUtils::wrapTextLines()` |
| Address book / nodelist picker (search → select → return name+address) | `TelnetUtils::runAddressPicker()` |

### Status bar discipline

The bottom status bar has limited width. Keep it to the **most-used primary actions only** — typically scroll, prev/next, reply, and quit. Every other key belongs exclusively in the Ctrl-K help overlay; do not put secondary keys in both places.

```
✅ Status bar:  U/D Scroll  L/R Prev/Next  R Reply  Ctrl-K Help  Q Quit
❌ Status bar:  U/D Scroll  PgUp/PgDn Page  L/R Prev/Next  R Reply  H Headers  X Delete  B Bookmark  T .txt  Q Quit
```

**Ctrl-K is the universal help key** across all terminal contexts (message viewer, editor, chat). When adding a new key binding to any message viewer:

1. Add it to the `$helpItems` array passed to `runMessageViewer()` so it appears in the Ctrl-K overlay.
2. Only add it to the status bar `$segments` if it truly belongs among the handful of primary actions.

### Adding actions to the message viewer

`runMessageViewer()` accepts an `$extraKeys` array that maps lowercase characters to action name strings, and a `$helpItems` array listing all key bindings for the Ctrl-K overlay. Use these instead of wrapping the viewer in custom key-reading logic:

```php
// Build the full key list for the Ctrl-K help overlay:
$helpItems = [
    ['key' => 'PgUp / PgDn', 'label' => $this->server->t('ui.terminalserver.message.help_page', 'Scroll one page', [], $locale)],
    ['key' => 'X',           'label' => $this->server->t('ui.terminalserver.netmail.help_delete', 'Delete message', [], $locale)],
    // ... all keys, including those not shown in the status bar
];

// In $buildView closure — status bar shows primary actions only:
$segments[] = ['text' => 'Ctrl-K',  'color' => TelnetUtils::ANSI_RED];
$segments[] = ['text' => ' Help  ', 'color' => TelnetUtils::ANSI_BLUE];

// Pass both to the viewer:
$result = TelnetUtils::runMessageViewer(
    $conn, $state, $this->server,
    $view['headerLines'], $view['wrappedLines'], $view['statusLine'],
    $state['rows'] ?? 24, 0, $allowDownload,
    $kludgeLines, $buildView, $imageRefs, $imageFn,
    ['x' => 'delete'],  // $extraKeys
    $helpItems          // $helpItems — shown in Ctrl-K overlay
);

// Handle in the switch:
case 'delete':
    $this->doDelete(...);
    break;
```

### Terminal resize

**All full-screen UI must respond to terminal resize events.** When a user resizes their terminal window, the client sends a NAWS (Negotiate About Window Size) update that lands in `$state['rows']` and `$state['cols']` during the next `readKeyWithIdleCheck()` call.

The standard pattern is to snapshot dimensions before the key loop, then compare after each read:

```php
$lastRows = $state['rows'] ?? 24;
$lastCols = $state['cols'] ?? 80;

while (true) {
    $key = $server->readKeyWithIdleCheck($conn, $state);

    $newRows = $state['rows'] ?? $lastRows;
    $newCols = $state['cols'] ?? $lastCols;
    if ($newRows !== $lastRows || $newCols !== $lastCols) {
        $lastRows = $newRows;
        $lastCols = $newCols;
        // recalculate layout, then redraw
        $rebuildLayout();
        $render();
    }

    // ... normal key handling ...
}
```

Layout-dependent variables (frame dimensions, wrapped line counts, status bar width, border strings) must be recalculated on every resize. Capture them by reference (`&$var`) in any render closure so a single `$rebuildLayout()` call propagates the new dimensions without recreating the closure.

`runMessageViewer()` already handles this via its `$rebuildFn` callback — pass a `$buildView` closure and the widget manages resize internally. Custom full-screen loops in handlers must implement the pattern above themselves.

### Extending widgets

If a widget genuinely lacks a capability needed by multiple features, extend it in `TelnetUtils` — don't work around it in the handler. The `$extraKeys` mechanism itself is an example of this: it was added once so all handlers can use it.

**When adding a new reusable widget or extending an existing one, update the widget table above in this file.** The table is the first place anyone looks before writing new terminal UI code — keep it current.

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

### apiRequest response structure

`TelnetUtils::apiRequest()` always returns:

```php
[
    'status' => $httpCode,   // int HTTP status code (200, 400, 404, …)
    'data'   => $parsedBody, // the decoded JSON body — never the root array itself
    'error'  => null|string,
]
```

**The parsed JSON body is always one level deep under `['data']`.** Never access top-level keys from the API response directly on the return value:

```php
// WRONG — 'messages' is in the JSON body, not at the apiRequest return level
$messages = $response['messages'] ?? [];

// CORRECT
$messages = $response['data']['messages'] ?? [];
$item     = $response['data']['item'] ?? null;
```

This mistake produces silently empty results with no error, which makes it hard to diagnose.

### CSRF tokens are required for all mutating API calls

**Every POST, PUT, PATCH, or DELETE call via `TelnetUtils::apiRequest()` must pass the CSRF token** as the 7th argument — omitting it causes a 403. The token lives in `$state['csrf_token']`:

```php
// CORRECT
TelnetUtils::apiRequest($this->apiBase, 'POST', '/api/some/endpoint', $payload, $session, 3, $state['csrf_token'] ?? null);
TelnetUtils::apiRequest($this->apiBase, 'DELETE', '/api/some/endpoint', null, $session, 3, $state['csrf_token'] ?? null);

// WRONG — 403 at runtime, no error at write time
TelnetUtils::apiRequest($this->apiBase, 'POST', '/api/some/endpoint', $payload, $session);
```

GET requests do not need the token.

```text
✅ TelnetUtils::apiRequest($base, 'GET', '/api/user/settings', null, $session);
✅ $doors = (new DoorManager())->getEnabledDoors();
✅ $db = Database::getInstance()->getPdo(); // narrow internal read/query when appropriate

⚠ Direct SQL writes that duplicate existing business logic are usually the wrong choice
⚠ New public API endpoints created only to let the termserver perform an internal-only action are usually the wrong choice

❌ Calling controllers, form handlers, or web-only helpers from terminal code
❌ Reimplementing business rules in terminal-side SQL when a shared service already owns them
❌ POST/DELETE via apiRequest() without passing $state['csrf_token'] ?? null as the 7th argument
```
