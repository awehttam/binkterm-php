# Upgrading to 1.9.0

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Security Fixes](#security-fixes)
  - [MCP Server Dependency Update](#mcp-server-dependency-update)
  - [CVE Coverage](#cve-coverage)
- [BinkP](#binkp)
  - [Exact Uplink Address Matching](#exact-uplink-address-matching)
- [Real-time Events (BinkStream)](#real-time-events-binkstream)
  - [Targeted Dashboard Stats Notifications](#targeted-dashboard-stats-notifications)
  - [Browser Transport Preference and Cursor Replay Fixes](#browser-transport-preference-and-cursor-replay-fixes)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

**Security Fixes**
- The optional MCP server now updates its `path-to-regexp` dependency from `8.3.0` to `8.4.0`.
- This release addresses `CVE-2026-4926` (`GHSA-j3q9-mxjg-w52f`) and `CVE-2026-4923` (`GHSA-27v5-c462-wpq7`) in the MCP server dependency tree.

**Logging**
- When a web-server PHP process cannot write directly to an application log file, it now sends the log entry to the admin daemon so the daemon can append it to the correct log file instead.

**BinkP**
- Outbound routing now checks for an exact configured uplink address match before falling back to network-based uplink selection. This allows multiple uplinks on the same FTN network, so messages explicitly addressed to a specific uplink node can be routed through that uplink instead of being matched only by shared network patterns.

**Real-time Events (BinkStream)**
- Dashboard stats notifications are now targeted per user. Echomail events are sent only to users subscribed to the affected echo area who are currently online; netmail events go only to the recipient if online; file events go to all online users. Previously all events were broadcast to every connected user regardless of subscriptions.
- Browser-side BinkStream transport selection in `auto` mode now prefers the configured public WebSocket endpoint instead of relying on local PID-file visibility from the web process. This prevents deployments that run the realtime server outside the PHP process namespace from incorrectly starting in SSE mode.
- The SharedWorker now waits for page configuration before opening a transport, and replay behavior on refresh has been tightened so already-seen events are not re-delivered after browser reloads.

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
