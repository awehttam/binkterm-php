# Terminal Server Developer Guide

Architectural and implementation reference for developers working on the BinktermPHP terminal server. For the user-facing feature reference and sysop configuration options see [TerminalServer.md](TerminalServer.md).

---

## Key Source Files

| Path | Role |
|------|------|
| `telnet/telnet_daemon.php` | Telnet daemon entry point — accepts connections, forks children, bootstraps `BbsSession` |
| `ssh/ssh_daemon.php` | SSH daemon entry point — shares the same `BbsSession` flow and `telnet/src/` handler classes |
| `telnet/src/BbsSession.php` | Core session class — owns the main menu loop, login flow, and handler dispatch |
| `telnet/src/TelnetUtils.php` | Shared utility class — all reusable widgets, ANSI helpers, `apiRequest()`, `buildStatusBar()`, `buildMessageHeaderBox()`, and `getDefaultStyleProfile()` |
| `telnet/src/TelnetServer.php` | Low-level telnet I/O — option negotiation, NAWS, key reading, idle check |
| `telnet/src/TerminalShellInterface.php` | Shell abstraction interface |
| `telnet/src/TuiShell.php` | Full-screen framed shell implementation |
| `telnet/src/LineShell.php` | Plain-text prompt-driven shell implementation |
| `telnet/src/TerminalShellFactory.php` | Selects TuiShell or LineShell based on terminal size and user setting |
| `telnet/src/*Handler.php` | Feature handlers — one per BBS section (netmail, echomail, files, doors, etc.) |
| `telnet/src/MailUtils.php` | Shared compose and drafts flow used by both netmail and echomail handlers |
| `telnet/src/TerminalBoxRenderer.php` | Paged and framed box widgets, configurable border styles |
| `telnet/src/TerminalMarkupRenderer.php` | Markdown and StyleCodes body renderer for message viewing |

Both daemon entry points manually `require_once` every `telnet/src/` class they use. New classes added under `telnet/src/` must be registered in both `telnet/telnet_daemon.php` and `ssh/ssh_daemon.php` — they are not Composer-autoloaded. See also `telnet/CLAUDE.md` for the include-list rule.

---

## Session Flow

Both daemons hand off to the same `BbsSession` flow after transport setup:

1. **Transport handshake** — Telnet negotiates NAWS, echo control, and optional TLS; SSH reads PTY dimensions from `pty-req` and probes Sixel capability.
2. **Pre-login menu** — Login / Register / Reset password / QWK (when enabled) / Quit.
3. **Authentication** — Username/password via `POST /api/auth/login`; session cookie stored in `$state`.
4. **Session init** — Single `GET /api/config/session-init` call returns timezone, locale, date format, charset, ANSI color flag, idle timeout thresholds, and main menu key bindings.
5. **Main menu loop** — `BbsSession::handle()` runs the menu, dispatches to feature handlers, and processes resize events on each iteration.
6. **Feature handlers** — Each handler runs until the user exits back to the main menu. Handlers share `$conn`, `$state`, and `$session` passed by reference.
7. **Logout** — `POST /api/auth/logout` tears down the session; transport closes the socket.

`$state` is a mutable array threaded through all calls. Key entries:

| Key | Content |
|-----|---------|
| `rows`, `cols` | Current terminal dimensions (updated live from NAWS) |
| `locale` | User's locale string (e.g. `en`, `fr`) |
| `csrf_token` | CSRF token required for all mutating API calls |
| `terminal_type` | Reported TTYPE string (e.g. `syncterm`, `xterm-256color`) |
| `charset` | Effective charset: `utf8`, `cp437`, or `ascii` |
| `ansi_color` | Whether ANSI color is enabled for this session |
| `repaint_fn` | Callable set by the active screen; overlays call it to repaint the background on resize |

---

## Terminal Shell Abstraction

