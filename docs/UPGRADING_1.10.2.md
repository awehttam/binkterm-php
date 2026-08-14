# Upgrading to 1.10.2

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [AI Bots](#ai-bots)
- [Files](#files)
- [Echomail](#echomail)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

- **AI Bots**: Fixed a bug that prevented the AI bot daemon from starting on PHP 8.1 and later.
- **Files**: Added a "File Requests" page where any logged-in user can request a file from another FidoNet node and have it delivered automatically to their private file area. The same feature is now also available from the Telnet/SSH terminal server, under **[Files] → File Requests**.
- **Files**: The **Allow FREQ** toggle in the file area editor is no longer hidden behind the experimental netmail FREQ flag and is available by default.
- **Auto Feed**: Reduced RSS/Atom feed-polling log noise — per-item body-source messages now log at `debug` instead of `info`.
- **Echomail**: Fixed a suspected echomail loop that could occur when this system is configured as a point using an uplink.

---

## AI Bots

### AI bot daemon fails to start with "PostgreSQL event listener: pg_connect failed"

On PHP 8.1 and later, the AI bot daemon's PostgreSQL LISTEN/NOTIFY connection could fail to establish even though the underlying database connection itself was working correctly. The daemon would log `PostgreSQL event listener: pg_connect failed` and exit immediately on startup.

The cause was a compatibility issue with how the daemon checked whether its native PostgreSQL connection succeeded. PHP 8.1 changed the `pgsql` extension to return connection objects instead of the older resource type, but the check used by the daemon still only recognized the older resource type, so a successful connection was incorrectly treated as a failure. This has been corrected so the daemon recognizes both connection types.

If you have previously seen the AI bot daemon fail to start with this error, restart it after upgrading and it should connect normally.

---

## Files

### Outbound file requests (FREQ) now available to all users

Any logged-in user can now request a file from another FidoNet node directly from the web interface, under **Files → File Requests**. Enter the remote node's address and the filename or magic name to request (e.g. `ALLFILES`, `ALLFILES`), choose whether to send the request as a classic `.req` file (the default, and the most broadly compatible option) or as a live-session `M_GET` request, and submit. Once the remote fulfils the request, the file is delivered to the requesting user's private file area and the File Requests page links directly to it.

A request that isn't fulfilled on the first attempt is retried automatically in the background, and requests that never succeed are eventually marked failed rather than retrying indefinitely. Users can delete their own request entries at any time; this only removes the tracking entry, not a file that was already received.

This feature is on by default and is controlled by the following optional `.env` settings:

| Variable | Default | Description |
|---|---|---|
| `FREQ_ENABLE_REQUESTS_WEB` | `true` | Set to `false` to hide the File Requests page and disable its API entirely |
| `FREQ_MAX_CONCURRENT_PER_USER` | `2` | Maximum number of requests a single user may have in progress at once |
| `FREQ_MAX_ATTEMPTS` | `5` | Number of retry attempts before an unfulfilled request is marked failed |
| `FREQ_POLL_INTERVAL` | `300` | Seconds between automatic retry attempts for a pending request |

This feature relies on the existing `binkp_scheduler` daemon to retry pending requests, so make sure it is running (see [Upgrade Instructions](#upgrade-instructions) below — restarting daemons after upgrading picks this up automatically).

### File Requests in the terminal server (Telnet/SSH)

The Telnet and SSH BBS interfaces now have their own File Requests screen, under **[Files] → File Requests** (default menu key `R`). It offers the same request/list/delete actions as the web page and is controlled by the same `FREQ_ENABLE_REQUESTS_WEB` setting above. Once a request is fulfilled, its file can be downloaded right from the same screen (key `D`) over ZMODEM.

**If your BBS already has a custom main menu key map saved** (i.e. you have ever changed a terminal menu key away from its default in **Admin → BBS Settings → Appearance → Terminal Server → Main Menu Keys**), the new File Requests action will not appear in the terminal menu until you explicitly assign it a key on that same page, or click "Reset to Defaults". A custom key map only shows actions it explicitly lists, so newly added actions are not retroactively included. Sites still running the built-in default key map see the new menu item immediately with no admin action needed.

### File area "Allow FREQ" toggle no longer tied to the experimental netmail flag

The **Allow FREQ** and **FREQ Password** fields in **Admin → Area Management → File Areas** were previously hidden, and force-disabled on save, unless `ENABLE_FREQ_EXPERIMENTAL=true` was set in `.env`. That flag was only ever meant to gate the older, admin-only "Request ALLFILES" netmail button on nodelist pages, not inbound FREQ serving on a file area — the two are unrelated mechanisms. The file area toggle is now always shown and available.

This toggle controls whether all approved files in that area can be served to any FidoNet node that FREQs them via `.req`/`M_GET`, independent of both the experimental netmail flag above and the outbound File Requests feature described earlier in this section. An optional FREQ password can be set to require remote nodes to supply it in their `M_GET` command; leave it blank for open access. See **[Enabling FREQ on a File Area](FileAreas.md#enabling-freq-on-a-file-area)** for details.

If you had previously set `ENABLE_FREQ_EXPERIMENTAL=true` solely to expose this file-area toggle, you may now remove that setting unless you still want the netmail ALLFILES button on nodelist pages.

---

## Echomail

### Fixed a suspected relay loop for point systems

When this system is set up as a point (using an uplink/boss rather than acting as a hub), incoming echomail could in some cases be relayed straight back to the uplink that had just sent it, instead of only being distributed locally. The relay logic previously decided whether a message needed to go back to the uplink using the message's original author address and its SEEN-BY/PATH tracking lines, but some upstream systems don't include SEEN-BY/PATH entries on point-bound links, and the author address alone isn't a reliable way to detect "this just came from our own uplink." The relay logic now also checks the immediate sender of the inbound packet itself, so mail received from the uplink is recognized and is no longer bounced back to it.

If your upstream has previously reported echomail loops or duplicate/rejected traffic involving your system, this should no longer occur after upgrading.

---

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
