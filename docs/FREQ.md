# FREQ — File Requests

FidoNet FREQ (File Request) is a protocol that lets one node request specific files from another node over a binkp session. BinktermPHP supports FREQ in both directions: serving files to remote nodes that request them, and requesting files from remote nodes.

Three FREQ transports are supported: WaZOO (`.req`) file requests, the most commonly used method; binkp `M_GET` (live-session FREQ per FSP-1011); and, experimentally, when explicitly enabled, legacy netmail-based requests.

---

## Table of Contents

- [Serving FREQ (inbound requests)](#serving-freq-inbound-requests)
  - [Enabling FREQ on a file area](#enabling-freq-on-a-file-area)
  - [FREQ passwords](#freq-passwords)
  - [Shared file links](#shared-file-links)
  - [Magic names](#magic-names)
  - [How requests are resolved](#how-requests-are-resolved)
  - [Denial reasons](#denial-reasons)
- [Requesting files (outbound)](#requesting-files-outbound)
  - [When a remote declines the request](#when-a-remote-declines-the-request)
  - [Web interface](#web-interface)
  - [CLI: scripts/freq_getfile.php](#cli-scriptsfreq_getfilephp)
  - [WaZOO .req file mode](#wazoo-req-file-mode)
  - [binkp M_GET mode](#binkp-m_get-mode)
  - [Automatic retry](#automatic-retry)
- [FREQ response routing](#freq-response-routing)
- [Admin interface](#admin-interface)
- [Configuration reference](#configuration-reference)

---

## Serving FREQ (inbound requests)

### Enabling FREQ on a file area

FREQ serving is controlled per-file-area. To allow remote nodes to request files from an area:

1. Go to **Admin → File Areas** and edit the area.
2. Enable the **FREQ Enabled** toggle.
3. Optionally set a **FREQ Password** (see below).
4. Save.

Only files with `status = 'approved'` in active, public, FREQ-enabled areas are served. Private areas are never served regardless of settings.

FREQ is also settable when creating a new area via the file area API.

### FREQ passwords

If a file area has a FREQ password set, the requesting node must supply it. For `.req` file mode the password appears on the first line of the `.req` file prefixed with `!` (e.g. `!mysecret`). For binkp M_GET mode it is appended as the last field of the `M_GET` frame per FSP-1011.

Leave the password field blank for open access.

### Shared file links

Individual files can also be made FREQ-accessible without enabling FREQ for the entire area. When creating a share link for a file (**Files → Share**), check the **FREQ Accessible** box. The file will then be resolvable by exact filename match even if its parent area does not have FREQ enabled, subject to:

- The area being public (not private).
- The file being approved.
- The share link being active and not expired.

Shared-file FREQ does not support area passwords.

### Magic names

The following magic names are supported and resolve dynamically:

| Magic name | Response |
|---|---|
| `ALLFILES` | A combined `ALLFILES.TXT` listing of every FREQ-enabled file area and their approved files |
| `FILES` | Alias for `ALLFILES` |
| `<AREA TAG>` | A per-area listing (e.g. requesting `UTILS` returns `UTILS.TXT` for the file area with that tag) |

Magic name resolution is case-insensitive. The generated listings use a FILES.BBS-compatible format with filename, size, upload date, and description.

`ALLFILES.TXT` additionally starts with a header identifying the BBS: system name, sysop name, location, and web address (from **Admin → BinkP → System Configuration** and `SITE_URL`). Per-area listings (requesting a specific `<AREA TAG>`) do not include this header.

Magic names do not require a password and cannot be subject to size or timestamp filters.

### How requests are resolved

BinktermPHP accepts FREQ requests through three channels, all handled by `src/Freq/FreqResolver.php`:

**1. binkp M_GET (live-session FREQ)**

During a binkp session the remote node sends an `M_GET` frame. `BinkpSession` parses it and calls `FreqResolver::resolve()`. If the file is found and all checks pass, it is sent immediately in the same session via `M_FILE`.

**2. WaZOO `.req` file**

The remote node transfers a file with a `.REQ` extension (named after its own net/node numbers, e.g. `007B01C8.REQ`). After the inbound files are received, `BinkpSession` detects the `.req` file and processes it through `FreqResolver`. Fulfilled files are queued in the `freq_outbound` table and sent during the same or a subsequent outbound session to that node.

**3. Netmail FREQ (experimental)**

A remote node sends a netmail with `is_freq = true`. The subject line contains one or more space-separated filenames (or magic names). An optional password may appear on the first non-blank, non-kludge line of the message body. `FreqResolver::processNetmailFreq()` resolves each filename and delivers fulfilled files back to the requesting node as netmail FILE_ATTACH messages via `MessageHandler::deliverFreqResponse()`.

This transport is gated behind `ENABLE_FREQ_EXPERIMENTAL` (default `false`) and not recommended. In our own testing we have yet to find a live site that actually responds to a netmail-based FREQ. Use WaZOO `.req` file requests instead — it's the transport almost every FTN mailer actually implements and expects.

### Denial reasons

| Reason | Meaning |
|---|---|
| `not_found` | Filename did not match any FREQ-enabled area or shared file |
| `password` | Area requires a password and none was supplied or it did not match |
| `size_limit` | File exceeds the size limit specified in the M_GET frame |
| `timestamp` | File is not newer than the timestamp requested in M_GET |
| `not_available` | File record was found but the file is missing or unreadable on disk |

All attempts (served and denied) are logged to the `freq_log` database table and visible in the admin FREQ log (see [Admin interface](#admin-interface)).

---

## Requesting files (outbound)

BinktermPHP can request files from any binkp-reachable remote node, either from the web interface or via CLI. The target can be given as an FTN address (`zone:net/node`, optionally with an `@domain` or `.point` suffix) or, for nodes with no nodelist/binkp_zone entry, as a plain internet hostname or IP (optionally `host:port`, e.g. `bbs.example.com:24554`). `src/Freq/FreqAddress.php` auto-detects which kind was given and is shared by both the CLI and the web API so they accept the same address syntax.

Every request — whether submitted via the web or the CLI — is recorded in the `freq_requests_outbound` table by `FreqRequestTracker` before the session opens. This allows a FREQ response that arrives asynchronously (in a later session) to be routed to the correct requesting user.

### When a remote declines the request

Some remote FREQ handlers respond to a declined request (file not found, no access, etc.) by sending back a netmail explaining why, instead of — or in addition to — the requested file. `scripts/freq_getfile.php` detects this case: if a session receives a `.pkt` and no file matching the request, the request is marked `failed` immediately instead of being retried against a remote that has already declined it. A session that receives only other infrastructure files (e.g. a `.tic` with no `.pkt`) does not trigger this and is left `pending` for the normal retry loop.

The bounce netmail itself is deliberately **not** inspected, redirected, or copied anywhere by this feature. It is left untouched for `scripts/process_packets.php` to deliver exactly as it always has, normally to the sysop. Requesting users are not notified of *why* a request failed beyond its status changing to `failed` in the File Requests list.

### Web interface

Any logged-in user can submit a request under **Files → File Requests** (`/file-requests`), backed by:

| Endpoint | Purpose |
|---|---|
| `POST /api/freq/requests` | Submit a new request (`node`, `filename`, `mode`: `req` or `mget`, optional `password`) |
| `GET /api/freq/requests` | List the current user's requests (admins can pass `?all=1` to see everyone's) |
| `GET /api/freq/requests/{id}` | Fetch a single request's status |
| `DELETE /api/freq/requests/{id}` | Remove a tracking entry (does not delete a file that was already received) |

Submitting spawns the same `scripts/freq_getfile.php` flow used by the CLI, via the admin daemon (`AdminDaemonClient::freqRequest()`), so behavior is identical either way. A request's status is `pending` until a matching file is routed (`complete`) or its attempts are exhausted (`failed`).

This is **disabled by default**. Set `FREQ_ENABLE_INTERFACE=true` to make it available to any logged-in user, or `FREQ_ENABLE_INTERFACE=sysop` to restrict it to admin accounts only. See [Configuration reference](#configuration-reference) for the per-user concurrency limit and retry settings.

### Terminal server (telnet/SSH)

The Telnet and SSH BBS interfaces expose the same feature under **[Files] → File Requests** (default menu key `R`, configurable in **Admin → BBS Settings → Appearance → Terminal Server → Main Menu Keys**). `telnet/src/FreqHandler.php` lists the logged-in user's own requests, and lets them submit a new request (with an address-book lookup for the node, `?`) or delete a tracking entry — it is a thin client of the same `/api/freq/requests` endpoints listed above, so behavior and the `FREQ_ENABLE_INTERFACE` gate are identical to the web page. The menu item itself is hidden when `FREQ_ENABLE_INTERFACE=false`.

Once a request completes, the received file is available right from the same screen — a `[DL]` marker appears next to fulfilled requests in the list, and pressing `D` (from the list or the request's detail view) downloads it over ZMODEM, the same way file downloads work elsewhere in the terminal server's Files area.

If the BBS already has a **custom** main menu key map saved (i.e. any key was ever changed from the defaults), File Requests will not appear until the sysop explicitly assigns it a key on that same admin page — a custom map only shows actions it explicitly lists, so newly added actions aren't retroactively included. Sites still running the built-in defaults (no custom map saved) see it immediately with no admin action needed.

### CLI: scripts/freq_getfile.php

`freq_getfile.php` and `scripts/freq_pickup.php` don't normally need to be run by hand — the web/terminal File Requests UI and `binkp_scheduler`'s retry loop already shell out to `freq_getfile.php` as needed. Running either script manually is mainly for special cases: testing a request against a specific host/port, forcing an authenticated session, or picking up files a remote queued for you because it couldn't reach you via crashmail (`freq_pickup.php`).

```
php scripts/freq_getfile.php [options] <address> <filename> [filename2 ...]
```

By FTN convention, FREQ defaults to anonymous at the binkp session level, even when `<address>` matches one of our own configured uplinks: no uplink/hub-node session password or CRAM-MD5 is used automatically. Pass `--authenticated` to opt into using that uplink's real session credentials for a single run, or set `FREQ_AUTHENTICATE_UPLINKS=true` in `.env` to make that the default everywhere (including the web/terminal File Requests UI, which shells out to this script) — `--anonymous` forces an anonymous session regardless of that setting. This is independent of `--password`, which supplies the FREQ *area* password carried inside the `.req`/`M_GET` request itself.

Leave `FREQ_AUTHENTICATE_UPLINKS` at its default (`false`) unless you specifically need it. Enabling it means anyone allowed to submit FREQs (see [`FREQ_ENABLE_INTERFACE`](#web-interface)) can request files "as the BBS" against a configured uplink, potentially reaching file areas that uplink gates by node address rather than by BinktermPHP user permissions.

### WaZOO .req file mode

The default mode. A `.req` file is built in memory, written to a temp directory, and attached to the outbound binkp session. The remote processes the `.req` on receipt and queues the requested files for delivery. The remote may send them in the same session or in a subsequent session when it polls you.

Example:

```
php scripts/freq_getfile.php 1:123/456 ALLFILES MYFILE.ZIP
```

With a password:

```
php scripts/freq_getfile.php --password=SECRET 1:123/456 MYFILE.ZIP
```

The `.req` file format (FTS-0006) is plain text: an optional `!password` line followed by one filename per line, each terminated with `\r\n`.

The conventional filename is eight uppercase hex digits derived from the remote's net and node numbers (e.g. net=0x007B, node=0x01C8 → `007B01C8.REQ`).

### binkp M_GET mode

Use `-g` for live-session FREQ (FSP-1011 `M_GET`). This sends the request during the binkp session itself and expects the remote to respond in the same session with `M_FILE`. Only use this when the remote node is known to support binkp M_GET FREQ natively (e.g. another BinktermPHP node).

```
php scripts/freq_getfile.php -g 1:123/456 ALLFILES
```

### Options

| Option | Description |
|---|---|
| `-g` | Use binkp M_GET (live-session FREQ) instead of `.req` file |
| `--user=USERNAME` | Store received files in this user's private area (default: first admin) |
| `--password=PASS` | Area password required by the remote node |
| `--hostname=HOST` | Override hostname; skip nodelist/DNS lookup |
| `--port=PORT` | Override port (default 24554) |
| `--authenticated` | Use the configured uplink's real session password/CRAM-MD5 when `<address>` matches one of our uplinks, instead of connecting anonymously. Overrides `FREQ_AUTHENTICATE_UPLINKS` for this run |
| `--anonymous` | Force an anonymous session even if `FREQ_AUTHENTICATE_UPLINKS=true` |
| `--request-id=ID` | Attach this run to an existing `freq_requests_outbound` row instead of creating a new one (used internally by the web API and the scheduled retry job) |
| `--log-level=LVL` | `DEBUG`, `INFO`, `WARNING`, or `ERROR` (default `INFO`) |
| `--log-file=FILE` | Log file path (default: `data/logs/freq_getfile.log`) |
| `--no-console` | Suppress console output |

### Automatic retry

A `.req`-mode request often isn't fulfilled during its initial session — the remote may process the `.req` and answer in a later session instead. `Scheduler::runScheduledFreqRetries()`, run periodically by the `binkp_scheduler` daemon, picks up any `pending` `freq_requests_outbound` row that is due for another attempt and re-runs `freq_getfile.php` against it (via `--request-id`), up to `FREQ_MAX_ATTEMPTS` attempts with at least `FREQ_POLL_INTERVAL` seconds between attempts. Once the cap is reached without a match, the row is marked `failed` instead of being retried indefinitely.

If the remote cannot reach you at all (no inbound binkp port), it will still queue the file and wait for you to poll — the retry loop above, which opens outbound sessions to the node on each attempt, is what picks it up.

---

## FREQ response routing

When files are received in a binkp session, `FreqResponseRouter::routeReceivedFiles()` is called with the remote node address and the list of received filenames. It looks up all pending entries in `freq_requests_outbound` for that node and matches received filenames case-insensitively against the requested filenames.

Matched files are moved into the requesting user's private incoming file area via `FileAreaManager::storeFreqIncoming()`. The corresponding `freq_requests_outbound` record is marked `complete`.

Files that do not match any pending request are left in `data/inbound/` for `process_packets` to handle (FTN packets, TIC files, netmail FILE_ATTACH attachments, etc.).

**Limitation**: Magic names (e.g. `ALLFILES`) cannot be auto-routed because the remote chooses the actual filename at fulfillment time (e.g. `ALLFILES.TXT`). When requesting magic names, use a specific `--user` so that received files with unexpected names can be identified manually if they are not auto-routed.

---

## Admin interface

**FREQ Requests tab** — `/binkp`

The BinkP Status page has a **FREQ Requests** tab showing the entire outbound `freq_requests_outbound` queue across all users (backed by `GET /api/freq/requests?all=1`): target node, filename(s), requesting user, mode (`req`/`mget`), status, attempt count, and submission time. Admins can delete any entry from here, same as a user deleting their own from [the web File Requests page](#web-interface).

**FREQ Log** — `/admin/freq-log`

Displays all FREQ serving activity: requesting node address, filename requested, whether it was served or denied, denial reason, file size, source (binkp M_GET, .req, or netmail), and session ID. Useful for auditing what remote nodes are requesting and diagnosing why requests are being denied.

**Nodelist node view** — `/nodelist/view/<address>`

Logged-in users see a **Request File** button on the node detail page (when `FREQ_ENABLE_INTERFACE` is enabled). It links to the [web File Requests page](#web-interface) with that node's address pre-filled, so the request is sent via `.req`/`M_GET` rather than netmail.

---

## Configuration reference

| Setting | Default | Description |
|---|---|---|
| `ENABLE_FREQ_EXPERIMENTAL` | `false` | Set to `true` to show the older netmail-based FREQ (`is_freq`) option in the netmail compose form. Not recommended — in our testing no live site has ever responded to a netmail FREQ. Prefer `.req`/`M_GET` via the [web File Requests page](#web-interface) or `freq_getfile.php` instead |
| `FREQ_ENABLE_INTERFACE` | `false` | `true` shows the File Requests page and enables its API for any logged-in user; `sysop` restricts it to admin accounts; `false` disables it entirely. See [When a remote declines the request](#when-a-remote-declines-the-request) before enabling |
| `FREQ_MAX_CONCURRENT_PER_USER` | `2` | Maximum number of requests a single user may have in progress at once |
| `FREQ_MAX_ATTEMPTS` | `5` | Number of retry attempts before an unfulfilled request is marked `failed` |
| `FREQ_POLL_INTERVAL` | `300` | Seconds between automatic retry attempts for a pending request |
| `FREQ_AUTHENTICATE_UPLINKS` | `false` | `true` makes outbound FREQ automatically use a matching configured uplink's real session password/CRAM-MD5 instead of connecting anonymously. See [CLI: scripts/freq_getfile.php](#cli-scriptsfreq_getfilephp) before enabling |

File area FREQ settings are configured per-area in **Admin → File Areas**:

| Field | Description |
|---|---|
| `freq_enabled` | Whether remote nodes may FREQ files from this area |
| `freq_password` | Optional password required for FREQ access to this area |

**Key source files:**

| File | Purpose |
|---|---|
| `src/Freq/FreqResolver.php` | Resolves inbound FREQ requests (M_GET, .req, netmail) |
| `src/Freq/FreqResult.php` | Result value object returned by the resolver |
| `src/Freq/FreqAddress.php` | Parses outbound FREQ target addresses (FTN address or hostname); shared by the CLI and web API |
| `src/Freq/FreqRequestTracker.php` | Tracks outbound FREQ requests for response routing and retry |
| `src/Freq/FreqResponseRouter.php` | Routes received files to requesting users |
| `src/Freq/MagicFileListGenerator.php` | Generates ALLFILES.TXT and per-area listings |
| `scripts/freq_getfile.php` | CLI tool for requesting files from a remote node |
