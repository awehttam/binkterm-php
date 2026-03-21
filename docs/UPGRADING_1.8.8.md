# Upgrading to 1.8.8

⚠️ Make sure you've made a backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [File Areas & TIC Processing](#file-areas--tic-processing)
  - [FILE_ID.DIZ Selection for Incoming TIC ZIPs](#file_iddiz-selection-for-incoming-tic-zips)
- [FTN Networking](#ftn-networking)
  - [BinkP Handshake Timeout Handling](#binkp-handshake-timeout-handling)
- [QWK Offline Mail](#qwk-offline-mail)
  - [Stable BBS-Wide Conference Numbers](#stable-bbs-wide-conference-numbers)
  - [QWKE Subject Extended Header in REP Packets](#qwke-subject-extended-header-in-rep-packets)
  - [QWKE From/To Headers No Longer Include FTN Address](#qwke-fromto-headers-no-longer-include-ftn-address)
  - [FTN Address in To Field for New Netmail](#ftn-address-in-to-field-for-new-netmail)
  - [Configurable QWK BBS ID](#configurable-qwk-bbs-id)
- [Web Interface](#web-interface)
  - [Dashboard: Today's Callers](#dashboard-todays-callers)
  - [Profile: Your Network Information](#profile-your-network-information)
  - [Profile: File Transfer Stats](#profile-file-transfer-stats)
  - [Settings: Sidebar Removed](#settings-sidebar-removed)
  - [ANSI Renderer: Debug Font Override](#ansi-renderer-debug-font-override)
  - [Dashboard: Today's Callers Timezone](#dashboard-todays-callers-timezone)
  - [Advertisements: Multimodal Content Rendering](#advertisements-multimodal-content-rendering)
  - [Advertisements: Upload Improvements](#advertisements-upload-improvements)
  - [Activity Stats: Login Source Breakdown](#activity-stats-login-source-breakdown)
  - [Shared Message: Kludge Lines Removed](#shared-message-kludge-lines-removed)
  - [Font Awesome Brands Font Removed](#font-awesome-brands-font-removed)
  - [Admin Settings: Loading Blur](#admin-settings-loading-blur)
- [MRC Chat](#mrc-chat)
  - [Default Room Fallback](#default-room-fallback)
  - [First DM Message Now Visible](#first-dm-message-now-visible)
  - [Tab Completion for Usernames](#tab-completion-for-usernames)
  - [Tab Completion for Slash Commands](#tab-completion-for-slash-commands)
  - [Polling Mode Toggle](#polling-mode-toggle)
  - [Hard Refresh Now Bypasses Service Worker Cache](#hard-refresh-now-bypasses-service-worker-cache)
  - [Room User Count Off By One](#room-user-count-off-by-one)
  - [DM Messages Disappear When No Prior History](#dm-messages-disappear-when-no-prior-history)
- [Telnet/SSH BBS Server](#telnetssh-bbs-server)
  - [User Action Logging](#user-action-logging)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

**File Areas & TIC Processing**
- Incoming TIC ZIP processing now uses `FILE_ID.DIZ` only from the archive root.

**FTN Networking**
- BinkP now handles exported socket timeouts more consistently during handshake reads.

**Telnet/SSH BBS Server**
- The terminal server now logs user actions to `data/logs/telnetd.log`: menu navigation, echoarea and netmail access, individual message reads, echomail and netmail compose/send, file area browsing, file downloads and uploads, door launches, shoutbox posts, and poll votes.

**Web Interface**
- Sysops now see a "Today's Callers" list in the dashboard System Information box.
- The profile page System Information box has been replaced with a "Your Network Information" card showing configured networks and a "Show Netmail Addresses" modal.
- The profile Activity Summary now includes files downloaded, files uploaded, and a download/upload ratio.
- The user settings page sidebar (System Status, Quick Actions, Help & Support) has been removed.
- A `DEBUG_ANSI_NOT_PERFECT` environment variable can be set to `true` to disable the Perfect DOS VGA 437 font override on ANSI art, useful for testing how art looks in the user's existing monospace font.
- A `DEBUG_ANSI_USE_CONSOLAS` environment variable can be set to `true` to use Consolas instead of Perfect DOS VGA 437 for ANSI art rendering.
- Today's Callers on the dashboard is now anchored to the sysop's configured timezone, so the midnight cutoff reflects local time rather than UTC.
- The advertisement display (dashboard ad box, ad modal, and full-page ad view) now renders RIPscrip, Sixel, ANSI/PCBoard, and plain-text content using the same multimodal pipeline as the echomail message viewer.
- The advertisement uploader now accepts `.ans`, `.rip`, `.six`, and `.txt` files and raises the file size limit to 5 MB.
- Newly created advertisements default to dashboard display disabled.
- The Activity Stats overview now shows a per-source login breakdown under the Auth category row, distinguishing web, telnet, and SSH logins.
- The kludge lines box has been removed from the public shared message view.
- The Font Awesome brands webfont (`fa-brands-400.woff2`, ~108 KB) is no longer loaded; the single brands icon used (Markdown) has been replaced with an inline SVG.
- Admin settings pages (BBS Settings, BinkP Configuration, MRC Settings, Appearance) now blur their content cards while settings are being fetched and show a centred spinner; cards stay blurred if the load fails.

**MRC Chat**
- When the server returns no rooms, the MRC chat WebDoor now shows the configured default room instead of an empty list.
- Fixed a bug where the first message sent in a new DM conversation was not visible until the next poll.
- Tab-completion of usernames in the chat input: type a partial name and press Tab to complete it, press Tab again to cycle through all matching online users.
- Tab-completion now also works for slash commands: type `/` or a partial command name and press Tab to complete or cycle through matching commands. `/msg` also completes the username argument.
- A toggle in the chat sidebar lets users switch between simple polling and long polling; the choice is remembered in localStorage.
- Hard refresh (Ctrl+Shift+R) now correctly bypasses the service worker cache, restoring the expected development behaviour.
- Fixed room user count showing one more user than actually present.
- Fixed sent DM messages disappearing immediately when there is no prior conversation history.

**QWK Offline Mail**
- QWK conference numbers are now stored as canonical BBS-wide IDs on echo areas so packets use the system's conference numbering instead of subscription position.
- REP packet processing now reads QWKE plain-text `Subject:` headers from the message body, fixing subject truncation to 25 characters for clients such as MultiMail.
- QWKE outgoing `From:` and `To:` extended headers now contain only the name; the FTN address is no longer appended, preventing it from appearing as part of the reply-to name in offline readers.
- New netmail sent via QWK can now specify the FTN destination by writing `Name@zone:net/node[.point]` in the To field.
- The QWK BBS ID (packet filename and CONTROL.DAT identifier) is now a configurable setting so it remains stable if the sysop renames the board.

## File Areas & TIC Processing

### FILE_ID.DIZ Selection for Incoming TIC ZIPs

BinktermPHP can use `FILE_ID.DIZ` inside an incoming TIC ZIP to fill in missing
`Desc` and `LDesc` values when the TIC metadata does not provide them.

In earlier 1.8.x builds, the TIC incoming processor matched `FILE_ID.DIZ` by
basename only. That meant a ZIP containing multiple copies of `FILE_ID.DIZ`
could use the wrong one, including files nested deeper inside subdirectories.

Version 1.8.8 tightens that lookup logic:

- Prefer `/FILE_ID.DIZ` at the ZIP root.
- If no root-level `FILE_ID.DIZ` exists, allow `/directory/FILE_ID.DIZ` only
  when that single directory is the only directory at the archive root.
- Never descend more than one directory level when selecting `FILE_ID.DIZ` for
  TIC description import.

This change improves compatibility with common single-top-level-directory ZIP
layouts while avoiding incorrect descriptions from nested archive content.

## FTN Networking

### BinkP Handshake Timeout Handling

Version 1.8.8 also tightens timeout handling for binkp sessions after sockets
are converted to stream resources. The exported stream now relies on stream
timeouts consistently instead of mixing native socket timeouts with stream I/O.

This helps avoid false handshake timeouts where the remote has already sent a
valid frame, but the local side fails the read during handshake processing.

## QWK Offline Mail

### Stable BBS-Wide Conference Numbers

Version 1.8.8 now stores QWK conference numbers as canonical BBS-wide values on
echo areas instead of rebuilding them from the current subscription order on
every packet download.

This means:

- Conference numbers are stable across the entire BBS, not just per user.
- Subscribing or unsubscribing from an area no longer affects conference IDs.
- Every user sees the same QWK conference number for the same echo area.
- New echo areas receive the next available canonical conference number.

The system still records a per-download conference map in `qwk_download_log`
for REP reply import safety, but packet generation and the QWK status view now
use the canonical echo area conference number.

### QWKE Subject Extended Header in REP Packets

The QWK format limits the subject field in the message header to 25 characters.
QWKE-capable clients such as MultiMail write a plain-text `Subject:` line at the
top of the message body when the subject exceeds that limit. Previous builds of
BinktermPHP ignored this line and always used the truncated 25-character header
field, causing long subjects to be silently cut off after a REP upload.

Version 1.8.8 now reads the plain-text `Subject:` (and `To:`/`From:`) extended
headers from the message body before falling back to the fixed header fields.

### QWKE From/To Headers No Longer Include FTN Address

When generating QWKE packets, BinktermPHP previously appended the sender's FTN
address to the plain-text `From:` and `To:` extended headers in the form
`Name <zone:net/node>`. The QWKE specification defines these headers as
name-extension fields only; the FTN address belongs in the `^A`-prefixed kludge
lines (`^AINTL`, `^AORIG`, etc.).

Including the address caused offline readers to store `Name <zone:net/node>` as
the from-name. When composing a reply, the full string then appeared verbatim in
the To field of the outgoing REP packet.

Version 1.8.8 now writes only the name in these headers, matching the spec.

### FTN Address in To Field for New Netmail

QWK has no dedicated field for a FTN destination address. When composing new
netmail (conference 0) in an offline reader, there is no standard way to
communicate the routing address to the BBS.

Version 1.8.8 supports a convention for specifying the destination: if the To
field of a conference-0 message matches the pattern `Name@zone:net/node[.point]`
the address portion is extracted and used for FTN routing. The name portion is
used as the recipient name. This allows users to address new netmail to arbitrary
FTN nodes directly from their QWK reader.

Replies to received netmail continue to resolve the destination via the message
index as before; this convention applies only to new messages with no reply
reference.

### Configurable QWK BBS ID

The QWK BBS ID — used as the packet filename (`BBSID.QWK`) and in
`CONTROL.DAT` — was previously derived from the system name on every packet
build. Renaming the board would silently change the ID, breaking existing
offline readers that had already configured the BBS.

Version 1.8.8 adds a **QWK BBS ID** field to the admin BBS Settings page
(under the QWK Offline Mail toggle). The value is up to 8 alphanumeric
characters and is stored in `config/bbs.json` as `qwk.bbs_id`.

The upgrade migration (`v1.11.0.48`) automatically derives the ID from the
current system name and writes it to `bbs.json`, so existing installations
receive a stable value without any manual action. After upgrading, the ID
can be changed in the admin panel, but note that changing it will require
users to reconfigure their offline readers.

## Web Interface

### Dashboard: Today's Callers

Sysops (admin users) now see a "Today's Callers" entry at the bottom of the
System Information card on the dashboard. It lists every distinct user who has
had an active session since midnight, along with their most recent activity time.
Non-admin users do not see this field.

### Profile: Your Network Information

The "System Information" card on the user profile page has been replaced with
"Your Network Information". It displays the configured FTN networks in a compact
two-per-row grid (network badge and node address side by side). A "Show Netmail
Addresses" button opens a modal table with "Network Name" and "Network Address"
columns, where the address is formatted as `RealName@zone:net/node` for direct
copy-paste use.

### Profile: File Transfer Stats

The Activity Summary card on the profile page now includes three additional rows:

- **Files Downloaded** — count of file download events from the activity log.
- **Files Uploaded** — count of file upload events from the activity log.
- **D/L Ratio** — downloads divided by uploads (e.g. `2.50:1`), or N/A when no
  transfers have occurred.

These counts are sourced from the `user_activity_log` table.

### Settings: Sidebar Removed

The user settings page previously showed a right-hand sidebar containing System
Status (BinkP online indicator, last poll time, messages today), Quick Actions
(compose netmail/echomail links), and Help & Support (GitHub links). These boxes
have been removed. The main settings content now uses the full page width.

### ANSI Renderer: Debug Font Override

Two new environment variables can be added to `.env` to test ANSI art rendering
with different fonts:

```
DEBUG_ANSI_NOT_PERFECT=true
```

When set to `true`, the `.ansi-art` CSS font-family is reset to `inherit`, so
art renders in whatever monospace font the rest of the page uses.

```
DEBUG_ANSI_USE_CONSOLAS=true
```

When set to `true`, Consolas is used instead of Perfect DOS VGA 437. Useful for
comparing rendering between fonts. Only one of these variables should be set at
a time; `DEBUG_ANSI_NOT_PERFECT` takes precedence. Set both to `false` or remove
them to restore the default Perfect DOS VGA 437 behaviour.

### Dashboard: Today's Callers Timezone

The Today's Callers list now anchors midnight to the sysop's configured timezone
rather than UTC. Users active after local midnight but before UTC midnight will
no longer be excluded from the list.

### Advertisements: Multimodal Content Rendering

The advertisement display now uses the same multimodal rendering pipeline as the
echomail message viewer. Ad content is rendered in priority order:

1. **RIPscrip** — detected by the `!|` magic bytes; rendered via the RIPterm canvas engine.
2. **Sixel** — detected by the DCS escape sequence; rendered via the Sixel decoder.
3. **ANSI/PCBoard** — rendered through the existing ANSI and PCBoard colour processors.
4. **Plain text** — displayed as-is when no special encoding is detected.

This applies to the dashboard ad box, the ad modal popup, and the full-page ad view.

### Advertisements: Upload Improvements

The advertisement upload form has been updated to reflect the full range of
supported content types:

- The accepted file extensions are now `.ans`, `.rip`, `.six`, and `.txt`.
- The file size limit has been raised from 1 MB to **5 MB**.
- Newly uploaded advertisements default to **dashboard display disabled**. Enable
  "Show on Dashboard" explicitly after uploading if the ad should appear in the
  dashboard rotation.

### Activity Stats: Login Source Breakdown

The Activity by Category table in the Activity Stats overview now expands the
Auth row into sub-rows showing login counts broken down by source. Each distinct
login source (`web`, `telnet`, `ssh`) appears as an indented sub-row with its
own count and progress bar, making it easy to see how users are connecting to
the BBS.

### Shared Message: Kludge Lines Removed

The public shared message view previously displayed a collapsible kludge lines
box beneath the message header. Kludge lines are internal FTN routing metadata
not meaningful to general visitors. The box has been removed; the message body
and origin line are now shown directly without it.

### Font Awesome Brands Font Removed

The Font Awesome brands webfont (`fa-brands-400.woff2`) was the only brands
asset used by the interface, and it was loaded solely to render the Markdown
logo icon in the compose form. At ~108 KB it was disproportionately large for
a single glyph.

The icon has been replaced with an equivalent inline SVG and the brands font
has been removed from the service worker precache. Browsers will no longer
download it.

### Admin Settings: Loading Blur

Admin settings pages now blur their content cards while the initial settings
fetch is in flight, and display a centred spinner so it is clear that data is
loading. The blur and spinner are cleared once the data loads successfully. If
the fetch fails the cards remain blurred, making the error state visually
distinct from a loaded page.

The following pages use this behaviour: BBS Settings, BinkP Configuration,
MRC Settings, and Appearance.

## MRC Chat

### Default Room Fallback

When the MRC daemon first connects, the server may not have sent a room list
yet. Previously the chat WebDoor showed an empty room list in this window.
Version 1.8.8 falls back to the default room configured in MRC Settings so
there is always at least one room available to join.

### First DM Message Now Visible

When opening a new direct message conversation by clicking a user, the first
message sent was not visible. The DM view was opened *after* sending, which
reset the message cursor and triggered a history reload that then overwrote the
local echo.

The fix loads the DM view and its history *before* sending the message, so the
local echo is appended in the correct position and remains visible without being
displaced by the subsequent poll.

### Tab Completion for Usernames

The MRC chat input now supports Tab-completion of online usernames. Type the
beginning of a username and press Tab to complete it. If more than one online
user matches the prefix, pressing Tab again cycles through the other matches;
Tab wraps back to the first match after the last one. Typing any other character
resets the cycle so the next Tab starts a fresh completion from the current
cursor position.

### Tab Completion for Slash Commands

Tab-completion in the chat input now also covers slash commands. Typing `/`
followed by Tab cycles through all available commands alphabetically. Typing a
partial command name (e.g. `/mo`) and pressing Tab completes it to `/motd `.
Pressing Tab again cycles through any other commands that share the prefix.

For `/msg`, Tab-completion also applies to the username argument: after typing
`/msg ` (or a partial username), pressing Tab completes the username from the
list of users currently in the room.

Available commands for completion: `/help`, `/identify`, `/motd`, `/msg`,
`/register`, `/rooms`, `/topic`, `/update`.

### Polling Mode Toggle

A button in the MRC chat sidebar lets users switch between **Simple Poll**
(short HTTP requests on a fixed interval) and **Long Poll** (a persistent
connection that returns as soon as new messages arrive). The chosen mode is
saved in localStorage and restored on the next visit.

Simple Poll is the safer choice in certain environments. Long Poll offers lower
message latency on servers that support long-running HTTP connections.

### Hard Refresh Now Bypasses Service Worker Cache

The service worker's fetch handler now checks the browser's request cache mode.
When a hard refresh is performed (Ctrl+Shift+R), the browser signals
`cache: 'reload'` and the service worker passes the request straight through to
the network instead of serving from its own cache. This restores the expected
behaviour where a hard refresh always retrieves fresh files.

### DM Messages Disappear When No Prior History

When opening a DM conversation for the first time (no prior messages between
the two users), sent messages vanished immediately after being echoed locally.
The initial history poll returned no messages, leaving `lastPrivateMessageId`
at `0`. Every subsequent timer poll then evaluated `append = false` and
replaced the entire chat area, wiping the local echo. Incoming replies from
the other person were similarly lost until the page was reloaded.

The fix sets `lastPrivateMessageId` to `-1` after the initial history load
when no messages were found. `-1 !== 0` triggers append mode on all subsequent
polls, and `WHERE id > -1` on the server is equivalent to `WHERE id > 0` since
message IDs start at 1.

### Room User Count Off By One

The room list occasionally showed one more user than was actually present. A
join announcement from the MRC server includes the user's real BBS name (e.g.
`user@MyBBS`), which inserts a row with `bbs_name = 'MyBBS'`. The subsequent
USERLIST from the server inserts the same user with `bbs_name = 'unknown'`.
Because the unique key on `mrc_users` includes `bbs_name`, both rows coexisted
until the next USERLIST sweep replaced them, causing the `COUNT` in the room
list query to be inflated.

The fix changes the room count query to `COUNT(DISTINCT username)` so it
matches the deduplicated user panel regardless of how many rows exist for a
given username.

## Telnet/SSH BBS Server

### User Action Logging

The terminal server now emits INFO-level action entries to `data/logs/telnetd.log`
for every significant user action. Each line includes timestamp, PID, and
username so a sysop can reconstruct how the BBS is being used. Logged events:

| Area | Events Logged |
|------|--------------|
| Main menu | Navigation to each section |
| Netmail | Message list viewed, individual message read, compose started, send success/failure |
| Echomail | Area entered, message list viewed, individual message read, compose started, post success/failure |
| Files | Area entered, subfolder entered, file detail viewed, download started/complete/failed, upload started/complete/error |
| Doors | Door launched (by name) |
| Shoutbox | Section entered, message posted/failed |
| Polls | Poll detail viewed, vote cast/failed |

Example log entries:

```
[2026-03-21 04:55:10] [12345] [INFO] Menu [johndoe]: johndoe -> Echomail
[2026-03-21 04:55:11] [12345] [INFO] Action [johndoe]: Echomail: read message list for FIDONET.NODE@fidonet
[2026-03-21 04:55:20] [12345] [INFO] Action [johndoe]: Echomail: read message #4821 in FIDONET.NODE@fidonet
[2026-03-21 04:55:55] [12345] [INFO] Action [johndoe]: Echomail: posted message to FIDONET.NODE@fidonet subject="Re: Hello"
[2026-03-21 04:56:40] [12345] [INFO] Action [johndoe]: Files: download complete somefile.zip
```

No configuration is required. Logging is active whenever the telnet/SSH daemon
is running and `TELNETD_LOG_LEVEL` is `INFO` or lower (the default).

## Upgrade Instructions

This release includes migrations for canonical QWK conference numbers and the
new QWK BBS ID setting. Run setup during upgrade so existing areas are
backfilled with BBS-wide conference IDs and the QWK BBS ID is populated from
the current system name before users download new packets.

### From Git

1. Pull the latest code: `git pull`
2. Run setup: `php scripts/setup.php`
3. Restart the daemons if they are running: `bash scripts/restart_daemons.sh`

### Using the Installer

Re-run the BinktermPHP installer to upgrade the application files, then restart
the daemons if your deployment manages them separately.