Terminal feature handlers target a shared shell interface instead of binding directly to raw prompt loops or `TelnetUtils` widgets. The shell owns UI intents — selecting from a list, prompting for text, confirming actions, showing read-only content, and displaying alerts — so handler code remains shell-agnostic.

### Available Shells

| Shell | Selection | Free-form text | Single-key actions | Read-only text | Alerts | Typical use |
|-------|-----------|----------------|--------------------|----------------|--------|-------------|
| `TuiShell` | Framed selector widgets | Framed input dialogs | Framed single-key modals | Paged framed viewers | Framed alerts | Normal-size terminals |
| `LineShell` | Prompt-driven numbered menus | Plain text prompts | Immediate single-key reads | Plain text screens | Plain text notices | Narrow or low-capability terminals |

Shell selection is automatic via `TerminalShellFactory::create($server, $state)`:

- `TuiShell` when rows ≥ 16 **and** cols ≥ 60, or when `term_shell_mode = tui`
- `LineShell` otherwise, or when `term_shell_mode = line`

The shell is re-created on each main menu iteration so a resize that crosses the size threshold switches the shell live without a reconnect.

### Shell Allowlist

User-selectable shell modes are filtered through the `.env` variable
`TERMSERVER_ALLOWEDSHELLS`, parsed centrally by `BinktermPHP\BbsConfig`.
The value is a space-separated allowlist of registered shell IDs such as
`tui`, `tui line`, or `tui retroglass`.

- If the variable is missing or empty, only `tui` is allowed.
- `routes/api-routes.php` uses the allowlist to validate persisted
  `term_shell_mode` values.
- `telnet/src/SettingsHandler.php` uses the allowlist to decide which shell
  choices to render in the terminal settings UI.
- `templates/admin/bbs_settings.twig` and `routes/admin-routes.php` use the
  allowlist to constrain the sysop-facing default shell selector.
- `TerminalShellFactory` treats any disallowed explicit shell request as a
  fallback to `TuiShell`, so stale user preferences do not block login.

### Plugin Shell Discovery

`BinktermPHP\TerminalShellRegistry` owns shell discovery and metadata.

- Built-in shells (`tui`, `line`) are registered in code.
- Additional shell plugins are discovered from `telnet/shells/*.plugin.php`.
- Each plugin file must return either one definition array or a list of
  definition arrays.
- The minimum definition shape is:

```php
[
    'id' => 'retroglass',
    'class' => \Vendor\Binkterm\RetroGlassShell::class,
    'label' => 'Retro Glass',
]
```

- Optional metadata fields:
  - `admin_label` and `settings_label` for context-specific labels
  - `admin_label_key` and `settings_label_key` for built-in translated labels
- If a plugin wants to split class code into another file, the plugin
  definition file should `require_once` that file before returning metadata.
- Plugin classes must accept the same constructor shape as built-ins:
  `__construct(BbsSession $server)`.
- Plugin classes should implement `TerminalShellInterface` directly or extend
  one of the built-in shells.

The registry is used from both daemon and web/admin contexts, so plugin files
must be safe to load outside an active terminal session. Discovery is
metadata-driven; actual shell instantiation happens later inside
`TerminalShellFactory`.

### `TerminalShellInterface`

All shells implement the same five intent methods:

| Method | Intent |
|--------|--------|
| `chooseFromList($conn, &$state, $title, $items, $options)` | Present a selectable list; returns selected index or null (cancel/quit) |
| `promptText($conn, &$state, $title, $prompt, $options)` | Free-form text input; returns string or null (cancel) |
| `promptKey($conn, &$state, $title, $prompt, $allowedKeys, $options)` | Single-key action prompt; returns lowercase key string or null |
| `showText($conn, &$state, $title, $lines, $options)` | Display read-only text; returns when user dismisses |
| `showAlert($conn, &$state, $title, $message, $style)` | Short notice (`'info'` or `'error'`); returns on dismiss |

Handler code calls these methods on whichever shell `TerminalShellFactory` returned:

