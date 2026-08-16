# Upgrading to 1.10.2

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [AI Bots](#ai-bots)
- [File REQuests](#file-requests)
- [Echomail](#echomail)
- [Media](#media)
- [Activity Tracking](#activity-tracking)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

- **AI Bots**: Fixed a bug that prevented the AI bot daemon from starting on PHP 8.1 and later.
- **Files**: Added a "File Requests" page where users can request a file from another FidoNet node and have it delivered automatically to their private file area, open to all users, sysops only, or nobody depending on configuration. The same feature is now also available from the Telnet/SSH terminal server, under **[Files] → File Requests**. **Disabled by default** — read [Outbound file requests (FREQ), now optionally available to all users](#outbound-file-requests-freq-now-optionally-available-to-all-users) below before enabling it.
- **Files**: The **Allow FREQ** toggle in the file area editor is no longer hidden behind the experimental netmail FREQ flag and is available by default.
- **Files**: When a remote node declines an outbound file request, the request is now marked failed immediately instead of being retried against a remote that has already declined it.
- **Auto Feed**: Reduced RSS/Atom feed-polling log noise — per-item body-source messages now log at `debug` instead of `info`.
- **Echomail**: Fixed a suspected echomail loop that could occur when this system is configured as a point using an uplink.
- **Media**: The inline media renderer now recognizes TikTok short/share links (`vm.tiktok.com/...`, `vt.tiktok.com/...`, `tiktok.com/t/...`), not just the full `tiktok.com/@user/video/id` form.
- **Activity Tracking**: JS-DOS browser door launches, C64 door launches, Telnet/SSH ZMODEM file transfers, FTP file downloads, and Packet BBS netmail/echomail sends are now recorded in the admin activity log; these were previously missed.

---

## AI Bots

### AI bot daemon fails to start with "PostgreSQL event listener: pg_connect failed"

On PHP 8.1 and later, the AI bot daemon's PostgreSQL LISTEN/NOTIFY connection could fail to establish even though the underlying database connection itself was working correctly. The daemon would log `PostgreSQL event listener: pg_connect failed` and exit immediately on startup.

The cause was a compatibility issue with how the daemon checked whether its native PostgreSQL connection succeeded. PHP 8.1 changed the `pgsql` extension to return connection objects instead of the older resource type, but the check used by the daemon still only recognized the older resource type, so a successful connection was incorrectly treated as a failure. This has been corrected so the daemon recognizes both connection types.

If you have previously seen the AI bot daemon fail to start with this error, restart it after upgrading and it should connect normally.

---

## File REQuests

### Outbound file requests (FREQ), now optionally available to all users

Users can now request a file from another FidoNet node directly from the web interface, under **Files → File Requests**. Enter the remote node's address and the filename or magic name to request (e.g. `ALLFILES`), choose whether to send the request as a classic `.req` file (the default, and the most broadly compatible option) or as a live-session `M_GET` request, and submit. Once the remote fulfils the request, the file is delivered to the requesting user's private file area and the File Requests page links directly to it.

A request that isn't fulfilled on the first attempt is retried automatically in the background. A request the remote has explicitly declined (see below) or that never succeeds after `FREQ_MAX_ATTEMPTS` is marked failed rather than retrying indefinitely. Users can delete their own request entries at any time; this only removes the tracking entry, not a file that was already received.

**This feature is disabled by default.** Read the note below before deciding whether to turn it on, then enable it with the following optional `.env` settings:

| Variable | Default | Description |
|---|---|---|
| `FREQ_ENABLE_INTERFACE` | `false` | `true` opens the File Requests page and its API to any logged-in user; `sysop` restricts it to admin accounts only; `false` disables it entirely |
| `FREQ_MAX_CONCURRENT_PER_USER` | `2` | Maximum number of requests a single user may have in progress at once |
| `FREQ_MAX_ATTEMPTS` | `5` | Number of retry attempts before an unfulfilled request is marked failed |
| `FREQ_POLL_INTERVAL` | `300` | Seconds between automatic retry attempts for a pending request |

This feature relies on the existing `binkp_scheduler` daemon to retry pending requests, so make sure it is running (see [Upgrade Instructions](#upgrade-instructions) below — restarting daemons after upgrading picks this up automatically).

**Why it defaults off:** requesting a file from a remote node opens an outbound binkp session to whatever address a user supplies, on your Sysop credentials if that address happens to be a configured uplink. Nothing about this feature is unsafe by itself, but it's new surface area, and it's worth reading how declined requests are handled (next section) before deciding whether to open it up to everyone, restrict it to admins via `FREQ_ENABLE_INTERFACE=sysop`, or leave it off.

### File Requests in the terminal server (Telnet/SSH)

The Telnet and SSH BBS interfaces now have their own File Requests screen, under **[Files] → File Requests** (default menu key `R`). It offers the same request/list/delete actions as the web page and is controlled by the same `FREQ_ENABLE_INTERFACE` setting above. Once a request is fulfilled, its file can be downloaded right from the same screen (key `D`) over ZMODEM.

**If your BBS already has a custom main menu key map saved** (i.e. you have ever changed a terminal menu key away from its default in **Admin → BBS Settings → Appearance → Terminal Server → Main Menu Keys**), the new File Requests action will not appear in the terminal menu until you explicitly assign it a key on that same page, or click "Reset to Defaults". A custom key map only shows actions it explicitly lists, so newly added actions are not retroactively included. Sites still running the built-in default key map see the new menu item immediately with no admin action needed.

### File area "Allow FREQ" toggle no longer tied to the experimental netmail flag

The **Allow FREQ** and **FREQ Password** fields in **Admin → Area Management → File Areas** were previously hidden, and force-disabled on save, unless `ENABLE_FREQ_EXPERIMENTAL=true` was set in `.env`. That flag was only ever meant to gate the older, admin-only "Request ALLFILES" netmail button on nodelist pages, not inbound FREQ serving on a file area — the two are unrelated mechanisms. The file area toggle is now always shown and available.

This toggle controls whether all approved files in that area can be served to any FidoNet node that FREQs them via `.req`/`M_GET`, independent of both the experimental netmail flag above and the outbound File Requests feature described earlier in this section. An optional FREQ password can be set to require remote nodes to supply it in their `M_GET` command; leave it blank for open access. See **[Enabling FREQ on a File Area](FileAreas.md#enabling-freq-on-a-file-area)** for details.

If you had previously set `ENABLE_FREQ_EXPERIMENTAL=true` solely to expose this file-area toggle, you may now remove that setting unless you still want the netmail ALLFILES button on nodelist pages.

### Declined file requests now fail immediately instead of retrying

Some remote FREQ handlers decline a request (file not found, access denied, etc.) by sending back a netmail explaining why, instead of the requested file. Previously, `freq_getfile.php` had no way to tell this apart from "the remote just hasn't gotten to it yet," so the request kept being retried in the background every `FREQ_POLL_INTERVAL` until it exhausted `FREQ_MAX_ATTEMPTS`.

A session that receives only FidoNet infrastructure files (a `.pkt`, etc.) and nothing matching the requested filename is now recognized as a decline, and the request is marked `failed` right away instead of continuing to retry against a remote that has already said no.

**This does not change where the bounce netmail itself goes.** It is deliberately left completely alone — not inspected, redirected, or copied — and is delivered by the normal packet-processing path exactly as before, typically to Sysop. A `.pkt` arriving in the same session as a FREQ response could just as easily be unrelated mail the remote had queued for you regardless of the FREQ (particularly if that node is also one of your uplinks), and there's no reliable way to distinguish the two from the packet alone. Guessing wrong would mean exposing Sysop's mail to whichever user happened to have a request pending to that node, so this update intentionally does not attempt it. A user whose request fails only sees the status change to `failed` — not why.

---

## Echomail

### Fixed a suspected relay loop for point systems

When this system is set up as a point (using an uplink/boss rather than acting as a hub), incoming echomail could in some cases be relayed straight back to the uplink that had just sent it, instead of only being distributed locally. The relay logic previously decided whether a message needed to go back to the uplink using the message's original author address and its SEEN-BY/PATH tracking lines, but some upstream systems don't include SEEN-BY/PATH entries on point-bound links, and the author address alone isn't a reliable way to detect "this just came from our own uplink." The relay logic now also checks the immediate sender of the inbound packet itself, so mail received from the uplink is recognized and is no longer bounced back to it.

If your upstream has previously reported echomail loops or duplicate/rejected traffic involving your system, this should no longer occur after upgrading.

---

## Media

### TikTok short and share links now embed inline

The inline media renderer's TikTok matcher previously only recognized the full `tiktok.com/@user/video/{id}` URL form. Short links (`vm.tiktok.com/{id}`, `vt.tiktok.com/{id}`) and the `tiktok.com/t/{id}` share form pasted into a message are now recognized and embedded the same way, using TikTok's oEmbed endpoint to resolve the link.

---

## Activity Tracking

### Several user activities were missing from the admin activity log

The admin **Activity Stats** page (**Admin → Activity Stats**) is built from events recorded by `ActivityTracker` at the point where each user activity happens. Four activity paths bypassed the code that records these events because they don't go through the same request handling as their web-interface equivalents, so they were silently missing from the stats:

- Launching a JS-DOS browser door (`/games/jsdos/...`) did not record a webdoor-play event.
- Launching a C64 door did not record a webdoor-play event — the C64 engine renders the emulator and embeds the program data directly in the page response and makes no separate API call, so it never reached the code path that records the event.
- Downloading or uploading a file over the Telnet/SSH terminal server via ZMODEM did not record a file-download or file-upload event.
- Downloading a file over FTP did not record a file-download event (FTP uploads were already recorded correctly).
- Sending netmail or posting echomail through the Packet BBS gateway (mesh radio interface) did not record a netmail-sent or echomail-sent event.

All four now record events consistently with their web-interface equivalents. This only affects the accuracy of the admin activity statistics; it does not change any user-facing behavior.

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
