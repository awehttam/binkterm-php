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
  - [Profile: Activity Log Tab](#profile-activity-log-tab)
  - [Service Worker: Spurious Reload Prompt Fixed](#service-worker-spurious-reload-prompt-fixed)
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
- [LovlyNet Integration](#lovlynet-integration)
  - [Setup Checklist](#setup-checklist)
  - [Setup Checklist: Five Parallel Checks](#setup-checklist-five-parallel-checks)
  - [BinkP CRAM-MD5 Default](#binkp-cram-md5-default)
  - [replace Metadata Flag for File Areas](#replace-metadata-flag-for-file-areas)
  - [Default Area Indicators on Echo and File Area Tabs](#default-area-indicators-on-echo-and-file-area-tabs)
  - [Metadata Respected on Area Creation](#metadata-respected-on-area-creation)
- [Echomail & Netmail](#echomail--netmail)
  - [Node Address Autocomplete in Netmail Compose](#node-address-autocomplete-in-netmail-compose)
  - [Node Address Popover in Netmail List](#node-address-popover-in-netmail-list)
  - [Outgoing Charset Selector](#outgoing-charset-selector)
  - [Message Edit: Character Set Selector](#message-edit-character-set-selector)
  - [Charset Alias Normalization](#charset-alias-normalization)
  - [Sender Name Popover with BBS Lookup](#sender-name-popover-with-bbs-lookup)
  - [To Name Autocomplete from Address Book](#to-name-autocomplete-from-address-book)
  - [Art Format Field Now Forces ANSI Renderer](#art-format-field-now-forces-ansi-renderer)
  - [Message Body Preserves Multiple Spaces](#message-body-preserves-multiple-spaces)
  - [PETSCII Removed from Message Viewer](#petscii-removed-from-message-viewer)
  - [ANSI Auto-Detection Simplified](#ansi-auto-detection-simplified)
  - [Message Body Line Spacing Tightened](#message-body-line-spacing-tightened)
  - [Echomail Reader From Line Style](#echomail-reader-from-line-style)
  - [Compose Advanced Options Panel](#compose-advanced-options-panel)
  - [Compose Hard Wrap](#compose-hard-wrap)
  - [Advanced Search: Message ID Field](#advanced-search-message-id-field)
- [Weather Reports](#weather-reports)
  - [Weather Configuration Admin Page](#weather-configuration-admin-page)
- [Broadcast Manager](#broadcast-manager)
  - [Rename from Ad Campaigns](#rename-from-ad-campaigns)
  - [Weather Report Preset](#weather-report-preset)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

**File Areas & TIC Processing**
- Incoming TIC ZIP processing now uses `FILE_ID.DIZ` only from the archive root.

**FTN Networking**
- BinkP now handles exported socket timeouts more consistently during handshake reads.

**Echomail & Netmail**
- Charset alias normalization: FTN alias values stored in `message_charset` (e.g. `IBMPC`, `ASCII`, `CP895`, `LATIN-1`) are now mapped to their canonical iconv-compatible equivalents at both storage time and reply time. `CP1252` has been added as a supported outgoing charset.
- Outgoing echomail and netmail replies now honour the original message's `CHRS` charset. When replying to a CP437-encoded message the reply is re-encoded in CP437 and the `CHRS: CP437 2` kludge is set; if the body contains characters that cannot be represented the reply falls back to UTF-8.
- A new **Encoding** selector in the compose form lets users explicitly choose the outgoing charset. It defaults to the original message's charset when replying, and to UTF-8 for new messages.
- The netmail compose To Address field now includes a nodelist autocomplete: typing a system name, location, or partial address triggers a live search and presents a dropdown of matching nodes showing the FTN address, network badge, system name, and location. Selecting an entry populates the To Address field.
- Clicking a node address in the netmail message list now shows a Bootstrap popover with the BBS system name, location, and a "View full node details" button. Point addresses that are not in the nodelist fall back to the parent node entry.
- The "Art Encoding" field in the netmail and echomail message edit dialogs has been renamed to **Character Set** and changed from a free-text input to a dropdown. The dropdown lists only valid iconv-compatible charsets. Messages with an unrecognised stored charset show it as a labelled "(unknown)" option so it is preserved if the edit is saved without changing it.
- Clicking the sender name in the echomail list or the From field in the netmail message view now shows a popover with the BBS system name, location, FTN address, and buttons to send netmail or view the full nodelist entry. Point addresses are resolved to the boss node for the lookup.
- The **To Name** field in the netmail compose form now supports address book autocomplete: typing two or more characters shows a dropdown of matching contacts with their node address, network badge, and BBS name. Selecting an entry fills both the To Name and To Address fields.
- Setting a message's Art Format to `ansi` now correctly forces ANSI rendering regardless of heuristic detection. Previously only `amiga_ansi` and `petscii` were treated as explicit overrides.
- PETSCII has been removed as an Art Format option from the echomail, netmail, and echo area edit dialogs, and from the message viewer render mode cycle. PETSCII is a binary format that cannot survive FTN text transport. The PETSCII renderer in `ansisys.js` is retained for file area previews of `.prg` and `.seq` files.
- The message body renderer now preserves multiple consecutive spaces and tabs (`white-space: pre-wrap`), fixing space-aligned tables and ASCII art that were being collapsed to single spaces by the browser.
- ANSI art auto-detection now triggers only on actual ANSI escape sequences or pipe colour codes. The previous leading-space and line-length heuristics have been removed; space-aligned ASCII art is now handled correctly by the `white-space: pre-wrap` rendering.
- Message body line spacing has been reduced from `1.6` to `1.2` across all themes, matching the tighter line cadence of BBS-style messages.
- The **From** field in the echomail message reader now uses a solid underline (matching a hyperlink) instead of a dashed underline.
- The compose form's Encoding and Markup Format selectors have been moved into a collapsible **Advanced Options** panel below the message textarea, keeping the default compose view clean. The panel's open/closed state is saved per-user and persists across sessions.
- A **Hard Wrap** selector has been added to the Advanced Options panel. When enabled, the compose textarea automatically breaks lines at 79 characters (standard 80-column format, default), 39 characters (for readability on 40-column systems such as the C64), or can be turned off entirely. Word-wrap is used when possible; a hard break is used if no space is found. The setting is saved per-user and persists across sessions.
- The echomail Advanced Search modal now includes a **Message ID** field that searches the `message_id` column using a partial (ILIKE) match.

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
- The user profile page now shows an **Activity Log** tab (admin/sysop only) listing recent events from the `user_activity_log` table. The existing transaction history is presented on an adjacent **Transaction History** tab. Both tabs are hidden from regular users.
- Fixed a spurious "New version available" reload prompt appearing during normal page navigation caused by a secondary service worker activation watcher.

**MRC Chat**
- When the server returns no rooms, the MRC chat WebDoor now shows the configured default room instead of an empty list.
- Fixed a bug where the first message sent in a new DM conversation was not visible until the next poll.
- Tab-completion of usernames in the chat input: type a partial name and press Tab to complete it, press Tab again to cycle through all matching online users.
- Tab-completion now also works for slash commands: type `/` or a partial command name and press Tab to complete or cycle through matching commands. `/msg` also completes the username argument.
- A toggle in the chat sidebar lets users switch between simple polling and long polling; the choice is remembered in localStorage.
- Hard refresh (Ctrl+Shift+R) now correctly bypasses the service worker cache, restoring the expected development behaviour.
- Fixed room user count showing one more user than actually present.
- Fixed sent DM messages disappearing immediately when there is no prior conversation history.

**Weather Reports**
- A new Weather Report configuration page is available under Admin → Community → Weather Report. It provides a form-based editor for `config/weather.json` (API key, locations, units) with a Load Example button to seed from `weather.json.example`. `scripts/README_weather.md` has been deprecated; documentation has moved to `docs/Weather.md`.

**Broadcast Manager**
- The "Ad Campaigns" section has been renamed to **Broadcast Manager** and the "Advertisements" page has been renamed to **Content Library** throughout the UI. The menu group is now labelled **Ads and Bulletins**. URLs and internal identifiers are unchanged. The Broadcast Manager now includes a **Weather Report** quick-setup preset that pre-configures a daily 3:00 AM schedule and sets the content command to `weather_report.php`. The preset is greyed out when `config/weather.json` is not present.

**LovlyNet Integration**
- The LovlyNet admin page has a new Setup Checklist column on the Setup tab that verifies registration status, hub connectivity, uplink configuration, default area subscriptions, and the LVLY_NODELIST file area rule. All five checks run in parallel and update as each result arrives. A Fix button can automatically create a canonical file area rule for nodelist import.
- New BinkP uplinks added by the LovlyNet setup script now default to CRAM-MD5 authentication.
- The `replace` metadata flag in `area_metadata.json` is now honoured: if LovlyNet recommends `replace: true` for a file area, BinktermPHP will detect and offer to correct a mismatch.
- Echo and File area tab rows for default (recommended) areas now show a warning icon when the area is not yet subscribed.
- When creating echo or file areas during subscription or area-sync, `area_metadata.json` recommended fields (`sysop_only`, `readonly`, `replace`) are now applied at creation time rather than only as a post-creation correction.

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

### Profile: Activity Log Tab

The user profile page now presents a tabbed card (visible to admin/sysop users
only) with two tabs:

- **Activity Log** — shows recent entries from the `user_activity_log` table:
  timestamp (in the viewer's timezone), category badge, activity label, and an
  object detail (e.g. the echo area tag for a "File Area Viewed" event). A
  **Load More** button fetches the next 25 entries. File area view events
  include the area tag as the detail field.
- **Transaction History** — the existing credit transaction table, unchanged.

Regular users do not see either tab. The card is only rendered when the viewing
user is an admin.

### Service Worker: Spurious Reload Prompt Fixed

A "New version available" reload toast was appearing during normal page
navigation, not just when a genuine service worker update had been installed.
The registration code previously watched for any service worker activation event
and showed the prompt whenever one fired — including activations that occur as
part of the browser's normal navigation lifecycle.

The secondary activation watcher has been removed. The reload prompt is now
shown only when the service worker itself signals that it replaced an older
version, which is the correct trigger.

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

## LovlyNet Integration

### Setup Checklist

The LovlyNet admin page (`/admin/lovlynet`) has been redesigned to a two-column
layout on the Setup tab. The left column contains the existing Update Registration
form. The right column contains a new **Setup Checklist** that is refreshed every
time the tab is opened.

The checklist verifies five items simultaneously. All five checks are dispatched in parallel and each row updates independently as its result arrives, so fast checks (registration, uplink configuration) resolve immediately while slower ones (hub connectivity) do not block the display.

| Item | What is checked |
|------|----------------|
| Registration | Always shown as complete — if you are on this page, registration has been done. |
| Hub Connectivity | Performs a live BinkP authentication test against the LovlyNet hub to verify the configured credentials and network reachability. |
| Uplink Configured | Verifies that a BinkP uplink entry for the LovlyNet hub exists in `config/binkp.json`. |
| Default Areas | Fetches the list of LovlyNet-recommended echo and file areas from the LovlyNet server and checks whether each is currently subscribed. Missing areas are listed with a hint to subscribe via the Echo Areas or File Areas tabs. |
| LVLY_NODELIST File Area Rule | Checks `config/filearea_rules.json` for a rule whose pattern matches the canonical LovlyNet nodelist filename pattern on the `lovlynet` domain. If no matching rule is found, a **Fix** button appears that automatically creates a working rule and saves it via the admin daemon. |

The default area subscription check uses an `is_default` flag added to every
area returned by the LovlyNet areas API; no separate API call is required.

### Setup Checklist: Five Parallel Checks

See the [Setup Checklist](#setup-checklist) section above. The checklist was
previously a sequential three-item list that required each check to complete
before the next began. It has been expanded to five items and now dispatches
all checks in parallel, rendering each row with a spinner immediately and
replacing it with the result as it arrives.

### BinkP CRAM-MD5 Default

The LovlyNet setup script (`scripts/lovlynet_setup.php`) now configures new
BinkP uplink entries with `crypt: true`, enabling CRAM-MD5 authentication by
default. Previously new uplinks were created with plain-text password
authentication. Existing uplinks are not modified.

### replace Metadata Flag for File Areas

LovlyNet can now recommend the `replace` behaviour for file areas via
`area_metadata.json`. When a file area's metadata contains `"replace": true`,
BinktermPHP checks whether the local file area has `replace_existing` enabled.
If there is a mismatch, an issue is reported in the same settings-issue system
used for the existing `readonly` flag, and **Sync** will correct it.

The `local_replace_existing` field is now included in the areas API response
so the admin interface can display the current value alongside the recommendation.

### Default Area Indicators on Echo and File Area Tabs

On the Echo Areas and File Areas tabs of the LovlyNet admin page, any area that
is marked as a default (recommended) area by the LovlyNet server but is not yet
subscribed will now show an amber warning icon
(`fa-exclamation-triangle text-warning`) next to the area tag in the table row.
Hovering over the icon shows a tooltip explaining that this is a default area
that has not been subscribed.

Areas that are subscribed, or areas that are not flagged as defaults, are
unaffected. The icon is removed automatically when the area is subscribed.

## Echomail & Netmail

### Node Address Autocomplete in Netmail Compose

The **To Address** field in the netmail compose form now doubles as a node
search box. While the field contains non-FTN characters (i.e. no colon or
slash) a debounced search fires against the imported nodelist. Results appear
in a dropdown beneath the field showing:

- FTN address
- Network badge (e.g. FidoNet, LovlyNet)
- System name
- Location (in parentheses)

Keyboard navigation (↑/↓/Enter/Escape) and mouse selection are both
supported. Selecting an entry populates only the **To Address** field; the
**To Name** field is left for the user to fill in. The help text below the
field has been updated from "Fidonet address (e.g., 1:123/456)" to
"FTN address or system name" to reflect the new capability.

The autocomplete requires a nodelist to have been imported via the nodelist
import process. If no nodelist data is present the search returns no results.

### Node Address Popover in Netmail List

In the netmail message list, sender and recipient node addresses are now
displayed as linkable text (dotted underline, primary colour). Clicking an
address opens a Bootstrap popover showing:

- BBS system name
- Location

The popover also contains a **View full node details** button that links to
the full nodelist entry page. Clicking anywhere else dismisses the popover.

Point addresses that are not found in the nodelist are automatically
looked up against their parent node (e.g. `1:123/456.1` → `1:123/456`),
so the popover and the "View full node details" link resolve correctly even
for point systems that do not have their own nodelist record.

### Outgoing Charset Selector

The compose form for both echomail and netmail now includes an **Encoding**
dropdown above the Markup Format selector. The available options are:

| Value | Description |
|-------|-------------|
| UTF-8 | Unicode — default for new messages |
| CP437 | IBM PC / DOS codepage 437 |
| CP850 | Latin-1 DOS codepage 850 |
| CP852 | Central European DOS codepage 852 |
| CP866 | Cyrillic DOS codepage 866 |
| CP1252 | Windows Western European |
| ISO-8859-1 | Latin-1 |
| ISO-8859-2 | Central European |

When replying to a message, the selector pre-selects the charset of the
original message. A reply to a `CHRS: CP437 2` message will default to
CP437 so the recipient's legacy system receives correctly-encoded bytes.

If the message body contains characters that cannot be represented in the
selected charset (e.g. an emoji in a CP437 message), BinktermPHP silently
falls back to UTF-8 rather than losing content.

The selected charset is written to the outgoing FTN packet as the
`CHRS` kludge line and the message body bytes are re-encoded accordingly
before the packet is written.

### Message Edit: Character Set Selector

The **Art Encoding** field in the message edit dialogs for both netmail and
echomail has been renamed to **Character Set** and converted from a free-text
input with a datalist to a `<select>` dropdown.

The dropdown lists only charsets that are valid for the FTN `CHRS` kludge and
supported by iconv for body re-encoding:

| Value | Description |
|-------|-------------|
| UTF-8 | Unicode |
| CP437 | IBM PC / DOS codepage 437 |
| CP850 | Latin-1 DOS codepage 850 |
| CP852 | Central European DOS codepage 852 |
| CP866 | Cyrillic DOS codepage 866 |
| CP1252 | Windows Western European |
| ISO-8859-1 | Latin-1 |
| ISO-8859-2 | Central European |

If a stored message has a `message_charset` value that is not in the list
(e.g. a legacy value such as `KOI8-R` or a former art-format hint like
`PETSCII`), the edit dialog inserts it as a labelled `(unknown)` option at the
top of the dropdown and pre-selects it. Saving the edit without changing the
charset preserves the original value. The unknown option is removed and
re-evaluated each time a different message is opened for editing.

The help text that previously described the field as affecting only ANSI/PETSCII
art rendering has been removed; art rendering format is controlled separately by
the **Art Format** selector above it.

The charset list is centralised in `BinkpConfig::getSupportedCharsets()` and
shared by the compose form and both edit dialogs.

### Sender Name Popover with BBS Lookup

Clicking the sender name in the echomail message list or the **From:** field in
the netmail message view now opens a Bootstrap popover that includes the remote
BBS name, location, and FTN address pulled live from the imported nodelist.

The popover shows a spinner immediately, then fills in the details once the
nodelist lookup completes. Two action buttons are provided:

- **Send Netmail** — opens the compose form pre-addressed to the sender.
- **View full node details** — links to the nodelist entry page for the node.

**Point address handling:** If the sender's FTN address is a point address
(e.g. `2:250/100.3`), the lookup is performed against the boss node
(`2:250/100`) so the BBS name and location are resolved even for point systems
that do not have their own nodelist entry. The popover notes the address is a
point.

The popover implementation is shared between echomail and netmail to avoid
duplication. The trigger element uses a standard solid underline in both
contexts.

### To Name Autocomplete from Address Book

The **To Name** field in the netmail compose form now provides inline
autocomplete backed by the user's address book. Typing two or more characters
triggers a debounced search and shows a dropdown beneath the field. Each result
displays:

- The contact's name (bold)
- Their node address in monospace, a network badge (e.g. `fidonet`), and the
  BBS system name pulled from the imported nodelist

Selecting an entry fills both fields at once: **To Name** is set to the
contact's name and **To Address** is set to their FTN node address. The
crashmail checkbox is also toggled to match the contact's `Always Crashmail`
preference. Full keyboard navigation (↑/↓/Enter/Escape) is supported.

### Art Format Field Now Forces ANSI Renderer

Setting a message's **Art Format** to `ansi` now unconditionally triggers the
ANSI renderer when the message is displayed. Previously only `amiga_ansi` was
treated as an explicit override; `ansi` fell through to heuristic detection
(cursor codes, dense colour lines) and was silently ignored for messages that
did not meet those thresholds.

### Message Body Preserves Multiple Spaces

The plain-text message renderer (`formatMessageText` in `public_html/js/app.js`)
now applies `white-space: pre-wrap` to each rendered line. This preserves
multiple consecutive spaces and tabs exactly as they appear in the raw message
text, fixing the display of space-aligned tables and column-formatted content
that were previously collapsed to a single space by standard HTML rendering.

The font and size are unchanged — only whitespace handling is affected. Messages
rendered as ANSI art, PCBoard colour, or forced plain text (`<pre>`) are not
affected by this change.

### PETSCII Removed from Message Viewer

PETSCII is a binary format used by Commodore 64 programs (`.prg`) and
sequential files (`.seq`). Because FTN echomail and netmail are text-based
transports, binary PETSCII data cannot survive the journey intact and the
format is not meaningful in this context.

The **PETSCII** option has been removed from:

- The **Art Format** dropdown in the echomail and netmail message edit dialogs
- The **Art Format Hint** dropdown on the echo area settings page
- The render mode cycle button in the echomail and netmail message viewers
- `ArtFormatDetector::detectArtFormat()` in `src/ArtFormatDetector.php` — the
  PETSCII encoding name list, byte-level heuristic, and related constants have
  all been removed

Any message that already has `art_format = petscii` stored in the database
will continue to display (falling back to the auto renderer). The PETSCII
decoder (`ArtPetsciiDecoder`, `renderPetsciiBuffer`) remains in `ansisys.js`
and is still used by the file area file previewer for `.prg` and `.seq`
downloads, where PETSCII format is detected purely by file extension.

### ANSI Auto-Detection Simplified

The ANSI art auto-detection heuristics in the message viewer have been
simplified. Previously the renderer triggered on:

- Actual ANSI escape sequences (cursor codes, SGR colour codes, pipe codes)
- Messages with many lines that start with 5 or more leading spaces
- Messages with long lines and dense colour codes

The leading-space and line-length heuristics have been removed. The renderer
now triggers only on actual ANSI/pipe escape sequences or an explicit
`art_format` setting. Space-aligned ASCII art and text tables no longer need a
separate rendering path — they are handled correctly by the `white-space:
pre-wrap` applied to all plain-text message lines.

### Message Body Line Spacing Tightened

The `line-height` on `.message-formatted` has been reduced from `1.6` to `1.2`
across all five theme stylesheets (`style.css`, `amber.css`, `dark.css`,
`greenterm.css`, `cyberpunk.css`). The previous value produced noticeably loose
line spacing compared to the tight terminal cadence of BBS messages.

### Echomail Reader From Line Style

The **From** sender name in the echomail message reader now uses
`text-decoration: underline` (matching a hyperlink) instead of the previous
`border-bottom: 1px dashed` dotted underline, consistent with the style used in
the echomail message list and the netmail reader.

### Compose Advanced Options Panel

The **Encoding** and **Markup Format** selectors in the echomail and netmail
compose forms have been moved into a collapsible **Advanced Options** panel.
The panel is toggled by a button placed next to the **Insert File** button
below the message textarea.

The panel's open/closed state is saved as a user preference
(`compose_advanced_open`) and restored on the next visit, so users who
frequently change these settings can leave it open without having to re-expand
it each time.

No workflow change is required for users who do not use those selectors — the
panel is collapsed by default and the selectors continue to work identically
when the panel is open.

### Compose Hard Wrap

A **Hard Wrap** selector is now available inside the Advanced Options panel.
When active, the compose textarea automatically breaks lines while typing:

- **79 characters** (default) — standard 80-column FTN format
- **39 characters** — for readability on 40-column systems such as the C64
- **Off** — no automatic wrapping; manage line length yourself

When the current line reaches the selected column limit and the user types a
printable character, the editor word-wraps at the last space on the line. If
no space is present, a hard break is inserted at the column limit. Pressing
Space at or past the limit inserts a newline directly.

The selected value is saved as the user preference `compose_hard_wrap` and
restored on every compose page load.

### Charset Alias Normalization

FTN software uses several aliases and legacy names for charsets in the `CHRS`
kludge that are not accepted by PHP's iconv. Version 1.8.8 introduces a
centralized `BinkpConfig::normalizeCharset()` method that maps these to their
canonical equivalents.

The normalization is applied at two points:

- **Storage time** — when incoming packets are processed, the detected charset
  is normalized before being written to the `message_charset` column.
- **Reply time** — when opening the compose form for a reply, the stored charset
  is normalized before pre-selecting the encoding dropdown, so the correct
  option is highlighted even for messages stored under an alias.

The full alias table:

| Stored value | Normalized to | Notes |
|---|---|---|
| `IBMPC` | `CP437` | Common FTN alias for IBM PC codepage 437 |
| `IBM437` | `CP437` | |
| `ASCII` / `US-ASCII` | `UTF-8` | ASCII is a 7-bit subset of UTF-8 |
| `CP895` | `CP850` | Kamenický (Czech DOS); not supported by iconv — CP850 is the closest substitute |
| `IBM850` | `CP850` | |
| `IBM852` | `CP852` | |
| `IBM866` | `CP866` | |
| `LATIN-1` / `LATIN1` | `ISO-8859-1` | |
| `LATIN-2` / `LATIN2` | `ISO-8859-2` | |
| `WINDOWS-1252` | `CP1252` | |

Existing messages in the database with alias values in `message_charset` are
not migrated — the normalization is applied on read when composing a reply, so
no data migration is needed.

### Advanced Search: Message ID Field

The echomail **Advanced Search** modal now includes a **Message ID** field.
Entering a value performs a case-insensitive partial match (`ILIKE`) against
the `message_id` column of the `echomail` table, so you can search by a
fragment of a FidoNet message ID such as `<12345@fidonet.org>` without knowing
the full value.

The field follows the same rules as the other Advanced Search text fields:
minimum two characters, combined with the remaining fields using AND logic.

## Weather Reports

### Weather Configuration Admin Page

A new admin page at **Admin → Community → Weather Report** provides a form-based
editor for `config/weather.json`. Previously this file had to be created and
edited by hand using `config/weather.json.example` as a guide.

The page offers:

- Fields for report title, coverage area, OpenWeatherMap API key, units
  (metric/imperial/standard), API timeout, and maximum locations.
- A location manager with add, edit, and remove actions for each city
  (name, latitude, longitude).
- A **Load Example** button that appears when no `config/weather.json` exists
  yet, seeding all fields from `weather.json.example` so there is a working
  starting point.
- Saves are written via the admin daemon, consistent with how other config
  files are managed.

`scripts/README_weather.md` has been deprecated and replaced with a stub
that redirects to the new documentation at `docs/Weather.md`.

## Broadcast Manager

### Rename from Ad Campaigns

The section previously labelled **Ad Campaigns** in the admin navigation has
been renamed to **Broadcast Manager**. The page previously labelled
**Advertisements** has been renamed to **Content Library**. The menu group
that contains both has been renamed from **Ads** to **Ads and Bulletins** to
better reflect that it now covers both traditional ANSI content and automated
bulletins such as weather reports and file area updates.

No URLs, database columns, config keys, or template file names have changed.
Existing campaigns continue to function without any action required.

### Weather Report Preset

The Broadcast Manager quick-setup wizard now includes a **Weather Report**
preset. Selecting it pre-fills:

- A daily schedule running at 3:00 AM every day of the week.
- A content command pointing to `scripts/weather_report.php`, which prints
  the report to stdout for the scheduler to post.
- A subject template of `Daily Weather Report`.

The preset is disabled (greyed out with a tooltip) when `config/weather.json`
does not exist. Configure the weather report first via
**Admin → Community → Weather Report**, then return to the Broadcast Manager
to create the campaign.

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