```php
$shell = TerminalShellFactory::create($this->server, $state);

$index = $shell->chooseFromList($conn, $state, 'Pick an area', $areaNames);
if ($index === null) {
    return; // user quit
}

$confirmed = $shell->promptKey($conn, $state, 'Confirm', 'Delete this message?', ['y', 'n']);
if ($confirmed !== 'y') {
    return;
}
```

### `TuiShell` Capabilities

`TuiShell` wraps `TelnetUtils` widgets and threads the style profile to each call automatically. It also exposes these additional widget methods beyond the interface:

| Method | Widget used |
|--------|-------------|
| `showMessageViewer(...)` | `TelnetUtils::runMessageViewer()` with help overlay profile |
| `showMessageList(...)` | `TelnetUtils::runMessageList()` with help overlay profile |
| `showSelectableList(...)` | `TelnetUtils::runSelectableList()` with help overlay profile |
| `showScrollablePanel(...)` | Inline framed scrollable panel |
| `showConfirmDialog(...)` | `TelnetUtils::showConfirmDialog()` with dialog profile |
| `showWorkingOverlay(...)` | `TelnetUtils::showWorkingOverlay()` with working overlay profile |
| `showCheckboxListDialog(...)` | `TelnetUtils::showCheckboxListDialog()` with checkbox dialog profile |
| `showSelectableDialog(...)` | `TelnetUtils::showSelectableDialog()` with selectable dialog profile |
| `showPublicProfileViewer(...)` | `TelnetUtils::showPublicProfileViewer()` with profile viewer profile |
| `showPagedBox(...)` | `TerminalBoxRenderer::showPagedBox()` with panel profile |
| `renderPanel(...)` | `TerminalBoxRenderer::renderBox()` with panel profile |
| `showAddressPicker(...)` | `TelnetUtils::runAddressPicker()` |

### `LineShell` Capabilities

`LineShell` maps the same interface onto plain terminal output:

- `chooseFromList` renders a numbered list with typed page navigation and reads the selection via `prompt()`
- `promptText` calls `prompt()` directly
- `promptKey` reads a single keystroke via `readKeyWithIdleCheck()` — no framed UI
- `showText` wraps and prints lines then waits for any key
- `showMessageList` and `showSelectableList` render prompt-driven numbered pages and return the same action strings the handlers use in TUI mode
- `showMessageViewer`, `showPagedBox`, and `showPublicProfileViewer` render wrapped text readers with typed navigation commands instead of full-screen framed widgets
- `showConfirmDialog` and `showSelectableDialog` render plain text prompts instead of centered overlays
- `showAlert` prints a plain text notice

### Adding a New Shell

For a custom shell plugin:

1. Create a shell class that implements `TerminalShellInterface` or extends an existing shell.
2. Place a plugin definition file in `telnet/shells/` that returns metadata for the shell ID and class name.
3. Add the shell ID to `TERMSERVER_ALLOWEDSHELLS` if users or sysops should be able to select it.
4. Keep handler code shell-agnostic — feature handlers must call interface methods only.
5. Update this document and `docs/TerminalServer.md` if the shell is meant to be part of the supported platform surface.

For a new built-in shell shipped by the project:

1. Add the class under `telnet/src/`.
2. If it is a new `telnet/src/` file, add the required `require_once` entries to both daemon entrypoints.
3. Register it in `BinktermPHP\TerminalShellRegistry::getBuiltInDefinitions()`.
4. Keep `TerminalShellFactory` shell-agnostic — it should instantiate through the registry rather than hardcoding additional branches.

The shell layer is intentionally minimal so a new shell can be added without rewriting existing handlers.

### Shell Abstraction Exemptions

Some handlers must remain on fixed plain-text flows and must never be routed through the shell abstraction:

