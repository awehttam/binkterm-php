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

### Message Browsing

- List netmail and echomail messages with pagination
- Navigate between pages
- View message details with headers
- Thread awareness and proper message display
- ANSI color support for enhanced readability

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

When viewing messages that contain Markdown image references, the terminal
server will render those images inline using the Sixel graphics protocol for
clients that advertise Sixel support. Clients that do not support Sixel skip
the image gracefully.

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
- Helpful command documentation

### Local Chat

The terminal server now includes the same local chat system used by the web UI.
From the main menu, press **C** to open **Local Chat**.

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

Sessions that have been idle for 300 seconds receive a warning prompt. If no input is received within a further window (420 seconds total), the session is disconnected automatically. Activity at any point resets the timer.

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
| `/api/user/settings` | GET | Load per-user settings (timezone, locale, terminal prefs) |
| `/api/messages/netmail` | GET | List netmail messages |
| `/api/messages/netmail/{id}` | GET | Get netmail message details |
| `/api/messages/netmail/send` | POST | Send netmail message |
| `/api/messages/echomail` | GET | List echomail messages |
| `/api/messages/echomail/{id}` | GET | Get echomail message details |
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
