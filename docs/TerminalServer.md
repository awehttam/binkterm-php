# BinktermPHP Terminal Server

The BinktermPHP Terminal Server is the shared text-mode BBS experience used by
both the Telnet and SSH daemons. Once users are logged in, they interact with
the same menus, handlers, and API-backed features regardless of transport.

Access methods:
- Telnet daemon: [TelnetServer.md](TelnetServer.md)
- SSH daemon: [SSHServer.md](SSHServer.md)

## Requirements

- PHP 8+
- PHP extensions:
  - `curl` — for API requests
  - `pcntl` — for process forking on Linux/macOS (multiple concurrent connections; single-connection on Windows)
- `libsixel-bin` (optional) — provides `img2sixel` for inline image rendering in Sixel-capable terminal clients (`apt install libsixel-bin`)
- BinktermPHP web API reachable (defaults to `SITE_URL` from config)

Each transport daemon has additional extension requirements — see
[TelnetServer.md](TelnetServer.md) and [SSHServer.md](SSHServer.md).

## Core Functionality

- Netmail browsing, reading, composing, replying, and sending
- Echomail browsing, threaded reading, composing, replying, and posting
- QWK offline mail packet download and upload (when enabled)
- File areas browsing with ZMODEM downloads and uploads
- Bulletins (read-only system announcements posted by admins)
- Polls (view/vote/create where enabled)
- Shoutbox (view/post where enabled)
- Local chat with rooms, direct messages, online users, and moderation shortcuts
- BBS Directory (browse other BBSes when enabled)
- Nodelist browser (browse Fidonet nodelist entries when available)
- Interests (tag-based content interests when enabled)
- Door launcher integration (DOS doors, native doors, and configured door menu)
- Who's Online display
- Full-screen message editor with cursor navigation and line editing controls
- ANSI color and screen-aware rendering (auto-detected on Telnet via TTYPE negotiation)
- Sixel image rendering for terminal clients that support it
- Per-user localization (same i18n flow used by web/API)
- User preferences and settings (editor behavior, display options, and other per-user configuration)

## Features

### Screen-Aware Display

- Automatically detects terminal dimensions via NAWS (Negotiate About Window Size)
- Adapts message lists to fit available screen height
- Prevents list overflow on different terminal sizes
- Dynamic pagination based on terminal rows
- Terminal resize events are processed in real time during key-wait loops — the main menu re-renders immediately when the window is resized, without requiring a keypress
- On narrow terminals, the fallback main-menu header truncates cleanly and prefers keeping the BBS name visible before dropping the clock

### Message Browsing

- List netmail and echomail messages with pagination
- Navigate between pages
- View message details with headers
- Thread awareness and proper message display
- ANSI color support for enhanced readability
- Netmail reader supports **Inbox** and **Sent** folder views — press `S` from the message list to toggle. The active folder is persisted per user across sessions. In Sent view, the message header shows the recipient (`To:`) instead of the sender, and replying pre-fills the original recipient's address.
- Press `B` in the netmail viewer to bookmark (save) a message for later. Pressing `B` again unsaves it. The status bar label toggles between **Bookmark** and **Unsave** to reflect the current state.
- Press `E` in the netmail viewer to forward the current message to the logged-in user's email address. Requires outbound email to be configured on the BBS; an error is shown inline if it is not.
- Press `B` in the echomail viewer to bookmark (save) a message for later. Pressing `B` again unsaves it. Bookmarked messages appear under the **Saved** filter in the web interface.
- Press `T` in the echomail viewer to download the current message as a plain-text `.txt` file via ZMODEM. The filename is derived from the message subject. Requires `lrzsz` (`sz`/`rz`) installed on the server.
- Press `E` in the echomail viewer to forward the current message to the logged-in user's email address. Requires outbound email to be configured on the BBS; an error is shown inline if it is not.

### Full-Screen Message Editor