- **`QwkMenuHandler`** — QWK reader software and expect-style automation scripts parse specific prompt strings (`> `, conference numbers, format prompts) to navigate the QWK menu programmatically. A TUI shell would break those scripts. `QwkMenuHandler` must stay on plain `prompt()`/`writeLine()` calls.
- **`TerminalSettingsHandler::runDetectionWizard()`** — the terminal detection wizard must stay on plain `prompt()`/`writeLine()` calls for every shell. Its job is to determine whether charset rendering and ANSI color can be trusted at all; if it were converted to shell widgets, the capability-detection path would depend on the very rendering features it is trying to verify.

---

## Style Profile

All terminal UI colors are routed through the terminal style profile. Widgets should read the active session profile with `TelnetUtils::getStyleProfile($state)` so shell or plugin overrides apply consistently; `TelnetUtils::getDefaultStyleProfile()` is only the canonical fallback palette.

```php
$profile = TelnetUtils::getStyleProfile($state);
```

### Profile Sections

| Profile key | Sub-keys | Usage |
|-------------|----------|-------|
| `panel` | TerminalBoxRenderer scheme | Framed paged-text and info-panel boxes |
| `list` | `title`, `selected_bg` | List title color and selection highlight |
| `scrollable_panel` | `border`, `divider`, `title_bar`, `body`, `status_bar_bg` | TuiShell scrollable panel widget |
| `status_bar` | `bg`, `key`, `label`, `text`, `fill` | Bottom status bar — `key` = key name color (default red), `label` = label text color (default blue) |
| `header_box` | `bg`, `frame`, `body` | Message header box (From / To / Date / Subj) |
| `help_overlay` | `bg`, `frame`, `body`, `key`, `status_key`, `status_label` | Ctrl-K help overlay |
| `dialog` | `bg`, `frame`, `body`, `hint`, `choice_key`, `choice_label` | Confirmation and multi-choice dialogs |
| `working_overlay` | `bg`, `frame`, `body` | "Please wait" overlay |
| `checkbox_dialog` | `bg`, `frame`, `body`, `hilite`, `dim` | Checkbox picker dialog |
| `selectable_dialog` | `bg`, `frame`, `body`, `hilite`, `dim` | Selectable item dialog |
| `alert` | `info`, `error` (each: `bg`, `frame`, `body`) | Alert dialog color variants |
| `image_prompt` | `bg`, `frame`, `body` | Sixel image prompt overlay |
| `profile_viewer` | `bio_label`, `status_key`, `status_label` | Public profile viewer |
| `file_detail_panel` | `border`, `divider`, `title_bar`, `body`, `status_bar_bg` | File detail panel |

### Building Status Bar Segments

Use the `key`/`label` entries from `status_bar` rather than hardcoding `ANSI_RED`/`ANSI_BLUE`:

```php
$sbProfile = TelnetUtils::getDefaultStyleProfile()['status_bar'];
$keyColor  = $sbProfile['key']   ?? TelnetUtils::ANSI_RED;
$lblColor  = $sbProfile['label'] ?? TelnetUtils::ANSI_BLUE;

$segments = [
    ['text' => 'U/D',       'color' => $keyColor],
    ['text' => ' Scroll  ', 'color' => $lblColor],
    ['text' => 'Q',         'color' => $keyColor],
    ['text' => ' Quit',     'color' => $lblColor],
];
$statusLine = TelnetUtils::buildStatusBar($segments, $width);
```

`TuiShell` methods thread the profile to their widget calls automatically. Handlers that go through `TuiShell` do not need to pass the profile explicitly.

---

## Reusable UI Widgets

**Always check for an existing `TelnetUtils` or `TuiShell` widget before writing custom terminal UI.** Duplicating widget logic creates inconsistency and bugs. The full widget reference lives in `telnet/CLAUDE.md`; a summary of the most-used widgets follows.

