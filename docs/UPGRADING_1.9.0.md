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
  - [Archive Listing Size Limit](#archive-listing-size-limit)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

**Security Fixes**
- The optional MCP server now updates its `path-to-regexp` dependency from `8.3.0` to `8.4.0`.
- This release addresses `CVE-2026-4926` (`GHSA-j3q9-mxjg-w52f`) and `CVE-2026-4923` (`GHSA-27v5-c462-wpq7`) in the MCP server dependency tree.

**Logging**
- When a web-server PHP process cannot write directly to an application log file, it now sends the log entry to the admin daemon so the daemon can append it to the correct log file instead.
- Door session setup events (session start, node allocation, DOSBox launch) are now written to `data/logs/dosdoor.log`. Activity during actual door execution continues to be logged by the multiplexing bridge server to `data/logs/multiplexing-server.log`.

**BinkP**
- Outbound routing now checks for an exact configured uplink address match before falling back to network-based uplink selection. This allows multiple uplinks on the same FTN network, so messages explicitly addressed to a specific uplink node can be routed through that uplink instead of being matched only by shared network patterns.
- Outbound FTN packet message bodies now use bare CR (`\r`, 0x0D) as the line separator, as required by FTS-0001. Previously all line terminators were written as CRLF (`\r\n`), which caused interoperability problems with remote nodes that enforce strict FTS-0001 parsing — most visibly a spurious LF after the `* Origin:` line.

**Robots**
- The echomail robot processor type previously displayed as "Auto-Reply" is now displayed as "TEST Area Auto Responder" to better describe its purpose. No configuration changes are required; the internal processor type identifier (`auto_reply`) is unchanged.

**Caddy Configuration**
- The recommended Caddy configuration has been updated. If you use Caddy, a one-time manual edit to your Caddyfile is required to ensure static files and subdirectories with `index.html` (such as WebDoors and the C64 emulator) are served correctly instead of being routed through PHP.

**File Previewer**
- The file area previewer now supports Commodore 64 SID music files (`.sid`). Clicking a SID file opens an in-browser player powered by the bundled wothke/websid WebAssembly emulator. The player displays the embedded title, author, and release year from the SID header and supports multi-subtune files via a track selector. SID files inside ZIP archives are also playable from the archive browser.
- Non-ZIP archive listing (RAR, 7-Zip, TAR, LZH, etc.) now enforces a configurable maximum file size before invoking the 7z tool. Archives that exceed the limit display a message and a download link instead of timing out. The limit defaults to 20 MB and is controlled by `ARCHIVE_LIST_MAX_SIZE` in `.env`. ZIP archives are not affected because their file listing reads only the central directory index and does not require 7z.

**Documentation**
- `scripts/import_bbslist.php` is now documented in `docs/CLI.md`, including how imports merge with locally-edited BBS Directory entries.

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

The fix is to add a `@static` matcher block before `php_fastcgi` so that Caddy serves real files and HTML index directories directly, without involving PHP:

```
@static file {
    try_files {path} {path}/index.html
}
handle @static {
    file_server {
        index index.html
    }
}

php_fastcgi unix//run/php/php8.2-fpm.sock {
    capture_stderr
}
```

Remove the bare `file_server` line that was previously listed after `php_fastcgi` — it is no longer needed. The updated full Caddy example is in `README.md`.

This change is required if you use Caddy. nginx users are unaffected; the existing `try_files` directive in the nginx example already handles this correctly.

## File Previewer

### SID Music Previewer

The file area previewer now supports Commodore 64 SID music files (`.sid`). Clicking a SID file opens an in-browser player powered by the [wothke/websid](https://bitbucket.org/wothke/websid) WebAssembly SID emulator.

The player reads the standard PSID/RSID file header to display the embedded song title, author, and release year. Files that contain multiple subtunes expose a track selector so listeners can navigate between them. Playback stops automatically when the preview modal is closed or a different file is opened. SID files stored inside ZIP archives are also playable directly from the archive browser without extracting them first.

The websid emulator files are included under `public_html/vendor/websid/` and require no additional installation steps. No database migration is required.

### Archive Listing Size Limit

Listing the contents of non-ZIP archives (RAR, 7-Zip, TAR, LZH, ARJ, CAB, and similar formats) requires running the 7z command-line tool, which must read and decompress part of the archive to produce the file list. On large archives this can be slow enough to cause browser timeouts or visibly stall the page.

A size cap is now enforced before 7z is invoked. Archives that exceed the limit are not listed; the file area previewer displays a message explaining the limit and offers a direct download link instead. ZIP archives are exempt because their listing reads only the central directory index, which is fast regardless of archive size.

The default limit is 20 MB. To change it, set `ARCHIVE_LIST_MAX_SIZE` in `.env` to the desired number of bytes. Set it to `0` to disable the limit entirely.

```
# .env — raise limit to 50 MB
ARCHIVE_LIST_MAX_SIZE=52428800
```

No database migration is required.

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