- Arrow key navigation (Up, Down, Left, Right, Home, End, Page Up, Page Down)
- Insert and edit text at any cursor position
- Delete characters with Backspace/Delete
- Line operations:
  - Enter: Insert new line at cursor
  - Ctrl+Y: Delete entire current line
- Save/Cancel operations:
  - Ctrl+Z: Save message and send
  - Ctrl+C: Cancel and discard message
- Visual feedback with colorized prompts
- Message quoting when replying

### Security

- **Login Attempts**: Limited to 3 attempts per connection before the connection is closed
- **Connection Rate Limiting**: The transport daemon (Telnet/SSH) enforces a per-IP connection rate limit before forking a child process — see [TelnetServer.md](TelnetServer.md) and [SSHServer.md](SSHServer.md) for transport-specific details
- **Connection Logging**: All login/logout events logged

### Reliability

- **API Retry Logic**: Exponential backoff for failed API requests (up to 3 retries)
- **Process Cleanup**: Proper signal handling for SIGCHLD, SIGTERM, and SIGINT
- **Connection Health**: Timeout on socket accept operations
- **Zombie Prevention**: Automatic reaping of child processes

### Sixel Image Rendering

When viewing messages in the terminal server, the inline Sixel image viewer can
open:

- Markdown image references written as `![alt](url)` in markdown-formatted messages
- Bare direct image URLs in any message body when the URL ends in a supported
  raster extension: `.png`, `.jpg`, `.jpeg`, or `.gif`

The message body shows numbered image placeholders, and pressing `I` opens the
viewer for the selected image. SVG and other non-raster extensions are not
included in this terminal-side URL scan.

Sixel output requires `img2sixel` from the `libsixel-bin` package to be
installed and available in `PATH`. If the binary is not found, images are
silently omitted.

### Main Menu Dashboard

The main menu displays a live dashboard panel alongside the navigation options. The panel shows the same stats as the web dashboard, sourced from `/api/dashboard/stats`.

**Widgets shown (in priority order):**

| Widget | Content |
|--------|---------|
| Netmail | Unread netmail count |
| Echomail | New echomail since last visit |
| Online | Users active in the last 15 minutes |
| Bulletins | Unread bulletins |
| Credits | Credit balance (only when credits are enabled) |

**Layout adapts to terminal width:**

- **Wide screens (≥ ~110 columns):** A bordered panel is drawn to the right of the menu box using ANSI cursor positioning. Lower-priority widgets are dropped from the bottom when there is insufficient vertical space.
- **Narrower screens:** A compact single-line stats bar appears below the menu box. Bulletins and credits are omitted if there are no spare rows.

Stats are refreshed from the API after returning from netmail, echomail, or bulletins. On resize, the menu re-renders with the cached stats (no extra API call).

### User Experience

- Colorized prompts and status messages
- Welcome message with BBS website URL
- Goodbye message on logout with reminder to visit website
- Unread and new message counts shown on main menu items (netmail: unread count; echomail: new since last visit)
- Live dashboard widgets alongside the main menu (see above)
- Shoutbox screens use the same centered framed panel style as other terminal viewers, with separate timestamp/author headers and wrapped message bodies for cleaner readability
- Helpful command documentation

### Border and Frame Style

All terminal frames, box viewers, and paged content screens share a single configurable box-drawing style. The setting is stored in `data/appearance.json` under `shell.term_border_style` and is configured through **Admin → BBS Settings → Appearance → Terminal Server → Border Style**.

| Style | Description | Charset required |
|-------|-------------|-----------------|
| **Classic** *(default)* | Double-line corners and tees, single-line sides and dividers | CP437 or UTF-8 |
| **Double** | All double-line box drawing | CP437 or UTF-8 |
| **Single** | Thin single-line box drawing | CP437 or UTF-8 |
| **Heavy** | Bold thick single-line box drawing | UTF-8 only |
| **Rounded** | Thin single-line with curved corners | UTF-8 only |
| **Minimal** | Top and bottom horizontal rules only; no side walls | CP437 or UTF-8 |
| **Mixed** | Double horizontal lines, single vertical lines | CP437 or UTF-8 |
| **Shadow** | Classic borders with a half-block drop shadow (▒) on the right and bottom | UTF-8 only |
| **ASCII** | Plus, hyphen, and pipe characters — safe for any terminal | Any |