| Need | Use |
|------|-----|
| Full-screen scrollable message reader (Ctrl-K help built in) | `TelnetUtils::runMessageViewer()` / `$shell->showMessageViewer()` |
| Paginated selectable list with keyboard nav | `TelnetUtils::runSelectableList()` / `$shell->showSelectableList()` |
| Pre-built message list with compose/read actions | `TelnetUtils::runMessageList()` / `$shell->showMessageList()` |
| Scrollable kludge/header overlay | `TelnetUtils::runKludgeViewer()` (called internally by message viewer) |
| Sixel image viewer | `TelnetUtils::showSixelImageViewer()` |
| Message header box (From/To/Date/Subj) | `TelnetUtils::buildMessageHeaderBox()` |
| Status bar from segments array | `TelnetUtils::buildStatusBar()` |
| Centered confirmation / multi-option dialog | `TelnetUtils::showConfirmDialog()` / `$shell->showConfirmDialog()` |
| Centered alert/notice dialog | `TelnetUtils::showAlertDialog()` / `$shell->showAlert()` |
| Centered single-line text input | `TelnetUtils::showInputDialog()` / `$shell->promptText()` |
| Centered "please wait" overlay | `TelnetUtils::showWorkingOverlay()` / `$shell->showWorkingOverlay()` |
| Checkbox picker dialog | `TelnetUtils::showCheckboxListDialog()` / `$shell->showCheckboxListDialog()` |
| Selectable item dialog | `TelnetUtils::showSelectableDialog()` / `$shell->showSelectableDialog()` |
| ANSI-wrapped text lines | `TelnetUtils::wrapTextLines()` |
| Address book / nodelist picker | `TelnetUtils::runAddressPicker()` / `$shell->showAddressPicker()` |
| Public profile viewer | `TelnetUtils::showPublicProfileViewer()` / `$shell->showPublicProfileViewer()` |

If a widget genuinely lacks a capability needed by multiple features, extend it in `TelnetUtils` — do not work around it in a handler. When adding or extending a widget, update the table in `telnet/CLAUDE.md`.

### Status Bar Discipline

The bottom status bar has limited width. Keep it to the **most-used primary actions only** — typically scroll, prev/next, reply, and quit. Every other key belongs exclusively in the Ctrl-K help overlay.

```
✅ Status bar:  U/D Scroll  L/R Prev/Next  R Reply  Ctrl-K Help  Q Quit
❌ Status bar:  U/D Scroll  PgUp/PgDn Page  L/R Prev/Next  R Reply  H Headers  X Delete  B Bookmark  T .txt  Q Quit
```

### Adding Actions to the Message Viewer

`TelnetUtils::runMessageViewer()` accepts `$extraKeys` mapping characters to action names and `$helpItems` for the Ctrl-K overlay:

```php
$helpItems = [
    ['key' => 'PgUp / PgDn', 'label' => $this->server->t('ui.terminalserver.message.help_page', 'Scroll one page', [], $locale)],
    ['key' => 'X',           'label' => $this->server->t('ui.terminalserver.netmail.help_delete', 'Delete message', [], $locale)],
];

$result = $shell->showMessageViewer(
    $conn, $state,
    $view['headerLines'], $view['wrappedLines'], $view['statusLine'],
    $state['rows'] ?? 24, 0, false, $kludgeLines, $buildView,
    $imageRefs, $imageFn,
    ['x' => 'delete'],  // $extraKeys
    $helpItems
);

switch ($result['action']) {
    case 'delete': $this->doDelete(...); break;
}
```

### Terminal Resize Handling

