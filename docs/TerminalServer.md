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
- File areas browsing with ZMODEM downloads and uploads
- Polls (view/vote/create where enabled)
- Shoutbox (view/post where enabled)
- Door launcher integration (DOS doors, native doors, and configured door menu)
- Who's Online display
- Full-screen message editor with cursor navigation and line editing controls
- ANSI color and screen-aware rendering
- Sixel image rendering for terminal clients that support it
- Per-user localization (same i18n flow used by web/API)
- User preferences and settings (editor behavior, display options, and other per-user configuration)

## Features

### Screen-Aware Display

- Automatically detects terminal dimensions via NAWS (Negotiate About Window Size)
- Adapts message lists to fit available screen height
- Prevents list overflow on different terminal sizes
- Dynamic pagination based on terminal rows

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

- **Login Attempts**: Limited to 3 attempts per connection
- **Rate Limiting**: Maximum 5 failed login attempts per minute per IP address
- **Connection Logging**: All login/logout events logged
- **Session Management**: Automatic cleanup of expired rate limit entries

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

### User Experience

- Colorized prompts and status messages
- Welcome message with BBS website URL
- Goodbye message on logout with reminder to visit website
- Message count display on main menu
- Helpful command documentation

## Shared Session Model

Both transport daemons run the same `BbsSession` flow after connection setup:

1. Transport handshake and authentication entry
2. Login/register path (or direct-auth for valid SSH credentials)
3. Main menu and feature handlers (Netmail, Echomail, Files, Doors, etc.)
4. Logout and session cleanup

Because the feature handlers are shared, behavior and capabilities remain
consistent across Telnet and SSH.

## Transport-Specific Notes

- Telnet:
  - Uses telnet option negotiation (IAC/NAWS/echo control) and optional TLS listener
  - Includes a pre-login ESC anti-bot challenge
  - Supports an optional ANSI login screen (`telnet/screens/login.ans`)

- SSH:
  - Uses encrypted SSH-2 transport and host-key authentication model
  - Supports direct login when SSH credentials validate successfully
  - Passes PTY dimensions from `pty-req` to the BBS session

These differences are transport-layer concerns only; terminal features after
login are the same.

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

The terminal server uses the following BinktermPHP API endpoints:

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/auth/login` | POST | User authentication |
| `/api/messages/netmail` | GET | List netmail messages |
| `/api/messages/netmail/{id}` | GET | Get netmail message details |
| `/api/messages/netmail/send` | POST | Send netmail message |
| `/api/messages/echomail` | GET | List echomail messages |
| `/api/messages/echomail/{id}` | GET | Get echomail message details |
| `/api/messages/echomail/post` | POST | Post echomail message |

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