**Examples:**

```
Classic                         Double
╔══════════════════╗            ╔══════════════════╗
│      Title       │            ║      Title       ║
╠══════════════════╣            ╠══════════════════╣
│ Content line     │            ║ Content line     ║
╚══════════════════╝            ╚══════════════════╝

Single                          Heavy (UTF-8)
┌──────────────────┐            ┏━━━━━━━━━━━━━━━━━━┓
│      Title       │            ┃      Title       ┃
├──────────────────┤            ┣━━━━━━━━━━━━━━━━━━┫
│ Content line     │            ┃ Content line     ┃
└──────────────────┘            ┗━━━━━━━━━━━━━━━━━━┛

Rounded (UTF-8)                 Minimal
╭──────────────────╮            ──────────────────
│      Title       │                  Title
├──────────────────┤            ──────────────────
│ Content line     │              Content line
╰──────────────────╯            ──────────────────

Mixed                           Shadow (UTF-8)
╒══════════════════╕            ╔══════════════════╗▒
│      Title       │            │      Title       │▒
╞══════════════════╡            ╠══════════════════╣▒
│ Content line     │            │ Content line     │▒
╘══════════════════╛            ╚══════════════════╝▒
                                 ▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒

ASCII
+------------------+
|      Title       |
+------------------+
| Content line     |
+------------------+
```

**Charset fallback:** The rendered style is determined by both the configured setting and the connecting client's detected character set:

- **UTF-8 terminal** — all styles render as configured.
- **CP437 terminal** — Heavy falls back to Classic; Rounded falls back to Single. All other styles are unchanged.
- **ASCII terminal** (or when ANSI color is disabled) — always renders as ASCII regardless of the configured style.

### Customizable Main Menu Keys

Every action in the terminal main menu can be bound to a different single letter or digit (0–9) by the sysop. The binding is stored in `data/appearance.json` under `shell.term_menu_keys` and is configured through **Admin → BBS Settings → Appearance → Terminal Server → Main Menu Keys**. The key table in that UI shows the built-in default for each action in a center reference column.

**Default key map:**

| Key | Action |
|-----|--------|
| N | Netmail |
| E | Echomail |
| S | Shoutbox |
| U | Bulletins |
| P | Polls |
| D | Door Games |
| F | Files |
| T | Settings |
| I | Interests |
| W | Who's Online |
| K | QWK Offline Mail |
| B | BBS Directory |
| L | Node List |
| C | Local Chat |
| Q | Quit |

If no custom map is saved the built-in defaults above are used. When a custom map is saved, any action with no assigned key is hidden from the menu and its slot is not rendered — remaining items in that section reflow to fill the gap. When every action in a section (Messaging, Community/Explore, or Files/Settings) is unassigned, the section header itself is suppressed. The `quit` key is always required. Sysops are responsible for ensuring their `mainmenu.ans` art matches whatever keys are configured.

The key map is returned as part of the `GET /api/config/session-init` call made immediately after login.

### Local Chat

The terminal server includes the same local chat system used by the web UI.
From the main menu, press the configured **Local Chat** key (default **C**) to open local chat.

Local chat supports:

- room selection from an in-chat navigation pane
- direct messages to online users and known DM contacts
- unread badges for rooms and DMs during the current chat session
- online user display in the left navigation pane
- message polling with automatic updates while the chat screen is open
- scrollback with **PgUp/PgDn**
- Markdown rendering in the message pane using the shared terminal markup renderer
- automatic reopen to the last selected room or DM
- multiline compose via **Ctrl+E**

Chat controls:

