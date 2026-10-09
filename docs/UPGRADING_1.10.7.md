# Upgrading to 1.10.7

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Messaging](#messaging)
  - [Date Display Preferences](#date-display-preferences)
  - [Fixed: Inbound Echomail Landed in Areas With an Empty Domain](#fixed-inbound-echomail-landed-in-areas-with-an-empty-domain)
  - [Message Search Scoped by Network and Interest](#message-search-scoped-by-network-and-interest)
- [AreaFix / FileFix](#areafix-filefix)
  - [Structural Reply Parsing Across More Hub Mailers](#structural-reply-parsing-across-more-hub-mailers)
  - [Mandatory Preview Before Syncing Areas](#mandatory-preview-before-syncing-areas)
  - [Data-Driven Grammar Definitions](#data-driven-grammar-definitions)
  - [Per-Uplink Format Memory](#per-uplink-format-memory)
- [Administration](#administration)
  - [Fixed: user-manager.php create Command](#fixed-user-managerphp-create-command)
- [AreaFix / FileFix](#areafix--filefix)
  - [Automatic Area Sync on Reply Now Opt-In](#automatic-area-sync-on-reply-now-opt-in)
  - [Fixed: AreaFix Sync Set an Override Address on Echo Areas](#fixed-areafix-sync-set-an-override-address-on-echo-areas)
- [Web Doors](#web-doors)
  - [Longer Browser Caching for Door Assets](#longer-browser-caching-for-door-assets)
  - [RLogin Door Asset Sizes Stored in the Database](#rlogin-door-asset-sizes-stored-in-the-database)
  - [MRC Chat Loads Its Libraries From the Bundled Copies](#mrc-chat-loads-its-libraries-from-the-bundled-copies)
- [MeshCore](#meshcore)
  - [Radio Settings Link on the Dashboard](#radio-settings-link-on-the-dashboard)
- [Networks](#networks)
  - [SysopNet Added to the Networks List](#sysopnet-added-to-the-networks-list)
  - [Networks Listed Alphabetically](#networks-listed-alphabetically)
- [BBS Directory](#bbs-directory)
  - [Geocoding Provider Failures No Longer Cached](#geocoding-provider-failures-no-longer-cached)
  - [Geocoding Backfill Skips Known No-Match Locations](#geocoding-backfill-skips-known-no-match-locations)
- [Security](#security)
  - [Secure Flag on Session Cookies](#secure-flag-on-session-cookies)
  - [Default Terminal Registration Secret No Longer Trusted](#default-terminal-registration-secret-no-longer-trusted)
  - [Telnet and SSH Sessions Disconnected When Their Web Session Is Revoked](#telnet-and-ssh-sessions-disconnected-when-their-web-session-is-revoked)
  - [Docker: Config JSON Files No Longer World-Readable](#docker-config-json-files-no-longer-world-readable)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)
- [Thanks](#thanks)

## Summary of Changes

### Messaging

- **Date display preferences:** users and sysops can now choose between relative timestamps ("4d ago") and exact date/time for message lists and headers, and choose whether echomail is ordered and displayed by received date or written date.
- **Message search scoped by network and interest:** searching for messages from the Echo Areas page now respects the network and interest filters selected there, and searching while browsing a single interest on the Echomail page now stays within that interest's echo areas, instead of always searching every echo area.
- **Fixed: inbound echomail landed in areas with an empty domain:** the network domain for incoming echomail is now taken from the uplink that delivered the packet, instead of only from the message author's address. Authors outside the uplink's routing patterns no longer produce messages with an empty domain.

### AreaFix / FileFix

- **Structural reply parsing across more hub mailers:** AreaFix and FileFix replies are now parsed by recognizing the concrete layout each hub mailer actually sends — Mystic BBS/MBSE command blocks, delimited and columnar tables, BBBS/Li6-style quoted address lists, and HPT-style flag-prefixed quoted lists — instead of scanning for keywords. Real echo areas with common names such as `LINUX`, `WINDOWS`, or `BASE` are no longer mistaken for header text or help output.
- **Mandatory preview before syncing areas:** clicking "Sync Areas to Local BBS" (from the latest reply, or from any individual incoming message in the Message History table) now shows a preview of exactly which areas will be created, reactivated, deactivated, or left unchanged. Nothing is written to the database until this preview is explicitly confirmed.
- **Data-driven grammar definitions:** a new **Admin -> Area Management -> AreaFix Grammars** page lets a sysop teach AreaFix a new hub reply format without a code change, either by hand or by pasting a sample reply and asking the built-in AI assistant to suggest one. Suggestions are always added disabled for review before saving.
- **Per-uplink format memory:** BinktermPHP now remembers which reply format last matched each hub's confirmed sync, tries that format first on the hub's next reply, and flags it on the preview screen if the format changes unexpectedly. The remembered format for each uplink can be viewed, forced, or cleared from **Admin -> BBS Settings -> BinkP Uplinks -> Edit Uplink**.

### Administration

- **Fixed `scripts/user-manager.php create`:** the operator CLI's `create` command failed on PostgreSQL with `column "is_active" is of type boolean but expression is of type integer`, because it inserted the literal `1` instead of a boolean. This is now fixed.

### AreaFix / FileFix

- **Automatic area sync on reply is now opt-in:** receiving an AreaFix/FileFix reply from a hub that looks like an area list no longer automatically creates or activates local echo areas / file areas by default. Set `AREAFIX_AUTOIMPORT_ENABLED=true` in `.env` to restore the previous automatic behavior.
- **Fixed: AreaFix sync set an override address on echo areas:** syncing areas from a hub's AreaFix reply filled in the echo area's **Uplink Address** ("Override Uplink FidoNet address") field on every area it created or touched. The sync no longer sets it, and deactivating areas missing from the hub's list is now scoped by network domain and tag instead of by that address.

### Web Doors

- **Longer browser caching for door assets:** icons and screenshots served from `/door-assets/` now use `Cache-Control: public, max-age=604800, stale-while-revalidate=86400` (up from a 24-hour max-age), plus ETag/Last-Modified conditional requests, so repeat visits reload door pages faster and generate less server load.
- **RLogin door asset sizes stored in the database:** icon and screenshot byte sizes for RLogin doors are now stored alongside the image data instead of being recomputed on every request, reducing memory overhead when serving those assets.
- **MRC chat loads its libraries from the bundled copies:** the MRC web door now loads Bootstrap, jQuery and its icons from the copies bundled with BinktermPHP instead of public CDNs, so it works under a strict Content Security Policy and without access to those CDNs.

### MeshCore

- **Radio settings link on the dashboard:** the PacketBBS Nodes card on the main dashboard now includes a "My MeshCore radios" link that opens the MeshCore tab of your user settings, where you manage your radios.

### Networks

- **SysopNet added to the networks list:** SysopNet (zone 23, hub 23:1/1), an FTN for sysops run by sysops, is now registered automatically on upgrade, so it appears in **Admin -> Networks** without being created by hand.
- **Networks listed alphabetically:** the network list in **Admin -> Networks** and the network dropdown when editing an uplink are now sorted purely by name.

### BBS Directory

- **Geocoding provider failures no longer cached:** a failed request to the geocoding provider, such as a timeout or an outage, was stored the same way as a genuine "no match" answer, so the location was never looked up again. Failures are now never cached and are retried on the next run.
- **Geocoding backfill skips known no-match locations:** locations the provider has already said it cannot find no longer take up places in a limited backfill batch, so newer entries further down the directory are no longer starved of coordinates.

### Security

- **Secure flag on session cookies:** the `binktermphp_session` cookie now sets the `Secure` flag whenever the site is served over HTTPS, so the cookie is no longer sent over a plain HTTP connection even if one is reachable.
- **Default terminal registration secret no longer trusted:** an unset `TERMINAL_REGISTRATION_SECRET`, or the published default `Chang3Me`, is no longer accepted as proof that a request came from the telnet/SSH daemons. Docker installs generate a site-specific secret automatically; other installs must set one in `.env`.
- **Failed-login throttle:** repeated failed logins are now limited per account and per source IP across the web login, telnet, SSH, FTP, NNTP and QWK HTTP downloads. By default an account allows 5 failures and an IP allows 20 within 15 minutes; once either limit is reached, further attempts fail exactly like a wrong password until the window passes. Set `AUTH_LOGIN_USER_MAX`, `AUTH_LOGIN_IP_MAX` and `AUTH_LOGIN_WINDOW` in `.env` to change the limits (see [CONFIGURATION.md](CONFIGURATION.md#failed-login-throttle)). The upgrade migration creates the `auth_login_attempts` table.
- **Telnet and SSH sessions disconnected when their web session is revoked:** a connected Telnet or SSH user, including one playing a door, is now signed out shortly after their web session is revoked, expires, or is deleted by a password reset. Previously the terminal session kept running with full access until the user disconnected.
- **Docker: config JSON files no longer world-readable:** the container now restricts the top-level `config/*.json` files, which hold uplink passwords and API keys, to the owner and group at startup. Previously they were readable by any user in the container.

## Messaging

### Date Display Preferences

Two new preferences control how dates are shown across echo area listings, echomail message headers, and netmail lists:

- **Date display style** — choose relative time (e.g. "4d ago", "2h ago") or exact date and time, formatted using the user's selected date format locale and timezone.
- **Echomail date field** — choose whether echomail lists and ordering use the received date (when the message arrived on this system) or the written date (when the original author composed it).

Both preferences default to "System Default," which follows a BBS-wide default that sysops can set in **Admin -> BBS Settings -> Features**. Users can override the system default for either preference individually in **Settings -> Preferences**.

Previously, only admin users could choose to order echomail by written date; this option is now available to all users. If your installation currently sets `ECHOMAIL_ORDER_DATE=written`, non-admin users will now see that ordering apply to them as well, following the same fallback chain (user preference, then BBS default, then this environment variable).

### Message Search Scoped by Network and Interest

The Echo Areas page lets you filter the area list down to one or more networks and interests using the **Network** and **Interests** dropdowns. The "Search Messages" box on that same page now carries those selections into the search, so results are limited to matching echo areas instead of every echo area on the system. Leaving both dropdowns on their "All" default still searches everything.

On the Echomail page, searching while browsing a single interest under the Interests tab is likewise scoped to that interest's echo areas. Searching from a specific echo area continues to scope to that single area, as before, taking priority over any network or interest scope.

### Fixed: Inbound Echomail Landed in Areas With an Empty Domain

When echomail arrived in a packet, the network domain was worked out only from the message author's address, by matching it against the routing patterns of your configured uplinks. Authors on systems outside those patterns do not match, which is the usual case for echomail, so their messages were stored with an empty domain and ended up in echo areas not tied to any network.

The domain is now taken from the uplink the packet came from, which is authoritative whatever the author's address is. The author's address is still used first for a message when it resolves to a network, and the packet's uplink domain is used when it does not.

Messages already stored with an empty domain are not changed by the upgrade.

## AreaFix / FileFix

### Structural Reply Parsing Across More Hub Mailers

AreaFix and FileFix replies from a hub are parsed by matching the actual layout the hub's mailer software produces, rather than by scanning line-by-line for known words and phrases. The parser recognizes:

- Mystic BBS and MBSE `Command:`/`Result:` blocks, including stacked multi-command replies and `%LIST`/`%QUERY`/`%LINKED`/`%UNLINKED` result listings.
- Colon- and pipe-delimited tables (Husky, Clearing Houz, FastEcho, FrontDoor, InterMail).
- Columnar and dotted-leader tables (HPT, Husky), including table headers that name the tag column something other than the literal word "Area" (for example "Message area").
- BBBS/Li6-style quoted address lists (`+TAG (address) "description"`), including descriptions that wrap onto a continuation line and a single reply that lists both echo areas and file areas.
- HPT-style flag-prefixed dotted-leader lists with quoted descriptions (`*S   TAG ....... "description"`).
- As a last resort, a conservative bare `TAG   Description` line matcher for hub replies that don't match any of the above, which never marks a matched area as subscribed on its own.

Because this approach recognizes real structure instead of matching words, an echo area named the same as an ordinary English word or a common piece of software (`LINUX`, `WINDOWS`, `BASE`, and similar) is preserved correctly instead of being mistaken for a header, a help topic, or unrelated prose.

A Mystic BBS/MBSE `%QUERY` reply that lists both linked and unlinked areas in a single block, with individual rows explicitly annotated `(linked)`, `(unlinked)`, or `(not linked)`, now honors each row's own annotation instead of marking every row in the block the same way.

### Mandatory Preview Before Syncing Areas

Previously, clicking "Sync Areas to Local BBS" on the AreaFix / FileFix Manager page applied the parsed area list to your local echo areas or file areas immediately, with no chance to review it first. It now opens a preview dialog instead, and nothing is written to your database until you explicitly confirm it there. This preview is available in two places: from the "Latest Reply" panel's sync button, and per-message from a sync button next to each incoming reply in the Message History table, so you can also review and apply an older reply without it needing to still be the most recent one.

The preview lists every area found in the reply as a row with a checkbox, its tag, its description, and a status badge:

- **New** — the area doesn't exist locally yet and will be created.
- **Reactivate** — the area exists but is currently inactive and will be turned on.
- **Deactivate** — the area is currently active and the reply says to unsubscribe from it.
- **Updated** — the area's activation state isn't changing, but its description will be filled in or updated to match the hub's reply.
- **Unchanged** — nothing about the area differs from what the reply says; selecting it has no effect.

For an "Updated" row, the description cell shows your current description struck through above the incoming one when it will actually be replaced. A description is only ever replaced when your current one is empty, an auto-generated placeholder, or you've explicitly selected that row for sync (see below) — a real, sysop-set description is never silently overwritten. If the hub's reply lists a different description for an area whose own real description would otherwise be left alone, the preview still shows what the hub sent underneath it, so the mismatch doesn't go unnoticed just because it's not required to be applied.

Every row starts checked except a genuine no-op "Unchanged" row — including every "New", "Reactivate", "Deactivate", and "Updated" row, so the normal case (review, then confirm) still applies everything in one click. Use the checkboxes, or the "Select All" / "Select None" buttons above the list, to apply only a subset instead. Confirming a checked "Updated" row is what actually lets a hub's description win over your own where it otherwise wouldn't — uncheck that specific row first if you'd rather keep your own description for that one area.

### Data-Driven Grammar Definitions

The structural parser recognizes several hub mailer formats out of the box, but a new or unusual format can still come back as an empty reply. A new admin page, **Admin -> Area Management -> AreaFix Grammars** (`/admin/areafix-grammars`), lets a sysop describe a new format as data instead of waiting for a code change:

- Each grammar definition is a JSON object specifying a header pattern (to detect the format), a per-row pattern (to extract the area tag, description, and status), and how status text maps to subscribed/unsubscribed/available. The full schema is documented on the page and in `docs/AreaFix.md`.
- A **Paste from AreaFix Message** button lets you paste the raw text of a hub reply and have the configured AI provider suggest a grammar definition for it. The suggestion is always added disabled, and every regex in it is validated, so nothing starts matching mail until you review and explicitly enable it.
- A **Populate from Example** button loads a starter definition from `config/areafix_grammars.json.example`, which ships disabled and has no effect until you edit and save it.
- A **Test Against Sample** button lets you paste a sample reply and see exactly what your grammars (saved or not, enabled or not) would extract from it before you save — which format matched and every tag, description, and action it found. Nothing is written to disk by this button.
- Grammars you define are tried after the built-in structural formats and before the last-resort freeform line matcher, in the order they appear on the page.

### Per-Uplink Format Memory

A given hub's AreaFix/FileFix robot always replies in the same format, so BinktermPHP now remembers which format matched the last confirmed sync for each uplink and robot (AreaFix and FileFix are tracked separately). On the next reply from that uplink, the remembered format is tried first, and if a reply no longer matches it, the sync preview shows a warning naming the old and new format — a concrete signal that the hub's mailer software may have changed or been reconfigured.

The remembered format for each uplink is visible and directly editable from **Admin -> BBS Settings -> BinkP Uplinks -> Edit Uplink**: a "Remembered Reply Format" panel shows the current format for AreaFix and FileFix, with buttons to force it to a specific format or clear it. Clearing is useful after you've confirmed a hub's format really did change; forcing is useful to pre-seed a known format for a brand-new uplink before its first reply arrives.

## Administration

### Fixed: user-manager.php create Command

`scripts/user-manager.php create` previously failed on every PostgreSQL install with:

```
SQLSTATE[42804]: column "is_active" is of type boolean but expression is of type integer
```

This was left over from the project's earlier SQLite-based schema, where `is_active` accepted an integer. The command now inserts a proper boolean and reads back the new user's id via `RETURNING id` instead of `lastInsertId()`. If you were creating operator accounts by editing the database directly to work around this, you can now use `scripts/user-manager.php create` normally again.

## AreaFix / FileFix

### Automatic Area Sync on Reply Now Opt-In

When your BBS receives a netmail reply from a hub's AreaFix or FileFix robot that looks like an area list (for example, the response to a `%LIST` or `%QUERY` command), BinktermPHP can automatically create matching `echoareas` or `file_areas` rows and activate them, using the descriptions the hub reports.

Starting with this release, that automatic sync is **disabled by default**. Incoming AreaFix/FileFix replies are still stored and viewable as normal netmail; they simply no longer create or activate local areas on their own. Sysops who want to review and apply a hub's area list continue to do so from **Admin -> AreaFix / FileFix**, using the **Sync to Echo Areas** button on a parsed reply.

If you relied on the previous automatic behavior — for example, to pick up new areas from your hub without visiting the admin page — set the following in `.env` to restore it:

```
AREAFIX_AUTOIMPORT_ENABLED=true
```

### Fixed: AreaFix Sync Set an Override Address on Echo Areas

Each echo area has an optional **Uplink Address** field in **Admin -> Echo Areas**, described as "Override Uplink FidoNet address". When it is empty, echomail for that area is sent to the uplink configured for the area's network. When it is set, echomail for that area is sent to that address instead, which is only wanted when a sysop deliberately routes one area differently.

Syncing areas from an AreaFix reply (the **Sync to Echo Areas** button, or automatic sync when `AREAFIX_AUTOIMPORT_ENABLED=true`) was filling this field in with the hub's address on every area it created, and on existing areas where it was empty. Newly created echo areas therefore appeared to have an override that nobody had set. The sync no longer writes this field.

When the *deactivate missing* option is used, it now deactivates active areas in the same network domain whose tag is not in the hub's list. Previously it only considered areas whose Uplink Address matched the hub, which would have skipped areas that have no override.

Echo areas that were already given an Uplink Address by an earlier sync keep it, because it cannot be distinguished from an address a sysop entered on purpose. If you see an override you did not intend, open the area in **Admin -> Echo Areas** and clear the **Uplink Address** field.

## Web Doors

### Longer Browser Caching for Door Assets

Door icons and screenshots served through `/door-assets/{doorid}/{asset}` — whether stored as files or as database blobs — now set:

```
Cache-Control: public, max-age=604800, stale-while-revalidate=86400
```

- **`max-age=604800`** (7 days, up from 1 day) tells the browser it can reuse a cached copy of the asset for up to a week without re-checking with the server.
- **`stale-while-revalidate=86400`** (1 day) lets the browser keep serving its cached copy for up to a day past that while it revalidates in the background, instead of blocking the page on a fresh request.

Requests also now include an `ETag` (and, for filesystem-backed assets, a `Last-Modified` header), so once the 7-day cache does expire, the browser can send a conditional request and get a lightweight `304 Not Modified` response instead of re-downloading the asset if it hasn't changed.

Door game listing pages also load door icons with `loading="lazy"`, so icons off-screen are not fetched until the user scrolls to them.

If you update a door's icon or screenshot file, its changed modification time (or content hash, for database-stored assets) invalidates the old cached copy automatically.

### RLogin Door Asset Sizes Stored in the Database

RLogin doors store their icon and screenshot images as binary data directly in the `rlogin_doors` table, since these doors have no directory on disk. Their byte sizes are now stored in new `icon_size` and `screenshot_size` columns on that table, populated whenever an icon or screenshot is uploaded through **Admin -> RLogin Doors**. Existing icons and screenshots are backfilled automatically by the upgrade migration, so their sizes are recorded immediately without needing to re-upload anything.

### MRC Chat Loads Its Libraries From the Bundled Copies

The MRC chat page loaded Bootstrap 5.1.3, Bootstrap Icons and jQuery 3.6.0 from jsDelivr and code.jquery.com. With a strict Content Security Policy, or on a server without access to those hosts, the page rendered without styling, scripts or icons. It now uses the Bootstrap 5.3.0, jQuery 3.7.1 and Font Awesome 6.4.0 copies that the rest of BinktermPHP already serves from `/vendor/`, so nothing is fetched from a third party.

## MeshCore

### Radio Settings Link on the Dashboard

The PacketBBS Nodes card on the main dashboard, shown when MeshCore is enabled, now has a "My MeshCore radios" link beside "View all nodes". It opens **Settings** directly on the **MeshCore** tab, where you can add, edit, and remove your own radios. The settings page also accepts `/settings#meshcore` as a direct link to that tab.

## Networks

### SysopNet Added to the Networks List

The upgrade migration registers SysopNet (domain `sysopnet`, https://sysopnet.com) in the networks table, using the real-name posting policy and CP437 as the default code page. You can review or change these in **Admin -> Networks**. To join SysopNet you still need to request a node at sysopnet.com/node-request/ and add the uplink details your hub gives you. If a network with the domain `sysopnet` already exists, the migration leaves it unchanged.

### Networks Listed Alphabetically

The network list in **Admin -> Networks**, and the network dropdown in the uplink editor under **Admin -> BBS Settings -> BinkP Uplinks**, previously showed all built-in networks first and then any other networks (such as locally created ones, or SysopNet) in a separate group below them. They are now sorted by name in a single list, regardless of whether a network is built in.

## BBS Directory

### Geocoding Provider Failures No Longer Cached

The BBS directory looks up map coordinates for each listed location and keeps the answers in the `geocode_cache` table. A failed request to the provider (a network error, timeout, error response or unreadable reply) used to be saved as an empty answer, exactly like the provider replying that it found no match. A short outage during an automated backfill could therefore leave locations permanently without coordinates.

Now only real answers are cached: a result with coordinates, or a successful reply with no match. A failed request is not cached, never replaces an existing cache entry, and is tried again on the next run.

The upgrade migration adds a `status` column to `geocode_cache`. Existing rows that have coordinates are marked as successes, and existing rows without coordinates are marked as no-match, because there is no way to tell whether an old empty row was a real no-match or an earlier failure. The migration does not look those locations up again.

### Geocoding Backfill Skips Known No-Match Locations

`scripts/geocode_bbs_directory.php` backfills coordinates for directory entries that have a location but no coordinates. When run with a limit, it picked the entries with the lowest ids first. Entries whose locations can never be found stayed at the front on every run and used up the whole batch, so newer entries were never reached.

The backfill now leaves out locations already cached as no-match before applying the limit, and examines at most 2000 candidate entries per run (or 20 times the limit, if that is larger). The script prints a new line, "Rows excluded (known permanent no_result)", with the number of entries it left out.

An entry whose location text is changed is looked up again automatically, because the cache is keyed on the location text. To retry a location without changing its text, delete its row from `geocode_cache`.

## Security

### Secure Flag on Session Cookies

The `binktermphp_session` cookie is now marked `Secure` whenever the site's effective URL uses HTTPS, determined from the `SITE_URL` environment variable (or, if that isn't set, from the request's own HTTPS signal). This prevents the browser from sending the session cookie over a plain HTTP connection, closing off a path where the session id could otherwise be exposed on the wire. Installations that serve BinktermPHP over HTTPS behind a reverse proxy should ensure `SITE_URL` in `.env` is set to the `https://` URL so this detection works correctly.

### Default Terminal Registration Secret No Longer Trusted

The telnet and SSH daemons send `TERMINAL_REGISTRATION_SECRET` to the web API to report the connecting user's real IP address and to mark registrations as terminal-originated (which skips the browser-only anti-spam checks). Until now an unset value fell back to the published default `Chang3Me`, so any HTTP client could send that value to set its own recorded session IP and to skip the registration anti-spam checks.

The web side now treats an unset, empty, or `Chang3Me` value as "no secret configured" and ignores those headers.

**Docker:** the container generates a random `TERMINAL_REGISTRATION_SECRET` on first start when your `.env` leaves it unset or set to `Chang3Me`, and reuses it on later restarts. Web and terminal daemons read the same generated value.

**Other installs:** if your `.env` does not set a site-specific value, set one now and restart the web server and the telnet/SSH daemons:

```ini
TERMINAL_REGISTRATION_SECRET=<a long random string, e.g. the output of: openssl rand -hex 32>
```

Until it is set:

- telnet/SSH sessions are recorded with the server's own address instead of the caller's IP;
- telnet/SSH registrations are treated like browser registrations and are rejected by the browser timing check ("Session expired");
- `scripts/setup.php` prints a warning with a generated value you can paste into `.env`, and the server log records a warning when a terminal registration arrives.

### Telnet and SSH Sessions Disconnected When Their Web Session Is Revoked

A Telnet or SSH login creates a web auth session, which was checked only once, at login. If that session was later removed, the terminal session stayed connected with full access, and so did any door the user was playing. A session can be removed by:

- **Revoke** or **Revoke all sessions** in **Settings**;
- a password reset, which deletes all of the user's sessions;
- the session expiring, or the account being deactivated.

The terminal server now re-checks the session at most every 30 seconds while the user is at a prompt or waiting for a keypress, and while a door is running. Once the session is gone, the user sees "Your session was signed out elsewhere - disconnecting..." and is disconnected. A user inside a door is taken out of the door first. Once a session is found revoked it stays revoked.

A database error during the re-check is logged and is not treated as a revocation, so a brief database outage does not disconnect users. Nothing is checked before login.

The message is a new `ui.terminalserver.server.session_revoked` key, added to all six locales. Restart the telnet and SSH daemons so they load the new code; `scripts/restart_daemons.sh` does this.

### Docker: Config JSON Files No Longer World-Readable

The container entrypoint set `config/`, `data/` and `dosbox-bridge/` to mode 775 on every start. That left files such as `config/binkp.json` (BinkP uplink passwords) and `config/lovlynet.json` (LovlyNet keys) readable by every user in the container.

After setting those permissions, the entrypoint now sets every top-level `config/*.json` file to mode 640: readable and writable by the `binkterm` user and group, with no access for anyone else. The web server user (`www-data`) belongs to that group, so PHP still reads the files. The `config/` directory stays at 775 so the admin daemon can still create and replace files.

This applies on the next container start. If you mount `config/` from the host, the files on the host are changed to 640 as well, so check that any host-side tools or backup jobs reading them run as a user or group that can still do so.

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

---

## Thanks

Thanks to **TheWebExpert** and **Skrawl** for their contributions to this release.
