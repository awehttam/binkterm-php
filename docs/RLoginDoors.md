# RLogin Doors

> **See also:** [Doors.md](Doors.md) for an overview of all door types and shared multiplexing bridge setup.

## Table of Contents

- [How It Works](#how-it-works)
- [Why RLogin Doors Are Database-Backed](#why-rlogin-doors-are-database-backed)
- [Creating a New RLogin Door](#creating-a-new-rlogin-door)
- [Door Fields](#door-fields)
- [BBS Type Presets](#bbs-type-presets)
- [Pre-Login Command](#pre-login-command)
- [Import from Synchronet](#import-from-synchronet)
- [Icons and Screenshots](#icons-and-screenshots)
  - [Generating an Icon with AI](#generating-an-icon-with-ai)
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
| Client Username | Local (`rlogin -l`) username sent to the remote host. Supports `{user_name}`, `{real_name}`, `{user_number}` placeholders. `{user_name}` is shown as a placeholder hint in the admin form, but the field itself has no default — leave it blank to send an empty username field, if the remote rlogin daemon accepts that. |
| Server Username | Remote username the rlogin handshake requests. Same placeholders, same blank-is-valid behavior as Client Username. |
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
| **Synchronet with BinktermPHP Service** | Pre-Login Command prefilled to invoke `scripts/synchronet_add_user.php` | Automatic account creation/sync — the "batteries included" option. **Requires installing [binktermphp-synchronet](https://github.com/awehttam/binktermphp-synchronet) on the Synchronet system first** (`services.ini` service, MIT licensed). BinktermPHP only ships the *client* side of this call (the script above); without the Synchronet-side service installed and running, this preset's Pre-Login Command will fail and block every launch. See [Pre-Login Command](#pre-login-command) for the wire protocol. |

Terminal Type is also where Synchronet-specific routing lives: Synchronet's door server reads the client's reported terminal type to decide what to launch. Setting Terminal Type to something like `xtrn=LORD` (instead of a real terminal type) tells Synchronet to launch a specific door/xtrn program directly rather than dropping the user at the main menu. This is a Synchronet-side convention, not part of RFC 1282 itself — consult your Synchronet configuration for the exact `xtrn` codes it expects.

### Terminal Type Resolution

If Terminal Type is left blank, BinktermPHP resolves it at launch time, in order:

1. The connecting user's last-known TERM string, if they've logged in over telnet or SSH before — telnet captures it via TTYPE negotiation, SSH via the client's `pty-req`, and either is saved to that user's account (`users_meta` key `last_terminal_type`) the moment they log in.
2. `xterm-256color`, if nothing is known yet — most commonly because the door was launched from the web UI, where there's no negotiated terminal type to inherit in the first place.

This only applies when Terminal Type is blank. Setting an explicit value (including a Synchronet `xtrn=` routing code) always wins and skips this resolution entirely.

---

## Pre-Login Command

A pre-login command is a server-side helper that BinktermPHP runs — in PHP, as part of `POST /api/door/launch`, before the door session is created — so a remote account can be guaranteed to exist (and optionally kept in sync) by the time the rlogin connection is made.

> ⚠️ **Security Warning: only point Pre-Login Command at scripts you trust.**
>
> The pre-login command runs on the server with the same privileges as the web process — the same trust level Native Doors' local executables run at. A malicious or buggy pre-login script can read/write any file the web server user can access, including `.env`, `config/binkp.json`, and the database, and can make outbound network connections. It runs on **every** door launch attempt, not just once, so treat it with the same care as any other server-side code path that executes on user action.

### Input

The command template supports CLI placeholders, substituted before execution:

| Placeholder | Replaced with |
|-------------|----------------|
| `{user_name}` | BinktermPHP username |
| `{real_name}` | BinktermPHP username (used as the display/real name for door sessions) |
| `{user_number}` | BinktermPHP user ID (numeric) |

Example:

```
php scripts/synchronet_add_user.php {user_name} {real_name} {user_number}
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

`scripts/synchronet_add_user.php` is the client half of this integration for the **Synchronet with BinktermPHP Service** preset. It's a thin CLI wrapper around `BinktermPHP\Synchronet` (`src/Synchronet.php`), which owns the actual wire protocol and can be reused from other PHP code if needed (including the door import feature below). It reads connection details from `config/rlogin_synchronet_service.json` (a small standalone config file for this script only — unrelated to the door's own database row). Copy the example to get started:

```bash
cp config/rlogin_synchronet_service.json.example config/rlogin_synchronet_service.json
```

```json
{
    "host": "127.0.0.1",
    "port": 24512,
    "secret": "changeme",
    "timeout": 5,
    "tls": true,
    "tls_verify_peer": false,
    "tls_cafile": null,
    "rlogin_host": "127.0.0.1",
    "rlogin_port": 513
}
```

`host`/`port`/`secret`/`timeout` are for the `services.ini` API connection (`secret` must match the `API_KEY` configured in the Synchronet-side `binkterm_sync_service.js`). `rlogin_host`/`rlogin_port` are the Synchronet system's actual **rlogin** listener — almost always the same host but a different port (513 by default) — used only by [Import from Synchronet](#import-from-synchronet) below.

`tls`/`tls_verify_peer`/`tls_cafile` control encryption of the `services.ini` API connection (not the separate rlogin connection, which has no encryption of its own — see the security warning above):

| Field | Default | Meaning |
|-------|---------|---------|
| `tls` | `true` | Wrap the connection in TLS. Must match the `Options = TLS` flag on the Synchronet-side `services.ini` section — a TLS client cannot talk to a plaintext-only service, or vice versa. Set to `false` only for a deliberately plaintext setup (e.g. an already-encrypted tunnel between the two hosts). |
| `tls_verify_peer` | `false` | Verify the server's certificate. Left off by default because this link is typically LAN/localhost between two systems the same sysop controls, using Synchronet's self-signed `ctrl/ssl.cert`. Set to `true` once a CA-signed or otherwise trusted certificate is in place. |
| `tls_cafile` | `null` | Optional path to a CA bundle (or the server's own certificate, for pinning) used when `tls_verify_peer` is `true`. |

See [binktermphp-synchronet](https://github.com/awehttam/binktermphp-synchronet)'s README for the matching `services.ini` setup.

It speaks a one-shot JSON-over-TCP protocol — one connection, one request, one response, then the connection closes — matching the [binktermphp-synchronet](https://github.com/awehttam/binktermphp-synchronet) `services.ini` service. Every request carries an `action` field (`provision` or `list_doors`); omitting it defaults to `provision`.

- **Request** (one line of JSON): `{"action":"provision","api_key":"<secret>","username":"{user_name}","real_name":"{real_name}"}`
- **Response** (one line of JSON), success: `{"success":true,"username":"...","user_number":42,"created":true}`
- **Response**, failure: `{"success":false,"error":"reason"}`

The script converts that response into the exit-code/JSON contract above (`username` from the response becomes `remote_username`). BinktermPHP ships only the client side — the Synchronet-side `services.ini` service itself lives in the separate [binktermphp-synchronet](https://github.com/awehttam/binktermphp-synchronet) repository that a sysop installs on the Synchronet system.

> ⚠️ **Username collisions on a shared user base**
>
> `scripts/synchronet_add_user.php` sends the BinktermPHP username to Synchronet as-is — there is no built-in prefixing.
>
> If the linked Synchronet system is a dedicated, freshly-installed game server whose only accounts come from this provisioning flow, that's fine. But if it's an existing Synchronet BBS with its own independent user base, a BinktermPHP username can collide with (or shadow) an unrelated existing Synchronet account of the same name.
>
> Sysops in that situation should decide on a naming convention up front — for example, prefixing provisioned usernames (`bt_{user_name}`) — and apply it before the username reaches Synchronet, either by wrapping `synchronet_add_user.php` in a small custom script or command that prefixes `{user_name}` before calling it, or by writing an equivalent pre-login command of their own.
>
> There's no config flag for this; it's a per-install call because a fresh dedicated instance doesn't need it and a shared instance needs a convention chosen by the sysop, not one BinktermPHP can safely guess.

### Import from Synchronet

Once `config/rlogin_synchronet_service.json` is configured (including `rlogin_host`/`rlogin_port`), **Admin → RLogin Doors** shows an **Import from Synchronet** button. It calls the same service's `list_doors` action to fetch every installed external program (door) and opens a preview — a checkbox list of candidates (all checked by default; use Select All/None) — before anything is created. Clicking **Import Selected** creates one fully-configured RLogin door per checked candidate — `bbs_type: synchronet_service`, `host`/`port` from `rlogin_host`/`rlogin_port`, Pre-Login Command set to the bundled `scripts/synchronet_add_user.php` invocation, and **Terminal Type set to `xtrn=<code>`**, where `<code>` is Synchronet's internal program code for that door — this is what makes the rlogin handoff land directly in the right door instead of the main menu.

The candidate list only ever includes doors from Synchronet's **Games** and **Main** xtrn sections (matched case-insensitively; anything named `Operator`, `Utilities`, or any other section is never offered) — sysop/operator-only utilities aren't something a regular user should be rlogin-handed into. On top of that, a door whose slugified door_id already exists is excluded from the list entirely, so re-running the import only ever shows genuinely new doors; it never touches or overwrites a door you've already imported or created manually.

Imported doors are created **disabled**, so review credit cost / admin-only / etc. before enabling each one.

**Description and author.** Synchronet's own external-program record (`xtrn.ini`) has no description or author field, so the Synchronet-side service instead makes a best-effort read of the door's `install-xtrn.ini` — the file most doors were installed from via `exec/install-xtrn.js` — for its `Desc:`, `By:`, `Cats:`, and `Subs:` header lines, and returns them alongside the door listing. When present, they're shown in the import preview and carried into the created door's Description/Author/Genre fields; when the file is missing (a door installed by hand, or with the file since removed), those fields are simply left blank for the sysop to fill in after import.

---

## Icons and Screenshots

Because RLogin doors have no directory on disk, icon and screenshot images are uploaded through **Admin → RLogin Doors** and stored as binary data (`BYTEA`) directly in the `rlogin_doors` row, alongside a stored MIME type. Accepted formats: PNG, JPEG, GIF, WebP, SVG.

They're served publicly at `/door-assets/{doorId}/icon` and `/door-assets/{doorId}/screenshot`, the same URL pattern used by the other door types — the route detects that a door ID belongs to an RLogin door and streams the image straight from the database instead of reading a file. To replace an image, upload a new one on the **Edit** form; to remove one entirely without replacing it, check **Remove image** and save.

### Generating an Icon with AI

If no suitable icon image is on hand, the **Generate with AI** button next to the Icon field can produce one on the spot. It sends the door's Name, Short Name, Genre, and Description fields (Name is required; the others improve the result) to whichever text-generation provider is configured under **Admin → AI Settings**, asking it to design a small flat/geometric icon as raw SVG artwork — no separate image-generation API or API key is needed beyond an existing text provider.

The response is run through a strict sanitizer (`BinktermPHP\AI\SvgIconSanitizer`) before it's shown or accepted: only a fixed allowlist of SVG shape/gradient tags and attributes survives, and anything that could reference an external URL or execute script (`<script>`, event-handler attributes, external `href`/`xlink:href`/`url(...)` references) is stripped. This matters because, unlike a raster upload, an SVG is served back with `Content-Type: image/svg+xml` and would otherwise execute as a mini HTML document if a user opened the asset URL directly.

The generated icon is loaded into the same file input used for manual uploads and previewed immediately, but nothing is saved until the door form itself is submitted — click **Generate with AI** again for a different result, or **Choose File** to use an uploaded image instead. If generation fails (no AI provider configured, or the model's output couldn't be sanitized into valid SVG), an error is shown in the modal and the existing icon, if any, is left untouched.

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

**Import from Synchronet fails or returns no doors**
- Confirm `config/rlogin_synchronet_service.json` exists with `host`/`port`/`secret` matching the Synchronet-side `services.ini` section and `API_KEY`
- Confirm `rlogin_host` is set in that file (the import is refused without it)
- If the request reaches the service but returns `success:false`, check the Synchronet Services server log — `list_doors` enumerates `xtrn_area.sec_list`, which needs verifying against your Synchronet version if it comes back empty (see the note in `binkterm_sync_service.js`)

**Pre-login command or Import from Synchronet fails with a connection error after enabling TLS**
- Confirm `tls` in `config/rlogin_synchronet_service.json` matches the `Options = TLS` flag on the Synchronet-side `services.ini` section — both sides must agree, a plaintext client cannot reach a TLS-only service and a TLS client cannot reach a plaintext one
- Confirm the Synchronet system has a valid `ctrl/ssl.cert` configured (self-signed is fine with the default `tls_verify_peer: false`)
- If `tls_verify_peer` is `true`, confirm `tls_cafile` (if set) points at a CA bundle or certificate that actually validates the server's certificate