- **Tab / Shift+Tab** — move focus between panes
- **Up / Down / Home / End** — move within the focused pane
- **Enter** — open selected room/DM or send the current message
- **PgUp / PgDn** — scroll message history
- **Ctrl+E** — open the full-screen multiline composer
- **Ctrl+K** — show local chat help
- **R** — refresh rooms and online users
- **Ctrl+C** — exit local chat

The current wide layout uses:

- a narrower **Rooms / DMs** pane on the left
- a larger **Messages** pane on the right
- a full-width **Compose** box at the bottom

The left pane also includes the current online-user summary, so there is no
separate right-hand online-users sidebar.

Chat messages are rendered as terminal Markdown, matching the message-body
renderer used by echomail and netmail viewers. Sender and timestamp are shown
as a header line above each rendered message body.

On narrower terminals the chat client switches to a stacked layout so rooms/DMs,
messages, and the compose box still fit on screen.

### Idle Timeout

After a configurable period of inactivity the session displays an "Are you still there?" prompt. If no key is pressed before a second configurable deadline the session is disconnected. Any keypress resets the timer. The thresholds are set in **Admin → BBS Settings → Terminal Idle Timeout** and default to 5 minutes for the warning and 7 minutes for the disconnect.

### Login Menu

Before authenticating, users are shown a pre-login menu:

- **L** — Login to existing account
- **R** — Reset lost password
- **N** — Register a new account
- **K** — QWK transfer (only shown when QWK is enabled)
- **Q** — Quit / disconnect

New users who register are disconnected after registration and must reconnect to log in.

### Terminal Detection Wizard

On first login, if the user has no saved terminal settings, the server runs an auto-detection wizard that tests character set support and color capability, then saves the results. This wizard is skipped on subsequent sessions once settings are stored.

### System News

After login, the server displays any pending system news before presenting the main menu.

## Shared Session Model

Both transport daemons run the same `BbsSession` flow after connection setup:

1. Transport handshake and authentication entry
2. Pre-login menu (Login / Register / Reset password / QWK / Quit)
3. Main menu and feature handlers (Netmail, Echomail, Files, Doors, Bulletins, Polls, QWK, etc.)
4. Logout and session cleanup

Because the feature handlers are shared, behavior and capabilities remain
consistent across Telnet and SSH.

## Transport-Specific Notes

- Telnet:
  - Uses telnet option negotiation (IAC/NAWS/echo control) and optional TLS listener
  - Supports an optional ANSI login screen (`telnet/screens/login.ans`)

- SSH:
  - Uses encrypted SSH-2 transport and host-key authentication model
  - Supports direct login when SSH credentials validate successfully
  - Passes PTY dimensions from `pty-req` to the BBS session
  - Sixel capability probing for SSH runs inside the SSH transport layer before `BbsSession` starts, so the DA reply can be consumed there without leaking into pre-login prompts

## Custom Terminal Screens

Terminal login, main-menu, and goodbye screens are loaded from `telnet/screens/`.
Both ANSI (`.ans`) and Sixel (`.sixel`) assets support simple rotating families:

- `login.ans`, `login1.ans`, `login2.ans`
- `login.sixel` (Sixel login screen; shown instead of ANSI when the client supports Sixel)
- `mainmenu.ans`, `mainmenu1.ans`
- `mainmenu.sixel`, `mainmenu1.sixel`
- `bye.ans`, `bye1.ans`
- `bye.sixel` (Sixel goodbye screen; shown instead of ANSI when the client supports Sixel)

When multiple files share the same base name and extension, the terminal server
uses a simple glob match and picks one at random each time that screen is shown.

## Editor Controls

### Cursor Navigation

- **Arrow Keys** (Up/Down/Left/Right) — Move cursor position
- **Home** — Jump to beginning of current line
- **End** — Jump to end of current line
- **Page Up** — Scroll up one screen
- **Page Down** — Scroll down one screen

### Editing

- **Backspace/Delete** — Delete character at or before cursor
- **Enter** — Insert new line at cursor position
- **Ctrl+Y** — Delete entire current line

