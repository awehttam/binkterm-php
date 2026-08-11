# Upgrading to 1.10.0

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
  - [Downlinks: Act as a Hub for Subordinate Nodes and Points](#downlinks-act-as-a-hub-for-subordinate-nodes-and-points)
  - [Self-Serve Point Management](#self-serve-point-management)
  - [Echomail Unread/Read Filter (Threaded View)](#echomail-unreadread-filter-threaded-view)
  - [Auto Feed (RSS/Bluesky) Watermark Fix](#auto-feed-rssbluesky-watermark-fix)
  - [Duplicate Auto-Created Echo Areas from Domain Case Mismatch](#duplicate-auto-created-echo-areas-from-domain-case-mismatch)
  - [Echo Area Deletion: Move or Delete Remaining Messages](#echo-area-deletion-move-or-delete-remaining-messages)
  - [Echo Area Creation ID Fix and FTN Address Parsing Hardening](#echo-area-creation-id-fix-and-ftn-address-parsing-hardening)
  - [BinkP Session Close Could Be Reported as a Failed Session by Remote Mailers](#binkp-session-close-could-be-reported-as-a-failed-session-by-remote-mailers)
  - [Uplink Status Card Now Shows Network Name and Is Sortable](#uplink-status-card-now-shows-network-name-and-is-sortable)
  - [Fresh Installs Now Get the Full Set of Built-In Themes](#fresh-installs-now-get-the-full-set-of-built-in-themes)
  - [Admin: Sixel Screen Slot Preview](#admin-sixel-screen-slot-preview)
  - [Uplink Kept Packets Page Was Slow to Load with Months of History](#uplink-kept-packets-page-was-slow-to-load-with-months-of-history)
  - [Manage Downlinks: Pending Queue Counts and Quick Link to Queue Viewer](#manage-downlinks-pending-queue-counts-and-quick-link-to-queue-viewer)
  - [Docker Image Was Missing Required PHP Extensions](#docker-image-was-missing-required-php-extensions)
  - [Docker: BinkStream Realtime Server Was Not Started or Reachable](#docker-binkstream-realtime-server-was-not-started-or-reachable)
  - [Docker: PHP Fatal Errors Were Silently Lost](#docker-php-fatal-errors-were-silently-lost)
  - [Docker: Upload and POST Size Limits Were PHP Defaults](#docker-upload-and-post-size-limits-were-php-defaults)
- [Downlinks: Act as a Hub for Subordinate Nodes and Points](#downlinks-act-as-a-hub-for-subordinate-nodes-and-points-1)
- [Self-Serve Point Management](#self-serve-point-management-1)
- [Echomail Unread/Read Filter (Threaded View)](#echomail-unreadread-filter-threaded-view-1)
- [Auto Feed (RSS/Bluesky) Watermark Fix](#auto-feed-rssbluesky-watermark-fix-1)
- [Duplicate Auto-Created Echo Areas from Domain Case Mismatch](#duplicate-auto-created-echo-areas-from-domain-case-mismatch-1)
- [Echo Area Deletion: Move or Delete Remaining Messages](#echo-area-deletion-move-or-delete-remaining-messages-1)
- [Echo Area Creation ID Fix and FTN Address Parsing Hardening](#echo-area-creation-id-fix-and-ftn-address-parsing-hardening-1)
- [BinkP Session Close Could Be Reported as a Failed Session by Remote Mailers](#binkp-session-close-could-be-reported-as-a-failed-session-by-remote-mailers-1)
- [Uplink Status Card Now Shows Network Name and Is Sortable](#uplink-status-card-now-shows-network-name-and-is-sortable-1)
- [Fresh Installs Now Get the Full Set of Built-In Themes](#fresh-installs-now-get-the-full-set-of-built-in-themes-1)
- [Admin: Sixel Screen Slot Preview](#admin-sixel-screen-slot-preview-1)
- [Uplink Kept Packets Page Was Slow to Load with Months of History](#uplink-kept-packets-page-was-slow-to-load-with-months-of-history-1)
- [Manage Downlinks: Pending Queue Counts and Quick Link to Queue Viewer](#manage-downlinks-pending-queue-counts-and-quick-link-to-queue-viewer-1)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Downlinks: Act as a Hub for Subordinate Nodes and Points

- BinktermPHP can now act as an FTN hub for subordinate systems: independently-addressed nodes/peers, and FidoNet-style points hanging off one of its own AKAs. Manage them from the new **Admin → BBS Settings → BinkP Downlinks** page: register a downlink, choose which echo areas it receives, and it's delivered to (and can deliver mail back) automatically. See `docs/Downlinks.md` for full details.

### Self-Serve Point Management

- Users granted a new **Point Management Access** flag on their account can now register and manage their own point address from a new **Point Management** page in their account menu, without waiting on the sysop for each request. See `docs/Downlinks.md#self-serve-point-management`.

### Echomail Unread/Read Filter (Threaded View)

- The **Unread** and **Read** tabs on an echo area's message list now show a flat list of just the matching messages instead of trying to preserve conversation threading. Threading unread/read results could mix in already-read messages, hide genuinely unread ones inside a conversation, and slow page loads considerably in areas with deep reply chains.

### Auto Feed (RSS/Bluesky) Watermark Fix

- Auto feed sources (`scripts/rss_poster.php`) now track a second watermark, the publish date of the last posted article, alongside the existing GUID watermark. This prevents an entire feed from being reposted when a single article disappears from the source feed (for example, an editor unpublishing it) even though the feed itself was never actually reset.

### Duplicate Auto-Created Echo Areas from Domain Case Mismatch

- Incoming echomail packets could create a second, duplicate echo area for a tag that already existed, if the network's domain was saved with different letter casing than the domain stored on the existing area (for example `FsxNet` vs `fsxnet`). This has been fixed, and network domains saved through **Admin → Networks** are now always normalized to lowercase to prevent the mismatch from being reintroduced.

### Echo Area Deletion: Move or Delete Remaining Messages

- Deleting an echo area that still has messages in it no longer just fails with an error. The confirmation dialog on **Admin → Echo Areas** now asks whether to delete those messages along with the area, or move them into another echo area first.

### Echo Area Creation ID Fix and FTN Address Parsing Hardening

- Creating an echo area could occasionally return the wrong ID for the newly created area, because the lookup relied on Postgres's session-wide last-used-sequence value instead of reading the inserted row directly. This has been fixed.
- Outbound BinkP packet writing now parses FTN addresses more defensively, avoiding warnings on malformed addresses instead of failing outright.

### BinkP Session Close Could Be Reported as a Failed Session by Remote Mailers

- BinkP sessions that transferred every file successfully could still be logged as a failed session by the remote mailer (for example binkd logging `connection closed by foreign host` and marking the session `failed`), because our side assumed a session only ever has one end-of-batch exchange and closed the connection as soon as that first exchange completed, instead of continuing to answer end-of-batch signals until the remote itself decided the session was over. This has been fixed.
- A new `BINKP_LOG_LEVEL` `.env` setting lets you turn on DEBUG logging for `binkp_server.php`, `binkp_poll.php`, and `binkp_scheduler.php` without changing how those processes are launched — useful for supervised daemons where you can't easily add a `--log-level=DEBUG` flag.

### Uplink Status Card Now Shows Network Name and Is Sortable

- The Uplink Status card on the **Binkp Status** admin page (Overview tab) is now a table with **Network**, **Node Number**, and **Status** columns instead of a plain list, and each column header can be clicked to sort.

### Fresh Installs Now Get the Full Set of Built-In Themes

- Installs without a `config/themes.json` file previously only offered **Regular** and **Dark** in the theme picker. They now also offer **Amber**, **Cyberpunk**, and **Green Term**, matching `config/themes.json.example`.

### Uplink Kept Packets Page Was Slow to Load with Months of History

- The **Uplink Kept Packets** tab on the **Binkp Status** admin page re-parsed every archived packet on every load and returned the entire history in one response, which got slow once months of kept packets had accumulated. The page now loads and parses packets ten date groups at a time, with a **Load More** button to fetch older groups, and the per-packet parser itself is significantly faster. A date picker was also added next to the Inbound/Outbound toggle so you can jump straight to a specific day instead of clicking **Load More** repeatedly to page back through a long history.

### Manage Downlinks: Pending Queue Counts and Quick Link to Queue Viewer

- Each row on **Admin → BBS Settings → BinkP Downlinks** now shows that downlink's current pending/failed/held queue counts, and a new **View Queue** action jumps directly to that downlink's filtered view on the **Binkp Status → Downlink Queue** tab instead of requiring you to navigate there and pick it out of the list manually.

---

## Downlinks: Act as a Hub for Subordinate Nodes and Points

BinktermPHP can now act as an FTN **hub**, distributing echomail and routing netmail to subordinate systems below it — the reverse of the existing uplink relationship, where BinktermPHP receives mail from and sends mail up to a hub above it.

Subordinates are managed from a new admin page, **Admin → BBS Settings → BinkP Downlinks**, and come in two kinds:

- **Node** — an independently-addressed system (for example `2:345/67`), entered as a free-text FTN address. Covers both traditional downlinks and symmetric peer links.
- **Point** — a system addressed as one of this BBS's own AKAs plus a point number (for example `1:153/149.1`). The boss address is picked from the AKAs already configured under **Admin → Binkp Uplinks**, and a next-available point number is suggested automatically.

Each downlink has its own echo area subscription list, its own session/packet passwords, and independent enable/hold/quota controls. New echomail is distributed to every subscribed downlink and, separately, forwarded up to the area's configured uplink (unless it just came from that uplink). Netmail addressed to a registered downlink is delivered into its queue instead of being handled as ordinary local netmail, and netmail from a registered point addressed elsewhere is relayed onward rather than dropped. There is no open relay — only traffic to or from an explicitly registered downlink is handled this way.

Downlinks connect the same way uplinks do, over binkp: they can either poll BinktermPHP (pull) or be polled by it on a schedule (push, for node-type downlinks with a routable host — most points are pull-only). Both CRAM-MD5 and plaintext authentication are accepted for registered downlinks regardless of the global plaintext-fallback setting, since simple point software may not support CRAM-MD5.

A new **Binkp Status → Downlink Queue** tab shows every queued packet across all downlinks with an inspector to view a packet's contents before delivery, and a new `scripts/binkp_test_client.php --compose-echomail`/`--compose-netmail` mode lets you simulate a downlink for testing without a real point client.

Delivered and failed queue entries are kept for each downlink's configured retention period (default 30 days) and then purged automatically by `scripts/database_maintenance.php`; pending or held mail is never purged regardless of age.

See `docs/Downlinks.md` for the full reference, including field descriptions and current limitations (file-attach netmail forwards only the `.pkt` header).

## Self-Serve Point Management

Users can now be granted **Point Management Access** on their account (**Admin → Manage Users**) to register and manage their own point without going through the sysop for each request. Once granted, a **Point Management** entry appears in that user's account menu, from which they can:

- **Create a point** under any configured network that isn't itself a point address, up to a configurable per-network limit (`HUB_POINT_MAX_PER_USER_PER_NETWORK` in `.env`, default `1` — see `docs/CONFIGURATION.md`). The point number is allocated automatically, and a Session Password plus a single AreaFix Password (also used as the FileFix Password) are generated automatically.
- **Edit** their point's Session Password, AreaFix/FileFix Password, Internet Host, Port, Hold Mail, and Compress Outbound. Point Number, Boss Address, Enabled state, and the accept-echomail/accept-netmail flags remain sysop-only, changeable only from **Admin → BBS Settings → BinkP Downlinks**.
- **Manage their own echo and file area subscriptions**, using the same eligibility rules as the AreaFix/FileFix netmail robots.
- **Delete** their own point registration.

A sysop can hand an existing downlink off to a user for self-service by searching for a username in that downlink's edit form on **Admin → BBS Settings → BinkP Downlinks**; this also lets a sysop register additional points for a user beyond that user's self-service per-network limit. Self-service point creation notifies the sysop.

See `docs/Downlinks.md#self-serve-point-management` for the full reference.

## Echomail Unread/Read Filter (Threaded View)

When browsing an echo area in threaded (conversation) view, switching to the **Unread** or **Read** tab now shows a flat list of just the messages matching that filter, rather than the full conversation tree.

Previously, filtering by unread or read status in threaded view worked by matching whole conversations, which caused two problems: a thread whose first message had already been read could hide an unread reply buried further down, and conversely a conversation containing both read and unread messages would show every message in it — including ones you'd already read — under the Unread tab. Areas with long reply chains could also take a long time to load under this filter.

The Unread and Read tabs now filter and display individual messages directly, the same way the non-threaded message list already does. Threading is unaffected for the **All**, **To Me**, and **Saved** tabs.

## Auto Feed (RSS/Bluesky) Watermark Fix

Auto feed sources deduplicate articles by remembering the GUID of the last article they posted. On each poll, the source feed is scanned for that GUID; anything appearing before it in the feed is treated as new. If the GUID can no longer be found anywhere in the feed, the script previously assumed the whole feed had been reset or cleared, and reposted every article currently in the feed to every configured echo area.

This assumption broke when a single article dropped out of a source feed without the feed actually being reset — for example, when a site editor unpublished the most recent article after it was already picked up, causing it to vanish from that site's RSS output on the next poll. Because the stored GUID no longer matched anything in the feed, the script incorrectly treated this as a full feed reset and reposted every remaining article as if it were new.

Auto feed sources now also store the publish date of the last posted article in a new `last_article_pubdate` column. When the stored GUID can't be located in the current feed, the script falls back to this publish-date watermark and only treats articles newer than it as new, instead of reposting the entire feed. Feeds that have never posted anything yet, or that were tracked before this column existed, keep the original "treat everything as new" behavior for their very first check after upgrading.

## Duplicate Auto-Created Echo Areas from Domain Case Mismatch

Echo areas are looked up by echo tag together with network domain. When a packet arrived for an echo area whose tag already existed, but the network's configured domain used different letter casing than the domain already stored on that area, the lookup failed to find the existing area and created a new one instead — showing up as a second area with the same echo tag and a description starting with `Auto-created:`.

This most commonly happened when an echo area was created through a `.NA` file import (which always stores the domain in lowercase) for a network whose domain had been saved with mixed case in **Admin → Networks**. The lookup used by incoming packet processing now compares domains case-insensitively, matching the behavior already used elsewhere in the application (area import, network lookups). Network domains saved through **Admin → Networks** are also now normalized to lowercase automatically, so a mismatch can't be reintroduced by re-saving network settings.

This fix does not merge any duplicate echo areas that were already created by this bug before upgrading. If you have duplicate areas with the same tag, check **Admin → Echo Areas**, move any wanted messages from the unwanted duplicate, and deactivate or delete it manually.

## Echo Area Deletion: Move or Delete Remaining Messages

Previously, deleting an echo area from **Admin → Echo Areas** would simply fail with an error if the area still contained any messages, and the only way to remove such an area was to deactivate it instead.

The delete confirmation dialog now checks whether the selected area has messages. If it does, you're asked to choose one of two options before the area can be removed:

- **Delete them** — the messages are permanently deleted along with the area.
- **Move them to another area** — the messages are reassigned to a different local echo area, which is then deleted in their place. This is a local reassignment only; it does not re-gate, re-spool, or republish the moved messages to any uplink.

If you'd rather keep an area's message history intact, uncheck **Active** on the area instead of deleting it.

## Echo Area Creation ID Fix and FTN Address Parsing Hardening

`EchoareaManager::createIfMissing()` previously determined the ID of a newly created echo area with `PDO::lastInsertId()`. On Postgres, this reads the last value returned by *any* sequence used in the current database session, not necessarily the sequence for the row just inserted. In rare cases — for example when other inserts had run earlier in the same request — this could hand back the wrong echo area ID to the caller. The insert now uses `INSERT ... RETURNING id` and reads the ID directly from the inserted row, which is always correct.

Outbound BinkP packet generation (`BinkdProcessor::writeMessage()`) also parses FTN addresses (origin, destination, and system address for SEEN-BY/PATH lines) through a new `parseFtnAddressParts()` helper instead of raw `explode()`/`list()` calls. The old code could throw a PHP warning or fail outright when handed a malformed or incomplete address; the new parser fills in `0` for any missing zone/net/node/point component instead.

## BinkP Session Close Could Be Reported as a Failed Session by Remote Mailers

Some BinkP sessions with remote systems — most visibly ones running binkd — would show the entire session as failed in the remote's log even though every file was transferred and confirmed. A typical remote-side log would show all files sent, all `GOT` confirmations received, and then an unexpected `connection closed by foreign host` followed by the session being marked `failed`.

The cause was on our side, not the remote's. An end-of-batch signal only means "I have nothing queued right now" — the BinkP protocol doesn't promise there will be exactly one such exchange per session, since either side is free to queue more work and open a fresh batch after the first one closes out. Our BinkP session code assumed otherwise: it treated the *first* end-of-batch exchange as "session complete" and closed the connection immediately. binkd, correctly not making that same assumption, would often still be mid-batch — for almost any real transfer it silently starts a second, normally empty batch and sends a fresh end-of-batch signal for it, waiting for a reply before it closes on its own. Seeing our connection close before that reply arrived, binkd would log the session as failed even though every file had already transferred successfully.

Our BinkP session now always replies to an incoming end-of-batch signal, no matter how many times the remote sends one, and no longer closes the connection the instant one exchange completes. Termination is now driven primarily by the remote closing the connection on its own once *it* considers the session finished — which is now treated as the normal, successful end of a session rather than an error — with a short grace period as a fallback for mailers that instead expect us to close first. This does not change file transfer behavior in any way; it only affects how and when the session is torn down at the very end, and applies to both inbound and outbound BinkP sessions.

If you're troubleshooting a similar report, `binkp_server.php`, `binkp_poll.php`, and `binkp_scheduler.php` can now be switched to `DEBUG` logging via a `BINKP_LOG_LEVEL` `.env` setting, without changing how those processes are launched — useful for a process managed by systemd or cron where adding a `--log-level=DEBUG` flag isn't practical. See `docs/CONFIGURATION.md` for details.

## Uplink Status Card Now Shows Network Name and Is Sortable

The Uplink Status card on the **Binkp Status** admin page previously listed each uplink as a plain row showing only its node address and connectivity state, with no indication of which network it belonged to.

That card is now a table with three columns — **Network**, **Node Number**, and **Status** — so the two pieces of identifying information line up in their own columns instead of being crammed together. The network name is resolved from the uplink's configured domain via **Admin → Networks**; if a domain has no matching network record, the raw domain string is shown instead. Clicking any column header sorts the table by that column, toggling between ascending and descending order.

## Fresh Installs Now Get the Full Set of Built-In Themes

`Config::getThemes()` falls back to a hardcoded theme list whenever `config/themes.json` doesn't exist yet, which is the case on every fresh install until a sysop creates that file. That fallback previously only listed **Regular** and **Dark**, even though the codebase ships four other stylesheets (`amber.css`, `cyberpunk.css`, `greenterm.css`) that were only reachable by manually creating `config/themes.json`.

The fallback now matches `config/themes.json.example`, so a fresh install's theme picker shows **Amber**, **Cyberpunk**, **Dark**, **Green Term**, and **Regular** without any extra setup. If you already have a `config/themes.json` file, this change has no effect on you — your file continues to take precedence.

## Admin: Sixel Screen Slot Preview

The **Sixel Graphics** section on **Admin → Appearance** lets you upload a `.sixel`/`.six` file for each screen slot (Welcome, Main Menu, Goodbye) shown to sixel-capable terminal clients. Previously there was no way to see what a slot actually contained short of connecting with a sixel-capable client, so mistakes in an uploaded file weren't obvious until a user reported them.

The slot picker now shows a decoded, rendered preview of the currently selected slot's image directly on the page. The preview updates automatically when you switch slots, upload a new file, or remove one.

## Uplink Kept Packets Page Was Slow to Load with Months of History

The **Uplink Kept Packets** tab on the **Binkp Status** admin page lists archived inbound and outbound packets kept in the `keep` subdirectories, grouped by date. It previously fetched and parsed every kept packet in a single request every time the tab was opened, including the message count and originating/destination address for each individual packet inside every archived `.pkt` file. With months of accumulated history this meant re-parsing thousands of packets on every page load, making the tab noticeably slow to open.

Three changes address this:

- The page is now paginated by date group. Only the ten most recent date groups are fetched and parsed initially; a **Load More** button at the bottom of the list fetches the next ten. Date groups outside the currently loaded window are never scanned or parsed, no matter how much history exists.
- The packet parser itself (`OutboundQueue::analyzePacket()`) previously skipped each message's variable-length body one byte at a time to find its null terminator. It now reads the body in 4&nbsp;KB chunks and searches each chunk for the terminator, which is substantially faster per packet regardless of pagination.
- A date picker next to the Inbound/Outbound toggle lets you jump straight to a specific day instead of clicking **Load More** repeatedly. Picking a date resolves to the newest kept-packet date group on or before that day (falling back to the oldest available group if you pick a date older than any kept packets) and loads that page directly, so getting to old history no longer means paging back through everything in between.

## Manage Downlinks: Pending Queue Counts and Quick Link to Queue Viewer

Each downlink's row on **Admin → BBS Settings → BinkP Downlinks** now shows its current outbound queue counts — pending, failed, and held — instead of requiring a trip to the Downlink Queue tab just to see whether anything is backed up.

A new **View Queue** action in that row's action buttons opens **Binkp Status → Downlink Queue** (`/binkp`) with that downlink already selected, jumping straight to its filtered detail view instead of landing on the summary and having to pick it out of the list manually.

### Docker Image Was Missing Required PHP Extensions

- The `Dockerfile` did not install the `mbstring`, `dom`, `xml`, or `gmp` PHP extensions, even though the bare-metal install guide (`docs/INSTALL.md`) requires them. This most visibly broke posting a new echomail message, which calls `mb_substr()` unconditionally when updating an echo area's message count. Without `mbstring` loaded, that call is a fatal PHP error rather than a catchable exception, so it surfaced as a generic "failed to send" error in the compose UI with no useful detail in the response. Docker builds now install all four extensions, matching the bare-metal requirements. Rebuild your image (`docker-compose build --no-cache`) to pick up the fix.

### Docker: BinkStream Realtime Server Was Not Started or Reachable

- `docker/supervisord.conf` never started `scripts/realtime_server.php`, the WebSocket server behind BinkStream (the real-time event bus used for live updates in the browser interface). A `[program:realtime_server]` entry has been added alongside the existing `admin_daemon` entry so it now starts automatically with the rest of the daemons.
- The Docker image's Apache config also has no WebSocket reverse proxy set up (unlike the bare-metal install guide, which configures `mod_proxy_wstunnel` in front of this same daemon), and Apache in this image only listens on plain HTTP, so there was no way to reach the daemon from outside the container even once it was running. Port `6010` (`BINKSTREAM_WS_PORT`) is now published in `docker-compose.yml` and `EXPOSE`d in the `Dockerfile`, and the daemon binds `0.0.0.0` instead of `127.0.0.1` (via a new `BINKSTREAM_WS_BIND_HOST` setting) so it's reachable on that port directly. If you terminate TLS with a reverse proxy in front of BinktermPHP, proxy `wss://` traffic for this port through to it the same way you would `https://` traffic to `HTTP_PORT`.
- Rebuild your image (`docker-compose build --no-cache`) and recreate the container to pick up the updated supervisord, `Dockerfile`, and `docker-compose.yml` configuration.

### Docker: PHP Fatal Errors Were Silently Lost

- The `php:8.2-apache` base image ships with no `php.ini` at all, only the fragments that enable individual extensions. That leaves PHP on its compiled-in defaults: `log_errors` off and no `error_log` target, with `display_errors` on. A PHP fatal error (for example, the missing-`mbstring` crash described above) was therefore only ever written inline into the raw HTTP response body — for a JSON/AJAX endpoint the frontend's JSON parse just silently failed and fell back to a generic error message, and the actual error text never reached any log file or `docker-compose logs` output, making this class of failure effectively undiagnosable from inside the container.
- A new `docker/php-error-logging.ini` is now installed into `/usr/local/etc/php/conf.d/` that turns `display_errors` off, turns `log_errors` on, and points `error_log` at `data/logs/php_errors.log` (alongside the application's existing log files). PHP fatal errors, parse errors, and warnings now show up there instead of vanishing:
  ```bash
  docker exec -it binkterm-app tail -f /var/www/html/data/logs/php_errors.log
  ```
- Rebuild your image (`docker-compose build --no-cache`) and recreate the container to pick up the new `php.ini` fragment.

### Docker: Upload and POST Size Limits Were PHP Defaults

- Because the `php:8.2-apache` base image ships with no `php.ini` (see above), `upload_max_filesize` and `post_max_size` were also left at PHP's compiled-in defaults of `2M` and `8M`. Any file upload larger than 2 MB — file area uploads, door/attachment transfers — failed silently or with a generic error, with no way to raise the limit without editing the image yourself.
- A new `docker/php-uploads.ini` is now installed into `/usr/local/etc/php/conf.d/` alongside `php-error-logging.ini`, raising `upload_max_filesize` to `128M` and `post_max_size` to `136M`.
- Rebuild your image (`docker-compose build --no-cache`) and recreate the container to pick up the new `php.ini` fragment. If you need a different limit, override it with your own conf.d file or by editing `docker/php-uploads.ini` before building.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
