# FREQ — File Requests

FidoNet FREQ (File Request) is a protocol that lets one node request specific files from another node over a binkp session. BinktermPHP supports FREQ in both directions: serving files to remote nodes that request them, and requesting files from remote nodes.

---

## Table of Contents

1. [Serving FREQ (inbound requests)](#serving-freq-inbound-requests)
   - [Enabling FREQ on a file area](#enabling-freq-on-a-file-area)
   - [FREQ passwords](#freq-passwords)
   - [Shared file links](#shared-file-links)
   - [Magic names](#magic-names)
   - [How requests are resolved](#how-requests-are-resolved)
   - [Denial reasons](#denial-reasons)
2. [Requesting files (outbound)](#requesting-files-outbound)
   - [Bark .req file mode](#bark-req-file-mode)
   - [binkp M_GET mode](#binkp-m_get-mode)
   - [Picking up files asynchronously](#picking-up-files-asynchronously)
3. [FREQ response routing](#freq-response-routing)
4. [Admin interface](#admin-interface)
5. [Configuration reference](#configuration-reference)

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

Magic names do not require a password and cannot be subject to size or timestamp filters.

### How requests are resolved

BinktermPHP accepts FREQ requests through three channels, all handled by `src/Freq/FreqResolver.php`:

**1. binkp M_GET (live-session FREQ)**

During a binkp session the remote node sends an `M_GET` frame. `BinkpSession` parses it and calls `FreqResolver::resolve()`. If the file is found and all checks pass, it is sent immediately in the same session via `M_FILE`.

**2. Bark `.req` file**

The remote node transfers a file with a `.REQ` extension (named after its own net/node numbers, e.g. `007B01C8.REQ`). After the inbound files are received, `BinkpSession` detects the `.req` file and processes it through `FreqResolver`. Fulfilled files are queued in the `freq_outbound` table and sent during the same or a subsequent outbound session to that node.

**3. Netmail FREQ**

A remote node sends a netmail with `is_freq = true`. The subject line contains one or more space-separated filenames (or magic names). An optional password may appear on the first non-blank, non-kludge line of the message body. `FreqResolver::processNetmailFreq()` resolves each filename and delivers fulfilled files back to the requesting node as netmail FILE_ATTACH messages via `MessageHandler::deliverFreqResponse()`.

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

Use `scripts/freq_getfile.php` to request files from a remote node.

```
php scripts/freq_getfile.php [options] <address> <filename> [filename2 ...]
```

### Bark .req file mode

The default mode. A `.req` file is built in memory, written to a temp directory, and attached to the outbound binkp session. The remote processes the `.req` on receipt and queues the requested files for delivery. The remote may send them in the same session or in a subsequent session when it polls you.

Example:

```
php scripts/freq_getfile.php 1:123/456 ALLFILES MYFILE.ZIP
```

With a password:

```
php scripts/freq_getfile.php --password=SECRET 1:123/456 MYFILE.ZIP
```

The `.req` file format (FTS-0008) is plain text: an optional `!password` line followed by one filename per line, each terminated with `\r\n`.

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
| `--log-level=LVL` | `DEBUG`, `INFO`, `WARNING`, or `ERROR` (default `INFO`) |
| `--log-file=FILE` | Log file path (default: `data/logs/freq_getfile.log`) |
| `--no-console` | Suppress console output |

Every request is recorded in the `freq_requests_outbound` table by `FreqRequestTracker` before the session opens. This allows asynchronous FREQ responses (files arriving in a later session) to be correctly routed to the requesting user.

### Picking up files asynchronously

If the remote cannot reach you (no inbound binkp port), the remote will queue the files and wait for you to poll. Use `scripts/freq_pickup.php` to open an outbound session and collect them:

```
php scripts/freq_pickup.php 1:123/456
php scripts/freq_pickup.php 1:123/456 --hostname=bbs.example.com
```

This simply opens a binkp session; any files the remote has queued for your address are transferred normally and `FreqResponseRouter` routes them on receipt.

---

## FREQ response routing

When files are received in a binkp session, `FreqResponseRouter::routeReceivedFiles()` is called with the remote node address and the list of received filenames. It looks up all pending entries in `freq_requests_outbound` for that node and matches received filenames case-insensitively against the requested filenames.

Matched files are moved into the requesting user's private incoming file area via `FileAreaManager::storeFreqIncoming()`. The corresponding `freq_requests_outbound` record is marked `complete`.

Files that do not match any pending request are left in `data/inbound/` for `process_packets` to handle (FTN packets, TIC files, netmail FILE_ATTACH attachments, etc.).

**Limitation**: Magic names (e.g. `ALLFILES`) cannot be auto-routed because the remote chooses the actual filename at fulfillment time (e.g. `ALLFILES.TXT`). When requesting magic names, use a specific `--user` so that received files with unexpected names can be identified manually if they are not auto-routed.

---

## Admin interface

**FREQ Log** — `/admin/freq-log`

Displays all FREQ serving activity: requesting node address, filename requested, whether it was served or denied, denial reason, file size, source (binkp M_GET, .req, or netmail), and session ID. Useful for auditing what remote nodes are requesting and diagnosing why requests are being denied.

**Nodelist node view** — `/nodelist/view/<address>`

When `ENABLE_FREQ_EXPERIMENTAL=true` is set in `.env`, a **Request ALLFILES** button appears on the node detail page for admin users. This sends an ALLFILES FREQ request as a netmail `is_freq` message to the selected node. It is a convenience shortcut for requesting file listings without using the CLI.

---

## Configuration reference

| Setting | Default | Description |
|---|---|---|
| `ENABLE_FREQ_EXPERIMENTAL` | `false` | Set to `true` to show the FREQ request button on nodelist node pages (admin only) |

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
| `src/Freq/FreqRequestTracker.php` | Tracks outbound FREQ requests for response routing |
| `src/Freq/FreqResponseRouter.php` | Routes received files to requesting users |
| `src/Freq/MagicFileListGenerator.php` | Generates ALLFILES.TXT and per-area listings |
| `scripts/freq_getfile.php` | CLI tool for requesting files from a remote node |
| `scripts/freq_pickup.php` | CLI tool for collecting asynchronously queued FREQ responses |