All full-screen UI must respond to resize events. The standard pattern is to snapshot dimensions before the key loop and compare after each read:

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
        $rebuildLayout();
        $render();
    }
    // ... normal key handling ...
}
```

Layout-dependent variables must be recalculated on every resize. Capture them by reference (`&$var`) in closures so a single `$rebuildLayout()` call propagates the new dimensions without recreating closures.

`runMessageViewer()` handles resize internally via its `$rebuildFn` callback. Custom full-screen loops must implement the pattern above themselves.

---

## Adding / Modifying Main Menu Actions

Menu actions are data-driven via the key map in `AppearanceConfig`. Every new action must touch all six of the following — missing any one leaves the system in an inconsistent state:

1. **`src/AppearanceConfig.php`** — add/remove the action ID and its default key in `DEFAULT_TERM_MENU_KEYS`.
2. **`telnet/src/BbsSession.php`** — four touchpoints in `BbsSession::handle()`:
   - Key variable: `$<action>Key = $menuKeys['<action>'] ?? null;`
   - `$keyToAction` foreach: add/remove `'<action>' => $<action>Key`
   - Label variable + display row: `$lbl<Action>` (from `ui.terminalserver.server.menu.<action>`) and a `menuItemCol` call in the correct section of `$rows`
   - Dispatch branch: `elseif ($action === '<action>') { ... }`
3. **`templates/admin/appearance.twig`** — add/remove the action in `TERM_MENU_KEY_DEFAULTS` and `TERM_MENU_KEY_LABELS` in the "Terminal Main Menu Keys" script block.
4. **`config/i18n/*/common.php`** — add/remove `ui.admin.appearance.term_menu_keys.action.<action>` in every locale.
5. **`config/i18n/*/terminalserver.php`** — add/remove `ui.terminalserver.server.menu.<action>` in every locale.
6. **`docs/TerminalServer.md`** — update the default key table in "Customizable Main Menu Keys".

The admin save route derives its valid action list from `array_keys(AppearanceConfig::DEFAULT_TERM_MENU_KEYS)` automatically — no route change needed.

---

## Data Access

The terminal server is a trusted first-party component. Code under `telnet/src/` and `ssh/` may access the application's shared classes and database, but must respect clear boundaries.

### Choosing the Access Path

| Situation | Use |
|-----------|-----|
| Consuming an existing API-shaped feature (user-facing flows that should behave the same as the web) | `TelnetUtils::apiRequest()` |
| Sharing transport-agnostic business logic or domain services | Direct class reuse from `src/` |
| Simple internal read or small internal query where an API would add unnecessary complexity | Direct PDO: `$db = Database::getInstance()->getPdo()` |

Do not bypass important business rules. If validation, permissions, side effects, or invariants already live in a shared service or domain class, use that logic rather than reimplementing with ad hoc SQL.

Terminal code must not depend on web controllers, route handlers, Twig/view helpers, form handlers, middleware, or any code that assumes a browser-driven HTTP request lifecycle.

### `TelnetUtils::apiRequest()` — Response Structure

Always returns:

```php
[
    'status' => $httpCode,   // int HTTP status code
    'data'   => $parsedBody, // decoded JSON body — never the root array
    'error'  => null|string,
]
```

The parsed JSON body is always one level deep under `['data']`:

```php
// WRONG — 'messages' is in the JSON body, not at the return level
$messages = $response['messages'] ?? [];

// CORRECT
$messages = $response['data']['messages'] ?? [];
```

### CSRF Tokens

Every POST, PUT, PATCH, or DELETE call via `apiRequest()` must pass the CSRF token as the 7th argument. Omitting it causes a 403 at runtime with no write-time error:

```php
// CORRECT
TelnetUtils::apiRequest($base, 'POST', '/api/some/endpoint', $payload, $session, 3, $state['csrf_token'] ?? null);
TelnetUtils::apiRequest($base, 'DELETE', '/api/some/endpoint', null, $session, 3, $state['csrf_token'] ?? null);

