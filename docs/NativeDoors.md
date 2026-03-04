# Native Doors

Native doors are BBS door programs that run as native Linux binaries or Windows executables, launched directly via PTY (pseudo-terminal). Unlike DOS doors, they require no emulator — the program runs as a regular system process with full ANSI/VT100 terminal support.

## How It Works

1. A user clicks **Launch** on a native door from the `/games` page.
2. The web interface creates a door session via the API and opens the xterm.js terminal player in an iframe.
3. The multiplexing bridge (Node.js) reads the session from the database, spawns the door executable via `node-pty`, and bridges the WebSocket to the PTY.
4. A DOOR.SYS drop file is written to `native-doors/drops/NODE{n}/DOOR.SYS` and user data is injected as environment variables.
5. When the door exits (or the user disconnects), the PTY is killed and the session is cleaned up.

## Directory Structure

```
native-doors/
  doors/                   ← install doors here
    mydoor/
      nativedoor.json      ← required manifest
      mydoor.sh            ← executable (or binary, .bat, etc.)
      icon.png             ← optional icon (64×64)
  drops/                   ← generated at runtime, do not edit
    NODE1/
      DOOR.SYS
    NODE2/
      DOOR.SYS
```

Each door lives in its own subdirectory under `native-doors/doors/`. The directory name is the door's ID — it is used in URLs and the database, so it must be lowercase with no spaces (e.g. `lord`, `mygame`, `linuxdoortest`).

## Creating a New Door

### 1. Create the door directory

```bash
mkdir -p native-doors/doors/mydoor
```

### 2. Write the manifest

Create `native-doors/doors/mydoor/nativedoor.json`. See the [manifest format](#nativedoorjson-format) section below for full details.

Minimal example:

```json
{
  "type": "nativedoor",
  "version": "1.0",
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
| `launch_command` | string | No | Full command to run. Supports `{node}`, `{dropfile}`, and `{user_number}` placeholders (see below). Defaults to `executable` |
| `dropfile_format` | string | No | Drop file format. `"DOOR.SYS"` (default) or `"DOOR32.SYS"` |
| `output_encoding` | string | No | Character encoding of the door's output. `"utf8"` (default) or `"cp437"`. Use `"cp437"` for legacy DOS-style doors that output CP437 box-drawing and ANSI art |
| `max_nodes` | integer | No | Maximum simultaneous sessions. Defaults to `10` |
| `ansi_required` | boolean | No | Whether ANSI is required. Defaults to `true` |
| `time_per_day` | integer | No | Time limit in minutes per day. Defaults to `30` |

#### Launch command placeholders

The `launch_command` string may contain the following placeholders, which are substituted at launch time:

| Placeholder | Replaced with |
|-------------|---------------|
| `{node}` | Node number (e.g. `1`) |
| `{dropfile}` | Full path to the DOOR.SYS file (e.g. `/srv/bbs/native-doors/drops/NODE1/DOOR.SYS`) |
| `{user_number}` | BBS user ID (numeric) |

**Examples:**

```json
"launch_command": "/bin/bash mydoor.sh"
"launch_command": "./mydoor --node {node} --dropfile {dropfile}"
"launch_command": "cmd.exe /c mydoor.bat"
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
| `DOOR_DROPFILE` | Full path to the DOOR.SYS file | `/srv/bbs/native-doors/drops/NODE1/DOOR.SYS` |
| `DOOR_ANSI` | Always `1` (ANSI assumed) | `1` |
| `TERM` | Terminal type | `xterm-256color` |

The process also inherits the environment of the multiplexing bridge, including `PATH`.

---

## Drop File

A drop file is generated and written to `native-doors/drops/NODE{n}/` before the door is launched. Two formats are supported, selected via `dropfile_format` in the manifest:

### DOOR.SYS (default)

The classic 52-line format compatible with most traditional BBS door games. Written to `DOOR.SYS`.

```json
"dropfile_format": "DOOR.SYS"
```

### DOOR32.SYS

An 11-line format designed for modern doors running over telnet/socket connections. Written to `DOOR32.SYS`. Use this for doors that expect a socket-style connection rather than a serial/FOSSIL interface.

```json
"dropfile_format": "DOOR32.SYS"
```

The DOOR32.SYS fields are:

| Line | Field | Value |
|------|-------|-------|
| 1 | Comm type | `2` (telnet/socket) |
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

The same user data is also available via environment variables (see above), so simple doors do not need to parse either drop file format at all.

---

## Terminal Settings

Doors run in an `xterm-256color` PTY at **80 columns × 25 rows**. ANSI escape sequences are passed through directly — no character encoding conversion is applied (unlike DOS doors which convert CP437). Write UTF-8 or plain ANSI to stdout.

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

Native doors run as the same operating system user as the BinktermPHP web server and multiplexing bridge. A door that drops to a shell, spawns subprocesses, or reads arbitrary files on disk does so with the full permissions of that user — including access to your database credentials, configuration files, private keys, and all BBS data.

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
