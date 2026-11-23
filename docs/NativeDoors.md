# Native Doors

> **See also:** [Doors.md](Doors.md) for an overview of all door types and shared multiplexing bridge setup.

## Table of Contents

- [How It Works](#how-it-works)
- [File Structure](#file-structure)
- [Creating a New Door](#creating-a-new-door)
- [nativedoor.json Format](#nativedoorjson-format)
- [Environment Variables](#environment-variables)
- [Drop File](#drop-file)
- [Terminal Settings](#terminal-settings)
- [Platform Notes](#platform-notes)
- [Configuration File](#configuration-file)
- [Included Test Doors](#included-test-doors)
- [Security Warning](#security-warning)
- [Troubleshooting](#troubleshooting)

---

Native doors are BBS door programs that run as native Linux binaries or Windows executables, launched directly via PTY (pseudo-terminal). Unlike DOS doors, they require no emulator — the program runs as a regular system process with full ANSI/VT100 terminal support.

## Multiplexing Bridge Setup

Native doors use the same multiplexing bridge as DOS doors. Before native doors will work, the bridge must be installed and running.

**Quick start:**

```bash
# Install bridge dependencies (includes node-pty for native door PTY support)
cd scripts/dosbox-bridge
npm install

# Start the bridge (interactive)
node multiplexing-server.js

# Or run as a background daemon
node multiplexing-server.js --daemon
```

For full setup instructions including production service configuration, environment variables, and reverse proxy setup, see [Doors.md](Doors.md).

---

## How It Works

1. A user clicks **Launch** on a native door from the `/games` page.
2. The web interface creates a door session via the API and opens the xterm.js terminal player in an iframe.
3. The multiplexing bridge (Node.js) reads the session from the database, spawns the door executable via `node-pty`, and bridges the WebSocket to the PTY.
4. A DOOR.SYS drop file is written to `native-doors/drops/NODE{n}/DOOR.SYS` and user data is injected as environment variables.
5. When the door exits (or the user disconnects), the PTY is killed and the session is cleaned up.

## File Structure

```
binkterm-php/
├── native-doors/
│   ├── doors/                              # Install doors here
│   │   ├── linuxdoortest/                  # Bundled test door (Linux)
│   │   ├── windoortest/                    # Bundled test door (Windows)
│   │   └── mydoor/                         # Example custom door
│   │       ├── nativedoor.json             # Door manifest (required)
│   │       ├── mydoor.sh                   # Executable (or binary, .bat, etc.)
│   │       └── icon.png                    # Optional icon (64×64 PNG)
│   └── drops/                              # Generated at runtime — do not edit
│       ├── NODE1/
│       │   └── DOOR.SYS
│       └── NODE2/
│           └── DOOR.SYS
└── config/
    └── nativedoors.json                    # Runtime config (managed by admin panel)
```

Each door lives in its own subdirectory under `native-doors/doors/`. The directory name is the door's ID — it is used in URLs and the database, so it must be lowercase with no spaces (e.g. `lord`, `mygame`, `linuxdoortest`).

## Creating a New Door

### 1. Create the door directory

```bash
mkdir -p native-doors/doors/mydoor
```

### 2. Create the manifest

The easiest way is through the admin interface:

1. Go to **Admin → Native Doors**.
2. In the **Add New Door** panel, find your door directory and click **Create Manifest**.
3. Fill in the form. Set **Executable** to the script or binary that launches the door (e.g. `mydoor.sh`).
4. Click **Create Manifest** to save.

The manifest is saved as `native-doors/doors/mydoor/nativedoor.json`. See the [manifest format](#nativedoorjson-format) section for the full field reference. A minimal example:

```json
{
  "type": "nativedoor",
  "version": "1.0",
  "managed": "web",
  "game": {
    "name": "My Door",
    "short_name": "MYDOOR",
    "author": "Author Name",
    "version": "1.0",
    "release_year": 2026,
    "description": "A short description shown in the game library.",
    "genre": ["Action"],
    "players": "Single-player",
    "icon": null,
    "screenshot": null
  },
  "door": {
    "executable": "mydoor.sh",
    "launch_command": "/bin/bash mydoor.sh",
    "dropfile_format": "DOOR.SYS",
    "max_nodes": 10,
    "ansi_required": false,
    "time_per_day": 30
  },
  "requirements": {
    "admin_only": false
  },
  "config": {
    "enabled": false,
    "credit_cost": 0,
    "max_time_minutes": 30,
    "max_sessions": 10
  }
}
```

### 3. Place the executable

Copy your program into the door directory. Make sure it is executable on Linux:

```bash
chmod +x native-doors/doors/mydoor/mydoor.sh
```

For compiled binaries, the same applies:

```bash
chmod +x native-doors/doors/mydoor/mydoor
```

### 4. Enable the door in the admin panel

1. Go to **Admin → Native Doors**.
2. Click **Sync Doors** to import newly installed doors.
3. Find your door in the list and toggle it on.
4. Click **Save Configuration**.

The door will now appear in the `/games` game library.

---

## nativedoor.json Format

### Top-level fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `type` | string | Yes | Must be `"nativedoor"` |
| `version` | string | Yes | Manifest format version. Use `"1.0"` |
| `game` | object | Yes | Game metadata (see below) |
| `door` | object | Yes | Launch and technical settings (see below) |
| `requirements` | object | No | Access requirements (see below) |
| `config` | object | No | Default runtime configuration (see below) |

### `game` object

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Display name shown in the game library |
| `short_name` | string | No | Abbreviated name (uppercase, no spaces). Defaults to `name` |
| `author` | string | No | Author or publisher. Defaults to `"Unknown"` |
| `version` | string | No | Door game version number |
| `release_year` | integer | No | Year the door was written or released |
| `description` | string | No | Short description shown on the game card |
| `genre` | array | No | Array of genre strings, e.g. `["RPG", "Strategy"]` |
| `players` | string | No | Player count description. Defaults to `"Single-player"` |
| `icon` | string\|null | No | Filename of an icon image (64×64 PNG) in the door directory |
| `screenshot` | string\|null | No | Filename of a screenshot image in the door directory |

### `door` object

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `executable` | string | Yes | Filename of the main executable relative to the door directory |
| `launch_command` | string | No | Full command to run. Supports `{node}`, `{dropfile}`, `{user_number}`, and `{homedir}` placeholders (see below). Defaults to `executable` |
| `dropfile_format` | string | No | Drop file format. `"DOOR.SYS"` (default), `"DOOR32.SYS"`, or `"BBSDEV.DRP"` (experimental) |
| `output_encoding` | string | No | Character encoding of the door's output. `"utf8"` (default) or `"cp437"`. Use `"cp437"` for legacy DOS-style doors that output CP437 box-drawing and ANSI art |
| `max_nodes` | integer | No | Maximum simultaneous sessions. Defaults to `10` |
| `ansi_required` | boolean | No | Whether ANSI is required. Defaults to `true` |
| `time_per_day` | integer | No | Time limit in minutes per day. Defaults to `30` |
| `env` | object | No | Static environment variables to set for the door, as name/value strings. Any valid name may be used, including ones that look like secrets (for example `WX_API_KEY`), because the value is the literal one written in the manifest and is never read from the bridge. It is stored in plain text in `nativedoor.json`, so do not put a secret you share with other systems there |
| `env_passthrough` | array of strings | No | Names of variables the door would like copied from the bridge's environment. This is a request only: a variable is passed only after a sysop approves it for the door in **Admin → Native Doors** |

#### Launch command placeholders

The `launch_command` string may contain the following placeholders, which are substituted at launch time:

| Placeholder | Replaced with |
|-------------|---------------|
| `{node}` | Node number (e.g. `1`) |
| `{dropfile}` | Full path to the DOOR.SYS file (e.g. `/srv/bbs/native-doors/drops/NODE1/DOOR.SYS`) |
| `{user_number}` | BBS user ID (numeric) |
| `{homedir}` | Full path to the user's private per-door home directory (e.g. `/srv/bbs/data/users/42/mydoor`), for doors that keep their own state — save games, per-user config overlays, etc. Created automatically before launch if it does not already exist. Also available as the `DOOR_HOME` environment variable |

**Examples:**

```json
"launch_command": "/bin/bash mydoor.sh"
"launch_command": "./mydoor --node {node} --dropfile {dropfile}"
"launch_command": "cmd.exe /c mydoor.bat"
"launch_command": "./mydoor --node {node} --dropfile {dropfile} -home {homedir}"
```

If `launch_command` is omitted, `executable` is used directly as the command with no arguments.

### `requirements` object

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `admin_only` | boolean | `false` | If `true`, only admin users can launch the door |

### `config` object

These are the default settings applied when a door is first synced. They can be overridden at any time through **Admin → Native Doors** without editing the manifest.

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `enabled` | boolean | `false` | Whether the door is available to users. Always `false` in the manifest — enable through the admin panel |
| `credit_cost` | integer | `0` | Credits deducted per session launch (0 = free) |
| `max_time_minutes` | integer | `30` | Maximum session length in minutes |
| `max_sessions` | integer | `10` | Maximum concurrent sessions |

---

## Environment Variables

The following environment variables are set in the door process at launch:

| Variable | Description | Example |
|----------|-------------|---------|
| `DOOR_USER_NAME` | User's handle | `Sysop` |
| `DOOR_USER_REAL_NAME` | User's real name | `John Smith` |
| `DOOR_USER_NUMBER` | BBS user ID (numeric) | `42` |
| `DOOR_NODE` | Node number | `1` |
| `DOOR_BBS_NAME` | BBS name from configuration | `My BBS` |
| `DOOR_DROPFILE` | Full path to the drop file | `/srv/bbs/native-doors/drops/NODE1/DOOR.SYS` |
| `BBSDEV_DRP` | Full path to the `BBSDEV.DRP` file (only when `dropfile_format` is `BBSDEV.DRP`) | `/srv/bbs/native-doors/drops/NODE1/BBSDEV.DRP` |
| `DOOR_ANSI` | Always `1` (ANSI assumed) | `1` |
| `TERM` | Terminal type | `xterm-256color` |

A door's environment is built from scratch. It does **not** inherit the multiplexing bridge's environment, so secrets from `.env` are never visible to the door. In addition to the variables above, the door receives:

| Variable | Value |
|----------|-------|
| `PATH` | `/usr/local/bin:/usr/bin:/bin`, or the value of `NATIVEDOOR_PATH` in `.env` |
| `HOME` | The user's private per-door directory (same as `DOOR_HOME`) |
| `USER`, `LOGNAME` | The operating system user the bridge runs as |
| `LANG` | `C.UTF-8` |
| `COLUMNS`, `LINES` | The terminal size |
| `PUBTERM_HOST`, `PUBTERM_PORT`, `PUBTERM_TELNET_BIN` | PubTerm door only, supplied by the bridge |
| `door.env` values | Static values from the manifest |
| Approved `door.env_passthrough` names | Copied from the bridge's environment, only after sysop approval |

Variable names that look like secrets can never be approved for passthrough, and are never substituted into `${VAR:-default}` references in a launch command (the default is used instead), because both read from the bridge's own environment. They can be set as literals in `door.env`, since that value comes from the manifest. The bridge logs the names of any manifest requests that were not approved. At debug level it also logs the names, never the values, of the variables each door received.

---

## Drop File

A drop file is generated and written to `native-doors/drops/NODE{n}/` before the door is launched. Three formats are supported, selected via `dropfile_format` in the manifest:

### DOOR.SYS (default)

The classic 52-line format compatible with most traditional BBS door games. Written to `DOOR.SYS`.

```json
"dropfile_format": "DOOR.SYS"
```

### DOOR32.SYS

An 11-line format designed for modern doors, as an alternative to DOOR.SYS's serial/FOSSIL-oriented fields. Written to `DOOR32.SYS`. Native doors are spawned over a PTY (stdio), not a telnet socket, so comm type is `0` (local/stdio) here — not the `2` (telnet/socket) a real telnet-gatewayed door would see.

```json
"dropfile_format": "DOOR32.SYS"
```

The DOOR32.SYS fields are:

| Line | Field | Value |
|------|-------|-------|
| 1 | Comm type | `0` (local/stdio) |
| 2 | Comm handle | `0` |
| 3 | Baud rate | `0` |
| 4 | BBS name | From user data |
| 5 | User record number | From user data |
| 6 | User's real name | From user data |
| 7 | User's handle/alias | From user data |
| 8 | Security level | From user data |
| 9 | Time left (minutes) | From user data |
| 10 | ANSI | `1` (always) |
| 11 | Node number | Session node number |

### BBSDEV.DRP (experimental — untested)

> **Experimental.** This format is implemented per the [BBSDEV.DRP v1.0 spec](https://realdeuce.github.io/bbsdev.drp/) but has not been tested against a real door. Report issues on GitHub.

A modern, line-oriented drop file: exactly 19 lines, UTF-8 without BOM, CRLF line endings. Written to `BBSDEV.DRP`. The absolute path is also passed to the door in the `BBSDEV_DRP` environment variable (the legacy `DOOR_DROPFILE` variable is still set as well).

```json
"dropfile_format": "BBSDEV.DRP"
```

| Line | Field | Value |
|------|-------|-------|
| 1 | Format version | `1.0` |
| 2 | Communications type | `stdio` (native doors run over a PTY) |
| 3 | Communications parameters | empty |
| 4 | User alias | username (`Guest` for anonymous) |
| 5 | Unique user key | numeric user ID |
| 6 | Screen width | door `terminal_size` width, default `80` |
| 7 | Screen height | door `terminal_size` height, default `25` |
| 8 | ANSI enabled | `Y` |
| 9 | RIP enabled | `N` |
| 10 | CTerm version | empty |
| 11 | Logoff deadline | empty (no forced logoff) |
| 12 | Terminal encoding | `IBM437` when `output_encoding` is `cp437`, otherwise `UTF-8` |
| 13 | Language | user locale as a BCP 47 tag, default `en-US` |
| 14 | BBS software | `BinktermPHP <version>` |
| 15 | Board name | BBS name |
| 16 | Sysop alias | sysop name |
| 17 | Access level | `sysop` for admin accounts, otherwise `50` |
| 18 | Node number | session node number |
| 19 | Show local display | `N` |

A door configured for `BBSDEV.DRP` receives only that file — no `DOOR.SYS` is written.

The same user data is also available via environment variables (see above), so simple doors do not need to parse any drop file format at all.

---

## Terminal Settings

Doors run in an `xterm-256color` PTY. ANSI escape sequences are passed through directly — no character encoding conversion is applied (unlike DOS doors which convert CP437). Write UTF-8 or plain ANSI to stdout.

### Terminal size

The default PTY size is **80 columns × 25 rows**. For doors that benefit from a wider canvas (such as PubTerm), the initial terminal dimensions can be set per-door in `config/nativedoors.json` via the `terminal_size` key:

```json
{
  "pubterm": {
    "enabled": true,
    "terminal_size": "132x43"
  }
}
```

Accepted values are `"WxH"` strings (e.g. `"80x25"`, `"132x24"`, `"132x43"`, `"132x50"`) or `"autofit"` to size the canvas to the user's browser window. The PTY is spawned at the configured dimensions and the BBS receives the correct NAWS terminal size at connection time.

> **Note:** `"autofit"` sizes the xterm.js canvas to fill the browser window and sets the initial BBS dimensions accordingly. Mid-session resize (NAWS updates when the user resizes their browser) is not currently supported — the system `telnet` client used by PubTerm does not forward PTY window-change signals to the BBS. The BBS will start at the correct size but will not adapt if the user resizes their browser mid-session.

The `terminal_size` setting is read from `config/nativedoors.json` (the admin-configurable runtime config) rather than the static `nativedoor.json` manifest, so it can be changed through **Admin → Native Doors** without touching the door files.

---

## Platform Notes

### Linux

- Shell scripts must have a shebang line (e.g. `#!/bin/bash`)
- Compiled binaries must be built for the host architecture
- Executables must have the execute bit set (`chmod +x`)

### Windows

- Use `cmd.exe /c` in `launch_command` for `.bat` files:
  ```json
  "launch_command": "cmd.exe /c mydoor.bat"
  ```
- Use the full path to an interpreter if it is not on `PATH`

---

## Launch Modes

By default a native door runs as the same operating system user as the multiplexing bridge (`direct`). On Linux and BSD you can run a door as a separate, unprivileged user instead, so a door cannot read `.env`, `config/binkp.json` or any other application file. Choose the mode per door in **Admin → Native Doors**: click the edit button for the door and set **Launch Mode**. The mode is a sysop decision and can never be set from a door's manifest. Windows and macOS always run doors directly, and the setting is ignored with a log notice.

| Mode | How the door is started | Use it when |
|------|-------------------------|-------------|
| Direct | The bridge starts the door itself, as the bridge's user | Default. The only mode on Windows and macOS |
| Sudo | The bridge starts the door with `sudo -n -u <door user>` | The bridge runs as a normal user on a bare-metal install |
| Socket | The bridge asks a small launcher process that already runs as the door user (`scripts/dosbox-bridge/door-launcher.js`) to start the door | You can run a launcher per door user under systemd or supervisor, including in Docker |

A door with no launch mode uses the server default, which is `direct` unless `NATIVEDOOR_LAUNCH_MODE` is set in `.env`. Once any door, or the server default, uses `sudo` or `socket`, a door with no resolved mode is refused rather than run as `direct`. Set `direct` explicitly on a door that should stay that way.

In Sudo mode, **Door User** is the account the door runs as. Only members of the door group (`doors`, or the group named by `NATIVEDOOR_DOORS_GROUP`) are offered. In Socket mode, **Launcher Socket** names the launcher to use. Only sockets that currently exist in the socket directory (`/run/binkterm-doors/<name>/launcher.sock`, or `NATIVEDOOR_SOCKET_DIR`) are offered. These lists come from the operating system, not from a file the web server can write, so the admin interface cannot be used to pick an arbitrary account. The bridge also refuses `root` and its own user.

Server defaults in `.env`:

| Variable | Description |
|----------|-------------|
| `NATIVEDOOR_LAUNCH_MODE` | `direct`, `sudo` or `socket`, for doors that set no mode |
| `NATIVEDOOR_RUN_AS_USER` | Default door user for sudo mode |
| `NATIVEDOOR_LAUNCHER_SOCKET` | Default launcher socket name for socket mode. Falls back to the door user |
| `NATIVEDOOR_SOCKET_DIR` | Directory holding one subdirectory per launcher socket. Default `/run/binkterm-doors` |
| `NATIVEDOOR_DOORS_GROUP` | Group whose members may be used as door users. Default `doors` |

### Setting up a door user

The launch modes do nothing until the operating system is prepared. Run `scripts/setup_door_user.sh` as root (see [Door User Setup](CLI.md#door-user-setup)); it can be previewed first with `--dry-run`. In a Docker installation the container does this for you (see [Docker](#docker)). For one door per account:

```bash
sudo scripts/setup_door_user.sh sudo --door syncdoom --user door_syncdoom        # sudo mode
sudo scripts/setup_door_user.sh systemd --door syncdoom --user door_syncdoom     # socket mode, systemd
```

It does the following, which you can also do by hand:

- Creates one unprivileged account per door user, each with its own primary group, adds the accounts to the shared `doors` group, and adds the bridge's user (and the web server user, if you give one) to each door user's group.
- With `--apply-permissions` only (the Docker image uses it; on bare metal you set permissions yourself): makes `.env`, `config/` and `data/` unreadable to the door users. `data/` and `data/users/` stay traverse-only so a door can reach its own directory.
- With `--apply-permissions` only: gives each door directory under `native-doors/doors/` to the bridge's user with the door's group and mode `3770`, makes `native-doors/doors/` itself traverse-only, and gives the door user any files the manifest lists under `door.writable_paths`. The bridge creates each user's `data/users/<id>/<door>` directory with the door's group and mode `2770`.
- Sudo mode: installs a sudoers rule for the bridge's user, `bridgeuser ALL=(%doors) NOPASSWD: ALL`, together with `Defaults:bridgeuser !use_pty`, checked with `visudo -c` first. Without `!use_pty`, sudo inserts a second terminal that breaks raw output, resizing and teardown.
- Socket mode: runs `door-launcher.js` as each door user, with a clean environment, listening on `/run/binkterm-doors/<user>/launcher.sock` in a directory the bridge's user can traverse and other door users cannot. Under systemd it is socket-activated (`LISTEN_FDS`) with `NoNewPrivileges`, a read-only filesystem apart from the door's own directories, and `.env` and `config/` made inaccessible. The launcher reads these from its own environment, never from a file the web server can write:

| Variable | Description |
|----------|-------------|
| `NATIVEDOOR_LAUNCHER_SOCKET` | Socket path, when not socket-activated |
| `NATIVEDOOR_LAUNCHER_EXEC_ROOTS` | Colon-separated directories doors may be run from. Default `native-doors/doors/` |
| `NATIVEDOOR_LAUNCHER_INTERPRETERS` | Colon-separated interpreters allowed to run a script inside those directories. Default `/bin/bash:/usr/bin/bash:/bin/sh`. Options that run inline code, such as `-c`, are refused |

### Docker

The container image ships the support but leaves it off. Set `NATIVEDOOR_DOOR_USERS` for the service in `docker-compose.yml` (or the shell or `.env` that Compose reads) to a space-separated list of door ids, each optionally `id=user`, for example `syncdoom bbslinknative=door_bbs`. On every start the container then creates those users, applies the permissions above, and runs a launcher for each as a supervisor program. Afterwards choose **Socket** for the door in **Admin → Native Doors**, or set `NATIVEDOOR_LAUNCH_MODE=socket` in `.env` to make it the default.

Until a door's mode is changed it keeps running as `binkterm`, the bridge's own user, and can read `.env` and the bridge's `/proc/<pid>/environ`. It is not on by default because bind-mounted volumes on some hosts (Docker Desktop in particular) ignore ownership and mode changes, so the door user may not be kept away from `.env` or able to write its own directory there.

The container also no longer leaves `data/`, `config/` and `dosbox-bridge/` readable by other users: they are `770` (directories) and `660` (files).

### Failing closed

Before every launch the bridge checks that the door user exists, is not root or the bridge's own user, and can be reached (`sudo -n -u <user> true`, or a handshake with the launcher). It also confirms that the door user can write its own `data/users/<id>/<door>` directory. The bridge does not check whether the door user can read `.env` or `config/`; keeping door users away from those files is a matter of file permissions that you set up (or `--apply-permissions`, or the Docker image, does). If any check fails, the launch is refused and the reason is logged. A door is never started as the bridge's user as a fallback. The bridge logs the state of each non-direct door when it starts and when it is reloaded with `SIGHUP`.

To verify a setup, enable the bundled `linuxdoortest` door, set its Launch Mode and launch it.

## Configuration File

Runtime configuration (enabled/disabled, credit cost, session limits) is stored in `config/nativedoors.json`. This file is managed by the admin panel and the admin daemon — **do not edit it directly while the BBS is running**.

The file structure is a JSON object keyed by door ID:

```json
{
  "linuxdoortest": {
    "enabled": true,
    "credit_cost": 0,
    "max_time_minutes": 30,
    "max_sessions": 10
  },
  "mydoor": {
    "enabled": false,
    "credit_cost": 5,
    "max_time_minutes": 60,
    "max_sessions": 5
  }
}
```

All supported keys per door entry:

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | boolean | `false` | Whether the door is available to users |
| `credit_cost` | integer | `0` | Credits deducted per session |
| `max_time_minutes` | integer | `30` | Maximum session length in minutes |
| `max_sessions` | integer | `10` | Maximum concurrent sessions |
| `allow_anonymous` | boolean | `false` | Allow unauthenticated guest access (requires `credit_cost: 0`) |
| `guest_max_sessions` | integer | `2` | Maximum concurrent guest sessions when `allow_anonymous` is true |
| `terminal_size` | string | `"80x25"` | Initial PTY and canvas dimensions. Accepted values: `"80x25"`, `"132x24"`, `"132x43"`, `"132x50"`, or `"autofit"`. See [Terminal Settings](#terminal-settings) |
| `env_passthrough_approved` | array of strings | none | Variables from the door's manifest `env_passthrough` request that the sysop has approved. Set through **Admin → Native Doors**; names that look like secrets are removed when the configuration is saved |
| `launch_mode` | string | server default | `direct`, `sudo` or `socket`. See [Launch Modes](#launch-modes) |
| `run_as` | string | `NATIVEDOOR_RUN_AS_USER` | Door user for sudo mode |
| `launcher_socket` | string | `NATIVEDOOR_LAUNCHER_SOCKET`, else the door user | Launcher socket name for socket mode |
| `hide_from_web` | boolean | `false` | When `true`, hides this door from the web games list and blocks its `/games/nativedoors/{doorid}` web player page (`404`). The door remains launchable over telnet/SSH via **[Files] → Door Games** (`telnet/src/DoorHandler.php`), since that path calls `POST /api/door/launch` directly rather than going through the web listing/player route. Use this for doors that only make sense in a terminal (e.g. FOSSIL/ANSI-only games) or that a sysop wants restricted to the terminal server. |

---

## Included Test Doors

Two test doors are bundled in `native-doors/doors/` to verify the system is working:

| Door ID | Platform | Description |
|---------|----------|-------------|
| `linuxdoortest` | Linux | Bash script — displays "hello world" and waits for a keypress |
| `windoortest` | Windows | Batch file — displays "hello world" and waits for a keypress |

Both are disabled by default. Enable them through **Admin → Native Doors** to test your setup.

---

## Security Warning

> **Only install native doors from sources you trust.**

Native doors receive a clean environment without the secrets from `.env`. Unless you set up a separate door user (see [Launch Modes](#launch-modes)), they still run as the same operating system user as the multiplexing bridge. A door that drops to a shell, spawns subprocesses, or reads arbitrary files on disk does so with the full permissions of that user — including read access to `.env`, configuration files, private keys, and all BBS data. Running a door as a separate user limits it to what that user can read.

**Key risks to be aware of:**

- **Shell escape** — if a door provides any mechanism to execute shell commands (e.g. a built-in editor, help viewer using `less`, or debug mode), a user can break out and run arbitrary commands on the server.
- **File system access** — the door can read and write any file the web server user can access, including `.env`, `config/binkp.json`, and the database.
- **Network access** — the door can make outbound network connections.

---

## Troubleshooting

**Door does not appear in the game library after sync**
- Confirm `nativedoor.json` exists in the door directory and is valid JSON
- Check that `"type": "nativedoor"` and `"game.name"` are present
- Check the PHP error log for manifest parse errors

**Door launches but the screen is blank**
- Confirm the executable exists and is executable (`chmod +x`)
- Confirm the `launch_command` path is correct
- Test the command manually in a terminal to verify it runs

**Door exits immediately**
- Run the executable manually in a terminal to see error output
- Check that all required dependencies (libraries, interpreters) are installed on the host

**Drop file is not being written**
- Confirm `native-doors/drops/` exists and is writable by the user running the bridge
- The bridge creates `NODE{n}` subdirectories automatically

**Session does not clean up after exit**
- Ensure the door process exits cleanly when stdin is closed or a hangup signal (`SIGHUP`) is received
- The bridge sends `SIGHUP` to the PTY when the user disconnects