// WRONG — silent 403
TelnetUtils::apiRequest($base, 'POST', '/api/some/endpoint', $payload, $session);
```

GET requests do not need the token.

### Daemon-Side Logging

Self-contained daemon/runtime subsystems (e.g. ZMODEM under `telnet/src/`) may use targeted `error_log(..., 3, $file)` file logging. Web-side and shared application logging must use `BinktermPHP\Binkp\Logger` instead — never `error_log()` in route or controller code.

---

## i18n in the Terminal Server

Terminal handlers use the same key-based i18n as the web interface. Translation keys live in `config/i18n/<locale>/terminalserver.php` (terminal-specific) and `config/i18n/<locale>/common.php` (shared). Access them via `$this->server->t(...)` or `$server->t(...)`:

```php
$label = $this->server->t('ui.terminalserver.netmail.inbox_title', 'Inbox', [], $state['locale'] ?? 'en');
```

When adding any new user-visible string in a handler:

1. Add the key to every locale's `terminalserver.php` (or `common.php` if it is shared with the web).
2. Use `$server->t(key, fallback, params, locale)` — never hardcode an English string inline in terminal output.
3. Run `php scripts/check_i18n_syntax.php --locale=<locale>` after editing any catalog file.

---

## API Endpoints Used

The terminal server uses `TelnetUtils::apiRequest()` for most operations. A subset of direct internal calls are made for performance-critical paths: session validation (`Auth`), login activity tracking (`ActivityTracker`), nodelist presence check, feature flags (`BbsConfig`, `BinkpConfig`), and system news (`AppearanceConfig`).

Primary endpoints used by `BbsSession` and the core handlers:

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/auth/login` | POST | User authentication |
| `/api/auth/logout` | POST | Session logout |
| `/api/config/session-init` | GET | Post-login user settings (timezone, locale, date format), terminal settings (charset, ANSI color), idle timeout thresholds, and main menu key bindings |
| `/api/messages/netmail` | GET | List netmail messages (`filter=all` for inbox, `filter=sent` for sent folder; `sort=date_desc\|date_asc\|subject\|author`) |
| `/api/messages/netmail/{id}` | GET | Get netmail message details (includes `is_saved` flag) |
| `/api/messages/netmail/{id}/save` | POST | Bookmark (save) a netmail message |
| `/api/messages/netmail/{id}/save` | DELETE | Remove bookmark from a netmail message |
| `/api/messages/netmail/send` | POST | Send netmail message |
| `/api/messages/netmail/{id}/forward-email` | POST | Forward netmail to the logged-in user's email address |
| `/api/messages/echomail` | GET | List echomail messages |
| `/api/messages/echomail/{id}` | GET | Get echomail message details (includes `is_saved` flag) |
| `/api/messages/echomail/{id}/save` | POST | Bookmark (save) an echomail message |
| `/api/messages/echomail/{id}/save` | DELETE | Remove bookmark from an echomail message |
| `/api/messages/echomail/{id}/download` | GET | Download echomail message as plain text (used by `T` key in viewer) |
| `/api/messages/echomail/{id}/forward-email` | POST | Forward echomail to the logged-in user's email address (used by `E` key in viewer) |
| `/api/messages/echomail/post` | POST | Post echomail message |
| `/api/messages/echomail/ignore-rules` | POST | Create an echomail ignore rule (used by `G` in viewer) |
| `/api/user/echomail-ignore-rules` | GET | List the user's echomail ignore rules (used by `G` on echoarea list) |
| `/api/user/echomail-ignore-rules/{id}` | DELETE | Delete an echomail ignore rule |
| `/api/dashboard/stats` | GET | Main menu dashboard widgets (unread counts, online users, bulletins, credits) |

All API requests include cookie-based session management, automatic retry with exponential backoff, and optional SSL certificate verification. Additional endpoints are called by individual feature handlers — see each `*Handler.php` class and the full [API Reference](API.md).

---

## Related

- [TerminalServer.md](TerminalServer.md) — User-facing feature reference and sysop configuration
- [telnet/CLAUDE.md](../telnet/CLAUDE.md) — Tactical rules for AI-assisted development in this subsystem (include list rules, widget table, CSRF/data-access rules)
- [TelnetServer.md](TelnetServer.md) — Telnet daemon setup and transport-specific details
- [SSHServer.md](SSHServer.md) — SSH daemon setup
- [API Reference](API.md) — Full HTTP endpoint reference