### Save/Cancel

- **Ctrl+Z** — Save message and send
- **Ctrl+C** — Cancel and discard message

### Visual Feedback

The editor displays help text at the top of the screen showing available commands
with color-coded indicators:

- Green: Save command
- Red: Cancel command
- Yellow: Delete line command

## Platform Limitations

- **Windows**: Single connection only (no `pcntl_fork` support)
- **Linux/macOS**: Multiple concurrent connections supported via process forking

## API Endpoints

The terminal server uses the BinktermPHP web API for most operations. It also makes a small number of direct calls to the database and filesystem: session validation (`Auth`), login activity tracking (`ActivityTracker`), nodelist presence check, feature flags (`BbsConfig`, `BinkpConfig`), and system news (`AppearanceConfig`). The table below lists the primary API endpoints. Additional endpoints are called by individual feature handlers (polls, shoutbox, bulletins, file areas, QWK, interests, BBS directory, nodelist, user settings, etc.).

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/auth/login` | POST | User authentication |
| `/api/auth/logout` | POST | Session logout |
| `/api/config/session-init` | GET | Single post-login call that returns user settings (timezone, locale, date format), terminal settings (charset, ANSI color), idle timeout thresholds, and main menu key bindings |
| `/api/messages/netmail` | GET | List netmail messages (`filter=all` for inbox, `filter=sent` for sent folder) |
| `/api/messages/netmail/{id}` | GET | Get netmail message details (includes `is_saved` flag) |
| `/api/messages/netmail/{id}/save` | POST | Bookmark (save) a netmail message |
| `/api/messages/netmail/{id}/save` | DELETE | Remove bookmark from a netmail message |
| `/api/messages/netmail/send` | POST | Send netmail message |
| `/api/messages/netmail/{id}/forward-email` | POST | Forward netmail message to the logged-in user's email address |
| `/api/messages/echomail` | GET | List echomail messages |
| `/api/messages/echomail/{id}` | GET | Get echomail message details (includes `is_saved` flag) |
| `/api/messages/echomail/{id}/save` | POST | Bookmark (save) an echomail message |
| `/api/messages/echomail/{id}/save` | DELETE | Remove bookmark from an echomail message |
| `/api/messages/echomail/{id}/download` | GET | Download echomail message as plain text (used by `T` key in viewer) |
| `/api/messages/echomail/{id}/forward-email` | POST | Forward echomail message to the logged-in user's email address (used by `E` key in viewer) |
| `/api/messages/echomail/post` | POST | Post echomail message |
| `/api/dashboard/stats` | GET | Main menu dashboard widgets (unread counts, online users, bulletins, credits) |

All API requests include cookie-based session management, automatic retry with
exponential backoff, and optional SSL certificate verification.

## ZMODEM Requirements (Non-Windows)

On non-Windows hosts, file transfer support uses external `sz`/`rz` binaries
from the `lrzsz` package, falling back to the built-in PHP ZMODEM implementation
when the binaries are not found.

- **Default behavior:** terminal file transfers are disabled by default.
- Install `lrzsz` to enable ZMODEM download/upload in file areas.
- If `sz`/`rz` are not found, the built-in PHP implementation is used automatically.
- To enable terminal file transfers, set the following in `.env`:

```ini
TERMINAL_FILE_TRANSFERS=true
```

When enabled, ensure `sz` and `rz` are present (typically via `lrzsz`) and
available in `PATH`, or specify their paths explicitly:

```ini
TELNET_SZ_BIN=/usr/bin/sz
TELNET_RZ_BIN=/usr/bin/rz
```

### Forcing the built-in PHP ZMODEM implementation

To bypass external `sz`/`rz` binaries and always use the built-in PHP ZMODEM
implementation (useful for testing or if external binaries are unreliable):

```ini
TELNET_ZMODEM_FORCE_PHP=true
```

## Related Documentation

- [Telnet Daemon](TelnetServer.md)
- [SSH Server](SSHServer.md)
