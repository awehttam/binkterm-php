# BinktermPHP Terminal Server

The BinktermPHP Terminal Server is the shared text-mode BBS experience used by
both the Telnet and SSH daemons. Once users are logged in, they interact with
the same menus, handlers, and API-backed features regardless of transport.

Access methods:
- Telnet daemon: [telnet/README.md](../telnet/README.md)
- SSH daemon: [SSHServer.md](SSHServer.md)

## Core Functionality

- Netmail browsing, reading, composing, replying, and sending
- Echomail browsing, threaded reading, composing, replying, and sending
- File areas browsing with ZMODEM downloads and uploads
- Polls (view/vote/create where enabled)
- Shoutbox (view/post where enabled)
- Door launcher integration (DOS doors, native doors, and configured door menu)
- Who's Online display
- Full-screen message editor with cursor navigation and line editing controls
- ANSI color and screen-aware rendering
- Per-user localization (same i18n flow used by web/API)

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
  - Uses telnet option negotiation and optional TLS listener
  - Includes telnet-specific anti-bot/login flow behavior

- SSH:
  - Uses encrypted SSH-2 transport and host-key authentication model
  - Supports direct login when SSH credentials validate successfully

These differences are transport-layer concerns only; terminal features after
login are the same.

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

- [Telnet Daemon](../telnet/README.md)
- [SSH Server](SSHServer.md)
