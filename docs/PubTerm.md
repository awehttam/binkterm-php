# PubTerm

PubTerm is a built-in native door that gives users a full BBS terminal session inside the browser. It connects the xterm.js terminal player directly to the BBS telnet port, so users get the same experience as a native telnet client — menus, ANSI art, echomail, and all other terminal-side features — without needing a separate telnet application.

PubTerm supports anonymous (guest) access, making it the primary entry point for visitors arriving via the public `/play/pubterm` URL.

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Terminal Size](#terminal-size)
- [Environment Variables](#environment-variables)
- [Guest Access](#guest-access)
- [Known Limitations](#known-limitations)
- [Troubleshooting](#troubleshooting)

---

## Requirements

- The multiplexing bridge must be running. See [Doors Overview](Doors.md) for setup instructions.
- A telnet client must be installed on the server:
  - **Debian/Ubuntu:** `sudo apt install telnet`
  - **RHEL/Rocky:** `sudo dnf install telnet`
  - **Windows:** PuTTY's `plink.exe` is used instead (see [Environment Variables](#environment-variables))

---

## Installation

PubTerm ships with BinktermPHP and requires no additional installation. Enable it through the admin panel:

1. Go to **Admin → Native Doors**.
2. Find **Public Terminal (pubterm)** in the list and toggle it on.
3. Click **Save Configuration**.

PubTerm is now accessible at `/play/pubterm` and listed on the public guest doors page at `/guest-doors`.

---

## Configuration

PubTerm is configured in `config/nativedoors.json` via **Admin → Native Doors**. The relevant keys for the `pubterm` entry are:

```json
{
  "pubterm": {
    "enabled": true,
    "credit_cost": 0,
    "max_time_minutes": 60,
    "max_concurrent_sessions": 5,
    "allow_anonymous": true,
    "guest_max_sessions": 5,
    "terminal_size": "132x43"
  }
}
```

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `false` | Whether PubTerm is accessible to users |
| `credit_cost` | `0` | Must be `0` for guest access to work |
| `max_time_minutes` | `60` | Maximum session length in minutes |
| `max_concurrent_sessions` | `5` | Maximum total simultaneous sessions |
| `allow_anonymous` | `false` | Allow unauthenticated visitors to connect |
| `guest_max_sessions` | `5` | Maximum simultaneous anonymous sessions |
| `terminal_size` | `"80x25"` | Canvas and initial BBS dimensions (see [Terminal Size](#terminal-size)) |

---

## Terminal Size

By default the terminal is 80 columns × 25 rows. For a better experience with wider BBS layouts, set `terminal_size` to one of the available presets:

| Value | Dimensions | Notes |
|-------|------------|-------|
| `"80x25"` | 80 × 25 | Standard. Works everywhere |
| `"132x24"` | 132 × 24 | Wide, short |
| `"132x43"` | 132 × 43 | Wide, comfortable for most BBS menus |
| `"132x50"` | 132 × 50 | Wide, tall |
| `"autofit"` | Browser window | Canvas fills the browser; BBS starts at the actual browser size |

The configured size is used for both the xterm.js canvas in the browser and the PTY spawned by the multiplexing bridge. The BBS receives a NAWS terminal-size negotiation at connection time with the configured dimensions.

### Autofit

When `terminal_size` is set to `"autofit"`, the xterm.js canvas fills the browser window and the BBS starts at the dimensions that fit the user's current window. This gives users the most screen real estate without any fixed size commitment.

**Current limitation:** mid-session resize is not supported. The system `telnet` client used by PubTerm does not forward PTY window-change signals to the BBS as NAWS updates, so the BBS will not adapt if the user resizes their browser after connecting. The starting size is still correct.

---

## Environment Variables

PubTerm reads the following variables from `.env`. All have sensible defaults and only need to be set if your setup is non-standard.

| Variable | Default | Description |
|----------|---------|-------------|
| `PUBTERM_HOST` | `127.0.0.1` | Hostname or IP of the BBS telnet server |
| `PUBTERM_PORT` | `2323` | Port of the BBS telnet server |
| `PUBTERM_TELNET_BIN` | `telnet` | Path to the telnet binary (Linux/macOS). Override if telnet is not on `PATH` |
| `PUBTERM_PLINK_BIN` | `plink` | Path to PuTTY's `plink.exe` (Windows only). Override if plink is not on `PATH` |

---

## Guest Access

When `allow_anonymous` is `true`, unauthenticated visitors can connect via:

- **Direct URL:** `/play/pubterm`
- **Guest doors listing:** `/guest-doors`

Guest sessions are launched under the system guest user account.

`guest_max_sessions` limits how many simultaneous anonymous sessions are permitted. Set this to a low value (e.g. `2`–`5`) to prevent resource exhaustion from bots or scrapers.

---

## Known Limitations

### Mid-session terminal resize

When a user resizes their browser window after connecting, the xterm.js canvas updates correctly but the BBS does not receive a new NAWS terminal-size notification. This is because the system `telnet` client does not forward `SIGWINCH` (the PTY window-change signal) to the remote BBS as a NAWS subnegotiation.

The BBS does correctly handle mid-session NAWS when it arrives — the limitation is on the telnet-client side of the chain.

**Workaround:** set `terminal_size` to a fixed size that matches your BBS layout (e.g. `"132x43"`). The BBS will render correctly at that size for all users regardless of their browser window dimensions.

---

## Troubleshooting

**PubTerm connects but shows a blank screen or no BBS content**
- Confirm the BBS telnet daemon is running and listening on `PUBTERM_HOST:PUBTERM_PORT`.
- Test connectivity from the server: `telnet 127.0.0.1 2323`

**"telnet command not found" error on connect**
- Install the telnet client: `sudo apt install telnet` (Debian/Ubuntu) or `sudo dnf install telnet` (RHEL).
- Or set `PUBTERM_TELNET_BIN` in `.env` to the full path of your telnet binary.

**Guest sessions hit the concurrency limit immediately**
- Increase `guest_max_sessions` in the admin config, or check for stale sessions in the database (`door_sessions` table where `ended_at IS NULL` and `expires_at < NOW()`).
- Run `php scripts/setup.php` to trigger expired session cleanup.

**The BBS renders at 80×25 even after setting a larger terminal_size**
- Restart the multiplexing bridge after changing the config — it caches nothing, but a running session was started with the old size.
- Confirm the config was saved: check `config/nativedoors.json` directly.
