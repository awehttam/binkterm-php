# Upgrading to 1.9.0

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Security Fixes](#security-fixes)
  - [MCP Server Dependency Update](#mcp-server-dependency-update)
  - [CVE Coverage](#cve-coverage)
- [BinkP](#binkp)
  - [Exact Uplink Address Matching](#exact-uplink-address-matching)
  - [FTS-0001 Compliant Outbound Line Terminators](#fts-0001-compliant-outbound-line-terminators)
  - [Queue List Auto-Refresh](#queue-list-auto-refresh)
- [Robots](#robots)
  - [TEST Area Auto Responder Rename](#test-area-auto-responder-rename)
- [Real-time Events (BinkStream)](#real-time-events-binkstream)
  - [Targeted Dashboard Stats Notifications](#targeted-dashboard-stats-notifications)
  - [Browser Transport Preference and Cursor Replay Fixes](#browser-transport-preference-and-cursor-replay-fixes)
  - [Message List Refresh on Page Restore](#message-list-refresh-on-page-restore)
- [Caddy Configuration](#caddy-configuration)
  - [Static File Serving Fix](#static-file-serving-fix)
- [File Previewer](#file-previewer)
  - [SID Music Previewer](#sid-music-previewer)
  - [SID Player Visualizer and Controls](#sid-player-visualizer-and-controls)
  - [Archive Listing Size Limit](#archive-listing-size-limit)
  - [Torrent File Preview and Magnet Links](#torrent-file-preview-and-magnet-links)
- [File Upload](#file-upload)
  - [Torrent Metadata Pre-fill](#torrent-metadata-pre-fill)
- [Markdown Images](#markdown-images)
  - [Human-Readable Image URLs](#human-readable-image-urls)
- [Terminal Server Settings](#terminal-server-settings)
  - [Tabbed Settings Screen Parity and Workflow](#tabbed-settings-screen-parity-and-workflow)
- [Outgoing Message Charset](#outgoing-message-charset)
  - [Default Changed to CP437](#default-changed-to-cp437)
  - [Per-Uplink Charset Override](#per-uplink-charset-override)
- [Message Composer](#message-composer)
  - [Draft State Now Fully Preserved](#draft-state-now-fully-preserved)
- [Dashboard](#dashboard)
  - [Shoutbox Profile Links](#shoutbox-profile-links)
  - [Today's Callers in Its Own Card](#todays-callers-in-its-own-card)
  - [Today's Callers Persists After Logout](#todays-callers-persists-after-logout)
- [BBS Directory](#bbs-directory)
  - [Individual BBS Information Pages](#individual-bbs-information-pages)
  - [SEO Improvements](#seo-improvements)
- [Bug Fixes](#bug-fixes)
  - [Netmail Unsave in Message Modal](#netmail-unsave-in-message-modal)
  - [AreaFix History Not Reloading on Uplink Change](#areafix-history-not-reloading-on-uplink-change)
  - [Login with Real Name](#login-with-real-name)
  - [File Area Delete Stays in the Current Area](#file-area-delete-stays-in-the-current-area)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

**Outgoing Message Charset**
- Outgoing FTN messages now default to CP437 (IBM PC / DOS) instead of UTF-8. This minimizes text display issues on legacy networks such as FidoNet where many nodes run software that does not support UTF-8 encoding. The default can be changed in Admin → BBS Settings, and individual uplinks can override it with their own charset setting. Local echo areas always use UTF-8 regardless of this setting. Replies still inherit the charset of the message being replied to when possible.

**Security Fixes**
- The optional MCP server now updates its `path-to-regexp` dependency from `8.3.0` to `8.4.0`.
- This release addresses `CVE-2026-4926` (`GHSA-j3q9-mxjg-w52f`) and `CVE-2026-4923` (`GHSA-27v5-c462-wpq7`) in the MCP server dependency tree.

**Logging**
- When a web-server PHP process cannot write directly to an application log file, it now sends the log entry to the admin daemon so the daemon can append it to the correct log file instead.
- Door session setup events (session start, node allocation, DOSBox launch) are now written to `data/logs/dosdoor.log`. Activity during actual door execution continues to be logged by the multiplexing bridge server to `data/logs/multiplexing-server.log`.

**BinkP**
- Outbound routing now checks for an exact configured uplink address match before falling back to network-based uplink selection. This allows multiple uplinks on the same FTN network, so messages explicitly addressed to a specific uplink node can be routed through that uplink instead of being matched only by shared network patterns.
- Outbound FTN packet message bodies now use bare CR (`\r`, 0x0D) as the line separator, as required by FTS-0001. Previously all line terminators were written as CRLF (`\r\n`), which caused interoperability problems with remote nodes that enforce strict FTS-0001 parsing — most visibly a spurious LF after the `* Origin:` line.
- The inbound and outbound queue lists on the BinkP administration page now refresh automatically every 15 seconds. Files that are processed or transferred while the page is open are removed from the list without requiring a manual refresh.

**Robots**
- The echomail robot processor type previously displayed as "Auto-Reply" is now displayed as "TEST Area Auto Responder" to better describe its purpose. No configuration changes are required; the internal processor type identifier (`auto_reply`) is unchanged.

**Caddy Configuration**
- The recommended Caddy configuration has been updated. If you use Caddy, a one-time manual edit to your Caddyfile is required to ensure static files and subdirectories with `index.html` (such as WebDoors and the C64 emulator) are served correctly instead of being routed through PHP.

**Markdown Images**
- Images uploaded through the echomail message editor are now served at human-readable URLs in the form `/echomail-images/{username}/{name}-{hash}.png` instead of a raw SHA-256 hash path. Existing embedded images continue to resolve through the old hash-based URL so no message editing is required. A one-time database migration backfills the new URL slug for existing uploaded images.

**Terminal Server Settings**
- The telnet/SSH terminal server now includes a dedicated settings screen so users can change their own preferences, profile details, and account password directly from the text interface. The terminal login screen also now includes a lost-password reset path that starts the same email reset flow as the web form, including support for username, real name, or email address lookup. The settings screen includes tabbed navigation, in-place save behavior, centered save/discard feedback, and a full-screen signature editor with signature-specific wording.

**File Previewer**
- The file area previewer now supports Commodore 64 SID music files (`.sid`). Clicking a SID file opens an in-browser player powered by the bundled wothke/websid WebAssembly emulator. The player displays the embedded title, author, and release year from the SID header and supports multi-subtune files via a track selector. SID files inside ZIP archives are also playable from the archive browser.
- The SID player now includes a real-time spectrum visualizer showing 48 frequency bars that decay smoothly when playback is paused or stopped. Playback is manual — the player loads ready to play and waits for the user to press Play.
- Non-ZIP archive listing (RAR, 7-Zip, TAR, LZH, etc.) now enforces a configurable maximum file size before invoking the 7z tool. Archives that exceed the limit display a message and a download link instead of timing out. The limit defaults to 20 MB and is controlled by `ARCHIVE_LIST_MAX_SIZE` in `.env`. ZIP archives are not affected because their file listing reads only the central directory index and does not require 7z.
- The file area previewer now supports `.torrent` files. Clicking a torrent file displays a metadata card showing the torrent name, total size, comment, creation date, software that created it, piece size and count, tracker URLs, whether DHT/PEX is disabled, and a computed magnet link. Multi-file torrents also list every file contained in the torrent with individual sizes. A magnet icon button appears in the preview toolbar next to the download button and copies the magnet link to the clipboard when clicked.

**File Upload**
- When a `.torrent` file is selected in the upload dialog, the short description field is automatically pre-filled from the torrent's name (or comment, if the name is absent and the comment is not a URL). For multi-file torrents, the long description field is pre-filled with one file path per line. A torrent metadata preview card is also shown in the dialog so the contents can be verified before submitting.

**Message Composer**
- Saving an echomail or netmail draft now preserves the full compose state: message encoding (charset), markup format (plain, Markdown, Style Codes), hard-wrap setting, tagline selection, and cross-posted echo areas. When the draft is reopened, all of these fields are restored to the values that were in effect when the draft was saved. Previously, only the primary echo area, message text, subject, and recipient fields were saved; the encoding, markup type, wrap setting, tagline, and cross-post selections were silently discarded. A database migration (`v1.11.0.70`) adds a `meta` JSONB column to the `drafts` table to hold this additional state.

**BBS Directory**
- Each BBS listing now has its own dedicated information page at `/bbs-directory/{id}`. The page displays the BBS name, sysop, location, telnet and SSH addresses, website, software, OS, notes, and last-seen date. A small map is shown when coordinates are available. BBS names in the main directory listing now link to their detail pages.
- The BBS directory page now carries its own meta description, Open Graph tags, Twitter Card tag, and a `CollectionPage` JSON-LD structured data block. The page title has been updated to include "Telnet Bulletin Board Systems" for better keyword coverage.
- The Notes field label in the admin BBS directory edit form has been updated to "Notes about what this system offers (public)" to make clear that the field is user-facing.

**Dashboard**
- Usernames in the shoutbox are now clickable links that navigate to the user's profile page.
- The Today's Callers list (visible to admins) has been moved out of the System Information card into its own dedicated card. Usernames in the list are now clickable links to each user's profile page.
- Today's Callers now sources its data from the login activity log rather than active sessions. Users who have logged out are no longer removed from the list. The column previously labelled "Last seen" has been renamed to "Last Call".

**Bug Fixes**
- Switching to a different uplink in the AreaFix Manager now automatically reloads the message history for the active tab. Previously the history panel was cleared but not repopulated, leaving it blank until the Refresh button was clicked manually.
- Users can now log in using their real name as well as their username. Both fields are case-insensitive and unique, so there is no ambiguity.
- Opening a saved netmail message in the message modal and clicking the save button unsaved it correctly from the message list, but the same button inside the modal always showed "Save" instead of "Saved" and would re-save rather than unsave. The single-message API query for netmail was missing the `saved_messages` join, so `is_saved` was never included in the response. The join has been added so the modal reflects the correct saved state on open.
- Deleting a file from the web file browser now keeps the user in the same file area and subfolder after the page refreshes its sidebar state, instead of returning to the main `/files` index.
- Cross-posted echomail copies now preserve the same markup context as the primary post, so Markdown posts continue to carry their outbound markup kludge and formatting metadata on every selected destination instead of only the first area.

**Documentation**
- `scripts/import_bbslist.php` is now documented in `docs/CLI.md`, including how imports merge with locally-edited BBS Directory entries.
- A FAQ entry has been added clarifying that the "automated AI echomail posting" feature referenced in some discussions was an April Fools Day prank. Automated, unattended AI posting to echo areas is not a planned feature. BinktermPHP 2.0 does not exist.

**Real-time Events (BinkStream)**
- Dashboard stats notifications are now targeted per user. Echomail events are sent only to users subscribed to the affected echo area who are currently online; netmail events go only to the recipient if online; file events go to all online users. Previously all events were broadcast to every connected user regardless of subscriptions.
- Browser-side BinkStream transport selection in `auto` mode now prefers the configured public WebSocket endpoint instead of relying on local PID-file visibility from the web process. This prevents deployments that run the realtime server outside the PHP process namespace from incorrectly starting in SSE mode.
- The SharedWorker now waits for page configuration before opening a transport, and replay behavior on refresh has been tightened so already-seen events are not re-delivered after browser reloads.
- The echomail and netmail message lists now refresh automatically when the page is restored from the background after being hidden for more than 30 seconds. BinkStream events are paused or throttled while the page is hidden, so new messages that arrived during that period were not reflected in the list until a manual reload. The page now detects when it becomes visible again and triggers a refresh automatically.

## Security Fixes

### MCP Server Dependency Update

The `mcp-server/` package now pins `path-to-regexp` at `^8.4.0`, and the lockfile has been refreshed so the resolved package version is `8.4.0`.

This change affects the optional Model Context Protocol server used for AI assistant access to echomail. The main PHP application and the DOSBox bridge do not use this dependency.

No database migration is required for this release.

### CVE Coverage

This update is included specifically to address the following dependency advisories in the MCP server stack:

- `CVE-2026-4926` (`GHSA-j3q9-mxjg-w52f`)
- `CVE-2026-4923` (`GHSA-27v5-c462-wpq7`)

If you do not run the optional MCP server, no additional service-specific action is required beyond your normal application upgrade process.

## BinkP

### Exact Uplink Address Matching

Outbound routing now checks for an exact configured uplink address match before falling back to network-based uplink selection.

This allows systems to carry different echo areas through different uplink nodes on the same FTN network. When a message is explicitly addressed to a configured uplink node, that specific uplink can now be selected directly instead of being resolved only through shared network patterns.

If multiple uplinks are configured with the same network patterns and no exact uplink-address match applies, routing still falls back to the existing network-based selection rules.

### FTS-0001 Compliant Outbound Line Terminators

FTS-0001 defines CR (0x0D) as the sole line separator in FTN packet message bodies. LF (0x0A) is explicitly defined as a character parsers may ignore — it must not be emitted by a compliant writer. BinktermPHP was writing CRLF (`\r\n`) throughout the outbound packet body, which caused interoperability failures with remote nodes that apply strict parsing. The most visible symptom was a spurious LF character after the `* Origin:` line.

All line terminators in outbound packet bodies are now written as bare CR. This covers kludge lines (`\x01TZUTC`, `\x01MSGID`, `\x01PID`, etc.), the `AREA:` control line, the tearline, the blank line preceding the tearline, the `* Origin:` line, `SEEN-BY:`, `\x01PATH:`, and any stored kludge or Via lines read from the database. The message body text from the database is also normalized to bare CR before being written into the packet.

No database migration is required. The change affects only what is written into outbound `.pkt` files; incoming packet parsing is unchanged.

### Queue List Auto-Refresh

The inbound and outbound queue lists on the BinkP administration page previously showed the state of the queues at the moment the page was loaded. Files processed or transferred during that session were not removed from the display until the page was manually refreshed.

Both queue lists now poll the server every 15 seconds. The file counts in the overview cards at the top of the page are also updated on each poll. No user action is required to keep the view current.

No configuration changes or database migrations are required.

## Robots

### TEST Area Auto Responder Rename

The echomail robot processor that automatically replies to messages in a test echo area was previously labelled "Auto-Reply" in the admin interface. It is now labelled "TEST Area Auto Responder" to better reflect its intended use.

No configuration changes are required. The internal processor type identifier stored in the database (`auto_reply`) is unchanged, so existing robot configurations continue to work without modification.

## Real-time Events (BinkStream)

### Targeted Dashboard Stats Notifications

Dashboard stats notifications are now targeted per user. Echomail events are delivered only to users who are subscribed to the affected echo area and currently online. Netmail notifications are delivered only to the recipient when that user is online. File events continue to notify all online users.

This reduces unnecessary realtime traffic and prevents unrelated users from receiving dashboard refresh events for message areas they do not follow.

### Browser Transport Preference and Cursor Replay Fixes

Browser-side BinkStream transport selection has been corrected for deployments where the realtime WebSocket server is reachable through a public URL but is not directly visible to the PHP web process by PID.

Previously, `BINKSTREAM_TRANSPORT_MODE=auto` could still cause browsers to start in SSE mode when the template layer decided WebSocket availability by checking a local PID file and `posix_kill($pid, 0)`. That approach fails when the realtime server runs in another container, another PID namespace, or behind service isolation even though the public WebSocket endpoint is healthy.

With this update, browser `auto` mode prefers WebSocket whenever a public WebSocket URL is configured. If the socket cannot be reached, the SharedWorker still falls back to SSE automatically.

The browser SharedWorker startup sequence was also corrected so it waits for page configuration before opening any transport. This removes an incorrect first-connect SSE attempt that could happen before the page's realtime settings were delivered to the worker.

Cursor replay handling was tightened at the same time. Refreshes and worker restarts now use the persisted stream cursor without replaying already-seen application events back into the page after reload.

### Message List Refresh on Page Restore

When a browser tab or PWA window is sent to the background, BinkStream events are paused or throttled by the browser's background-tab throttling policy. As a result, `dashboard_stats` events that would normally trigger a message list reload were not delivered while the page was hidden. When the user returned to the page, the echomail and netmail lists showed stale content until they manually refreshed.

The echomail and netmail pages now listen for the browser's `visibilitychange` event. When the page transitions from hidden to visible and was hidden for more than 30 seconds, the message list and stats are reloaded automatically. Brief tab switches under 30 seconds do not trigger a reload.

No configuration changes are required.

## Caddy Configuration

### Static File Serving Fix

The previous recommended Caddy configuration passed all requests through `php_fastcgi`, including requests for static files and subdirectories that contain their own `index.html` entry point (WebDoors, the C64 emulator, and any future static content). When a directory with an `index.html` was requested by URL without specifying the filename, PHP received the request, found no matching route, and returned a 404.

The fix is to handle PHP files explicitly first, then serve static files. Because Caddy's `handle` blocks are mutually exclusive and first-match wins, PHP files are passed to `php_fastcgi` and never reach the static file handler:

```
@php path *.php
handle @php {
    php_fastcgi unix//run/php/php8.2-fpm.sock {
        capture_stderr
    }
}

@static file {
    try_files {path} {path}/index.html
}
handle @static {
    file_server {
        index index.html
    }
}
```

Remove the bare `file_server` line that was previously listed after `php_fastcgi` — it is no longer needed. The updated full Caddy example is in `README.md`.

This change is required if you use Caddy. nginx users are unaffected; the existing `try_files` directive in the nginx example already handles this correctly.

## File Previewer

### SID Music Previewer

The file area previewer now supports Commodore 64 SID music files (`.sid`). Clicking a SID file opens an in-browser player powered by the [wothke/websid](https://bitbucket.org/wothke/websid) WebAssembly SID emulator.

The player reads the standard PSID/RSID file header to display the embedded song title, author, and release year. Files that contain multiple subtunes expose a track selector so listeners can navigate between them. Playback stops automatically when the preview modal is closed or a different file is opened. SID files stored inside ZIP archives are also playable directly from the archive browser without extracting them first.

The websid emulator files are included under `public_html/vendor/websid/` and require no additional installation steps. No database migration is required.

### SID Player Visualizer and Controls

The SID music player now includes a real-time spectrum visualizer rendered on an HTML5 canvas directly below the song metadata. The visualizer reads frequency data from the Web Audio AnalyserNode that the websid player framework maintains internally and draws 48 bars across the full width of the player. Each bar uses a cyan-to-green gradient. When playback is paused or stopped, the bars decay smoothly to zero rather than cutting out instantly.

Playback is manual: the player loads the file and waits for the user to press Play. This avoids unexpected audio starting when browsing a file listing.

No installation steps or configuration changes are required. No database migration is required.

### Archive Listing Size Limit

Listing the contents of non-ZIP archives (RAR, 7-Zip, TAR, LZH, ARJ, CAB, and similar formats) requires running the 7z command-line tool, which must read and decompress part of the archive to produce the file list. On large archives this can be slow enough to cause browser timeouts or visibly stall the page.

A size cap is now enforced before 7z is invoked. Archives that exceed the limit are not listed; the file area previewer displays a message explaining the limit and offers a direct download link instead. ZIP archives are exempt because their listing reads only the central directory index, which is fast regardless of archive size.

The default limit is 20 MB. To change it, set `ARCHIVE_LIST_MAX_SIZE` in `.env` to the desired number of bytes. Set it to `0` to disable the limit entirely.

```
# .env — raise limit to 50 MB
ARCHIVE_LIST_MAX_SIZE=52428800
```

No database migration is required.

### Torrent File Preview and Magnet Links

The file area previewer now recognises `.torrent` files and displays their contents as a structured metadata card rather than offering no preview.

The card shows:

- **Name** — the torrent's root name (`info.name`)
- **Size** — total content size across all files
- **Comment** — the embedded comment field, if present
- **Created by** — the software that generated the torrent file
- **Created** — the creation timestamp, if embedded
- **Piece size** — the BitTorrent piece length and total piece count
- **Tracker(s)** — all announce URLs from the primary tracker and the multi-tracker list
- **Private** — a warning badge if DHT and PEX are disabled for this torrent
- **Magnet** — the computed magnet link with a copy-to-clipboard button

Multi-file torrents additionally list every file path and its individual size in a scrollable table below the metadata.

The magnet link is computed entirely client-side: the raw `info` dictionary bytes are extracted from the `.torrent` file already fetched for the preview, SHA-1 hashed using the browser's built-in `crypto.subtle` API, and assembled into a standard `magnet:?xt=urn:btih:...` URI with all tracker announce URLs appended. No additional server request is made.

A **magnet icon button** also appears in the preview modal toolbar to the left of the green download button whenever a `.torrent` file is open. Clicking it copies the full magnet link to the clipboard. The button is hidden for all other file types.

The same magnet row (with copy button and info hash display) appears in the torrent metadata preview card shown in the upload dialog.

No configuration changes or database migrations are required.

## File Upload

### Torrent Metadata Pre-fill

When a `.torrent` file is selected in the upload dialog, two fields are filled in automatically from the torrent's embedded metadata:

- **Short description** — set to `info.name` (the torrent's root name). If the name is absent, the `comment` field is used as a fallback, provided it does not look like a URL. Many torrent clients embed their own website in the comment field; that case is detected and skipped automatically.
- **Long description** — for multi-file torrents, one relative file path per line. Single-file torrents leave the long description unchanged.

A torrent metadata preview card also appears in the dialog immediately below the file picker, showing the same information as the file area previewer — including a computed magnet link with a copy button. This allows the sysop to confirm the torrent contents before submitting the upload. The card disappears when the dialog is closed or a different file is selected.

No configuration changes or database migrations are required.

## Markdown Images

### Human-Readable Image URLs

Images uploaded through the echomail message editor are now served at URLs that include the uploader's username and a descriptive slug derived from the original filename:

```
/echomail-images/{username}/{sanitized-name}-{12char-hash}
```

For example, an image originally named `retro-screenshot.png` uploaded by the user `sysop` is now accessible at a URL such as:

```
/echomail-images/sysop/retro-screenshot-07e6d7ea3e66
```

Previously all uploaded markdown images were served at `/echomail-images/{sha256-hash}`, which produced URLs that were opaque and difficult to read in plain-text message rendering.

Existing embedded image URLs (the old hash-based form) continue to resolve correctly, so messages that contain already-embedded images do not need to be edited. The two URL forms coexist permanently.

A database migration (`v1.11.0.68`) adds a `url_slug` column to the `files` table and backfills a slug for every existing markdown image. A second migration (`v1.11.0.69`) corrects any slugs that were written with a file extension by an earlier run of the backfill. Both migrations run automatically when `php scripts/setup.php` is executed.

No configuration changes are required.

## Outgoing Message Charset

### Default Changed to CP437

Outgoing FTN message packets previously used UTF-8 as the default character encoding for all new messages. The default has been changed to CP437 (IBM PC / DOS).

FidoNet and many other legacy FTN networks were built around CP437 as the standard encoding. A significant number of nodes on these networks run software that was written before UTF-8 became common, and those systems display UTF-8 encoded text as garbled characters. Defaulting to CP437 ensures that messages are readable across the widest range of nodes, including older DOS-era BBS software, hardware terminals, and modern clients that present an authentic retro experience.

The encoding used for a specific message is always written into the outgoing packet as a `CHRS` kludge line so that receiving software that does support UTF-8 can still decode the message correctly.

The default charset is configurable in Admin → BBS Settings under the **Default Outgoing Charset** selector. Supported values are CP437, CP850, CP852, CP866, CP1252, ISO-8859-1, ISO-8859-2, and UTF-8.

Local echo areas (areas that are not distributed to any uplink) always store and display messages as UTF-8 regardless of this setting, since no FTN packet encoding is involved.

When composing a reply, the charset of the original message is inherited when possible, so responses to a UTF-8 message will remain UTF-8 even if the BBS default is CP437.

No database migration is required. Existing stored messages are unaffected; only new outgoing packets use the updated default.

### Per-Uplink Charset Override

Individual uplinks can override the BBS-wide default with their own charset setting. This is useful when one uplink connects to a network that requires or recommends a specific encoding — for example, a network that mandates UTF-8 — while other uplinks continue to use the global default.

The per-uplink charset is configured in Admin → BinkP Configuration by editing an uplink and selecting a value from the **Default Charset Override** dropdown. Leaving the field set to **Use BBS default** means the uplink inherits the BBS-wide setting.

When composing echomail, the charset selector in the compose form is automatically pre-set to the correct encoding for the selected echo area's network, updating dynamically as the user switches between areas.

No configuration changes are required for existing setups. If you want to keep the previous UTF-8 default, set **Default Outgoing Charset** in BBS Settings to UTF-8.

## Message Composer

### Draft State Now Fully Preserved

Saving a message draft (echomail or netmail) now captures the full compose state at the time the draft is saved. When the draft is reopened, all of the following fields are restored:

- **Message encoding (charset)** — the character set selected in the compose form (e.g. CP437, UTF-8).
- **Markup format** — whether the message was being written in plain text, Markdown, or Style Codes. When the selected markup format requires a rich editor (Markdown or Style Codes), that editor is also re-initialised on load.
- **Hard-wrap setting** — the column width at which the composer automatically breaks long lines, or the disabled state if wrapping was turned off.
- **Tagline** — the tagline selected from the tagline dropdown at the time the draft was saved.
- **Cross-posted echo areas** — the set of additional echo areas that were checked for cross-posting when the draft was saved.

Previously, only the primary echo area, message body, subject line, and recipient fields were saved. The encoding, markup type, hard-wrap setting, tagline, and cross-post selections were lost when the draft was stored and were not restored when it was opened again.

A database migration (`v1.11.0.70`) adds a `meta` JSONB column to the `drafts` table. This column stores the additional composer state as a JSON object alongside the existing draft fields. No existing draft data is altered; drafts saved before this migration simply have no `meta` value and continue to open normally.

Run `php scripts/setup.php` to apply the migration.

## Dashboard

### Shoutbox Profile Links

Usernames displayed in the shoutbox on the web dashboard are now rendered as hyperlinks. Clicking a username navigates to that user's profile page at `/profile/<username>`. Previously usernames were displayed as plain bold text with no link.

No configuration changes or database migrations are required.

### Today's Callers in Its Own Card

The Today's Callers table (shown to admins on the dashboard) was previously embedded inside the System Information card as part of its definition list. It is now displayed in a separate card below the System Information card, giving the caller list more visual breathing room and making it easier to scan at a glance.

Usernames in the list are now rendered as clickable links that navigate to each user's profile page at `/profile/<username>`.

No configuration changes or database migrations are required.

### Today's Callers Persists After Logout

The Today's Callers list was previously built from active session records. When a user logged out, their session row was deleted and they were immediately removed from the list, even though they had genuinely called the system that day.

The list now queries the login activity log (`user_activity_log`) instead. A login event is written at the moment of login and is never deleted, so a user who logs out remains visible in Today's Callers for the rest of the day. The count shown in the "Active Today" stat on the dashboard is derived from the same source.

The "Last Call" column (previously "Last seen") shows the most recent session activity time for users who are currently online, or the login time for users who have since logged out.

No configuration changes or database migrations are required.

## BBS Directory

### Individual BBS Information Pages

Each entry in the BBS directory now has its own public detail page at `/bbs-directory/{id}`. Clicking a BBS name in the directory listing opens this page rather than launching a telnet connection directly.

The detail page displays all available information for that BBS:

- **BBS name**, **sysop**, and **location**
- **Notes** — free-text description of what the system offers, written by the sysop or admin
- **Telnet address** — as a clickable `telnet://` link
- **SSH port** — shown when configured
- **Website** — shown when configured
- **Software** and **OS** — the BBS software package and operating system, when known
- **Last seen** — the date the system was last observed active
- **Map** — a small Leaflet map pinpointing the BBS location when coordinates are on file

Pages for pending or rejected entries return 404 to prevent unapproved submissions from being publicly indexed.

The Notes field label in the admin BBS directory edit form has been updated to "Notes about what this system offers (public)" to make clear that the field contents appear on the public-facing detail page.

No database migration is required.

### SEO Improvements

The BBS directory page (`/bbs-directory`) now carries dedicated per-page metadata instead of falling back to the global site appearance settings:

- A `<meta name="description">` tag describing the directory.
- Open Graph tags (`og:title`, `og:description`, `og:url`, `og:type`) so social media shares display a meaningful preview.
- A Twitter Card tag (`twitter:card`).
- A `CollectionPage` JSON-LD structured data block that tells search engines the page is a curated collection and includes the total number of listed systems.

The page title now reads "BBS Directory — Telnet Bulletin Board Systems" to include search-relevant terms alongside the site name.

Individual BBS detail pages carry their own per-page metadata as well:

- `<meta name="description">` — derived from the BBS's notes field, falling back to the BBS name and location.
- Open Graph and Twitter Card tags.
- An `Organization` JSON-LD block with the BBS name, website URL, description, and location.

No configuration changes or database migrations are required.

## Bug Fixes

### AreaFix History Not Reloading on Uplink Change

When a different uplink was selected in the AreaFix Manager dropdown, the message history panel was cleared but a new history fetch was never triggered. The panel remained blank until the Refresh button was clicked manually. The uplink selector now automatically reloads the history for the currently active tab (AreaFix or FileFix) whenever the selection changes.

No configuration changes are required.

### Login with Real Name

Users can now log in using their real name in addition to their username. Both fields are unique and matched case-insensitively, so there is no ambiguity between accounts. No configuration changes are required.

### Netmail Unsave in Message Modal

When a netmail message was saved and then opened in the message modal, the save button inside the modal always displayed "Save" rather than "Saved". Clicking it would save the message a second time instead of unsaving it. The bookmark icon in the modal header had the same problem.

The root cause was that the single-message API endpoint for netmail (`GET /api/messages/netmail/{id}`) queried the `netmail` table without joining `saved_messages`, so the `is_saved` field was never included in the response. The modal's save button reads `is_saved` to decide whether to issue a DELETE (unsave) or POST (save), so with no `is_saved` value it always defaulted to POST.

The query now joins `saved_messages` for the current user and includes `is_saved` in the result, matching the behaviour already present in the message list and in the equivalent echomail single-message query.

No configuration changes or database migrations are required.

### File Area Delete Stays in the Current Area

The web file browser now preserves the currently selected file area and subfolder after in-page actions that rebuild the file area list, including file deletion.

This means that after deleting a file from an area such as `CDM_95_1`, the page remains anchored to that same area instead of falling back to the main `/files` view.

No configuration changes or database migrations are required.

## Terminal Server Settings

### Tabbed Settings Screen Parity and Workflow

The terminal server now includes a dedicated settings screen for telnet and SSH users. This allows users to manage their own preferences, update profile fields, and change their password directly from the text interface instead of needing to switch back to the web UI.

Changes in this area include:

- **User settings management**: users can edit terminal, display, messaging, and account settings directly from the terminal server interface.
- **Profile management**: users can edit email address, location, and About Me text from the Profile tab while password change remains on the Account tab.
- **Password management**: users can change their account password directly from the Account tab in the terminal settings screen.
- **Lost-password reset from terminal login**: the pre-login terminal menu now includes a reset option that starts the same password-reset request flow as the web forgot-password form.
- **Identifier parity for reset requests**: password reset requests now accept username, real name, or email address, matching the login flexibility more closely.
- **Tabbed navigation**: `]` advances to the next tab, `[` moves to the previous tab, `Tab` advances, and `Shift+Tab` goes backward.
- **Messages-per-page parity**: the terminal settings screen now recognises the same messages_per_page values as the web settings page, including 250 and 500.
- **Reliable select-state restoration**: terminal select controls now preserve numeric-looking option keys correctly, so saved values are shown accurately when the settings screen is reopened.
- **In-place save workflow**: pressing `S` saves the current settings and returns to the same settings screen instead of exiting back to the main menu.
- **Centered save feedback**: save and discard notices now appear in a centered modal-style dialog instead of replacing the screen with a plain status message.
- **Registered-feature presentation**: registered-only settings such as netmail forwarding and echomail digests now follow the same locked-state presentation as the web interface more closely.
- **Signature editor wording**: editing the signature field still opens the full-screen editor, but that editor now uses signature-specific text such as "Editing signature" and "Save changes" instead of message-sending copy.

No database migration is required for these terminal settings changes.

## Upgrade Instructions

### From Git

```bash
git pull origin main
composer install
php scripts/setup.php
```

If you run the optional MCP server, you must also update its npm packages so the new dependency version is installed:

```bash
cd mcp-server
npm install
```

This step updates the MCP server's Node dependency tree from `package-lock.json`, including the `path-to-regexp` security fix shipped with 1.9.0.

Then restart the MCP server process if it is running under a service manager, supervisor, or manual shell session.

If you use BinkStream with `BINKSTREAM_TRANSPORT_MODE=auto`, restart the PHP web service and the realtime WebSocket service after deploying the new code so browsers receive the corrected transport preference logic and worker script versions.

Restart the admin daemon after upgrading so the new log-ingest behavior is available when web-server PHP processes cannot write directly to the destination log file.

### Using the Installer

Re-run the BinktermPHP installer to update the application files. When prompted to run `php scripts/setup.php`, allow it to complete.

If you use the optional MCP server, run `npm install` inside `mcp-server/` after the upgrade so the updated npm packages are installed, then restart that service.

If you use BinkStream with browser realtime enabled, restart the web service and realtime WebSocket service after the upgrade so clients receive the updated transport selection and cursor-handling fixes.

Restart the admin daemon after upgrading so the new log-ingest behavior is available when web-server PHP processes cannot write directly to the destination log file.
