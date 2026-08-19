# Upgrading to 1.10.3

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [FREQ](#freq)
- [Activity Log](#activity-log)
- [Address Book](#address-book)
- [BinkP](#binkp)
- [Dashboard](#dashboard)
- [Doors](#doors)
- [Nodelist](#nodelist)
- [Realtime (BinkStream)](#realtime-binkstream)
- [Terminal Server](#terminal-server)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### FREQ

- `scripts/freq_getfile.php` and `scripts/freq_pickup.php` now connect anonymously by default, even when the target address matches one of your configured uplinks. A new `--authenticated` flag opts back into using that uplink's real session password/CRAM-MD5 for a single run, and a new `FREQ_AUTHENTICATE_UPLINKS` `.env` setting lets you make that the default everywhere, including the web/terminal File Requests UI.

### Activity Log

- Outbound FREQ, Viewing the public BBS Directory list and individual BBS detail page, entering local chat room, uploading/generating a PGP key or changing a primary key are now recorded events.
- **Admin → Activity Stats → Top Users** now has a "Returning Users" list showing which users were active on more than one day within the currently selected period, with a count at the top.

### Address Book

- The address book panel on the Netmail compose page now shows only your own saved contacts, instead of sometimes also listing other BBS users you never added and omitting some of your real entries.

### BinkP

- A subordinate node/point that self-registered more than one `hub_nodes` entry (for example one point address per network) now gets outbound mail, FREQ files, and hold-directory files for all of its own addresses delivered within a single session, instead of only whichever advertised AKA a session happened to authenticate against. Delivery to a secondary AKA is only allowed when that AKA's `hub_nodes` row shares the same account (`user_id`) as the authenticated address, so this never delivers a different subordinate's queued mail just because a connecting system listed that address alongside its own in the BinkP handshake.

### Dashboard

- The admin-only **Today's Callers** widget now lists callers in chronological order by their actual last-call time, and no longer shows a stale last-call time or incorrect online status from a session left over from a previous day.

### Doors

- DOS Doors and Native Doors now support a `hide_from_web` config option that hides a door from the web games list and blocks its web player page, while leaving it fully playable over telnet/SSH.
- Fixed the Native Door manifest AI autofill failing with "Missing assistant content in OpenRouter API response" when the `openrouter/auto` route picked a reasoning model that spent its whole token budget on hidden reasoning before producing an answer.
- A Native Door's `launch_command` can now use a `{homedir}` placeholder for a per-user, per-door private directory (for save games and per-user config), created automatically before launch.
- A Native Door's `launch_command`/`executable` no longer needs a `./` prefix — a bare executable name (e.g. `syncdoom` instead of `./syncdoom`) is now resolved against the door's own directory.
- Fixed `DOOR32.SYS` reporting comm type `2` (telnet/socket) for Native Doors; it's now `0` (local/stdio), matching how native doors actually run (over a PTY, not a socket).

### Nodelist

- The **Nodelist** browse page and node listings are more resilient to unexpected input in the `zone`/`net` filters, returning an empty result instead of an error page.

### Realtime (BinkStream)

- Fixed the BinkStream SharedWorker repeatedly disconnecting and reconnecting its live WebSocket connection every time a new browser tab was opened or a page was navigated to, even while the WebSocket connection was healthy and other tabs stayed open.

### Terminal Server

- Fixed box-drawing borders rendering as garbled text (mojibake) for CP437-charset users in the Shoutbox, file listings, and FREQ browser scrollable panels.
- Fixed the main BBS menu becoming completely unresponsive to keystrokes after exiting a door that enables SyncTERM/CTerm physical key-event reporting for movement controls (e.g. SyncDOOM). Also hardened door-session cleanup against several related terminal-state leaks: mouse tracking left on, an unflushed synchronized-output batch, and a gap in escape-sequence parsing for SGR-format mouse reports.
- Fixed a description-text wrapping bug in selectable list menus (door lists and anything else built on `chooseFromList()`) where a full-width item description could run 2 columns past the terminal's right edge and clip or wrap oddly.
- The SSH daemon now also supports the `curve25519-sha256` key exchange algorithm and the `aes256-ctr` cipher, in addition to the `diffie-hellman-group14-sha256`/`aes128-ctr` pair it already supported. Some SSH clients only implement one of these two algorithm sets, so a client whose supported algorithms didn't overlap with the server's previously-fixed offering could fail to connect entirely.
- The telnet/SSH pre-login menu has a new **(T) Login and run terminal setup** option, letting a user force the terminal detection wizard to re-run right after login even if terminal settings were already saved, without needing a sysop to clear their saved settings first.

## FREQ

FREQ requests made with `scripts/freq_getfile.php` or `scripts/freq_pickup.php` now default to an anonymous binkp session, matching standard FTN convention for file requests. Previously, if the address you were FREQing happened to match one of your configured uplinks, the script would automatically use that uplink's real session password and CRAM-MD5 credentials for the session — not what most sysops expect from a simple file request. This is separate from the FREQ area password (`--password`), which is still carried inside the `.req`/`M_GET` request itself and unaffected by this change.

If you specifically want a FREQ run to use your real uplink session credentials, pass the new `--authenticated` flag. To change the default for every FREQ (including the web/terminal File Requests UI, which shells out to `freq_getfile.php`), set `FREQ_AUTHENTICATE_UPLINKS=true` in `.env`; the new `--anonymous` flag forces an anonymous session for a single run even when that setting is enabled. See [docs/FREQ.md](FREQ.md#configuration-reference) for the full reference and the tradeoff to consider before enabling it.

## Activity Log

Several gaps in `user_activity_log` coverage are fixed in this release:

| Event |
| --- |
| Outbound FREQ |
| Viewing the public BBS Directory list |
| Viewing an individual BBS detail page |
| Entering local chat room |
| Uploading/generating a PGP key |
| Changing a primary PGP key |

None of these change any user-facing behavior; they only affect what shows up in a user's activity history and in **Admin → Activity Stats**.

### Returning Users (Admin → Activity Stats)

The **Top Users** tab now has a **Returning Users** card above the existing "Most Active Users" list. It shows a count and a list of users who were active on more than one distinct day within whatever period is currently selected on the page (7 days, 30 days, 90 days, or all time) — not a count of login events specifically. This app authenticates via a long-lived cookie, so a user can return many times without ever generating a fresh login event; the metric instead counts distinct calendar days with any tracked activity (echomail, chat, files, doors, etc.), which reflects real return visits regardless of how login/cookie auth behaves. This is purely a read of existing `user_activity_log` data — no new activity types or schema changes are involved.

## Address Book

The address book panel on the Netmail compose page listed entries using the same lookup that powers the "To:" field's autocomplete suggestions. That lookup is designed to suggest matching BBS users when you're still typing a partial name or address, so when the panel loaded with an empty search it matched every active BBS user and filled any unused slots (up to a fixed limit of 10) with accounts that were never added to your address book. Once real entries plus this filler exceeded that limit, some of your actual saved contacts could be left off the list entirely.

The panel now reads directly from your saved address book entries, with no unrelated accounts mixed in and no limit on how many of your own entries are shown.

## BinkP

### Multi-AKA Subordinate Outbound Delivery

A subordinate node/point registered in **Admin → Downlinks** (`hub_nodes`) can advertise more than one FTN address (AKA) during a session's `M_ADR` handshake — for example a point that self-registered a separate point address per echomail network it carries. Previously, `BinkpSession` resolved the whole session down to a single address (the first advertised AKA that matched a known uplink or `hub_nodes` entry) and used only that one address to look up queued `hub_node_outbound` rows, `freq_outbound` rows, and hold-directory files. Mail, FREQ responses, or hold files queued under any of that subordinate's *other* registered addresses sat undelivered until a session happened to authenticate against that specific address instead.

`BinkpSession` now delivers outbound for every AKA the remote advertised, as long as that AKA's `hub_nodes` row is registered under the same account (`hub_nodes.user_id`) as the address the session actually authenticated against. Since `M_ADR` is sent before authentication and is entirely remote-controlled, "the remote listed this address" is not by itself treated as proof of ownership — an AKA whose `hub_nodes` row has no owning account, or belongs to a different account, is never delivered to, even if it was named in the same handshake.

## Dashboard

The **Today's Callers** widget on the admin dashboard had two bugs that are fixed in this release:

- Callers were listed in the order they first logged in that day, rather than by their most recent activity — a caller who logged in early but then remained active later in the day could appear above someone who called in after them.
- The last-call time and "online now" indicator were pulled from any session belonging to the user, including a still-valid session left over from a previous day (for example a long-lived "remember me" cookie). This could show a stale last-call time, or mark a user as online based on a session that wasn't actually active today.

Both are now scoped correctly to today's activity, and the list is sorted by actual last-call time.

## Doors

DOS Doors and Native Doors can now be restricted to the terminal server only. Each door's runtime config (`config/dosdoors.json` or `config/nativedoors.json`) supports a new `hide_from_web` boolean, defaulting to `false`. When set to `true` on a door:

- The door is omitted from the web games list at `/games`.
- Its web player page (`/games/dosdoors/{doorid}` or `/games/nativedoors/{doorid}`) returns a 404.
- It remains fully playable over telnet/SSH via **[Files] → Door Games**, unaffected by this setting.

This is useful for doors that only make sense in a real terminal session, or that a sysop wants to keep off the web games page for any other reason. Set it through **Admin → DOS Doors** or **Admin → Native Doors** — each door's entry in the config editor now has a globe toggle button, and a "Telnet/SSH only" badge appears on doors with it enabled.

### Native Door AI Autofill Fix

The AI-assisted "autofill" button when adding a Native Door could fail with a 500 error and `Missing assistant content in OpenRouter API response` logged to `server.log`. This happened when the OpenRouter provider is configured with the `openrouter/auto` model: OpenRouter would sometimes route the request to a hybrid reasoning model that spent its entire token budget on hidden reasoning tokens, leaving no room for the actual answer.

The OpenRouter provider now asks routed models to skip reasoning where supported, falls back to any reasoning-field text if the model still returns one, and gives the autofill request a larger token budget. Failures that do still occur are now logged with the response's `finish_reason` and message body for easier diagnosis.

### Native Door Launch Improvements

Three related fixes and improvements for Native Doors (`config/nativedoors.json`, `native-doors/doors/*/nativedoor.json`):

- **`{homedir}` placeholder**: `launch_command` can now include a `{homedir}` token, which resolves to a private, per-user, per-door directory (`native-doors/homes/<user_id>/<door_id>/`) — created automatically before the door launches if it doesn't already exist. It's also available as the `DOOR_HOME` environment variable. Use this for doors that keep their own save games or per-user config files, such as `-home` in SyncDOOM.
- **Bare executable names now work**: previously, a manifest's `executable`/`launch_command` had to reference the door binary as `./mydoor` — a bare `mydoor` only worked if it happened to be on the bridge process's `$PATH`. Bare names are now resolved against the door's own directory first, so `./` is no longer required. Existing manifests using `./mydoor` are unaffected.
- **`DOOR32.SYS` comm type corrected**: line 1 of the generated `DOOR32.SYS` drop file now correctly reports comm type `0` (local/stdio) instead of `2` (telnet/socket). Native doors are spawned over a PTY, not a telnet socket, so a door that branches its behavior on this field was previously being told the wrong transport.

## Nodelist

### Sturdier Zone/Net Filtering

The **Nodelist** browse page (and the underlying net-listing lookups it uses) now validates the `zone`/`net` filter values before querying, returning an empty list for a non-numeric value instead of a server error page. This makes the page more robust against odd or unexpected query strings, including ones from automated crawlers probing the URL with unusual values.

## Realtime (BinkStream)

### SharedWorker Reconnect Thrash

When realtime transport mode is set to `auto` (the default), the server decides per page render whether to hand the browser a WebSocket or an SSE connection based on whether the realtime daemon looks available. That availability check used `posix_kill()` to signal the daemon's PID, which returns false not only when the daemon is actually down but also whenever the web server process lacks permission to signal that PID — which is the normal case whenever the daemon runs as a different OS user than PHP. That false negative made the server report "SSE only" on effectively every page render even when the daemon and its WebSocket connection were perfectly healthy.

Because BinkStream's SharedWorker previously adopted whatever transport preference the *most recently connected* tab reported, each new tab or page navigation would push that stale "prefer SSE" hint into the worker and it would tear down an already-live, working WebSocket connection to restart on SSE, then upgrade back to WebSocket a few seconds later — repeating on every subsequent tab or navigation. This is fixed on two levels:

- The daemon-availability check now just confirms the PID file exists with a positive PID in it, rather than relying on `posix_kill()`'s permission-sensitive result.
- The SharedWorker now only adopts an incoming tab's preferred transport when it doesn't already have a live connection, so a newly-opened tab can no longer disrupt a transport that's already up and working for tabs already attached to the worker.

If your realtime daemon runs under a different system user than your web server, this also means realtime status reporting will now correctly reflect the daemon actually being up.

## Terminal Server

### CP437 Box-Drawing Mojibake

Users with their terminal charset set to CP437 could see garbled characters (mojibake) instead of proper box-drawing borders in the Shoutbox, file area listings, and the FREQ browser — specifically the top/bottom border and the vertical side bars of each scrollable panel, while the horizontal divider lines rendered correctly. The panel renderer was sending those particular glyphs as raw UTF-8 bytes regardless of the client's charset setting instead of converting them to CP437 first. All panel borders are now converted consistently.

### Main Menu Unresponsive After Exiting a Door

Players of doors with real-time movement controls (SyncDOOM being the example that surfaced this) could find the main BBS menu completely unresponsive to keystrokes after quitting the door, on a SyncTERM-family client. The root cause: SyncTERM implements a non-standard CTerm terminal extension that lets a door request *physical* key press/release event reports (`CSI=1h`) instead of normal translated characters, and separately suppress translated input entirely (`CSI=2h`) — useful for a door that needs real key-up events for movement. SyncDOOM enables both for its controls, but nothing turned them back off when the door exited, so every subsequent keystroke kept arriving in that alternate format (or not at all) instead of as normal characters. The terminal server now explicitly disables both modes (`CSI=1l` / `CSI=2l`) as the first step of returning from any door session.

While tracking this down, three related terminal-state gaps that could compound the same class of problem were also fixed:

- **Mouse tracking left on**: a door that enables xterm-style mouse reporting for gameplay (e.g. SyncDOOM's mouse-look) could leave it active after exit, so subsequent clicks/movement arrived as mouse escape sequences instead of keystrokes. Mouse tracking and bracketed paste mode are now explicitly disabled when returning from a door.
- **Unflushed synchronized-output batch**: a frame-based door can wrap each rendered frame in a synchronized-output begin/end pair (`DECSET 2026`) so the terminal paints it atomically; exiting between the begin and its matching end can leave a supporting terminal holding every subsequent write in an unflushed buffer. The end sequence is now sent unconditionally on door exit (harmless no-op on terminals that don't support the mode).
- **SGR mouse reports mis-parsed**: the terminal server's key reader didn't recognize the `ESC[<...` prefix used by SGR-format mouse reports (mode 1006, the modern default), so a stray one landing after a door exited could desync the byte stream for the reads that followed. It's now recognized and discarded like other terminal-generated reports.

### Description Text Clipping in Selectable Lists

Any menu built on the terminal server's selectable-list widget (`chooseFromList()`) — door lists most visibly, since door descriptions tend to run long — could show an item's description text running 2 columns past the right edge of the terminal, clipping or wrapping oddly depending on the client. The description text was word-wrapped assuming a narrower on-screen indent than the renderer actually uses for continuation lines, so a full-width line ended up 2 columns too wide. Descriptions now wrap to the correct width and stay within the terminal.

### SSH Key Exchange and Cipher Algorithm Additions

The built-in SSH daemon previously offered exactly one algorithm per negotiation category: `diffie-hellman-group14-sha256` for key exchange and `aes128-ctr` for encryption. An SSH client is only required to implement a subset of possible algorithms, and a client whose supported set didn't include either of those two had no algorithm in common with the server at all, causing the connection to fail during the handshake before authentication was ever reached.

The daemon now also offers `curve25519-sha256` (with the legacy `curve25519-sha256@libssh.org` name as an alias) for key exchange, and `aes256-ctr` for encryption, alongside the existing `diffie-hellman-group14-sha256`/`aes128-ctr` pair. Each connection negotiates independently per the client's own algorithm preference, so existing clients that only know the older pair continue to work unchanged, while clients that only implement the newer pair can now connect as well. `hmac-sha2-256` remains the only MAC and `rsa-sha2-256` the only host key algorithm, since compatibility testing found no client-side gap in either of those categories. The new Curve25519 key exchange uses PHP's `sodium` extension, which ships enabled by default since PHP 7.2 — no configuration change or additional package install is needed.

See [docs/SSHServer.md](SSHServer.md#supported-algorithms) for the full current algorithm table.

### Re-Running Terminal Setup From the Login Menu

Previously, the terminal detection wizard only ran automatically the first time a user logged in with no saved terminal settings; running it again required a sysop to clear those settings. The telnet/SSH pre-login menu now has a **(T) Login and run terminal setup** option alongside **(L) Login**, which logs the user in as normal and then forces the detection wizard to run regardless of whether settings were already saved — handy for a user whose terminal, client, or connection type has changed since they last set it up.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
