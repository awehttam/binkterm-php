# RLogin Doors

> **See also:** [Doors.md](Doors.md) for an overview of all door types and shared multiplexing bridge setup.

## Table of Contents

- [How It Works](#how-it-works)
- [Why RLogin Doors Are Database-Backed](#why-rlogin-doors-are-database-backed)
- [Creating a New RLogin Door](#creating-a-new-rlogin-door)
- [Door Fields](#door-fields)
- [BBS Type Presets](#bbs-type-presets)
- [Pre-Login Command](#pre-login-command)
- [Icons and Screenshots](#icons-and-screenshots)
- [Security Warning](#security-warning)
- [Limitations](#limitations)
- [Troubleshooting](#troubleshooting)

---

RLogin doors connect out from BinktermPHP to a remote BBS or service — such as a linked Synchronet system — over the rlogin protocol (RFC 1282), instead of running a local process the way DOS Doors and Native Doors do. This lets users reach games, message bases, or other features on a separate BBS without leaving their BinktermPHP terminal session.

## Multiplexing Bridge Setup

RLogin doors use the same multiplexing bridge as DOS and Native doors. Before RLogin doors will work, the bridge must be installed and running.

```bash
# Install bridge dependencies
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

1. A user clicks **Launch** on an RLogin door from the `/games` page (or selects it from the telnet/SSH door menu).
2. The web interface creates a door session via `POST /api/door/launch`. Before the session is created, BinktermPHP runs the door's **pre-login command**, if one is configured, so the remote account can be provisioned or synced just-in-time.
3. Once the pre-login command succeeds (or if none is configured), the door session is created and the multiplexing bridge looks up the door's connection details in the database, opens an outbound TCP connection to the configured host/port, and performs the rlogin handshake (client username, server username, and a terminal type/speed string).
4. The rlogin session is bridged to the browser (or telnet/SSH session) exactly like any other door — no drop file is generated, since there is no local process; the remote system is authoritative for the session from the handshake onward.
5. When the user disconnects or ends the session, the bridge closes the TCP connection.

## Why RLogin Doors Are Database-Backed

DOS Doors, Native Doors, JS-DOS Doors, and WebDoors are all backed by files on disk — a manifest plus an executable, ROM, or game assets that live in a directory. RLogin doors are different: there is no executable, no directory of game files, nothing that belongs on the filesystem. A door "is" just a set of connection parameters (host, port, username fields, terminal type) plus optional metadata (name, description, icon).

Because of that, RLogin doors are stored directly in PostgreSQL, in the `rlogin_doors` table, rather than as a manifest file plus a JSON runtime-config file the way the other door types work. This means:

- **No manifest directory to create.** There's no `rlogindoor.json` and no `rlogin-doors/doors/` folder to manage — everything is configured through **Admin → RLogin Doors**.
- **No generic Door Manifest Editor.** RLogin doors don't plug into the shared file-based manifest editor used by DOS/Native/JS-DOS/WebDoors (that editor is inherently directory-oriented — "discover unmanifested directories," file pickers, etc.). Instead, RLogin doors get their own small dedicated admin page with a standard add/edit/delete form.
- **Icons and screenshots are uploaded, not referenced by filename.** Since there's no door directory to hold an `icon.png`, uploaded images are stored as binary data directly in the `rlogin_doors` row and served from the database. See [Icons and Screenshots](#icons-and-screenshots).
- **No admin-daemon config writes.** The other door types' runtime config lives in JSON files the web process can't write directly (see [AdminDaemon.md](AdminDaemon.md)), so saving them goes through the admin daemon. RLogin doors are ordinary database rows, written directly from the admin API the same way most other admin data in BinktermPHP is — no daemon round-trip needed.

Doors are still synced into the shared `dosbox_doors` catalog table (`door_type='rlogin'`) whenever they're saved, so session tracking, credit deduction, and node/capacity limits all reuse the same `door_sessions`/`dosbox_doors` infrastructure the other door types share.

---

## Creating a New RLogin Door

1. Go to **Admin → RLogin Doors**.
2. Click **Add Door**.
3. Fill in the form — at minimum, a **Door ID**, **Display Name**, and **Host** are required.
4. Click **Save**.
5. Check **Enabled** (either when creating the door or afterward, via **Edit**) for it to appear in the game library.

The door will now appear in the `/games` game library, tagged with an **RLOGIN** badge.

---

## Door Fields

### Game info

| Field | Description |
|-------|-------------|
| Door ID | URL-safe identifier (`[A-Za-z0-9_-]+`). Set once at creation; cannot be changed afterward. |
| Display Name | Shown in the game library and admin list. |
| Short Name | Abbreviated name for small UI spaces. Defaults to Display Name if left blank. |
| Author | Optional attribution. |
| Version | Optional version string for the remote service/game. |
| Release Year | Optional. |
| Description | Shown on the game card. |
| Genre / Tags | Comma-separated list, e.g. `RPG, Strategy`. |
| Icon | Uploaded image, stored in the database (see [Icons and Screenshots](#icons-and-screenshots)). |
| Screenshot | Same as Icon. |

### Connection / rlogin handshake

| Field | Description |
|-------|-------------|
| BBS Type | UI preset (`Plain RLogin`, `Synchronet`, `Synchronet with BinktermPHP Service`) that only affects the form's suggested defaults — see [BBS Type Presets](#bbs-type-presets). Not stored as separate behavior; the runtime is driven entirely by the fields below. |
| Host | Hostname or IP address of the remote rlogin-accessible system. Required. |
| Port | TCP port. Defaults to `513` (the standard rlogin port). |
| Client Username | Local (`rlogin -l`) username sent to the remote host. Supports `{user_name}`, `{real_name}`, `{user_number}` placeholders. Defaults to `{user_name}`. |
| Server Username | Remote username the rlogin handshake requests. Same placeholders. Defaults to `{user_name}`. |
| Terminal Type | Sent as the rlogin terminal type field. Leave blank to use the connecting user's own established terminal type (their last-known TERM from a telnet/SSH session), falling back to `xterm-256color` when nothing is known — e.g. for a web-originated launch, where there's no negotiated terminal type to inherit. On Synchronet this field doubles as a routing signal — see below. |
| Terminal Speed | Sent alongside the terminal type as `type/speed`. Defaults to `38400`. |
| Output Encoding | `UTF-8` (default) or `CP437 (DOS)` for legacy CP437-only remote systems. |
| Pre-Login Command | Command run server-side before every connection attempt. See [Pre-Login Command](#pre-login-command). Leave blank to skip. |
| Pre-Login Timeout (sec) | Seconds to allow the pre-login command to run before it is killed and the launch fails. Defaults to `10`. |

### Requirements & runtime config

Same shape as the other door types: **Admin Only**, **Enabled**, **Credit Cost**, **Max Session Time**, **Max Concurrent Sessions**, **Allow Anonymous**, **Guest Max Sessions**, and **Hide from web (telnet/SSH only)**.

---

## BBS Type Presets

The BBS Type field is a UI convenience — every preset uses the exact same underlying mechanism (Pre-Login Command, Terminal Type, etc.). Picking a preset just prefills sensible defaults for that target when adding a door.

| BBS Type | What it prefills | Notes |
|---|---|---|
| **Plain RLogin** | No pre-login command | Connects directly via rlogin with the configured username. Use this for any generic rlogin-accessible system where the sysop manages accounts out of band, or the remote system auto-creates accounts on first rlogin. |
| **Synchronet** | No pre-login command, left blank for the sysop to fill in | For sysops with their own account-provisioning approach who don't want the bundled Service integration. |
| **Synchronet with BinktermPHP Service** | Pre-Login Command prefilled to invoke `scripts/rlogin_synchronet_service_client.php` | Automatic account creation/sync — the "batteries included" option. **Requires installing [binktermphp-synchronet](https://github.com/awehttam/binktermphp-synchronet) on the Synchronet system first** (`services.ini` service, MIT licensed). BinktermPHP only ships the *client* side of this call (the script above); without the Synchronet-side service installed and running, this preset's Pre-Login Command will fail and block every launch. See [Pre-Login Command](#pre-login-command) for the wire protocol. |

Terminal Type is also where Synchronet-specific routing lives: Synchronet's door server reads the client's reported terminal type to decide what to launch. Setting Terminal Type to something like `xtrn=LORD` (instead of a real terminal type) tells Synchronet to launch a specific door/xtrn program directly rather than dropping the user at the main menu. This is a Synchronet-side convention, not part of RFC 1282 itself — consult your Synchronet configuration for the exact `xtrn` codes it expects.

### Terminal Type Resolution

If Terminal Type is left blank, BinktermPHP resolves it at launch time, in order:

1. The connecting user's last-known TERM string, if they've logged in over telnet or SSH before — telnet captures it via TTYPE negotiation, SSH via the client's `pty-req`, and either is saved to that user's account (`users_meta` key `last_terminal_type`) the moment they log in.
2. `xterm-256color`, if nothing is known yet — most commonly because the door was launched from the web UI, where there's no negotiated terminal type to inherit in the first place.

This only applies when Terminal Type is blank. Setting an explicit value (including a Synchronet `xtrn=` routing code) always wins and skips this resolution entirely.

---

## Pre-Login Command

A pre-login command is a server-side helper that BinktermPHP runs — in PHP, as part of `POST /api/door/launch`, before the door session is created — so a remote account can be guaranteed to exist (and optionally kept in sync) by the time the rlogin connection is made.

### Input

The command template supports CLI placeholders, substituted before execution:

| Placeholder | Replaced with |
|-------------|----------------|
| `{user_name}` | BinktermPHP username |
| `{real_name}` | BinktermPHP username (used as the display/real name for door sessions) |
| `{user_number}` | BinktermPHP user ID (numeric) |

Example:

```
/usr/local/bin/provision-synchronet-user.sh {user_name} {real_name} {user_number}
```

### Output contract

- **Exit code `0`** — proceed with the rlogin connection.
- **Non-zero exit code** — abort the launch. The user sees a translated error and the rlogin connection is never attempted.
- **Optional JSON on stdout** (only read on success) — lets the command hand back values the launch step can't know in advance:
  ```json
  {"remote_username": "jsmith2", "otp": "a1b2c3"}
  ```
  - `remote_username`, if present, overrides the `{user_name}` substitution in Client/Server Username for this launch — useful when the remote account's username differs from the BinktermPHP username.
  - `otp`, if present, is stored on the session for door-specific use (e.g. a companion service that expects a one-time password embedded elsewhere in the handshake).
  - Empty or non-JSON stdout on a successful (exit `0`) run is treated as "no overrides."

### Timeout and idempotency

- Pre-Login Timeout (default 10 seconds) bounds how long BinktermPHP waits before killing a hung command and failing the launch.
- The command runs on **every** login attempt, not just the first — write it with "create if not exists" semantics, not "create or error."

### The bundled Synchronet Service client

`scripts/rlogin_synchronet_service_client.php` is the client half of this integration for the **Synchronet with BinktermPHP Service** preset. It reads connection details from `config/rlogin_synchronet_service.json` (a small standalone config file for this script only — unrelated to the door's own database row). Copy the example to get started:

```bash
cp config/rlogin_synchronet_service.json.example config/rlogin_synchronet_service.json
```

```json
{
    "host": "127.0.0.1",
    "port": 24512,
    "secret": "changeme",
    "timeout": 5
}
```

`secret` must match the `API_KEY` configured in the Synchronet-side `binktermphp-api.js` service.

It speaks a one-shot JSON-over-TCP protocol — one connection, one request, one response, then the connection closes — matching the [binktermphp-synchronet](https://github.com/awehttam/binktermphp-synchronet) `services.ini` service:

- **Request** (one line of JSON): `{"api_key":"<secret>","username":"{user_name}","real_name":"{real_name}"}`
- **Response** (one line of JSON), success: `{"success":true,"username":"...","user_number":42,"created":true}`
- **Response**, failure: `{"success":false,"error":"reason"}`

The script converts that response into the exit-code/JSON contract above (`username` from the response becomes `remote_username`). BinktermPHP ships only this client script — the Synchronet-side `services.ini` service itself lives in the separate [binktermphp-synchronet](https://github.com/awehttam/binktermphp-synchronet) repository that a sysop installs on the Synchronet system.

---

## Icons and Screenshots

Because RLogin doors have no directory on disk, icon and screenshot images are uploaded through **Admin → RLogin Doors** and stored as binary data (`BYTEA`) directly in the `rlogin_doors` row, alongside a stored MIME type. Accepted formats: PNG, JPEG, GIF, WebP, SVG.

They're served publicly at `/door-assets/{doorId}/icon` and `/door-assets/{doorId}/screenshot`, the same URL pattern used by the other door types — the route detects that a door ID belongs to an RLogin door and streams the image straight from the database instead of reading a file. To replace an image, upload a new one on the **Edit** form; to remove one entirely without replacing it, check **Remove image** and save.

---

## Security Warning

> **Only point Pre-Login Command at scripts you trust.**

The pre-login command runs on the server with the same privileges as the web process — the same trust level Native Doors' local executables run at. A malicious or buggy pre-login script can read/write any file the web server user can access, including `.env`, `config/binkp.json`, and the database, and can make outbound network connections. It runs on **every** door launch attempt, not just once, so treat it with the same care as any other server-side code path that executes on user action.

---

## Limitations

- **No live mid-session resize.** RFC 1282 has no standard mechanism for renegotiating terminal size after the initial handshake. Only the Terminal Type/Terminal Speed sent at connect time applies for the whole session; resizing the browser window mid-session has no effect on the remote system's view of the terminal.
- **No guest/anonymous access yet.** Unlike Native Doors, RLogin doors do not currently support `POST /api/door/guest/launch`. Allow Anonymous/Guest Max Sessions exist for parity but are not yet wired up to a guest launch path.

---

## Troubleshooting

**Door does not appear in the game library after saving**
- Confirm **Enabled** is checked on the door
- Confirm Display Name and Host were both provided
- Check `data/logs/dosdoor.log` for sync errors

**Launch fails immediately with a "rejected the login request" error**
- The door has a Pre-Login Command configured and it exited non-zero, or timed out
- Run the command manually with the same arguments to see its actual output/exit code
- Check Pre-Login Timeout if the command is slow

**Connection opens but the remote system shows a blank or unexpected screen**
- Confirm Host/Port are correct and reachable from the server running the multiplexing bridge (not from your browser)
- Confirm Client/Server Username resolve to a real account on the remote system
- If targeting Synchronet with a routing Terminal Type (e.g. `xtrn=LORD`), confirm the `xtrn` code is configured on the Synchronet side

**Session does not clean up after exit**
- The bridge closes the TCP connection when the user disconnects; if the remote system holds the socket open past that, check the remote system's own idle/timeout handling
