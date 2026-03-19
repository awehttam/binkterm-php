# Upgrading to 1.8.7

⚠️ Make sure you've made a backup of your database and files before upgrading.

⏳ This upgrade rebuilds trigram indexes on the message tables. On large
message databases the migration step may take several minutes or more. The
upgrade will appear to pause — this is normal. Do not interrupt it.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Enhanced Message Search](#enhanced-message-search)
  - [Search Reindexing](#search-reindexing)
- [RIPscrip Detection and Rendering](#ripscrip-detection-and-rendering)
- [Database Statistics Page](#database-statistics-page)
- [Credits System Updates](#credits-system-updates)
- [Database Performance Improvements](#database-performance-improvements)
- [Nodelist Map](#nodelist-map)
- [Message Reader Improvements](#message-reader-improvements)
- [Echomail Reader Navigation](#echomail-reader-navigation)
- [Echomail Info Bar](#echomail-info-bar)
- [Gemini File Areas](#gemini-file-areas)
- [FREQ Enhancements](#freq-enhancements)
- [Nodelist Enhancements](#nodelist-enhancements)
- [Node Address Links](#node-address-links)
- [Outbound FREQ (File Request)](#outbound-freq-file-request)
- [Crashmail Logging and Packet Preservation](#crashmail-logging-and-packet-preservation)
- [File Area Subfolder Navigation](#file-area-subfolder-navigation)
- [File Preview](#file-preview)
  - [ANSI Art Rendering](#ansi-art-rendering)
  - [MOD Tracker Preview](#mod-tracker-preview)
  - [PETSCII / PRG Rendering](#petscii--prg-rendering)
  - [D64 Disk Image Preview](#d64-disk-image-preview)
  - [C64 Emulator](#c64-emulator)
  - [RIPscrip File Preview](#ripscrip-file-preview)
  - [Sixel Graphics](#sixel-graphics)
  - [ZIP File Browser](#zip-file-browser)
  - [Shared File Preview](#shared-file-preview)
  - [Maximize Button](#file-preview-maximize-button)
- [ISO-Backed File Areas](#iso-backed-file-areas)
  - [Behaviour](#behaviour)
  - [Creating an ISO area](#creating-an-iso-area)
  - [Import preview](#import-preview)
  - [Catalogue formats](#catalogue-formats)
  - [Subfolder navigation](#subfolder-navigation)
  - [Importing files (CLI)](#importing-files-cli)
  - [Migration](#migration)
- [File Area Comments](#file-area-comments)
- [LovlyNet Subscriptions](#lovlynet-subscriptions)
- [Global File Search](#global-file-search)
- [Page Position Memory](#page-position-memory)
- [Netmail Attachment Improvements](#netmail-attachment-improvements)
- [BinkP Inbound File Collision Handling](#binkp-inbound-file-collision-handling)
- [Echo Area Management Improvements](#echo-area-management-improvements)
- [Comment Echo Area Dropdown Grouping](#comment-echo-area-dropdown-grouping)
- [File Upload Filename Sanitization](#file-upload-filename-sanitization)
- [Public File Areas](#public-file-areas)
- [Echo List Network Filter](#echo-list-network-filter)
- [BinkP Status Page Improvements](#binkp-status-page-improvements)
  - [Log Search](#log-search)
  - [Advanced Log Search](#advanced-log-search)
  - [Kept Packets Viewer](#kept-packets-viewer)
  - [Faster Poll Session Termination](#faster-poll-session-termination)
- [Bug Fixes](#bug-fixes)
  - [Crashmail AKA Selection](#crashmail-aka-selection)
  - [Maximized Message Reader Gap](#maximized-message-reader-gap)
  - [Subscription Toggle in Echomail Reader](#subscription-toggle-in-echomail-reader)
  - [BinkP Filenames with Spaces](#binkp-filenames-with-spaces)
  - [TIC File Password Field](#tic-file-password-field)
  - [File Comment Tearline Trimming](#file-comment-tearline-trimming)
- [Footer Registration Display](#footer-registration-display)
- [Premium Features and Registration](#premium-features-and-registration)
  - [Registration Badge on Admin Dashboard](#registration-badge-on-admin-dashboard)
  - [Branding Controls](#branding-controls)
  - [Message Templates](#message-templates)
  - [Economy Viewer Now Requires Registration](#economy-viewer-now-requires-registration)
  - [Referral Analytics](#referral-analytics)
  - [Custom Login and Registration Splash Pages](#custom-login-and-registration-splash-pages)
  - [How to Register](#how-to-register)
- [Netmail Forwarding to Email](#netmail-forwarding-to-email)
- [Echomail Digest](#echomail-digest)
- [Shared File Preview for Unauthenticated Visitors](#shared-file-preview-for-unauthenticated-visitors)
- [Telnet and SSH File Area Fixes](#telnet-and-ssh-file-area-fixes)
- [Admin Menu Reorganization](#admin-menu-reorganization)
- [QWK/QWKE Offline Mail](#qwkqwke-offline-mail)
- [Advertising System](#advertising-system)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)
  - [After Upgrading: Clear Browser Cache](#after-upgrading-clear-browser-cache)

## Summary of Changes

**Message Reader**
- Kludge lines are now hidden by default and toggled via a small icon button in
  the modal header toolbar.
- A print button opens the message in a clean popup window for printing.
- FTN node addresses in From:/To: fields are now clickable links to the nodelist
  node view page.
- Echomail reader now transparently loads the next page when reaching the end of
  a page. When the last message in an echo is reached, a prompt offers to
  continue to the next subscribed echo with unread messages.
- Fixed: sticky message reader header was transparent in the default theme,
  allowing message body text to bleed through.
- Fixed: maximized message reader had a visible gap on the left and top edges
  due to Bootstrap's scrollbar-compensation padding. The modal now fills the
  full viewport when maximized.
- Fixed: end-of-echo "next unread" prompt always showed "no more unread
  messages" due to a tag comparison bug with `@domain` suffixes.
- Fixed: "Go to next echo" navigated to the wrong area (no messages shown)
  because the bare tag was passed instead of `TAG@domain`.
- Message list pagination now shows first page, context window around the
  current page, and last page with ellipsis gaps, so the total page count
  is always visible.

**Echomail / Netmail**
- Last-visited page is remembered per echo area (including All Messages) and
  for the netmail inbox, and restored automatically on return.
- New **QWK/QWKE offline mail** feature: download a QWK packet of all new
  netmail and echomail, read and reply offline, then upload the resulting REP
  packet to post replies. Supports both standard QWK (MultiMail, OLX, etc.)
  and QWKE extended format with full FidoNet metadata.

**Echomail**
- An info bar now appears above the message list when an echo area is selected,
  showing the area tag, domain, and description alongside Subscribe/Unsubscribe
  and Post Message buttons.
- The echomail page title header has been replaced by the info bar.
- Advanced message search with per-field filtering (poster name, subject, body)
  and date range support.
- Search performance significantly improved via trigram GIN indexes.
- RIPscrip messages are now detected in the echomail reader and rendered inline
  using the built-in RIP renderer.

**Netmail**
- Fixed: crashmail FILE_ATTACH netmails sent the staged path as the attachment
  filename instead of the actual filename from the subject line.

**File Areas**
- File area browser shows **Gemini** and **FREQ** capability badges next to each
  area name, along with the area description.
- Users now have a **My Files** entry in the sidebar giving direct access to their
  private file area.
- Virtual subfolder navigation within file areas, with named folders for netmail
  attachments and FREQ responses.
- Clicking a filename in the file browser now opens an inline preview: images
  display in a lightbox-style modal, video and audio play directly in the browser,
  text and NFO files (including CP437-encoded ANSI art) render in a scrollable
  panel. Unknown file types prompt a download. Navigation arrows and keyboard
  shortcuts cycle through files without closing the modal.
- `.mod` tracker music files now play inline in the preview modal with
  play/pause, stop, and volume controls. MOD files inside ZIP archives can be
  previewed the same way.
- `.ans` files render inline in the preview modal using the ANSI decoder.
- `.six` and `.sixel` files render inline as pixel-accurate canvas images using
  the built-in sixel decoder. Sixel sequences embedded directly in echomail and
  netmail message bodies are also detected and rendered inline.
- `.prg` files and ZIP bundles containing `.prg` files render using the C64
  screen RAM decoder with the exact C64 16-colour palette. Multi-file bundles
  show a gallery with previous/next navigation arrows.
- Shared file link pages (`/shared/file/…`) now display the same inline preview
  as the file browser, so recipients can view images, read text/NFO files, and
  see ANSI or PETSCII art without logging in.
- Netmail attachment delivery now stores a copy in the sender's private area as
  well as the recipient's.
- Fixed: TIC file import with **Replace Existing Files** enabled was blocked by
  the duplicate content hash check when the incoming file had the same content as
  the file it was meant to replace.
- File areas can now be published to the Gemini capsule server. Gemini clients
  can browse area listings and download files directly over the Gemini protocol.
- **ISO-backed file areas** — a file area can now be backed by a CD/DVD ISO
  image, a physical CD/DVD drive, or any readable directory. The sysop enters
  the mount point path in the file area properties.
  Files are imported from the ISO's directory tree using `FILES.BBS`,
  `DESCRIPT.ION`, `00INDEX.TXT`, `00_INDEX.TXT` (Simtel block format), or
  `INDEX.TXT` catalogues. ISO areas are read-only — uploads, renames, and
  moves are blocked; only descriptive information (short/long description,
  subfolder labels) can be edited, and those edits are stored in the database. The directory tree is exposed as browsable subfolders
  with editable labels. A preview modal lets sysops review and customise the
  import before committing. ZIP files inside the ISO show `FILE_ID.DIZ` in the
  preview modal. Partial imports (selecting child directories without their
  parent) are supported; ancestor directories are created automatically so
  navigation remains intact.
- **Global file search** — a search box in the file browser sidebar searches
  filename and description across all accessible file areas. Results include
  file size, upload date, a file-info button, and a direct link to the area
  containing each result.
- Spaces in uploaded filenames are now replaced with underscores at upload time
  so filenames are always compatible with FTN file transfer protocols.
- `.d64` Commodore 64 floppy disk images now render a PRG gallery in the
  preview modal, showing all closed PRG files found on the disk with the disk
  name displayed as a header.
- A **Run on C64** button appears on every C64 content preview — rendered PRGs
  (as an icon in the nav bar), machine-code PRGs (as a fallback button that
  launches the emulator inline), and PETSCII stream (`.seq`) files (in a bar
  below the rendered art). Clicking it loads a jsc64-based C64 emulator
  directly inside the preview panel without leaving the page.
- **File area comments** — each file area can now be linked to an echomail echo
  area that serves as a comment thread for its files. Users can leave threaded
  comments on individual files directly from the file detail panel and the file
  preview modal. Comments are stored as standard FTN echomail messages with a
  `^AFILEREF` kludge line, so they are visible to other FTN nodes that carry
  the same echo. A back-reference banner appears in the echomail reader when
  viewing a comment, linking back to the file it refers to. LovlyNet sysops
  should link their file areas to the `LVLY_FILECHAT` echo area.
- **RIPscrip file preview** — `.rip` files now render inline in the preview
  modal using the server-side `RipScriptRenderer`. RIP files inside ZIP
  archives are also supported and shown as a gallery.
- **ZIP file browser** — opening a ZIP file in the preview modal now shows a
  browsable listing of all entries. Previewable entries (images, video, audio,
  MOD tracker music, text/NFO, ANSI, RIPscrip, PETSCII/PRG) open inline; all
  entries have a download button. Entries using legacy DOS compression methods
  (implode, shrink) are flagged with a `legacy` badge and extracted via
  external archive tools on the server, falling back to a graceful notice if
  extraction is unavailable.
- **Public file areas** *(registered feature)* — individual file areas can be
  flagged as public, allowing unauthenticated visitors to browse and download
  files without a BBS account. An optional index page (`/public-files`) lists
  all public areas and can be enabled from BBS Settings.

**LovlyNet**
- New admin page **LovlyNet Subscriptions** (`/admin/lovlynet`) shows all
  available echo and file areas on LovlyNet with subscription status and
  one-click subscribe/unsubscribe toggles. Credentials are read from
  `config/lovlynet.json`.

**Nodelist**
- New interactive map tab powered by Leaflet, with network colour coding and marker
  clustering. Nodes are geocoded from their location field.
- Node view page shows an interactive dark map when the node has geocoded
  coordinates.
- Nodelist search supports a multi-select flag filter to narrow results by
  nodelist flags (CM, IBN, INA, FREQ, etc.).
- Flag badges and flag filter dropdown now show plain-English descriptions for
  all standard flags.

**BinkP / Crashmail**
- BinkP now advertises the closest network AKA when connecting to a node that is
  not a configured uplink.
- Crashmail delivery now writes structured logs to `data/logs/crashmail.log` and
  respects the **preserve sent packets** setting.
- Experimental FREQ support (see [FREQ Enhancements](#freq-enhancements)).
- Fixed: filenames containing spaces were sent unquoted in `M_FILE` commands,
  causing remote binkd systems to misparse the command and reject the transfer.
  Spaces are now replaced with underscores on the wire.

**User Interface**
- The "Registered to" line in the page footer is now displayed inline with the
  "Powered by BinktermPHP" line (e.g. *Powered by BinktermPHP 1.8.7 - Registered
  to My BBS*) and no longer shows a separate badge icon.

**Echo List**
- The `/echolist` page now includes a network filter dropdown. Select one or
  more networks (including Local) to limit the listing to those networks only.
  Selecting nothing shows all networks, matching the existing behaviour.

**Admin**
- New **Database Statistics** page (`/admin/database-stats`) showing size and
  growth, activity metrics, query performance, replication status, maintenance
  health, and index health.
- Configurable file upload and download credit costs/rewards in the **Credits
  System Configuration** page.
- Database performance improvements: new indexes on `mrc_outbound`, `users`,
  `shared_messages`, and `saved_messages`. Chat notification polling rewritten to
  use the primary key index instead of a full table count.
- Echo area management table now has sortable column headers and a **Subs**
  column showing the subscriber count per area.
- The **Comment Echo Area** dropdown in the file area editor now lists echo areas
  from the same network first, with a divider separating them from the rest.
- Admin menu reorganized: new **Analytics** and **Community** submenus; Auto
  Feed moved into Area Management.
- New **Sharing** admin page (`/admin/sharing`) under **Admin → Analytics**
  listing active shared messages and shared files sorted by view count, with
  separate tabs for each.

**Advertising**
- Legacy `bbs_ads/` ANSI ads are imported into a new database-backed ad library
  and enabled by default.
- New **Advertisements** admin page for uploading, editing, previewing, tagging,
  and managing ANSI ads.
- Dashboard advertising now uses the managed library with per-session rotation
  and keyboard/arrow navigation.
- New **Ad Campaigns** admin page for schedule-based echomail ad posting with
  per-target subject templates, weighted ad assignment, and post history.
- `binkp_scheduler` now processes due ad campaigns automatically.

**Premium / Registration**
- Registration status row added to the admin dashboard.
- Registered sysops can now set custom footer text and hide the "Powered by
  BinktermPHP" attribution line from the site footer.
- **Message Templates** — compose form now includes a Templates button for
  registered installations. Save and reuse subject/body templates, filterable
  by message type (netmail, echomail, or both).
- **Economy Viewer** now requires a valid license.
- **Referral Analytics** — new premium admin page showing top referrers, recent
  signups, bonus credits earned, and summary totals.
- Admin licensing page now shows a **Why Register?** panel explaining the value
  of registration, with a **How to Register** modal that renders `REGISTER.md`.
- **Custom splash pages** — registered sysops can configure custom Markdown
  content that appears above the login and registration forms.
- **Netmail forwarding to email** — users can opt in to have incoming netmail
  forwarded to their email address, including file attachments.

**Telnet / SSH**
- Telnet file area browser now supports virtual subfolders.
- Telnet file area listing now shows all areas (pagination was previously capped
  at one page).
- ISO-backed file areas can now be downloaded via ZMODEM over telnet.
- SSH server now correctly associates sessions with the authenticated user's account.

**Shared Files**
- File preview on shared file pages now works for visitors who are not logged in,
  including PETSCII/PRG rendering and the Run on C64 emulator button.

## Enhanced Message Search

The search sidebar now includes an **Advanced Search** button (sliders icon)
that opens a modal with individual fields for poster name, subject, message body,
and a date range picker. Fields are combined with AND logic — fill in only the
ones you need.

The simple search bar continues to work as before (searches across all fields at
once).

### Search Reindexing

This release adds trigram GIN indexes (`pg_trgm`) on the `subject` and
`message_text` columns of both `echomail` and `netmail`. These indexes make
`ILIKE '%term%'` searches fast regardless of table size.

**`setup.php` will build these indexes automatically, but on large message
databases the process may take a few minutes.** The upgrade will appear to pause
at the migration step — this is normal. Do not interrupt it.

A date range index on `echomail(date_received)` is also added in this release.

## RIPscrip Detection and Rendering

The echomail reader now detects RIPscrip content automatically and renders it
inline in the message modal instead of showing the raw RIP command stream.

Detection is based on RIP command lines beginning with `!|` plus recognised
RIP drawing/text opcodes used by the bundled renderer. Messages that do not
match that command structure continue to use the existing ANSI/PETSCII/plain
text rendering path.

No database migration is required for this feature. Existing messages are
eligible automatically after upgrade because detection happens when the message
is viewed.

## Database Statistics Page

A new admin page at `/admin/database-stats` provides a comprehensive view of
PostgreSQL internals, organized into six tabs:

- **Size & Growth** — total database size, top tables and indexes by size, dead
  tuple bloat estimates.
- **Activity** — active connections vs. maximum, cache hit ratio (warns if below
  99%), transaction counts, and tuple insert/update/delete totals.
- **Query Performance** — long-running queries, slowest and most-called queries
  via `pg_stat_statements` (if installed), current lock waits, and cumulative
  deadlock count.
- **Replication** — replication sender status with lag bytes, WAL receiver info
  for replicas.
- **Maintenance** — per-table vacuum and analyze timestamps, dead tuple counts,
  and a warning banner for tables that may need attention.
- **Index Health** — unused indexes, potentially redundant indexes (same column
  set), and index vs. sequential scan ratios per table.

A database size summary and link to this page appear on the admin dashboard.

For query performance data, install the `pg_stat_statements` extension:

```sql
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
```

Then add it to `shared_preload_libraries` in `postgresql.conf` and restart
PostgreSQL. The statistics page works without it but the slow/frequent query
tabs will be empty.

## Credits System Updates

This release adds four new optional credits settings:

- `file_upload_cost`
- `file_upload_reward`
- `file_download_cost`
- `file_download_reward`

These settings are available in **Admin -> BBS Settings -> Credits System Configuration**.

No database migration is required for these options. They are configuration-only
settings and default to `0` if not present in an older `config/bbs.json`.

If you manage `config/bbs.json` manually, you can add them under the `credits`
section:

```json
"file_upload_cost": 0,
"file_upload_reward": 0,
"file_download_cost": 0,
"file_download_reward": 0
```

## Database Performance Improvements

Several tables that were generating excessive sequential scans have been
addressed with new indexes and one query change. These are applied automatically
by `setup.php` via five new migrations (v1.11.0.13 – v1.11.0.17).

**Index changes:**

- `mrc_outbound` — replaced `(sent_at, priority)` with a partial index
  `(priority DESC, created_at ASC) WHERE sent_at IS NULL`. The MRC daemon polls
  this table every 100 ms; the old index did not match the query's `ORDER BY`
  so the planner seq-scanned a near-empty table millions of times.
- `users` — added `UNIQUE INDEX ON users(LOWER(username))`. Login queries and
  name-matching in mail delivery used `LOWER(username)` comparisons with no
  supporting index, causing a seq scan on every authentication request.
- `shared_messages` — replaced `(message_id, message_type)` with a partial
  composite index `(message_id, message_type, shared_by_user_id) WHERE is_active = TRUE`,
  matching the LEFT JOIN condition used in every echomail listing page load.
- `saved_messages` — replaced `(message_id, message_type)` with
  `(message_id, message_type, user_id)`. The previous index had the wrong column
  order for joins driven from the echomail side.

**Query change — chat notification polling:**

The `/api/dashboard/stats` endpoint previously counted all chat messages on
every 30-second poll using a query with an un-indexable OR condition across
three columns. It now queries only messages newer than the last seen message ID
(`WHERE m.id > ?`), using the primary key index. The last seen position is
stored as `last_chat_max_id` in user meta. Existing users will have this
initialized silently on first poll with no false notification badge.

## Nodelist Map

The nodelist page now includes a **Map** tab alongside the existing list view.
Nodes are plotted on an interactive Leaflet map with marker clustering to keep
dense areas readable.

**Key features:**

- Nodes with the same system name (across multiple networks) are grouped into a
  single marker. The popup shows all networks the system belongs to, including
  the FTN address and a "Send Netmail" link.
- Markers are colour coded by the network the node belongs to. Systems on
  multiple networks use a distinct colour. A legend is displayed in the
  bottom-right corner of the map.
- Map data loads lazily — only fetched when the Map tab is first opened.

**Geocoding:**

Coordinates are populated by a new CLI script:

```bash
php scripts/geocode_nodelist.php
```

Options:
- `--limit=N` — process at most N nodes (default: all pending)
- `--force` — re-geocode nodes that already have coordinates
- `--dry-run` — show what would be geocoded without making any changes

The script calls the Nominatim geocoding API (rate-limited to one request per
second) and caches results permanently in the `geocode_cache`
table so the same location string is never looked up twice.

Run it once after upgrading to seed initial coordinates, then add it to cron
to pick up newly imported nodes:

```
0 1 * * 6 php /path/to/scripts/geocode_nodelist.php
```

Geocoding requires the `BBS_DIRECTORY_GEOCODING_ENABLED` environment variable
to be `true` (the default). See `.env.example` for optional tuning variables
(`BBS_DIRECTORY_GEOCODER_EMAIL`, `BBS_DIRECTORY_GEOCODER_URL`,
`BBS_DIRECTORY_GEOCODER_USER_AGENT`).

A new database migration (`v1.11.0.18`) adds `latitude` and `longitude` columns
to the `nodelist` table. This is applied automatically by `setup.php`.

## Message Reader Improvements

The kludge lines panel in the echomail and netmail message readers is now
hidden by default. A small **`</>`** icon button in the modal header toolbar
toggles it open and closed — the button highlights when the panel is visible.

The previous "Show Kludge Lines" button that appeared inside the message body
has been removed.

A **print button** (printer icon) is also in the toolbar. Clicking it opens
the message in a minimal popup window and triggers the browser print dialog.
The popup closes automatically after printing or cancelling.

## Gemini File Areas

File areas can now be exposed via the Gemini capsule server. When a file area
has **Gemini Public** enabled, Gemini clients can browse the area and download
files directly over the Gemini protocol — including binary files such as ZIP
archives, executables, and images.

**To enable a file area for Gemini access:**

1. Go to **Admin → Area Management → File Areas**
2. Edit the file area
3. Check **Gemini Public**
4. Save

Once enabled, the area appears in the Gemini capsule under:

```
gemini://your-host/files/AREA_TAG/
```

and on the Gemini home page under a new **File Areas** section. Individual
files are served at:

```
gemini://your-host/files/AREA_TAG/filename.zip
```

Only files with an approved status are served. Private file areas are never
exposed regardless of this setting.

A new database migration (`v1.11.0.20`) adds the `gemini_public` column to the
`file_areas` table. This is applied automatically by `setup.php`.

## FREQ Enhancements

> **Note:** FREQ support in this release is experimental and sysop-only.
> Compatibility with third-party BinkP implementations varies.

### Response Delivery

FREQ responses are now delivered as FILE_ATTACH netmail via two paths:

1. **Crashmail (direct)** — if the requesting node has a hostname in the
   nodelist (IBN/INA flag), BinktermPHP connects directly and delivers the
   attachment immediately. No action required from the requesting node.

2. **Hold directory (reverse crash)** — if the node cannot be reached directly,
   the FILE_ATTACH packet and attachment are written to
   `data/outbound/hold/<address>/`. They are delivered during the next binkp
   session with that node, whichever side initiates. A notification netmail is
   also sent to the requesting node via hub routing to let them know files are
   ready.

The previous approach of queuing raw files in `freq_outbound` for hub-routed
delivery has been removed. Hubs typically strip file attachments from forwarded
netmail, making that approach unreliable.

`setup.php` creates the `data/outbound/hold/` directory automatically.

### Outbound FREQ Response Routing

When `freq_getfile.php` sends a file request, the request is now persisted to a
new `freq_requests_outbound` database table. When the remote node fulfils the
request — whether in the same session or a later one — the received files are
automatically matched against pending requests by node address and filename and
routed to the requesting user's private file area.

This means FREQ responses are handled correctly across all session types:
inbound sessions (remote connects to deliver), outbound polls, and same-session
delivery. Only files whose names exactly match a requested filename are routed;
all other received files (netmail attachments, packets, etc.) are left in
`data/inbound/` untouched.

A new database migration (`v1.11.0.24`) creates the `freq_requests_outbound`
table. This is applied automatically by `setup.php`.

### BinkP AKA Selection Fix

When connecting to a node that is not a configured uplink, BinktermPHP now
selects the uplink whose network covers the destination address and advertises
that uplink's `me` address in `M_ADR`. This ensures the remote system
identifies you by the correct AKA rather than your primary zone address.

This fix now applies to **crashmail delivery** as well. Previously, crashmail
sessions created a raw BinkP session without an uplink context, causing the
primary AKA to be advertised to all remote hosts regardless of network. This
resulted in "Bad password" rejections when delivering to nodes that are uplinks
for one network but not for the zone associated with your primary address.

## Nodelist Enhancements

### Flag Filter

The nodelist search page now includes a **multi-select flag filter**. Select one
or more flags (CM, IBN, INA, FREQ, MO, etc.) to narrow the results to nodes
that carry all of the chosen flags.

### Node View Map

The nodelist node detail page now includes a dark interactive map below the
info panels when the node has geocoded coordinates. If coordinates are missing,
a notice is shown with instructions to run `scripts/geocode_nodelist.php`.

## Node Address Links

FTN node addresses displayed in message headers are now clickable links to the
nodelist node view page:

- **Echomail** — From: address
- **Netmail** — From: and To: addresses (both in the message reader and in the
  folder list rows)

## Outbound FREQ (File Request)

### freq_getfile.php

A new CLI script allows you to request files from a remote binkp system:

```bash
php scripts/freq_getfile.php 1:123/456 ALLFILES
php scripts/freq_getfile.php 1:123/456 ALLFILES FILES --password=secret
php scripts/freq_getfile.php 1:123/456 ALLFILES --hostname=bbs.example.com --port=24554
```

The script resolves the hostname automatically from the nodelist or binkp zone
DNS. Received files are stored in your private file area and are visible in the
file browser under **My Files → FREQ Responses**. See [CLI.md](CLI.md) for the
full option reference.

### Nodelist File Request Dialog

The file request dialog on the nodelist node view page now includes **AllFix**
as a selectable addressee alongside FileFix, FileMgr, FileReq, and Sysop.
AllFix is a file area manager robot name, not a magic filename.

### ALLFILES.TXT Formatting

The dynamically generated `ALLFILES.TXT` file listing now uses plain ASCII
(no UTF-8 em dashes) and formats columns dynamically based on the longest
filename in each area. Long descriptions wrap at 80 characters with continuation
lines aligned to the description column.

## Crashmail Logging and Packet Preservation

### Structured Log File

Crashmail delivery now writes to `data/logs/crashmail.log` using the same
structured logger as the binkp server (timestamp, PID, level, message). Previously
all output went only to the PHP error log.

### Preserve Sent Packets

When **Preserve Sent Packets** is enabled in the binkp configuration, crashmail
packets are now moved to the preserved sent packets directory on successful
delivery instead of always being deleted. The preserved file is named
`crashmail_<id>_<timestamp>.pkt`.

## Echomail Reader Navigation

### Transparent Pagination

When reading messages in an echo and reaching the last message on the current
page, clicking Next automatically loads the next page and opens its first
message — no manual page flipping required.

### End-of-Echo Prompt

When the last message in an echo is reached (all pages exhausted), clicking
Next now shows a confirmation panel inside the message reader:

- **"End of [ECHONAME]"** with a prompt to continue to the next subscribed
  echo that has unread messages.
- **Go to [NEXT ECHO]** and **Close** buttons.
- If there are no more unread echoes, the panel says so and offers only Close.

The next/previous navigation buttons in the modal header now always display
descriptive tooltips ("Next message", "Previous message", "Load next page",
"End of echo").

## File Area Subfolder Navigation

The file browser now supports virtual subfolders within file areas. Subfolders
appear as folder icons in the file listing and are navigable by clicking. A
breadcrumb trail shows the current location and lets you return to the area
root.

**My Files sidebar entry:**

Each user with a private file area now sees a **My Files** entry pinned at the
top of the file area sidebar. Clicking it opens their own private area directly,
without navigating the full area list.

**Built-in subfolders:**

- **`attachments`** — displayed as *Netmail Attachments*. Netmail file
  attachments delivered to a user's private area are stored here automatically.
- **`incoming`** — displayed as *FREQ Responses*. Files received in response
  to outbound FREQ requests (`freq_getfile.php`) land here.

The `subfolder` column on the `files` table stores the virtual path for each
file record.

A new database migration (`v1.11.0.22`) adds the `subfolder` column. This is
applied automatically by `setup.php`.

## File Preview

Clicking a filename in the file browser now opens a preview modal instead of
going straight to a download.

**Supported types:**

| Type | Extensions | Behaviour |
|------|-----------|-----------|
| Image | jpg, jpeg, png, gif, webp, svg, bmp, ico, tiff, avif | Displayed inline; click to open full size in a new tab |
| Video | mp4, webm, mov, ogv, m4v | Plays in a `<video>` element with full controls and seek support |
| Audio | mp3, wav, ogg, flac, aac, m4a, opus | Plays in an `<audio>` element |
| MOD Tracker | mod | Plays inline in a tracker player with play/pause, stop, and volume controls |
| Text / NFO | txt, log, nfo, diz, md, cfg, ini, json, xml, … | Rendered in a scrollable panel; NFO and DIZ files are automatically converted from CP437 to UTF-8 so ANSI art displays correctly |
| ANSI Art | ans | Rendered inline using the ANSI decoder with the full 16-colour ANSI palette |
| PETSCII / PRG | prg | Rendered using the C64 screen RAM decoder with the exact C64 16-colour palette; **Run on C64** button in nav bar |
| PETSCII Stream | seq | Rendered using the PETSCII decoder; **Run on C64** button loads the emulator inline |
| D64 Disk Image | d64 | Parsed as a C64 floppy image; PRG files extracted and shown as a gallery |
| RIPscrip | rip | Rendered server-side to SVG by `RipScriptRenderer` and displayed on a dark background |
| Sixel Graphics | six, sixel | Decoded and rendered to canvas pixel-accurately on a black background |
| ZIP Archive | zip | Browsable file listing; previewable entries open inline; all entries have a download button |
| Unknown | everything else | Download prompt with a Download button |

The modal header includes:
- **◀ ▶ navigation arrows** to move through the current file list without
  closing the modal. Left/right arrow keys work too.
- **ⓘ (File Info)** button to switch to the full file-details view for the
  current file.
- **⬇ (Download)** button to download the file at any time.
- **⤢ (Maximize)** button to toggle the modal between its normal size and
  fullscreen. The preference is remembered in `localStorage` and restored
  automatically on the next file open.

No configuration or migration is required for this feature.

### ANSI Art Rendering

Files with the `.ans` extension are fetched as text (the backend converts them
from CP437 to UTF-8 automatically) and passed to the ANSI decoder. The result
is displayed in the preview modal with the standard ANSI colour palette on a
dark background, identically to how ANSI art in message bodies is rendered.

### MOD Tracker Preview

Files with the `.mod` extension now open in an inline tracker player in the
preview modal.

The player:

1. Fetches the module as raw bytes for browser-side playback.
2. Parses 4-channel ProTracker-style modules in the browser.
3. Provides **Play/Pause**, **Stop**, and **Volume** controls directly in the
   preview modal.

`.mod` files inside ZIP archives are also supported. Legacy ZIP compression
methods such as **implode** are handled via the same ZIP-entry extraction path
used for other previewable files.

No configuration or migration is required.

### PETSCII / PRG Rendering

Files with the `.prg` extension, and ZIP archives that contain `.prg` files,
are rendered using the C64 screen RAM decoder.

The renderer:

1. Strips the 2-byte PRG load-address header.
2. If the load address is `$0400` (the C64's screen RAM base address), the
   data is decoded directly as 40×25 screen codes with optional color RAM.
3. Otherwise a heuristic scans the executable body for an embedded 1000-byte
   screen RAM + color RAM block (the standard C64 art pack layout) and decodes
   that.
4. Color RAM values (0–15) are mapped to the exact C64 16-colour palette via
   CSS classes (`c64-fg-N` / `c64-bg-N`).


**ZIP bundles with multiple PRGs** show a gallery view with previous/next
navigation arrows and a "N / total" counter. `FILE_ID.DIZ` (if present in
the ZIP) is shown below the gallery.

A **▶ Run on C64** icon button appears in the nav bar for all rendered PRGs,
allowing the file to be run interactively in the built-in C64 emulator.

PRGs where no screen RAM block is detectable (machine-code programs) show a
"Preview unavailable" notice with a **Run on C64** button. Clicking it loads
the emulator inline in the preview panel and auto-executes the program.

A new API endpoint powers PRG extraction:

```
GET /api/files/{id}/prgs
```

Returns a JSON array of PRG entries, each with `name`, `load_address`, and
base64-encoded `data_b64`. Works for both standalone `.prg` files and ZIP
archives.

### D64 Disk Image Preview

`.d64` Commodore 64 floppy disk image files now open in the preview modal as a
PRG gallery.

The backend parses the standard D64 directory (track 18) and extracts all
closed PRG files by following the track/sector chain links. Both 35-track
(174,848-byte) and extended 40-track images are supported.

The preview shows:

- The **disk name** (from the BAM sector) as a header bar above the gallery,
  when present.
- Each PRG file rendered using the same C64 screen RAM decoder used for
  standalone `.prg` files and ZIP bundles — with previous/next navigation and
  a file counter.
- Machine-code PRGs (load address ≠ `$0400` and no detectable screen RAM block)
  show a "preview unavailable" notice instead of garbage output.

The same `/api/files/{id}/prgs` endpoint is extended to handle D64 files; the
response includes an additional `disk_name` field.

No configuration or migration is required.

### C64 Emulator

A **Run on C64** button is available on every C64 content type in the preview
modal, powered by [jsc64](https://github.com/Reggino/jsc64) — a JavaScript
port of the fc64 Commodore 64 emulator.

**Where the button appears:**

| Content type | Button location | Behaviour on click |
|---|---|---|
| Rendered PRG (screen art) | Icon (▶) on the right of the nav bar | Hides the art; loads emulator inline in the preview panel |
| Machine-code PRG | Below the "Preview unavailable" notice | Hides the notice; loads emulator inline in the preview panel |
| PETSCII stream (`.seq`) | Bar below the rendered art | Hides the art; loads emulator inline in the preview panel |

The emulator boots the C64, waits approximately 2 seconds for BASIC to
initialise, then fetches the PRG bytes from the `/api/files/{id}/prgs` API
endpoint, writes them into emulated memory, and executes the program.
A **RST** button resets the CPU; a **❙❙** button pauses and resumes execution.

**SEQ files** — `.seq` PETSCII stream files do not have a native PRG on disk.
When the emulator is requested for a SEQ file, the API generates a
machine-code PRG on the fly: a small 6502 stub (load address `$2000`) streams
every byte of the SEQ data through the C64 CHROUT kernal routine (`$FFD2`),
reproducing the file exactly as a C64 would display it.

**ROM files** — jsc64 requires the original Commodore 64 Kernal, BASIC, and
Character ROM binaries. These are included in the vendor directory
(`public_html/vendor/jsc64/js/assets/`).

**No configuration or migration is required.**

### RIPscrip File Preview

Files with the `.rip` extension now render inline in the preview modal.
The server-side `RipScriptRenderer` class converts the RIPscrip data to an SVG
image, which is returned by the `/api/files/{id}/preview` endpoint as
`text/html` and displayed on a dark background.

**RIP files inside ZIP archives** are also supported:

- Opening a ZIP in the preview modal now detects all `.rip` entries.
- If one or more RIP files are found, a gallery view is shown with previous/next
  navigation (accessible via the `/api/files/{id}/rips` endpoint).
- Individual `.rip` entries can also be opened from the ZIP file browser.

No configuration or migration is required.

### Sixel Graphics

DEC sixel images are now decoded and rendered natively in the browser with no
server-side processing and no external libraries.

**Supported everywhere sixel images can appear:**

- **Echomail and netmail message bodies** — DCS sixel sequences
  (`ESC P … q … ESC \`) embedded directly in message text are detected,
  extracted, and rendered as inline `<canvas>` elements between the surrounding
  text segments.
- **File area preview modal** — `.six` and `.sixel` files open in the preview
  modal with pixel-accurate canvas rendering on a black background.
- **ZIP file browser** — `.six` and `.sixel` entries inside ZIP archives are
  previewable the same way.

**Decoder features:**

- HLS and RGB colour register definitions (`#n;1;h;l;s` and `#n;2;r;g;b`)
- RLE repeat sequences (`!count char`)
- Raster attributes (`"Pan;Pad;Ph;Pv`) for pre-allocated image dimensions
- VT340-compatible default 16-colour palette for images that do not define their own colours
- Dynamic pixel buffer that grows as the image is decoded

No configuration or database migration is required.

### ZIP File Browser

Opening a ZIP file in the preview modal now shows a **browsable file listing**
instead of only displaying `FILE_ID.DIZ`.

**How it works:**

- The `/api/files/{id}/zip-contents` endpoint lists up to 500 non-directory
  entries, sorted by path, each with filename, size, and compression method.
- Clicking a previewable entry opens it inline via
  `/api/files/{id}/zip-entry?path=…`, applying the same type detection used by
  the main file preview (images, video, audio, MOD tracker, text/NFO, ANSI,
  RIPscrip, PETSCII/PRG).
- All entries show a **Download** button regardless of whether they can be
  previewed.
- Unsupported or unknown file types show a download-only panel.

**Legacy DOS compression (implode, shrink, etc.):**

Old BBS-era ZIP files often use compression methods not supported by PHP's
built-in `ZipArchive` (libzip). For these entries:

1. The server first attempts extraction via `ZipArchive`.
2. If that fails, it falls back to external archive tools such as `unzip` or
   `7z`, extracting the requested member to a temporary location first so
   binary files are not truncated on Windows.
3. If no supported extractor is available or the method is still unsupported,
   the server returns HTTP 415 and the browser shows a graceful notice with a
   "Download full ZIP" button.

Entries with non-standard compression are marked with a `legacy` badge in the
ZIP browser listing.

No configuration or migration is required. For legacy ZIP support, install at
least one supported extractor such as `unzip` or `7z`.

### Shared File Preview

The shared file link page (`/shared/file/{area}/{filename}`) now renders the
same inline preview as the file browser. All supported types work — images,
video, audio, text/NFO, ANSI art, and PETSCII/PRG — without requiring the
visitor to be logged in.

No configuration or migration is required.

### File Preview Maximize Button

The file preview modal now has a **maximize** toggle button in the header
(expand/compress icon), matching the existing maximize behaviour in the echomail
and netmail message readers.

Clicking the button switches the modal between its default `modal-xl` size and
fullscreen. The preference is saved to `localStorage` as
`previewModalFullscreen` and restored automatically the next time any file is
opened. This preference is independent of the message reader fullscreen setting.

No configuration or migration is required.

## ISO-Backed File Areas

A file area can now be backed by a CD/DVD ISO image on the server, allowing
sysops to expose large shareware CD collections (Simtel, Walnut Creek, etc.)
without copying files to local storage. Physical CD/DVD drives also work —
anything the OS can mount and expose as a directory path is supported. Plain
directories work too — the system only requires a readable path in the
**Mount Point** field.

> **Note:** CD/DVD jukeboxes and changers are not supported. The mount point
> must be a single, consistently accessible path. Media that changes or goes
> offline will cause file requests to return 503 until the path is accessible
> again.

### Behaviour

- ISO areas are **read-only** — uploads, renames, and moves are blocked.
  Admin file deletion removes the database record only; no disk change is made.
- Descriptive information (short/long description, subfolder labels) can always
  be edited and is stored in the database.
- If the mount point is not accessible when a file is requested, the server
  returns 503.
- ZIP files inside the ISO display `FILE_ID.DIZ` in the preview modal.

### Creating an ISO area

1. Mount the ISO using any suitable method and note the resulting path.
   On Linux: `sudo mount -o loop,ro /srv/isos/simtel.iso /srv/iso_mounts/simtel`
   On Windows: right-click the `.iso` → **Mount**, note the drive letter.
2. In **Admin → Area Management → File Areas**, click **Add File Area**.
3. Set **Area Type** to **ISO-backed**.
4. Enter the mount point path in **Mount Point**
   (e.g. `/srv/iso_mounts/simtel` or `D:\`).
5. Save. The status badge shows **Accessible** when the path is reachable.
6. Click **Re-index ISO** to open the import preview.

### Import preview

Clicking **Re-index ISO** opens a preview modal before writing anything to the
database. Each directory in the ISO is shown as a row with:

- **Include/exclude checkbox** — uncheck to skip that directory.
- **Description** — pre-filled from the catalogue or existing database entry;
  editable before committing.
- **File count** — how many files would be imported from that directory.
- **Status badge** — New or Existing.

Click **Apply Import** to commit. Import options in the modal header:

| Option | Effect |
|---|---|
| **Flat import** | All files imported to the area root, directory structure ignored. |
| **Catalogue only** | Only import files listed in `FILES.BBS` / `DESCRIPT.ION`. Unlisted files are skipped. If no catalogue exists in a directory, all files there are imported. |

### Catalogue formats

The importer recognises the following description files, tried in priority
order. If a higher-priority file is found but yields no entries, the next
format is tried automatically.

| File | Format |
|---|---|
| `FILES.BBS` | One filename per line followed by two or more spaces and a description. Continuation lines are indented. |
| `DESCRIPT.ION` | `filename "description"` per line (JPSOFT 4DOS / Midnight Commander format). |
| `FILE_LIST.BBS` | Same parser as `FILES.BBS`. |
| `00INDEX.TXT` | Same parser as `FILES.BBS`. |
| `00_INDEX.TXT` | Simtel block format: `Directory:` headers group entries; each `File:` block is followed by an indented multi-line description. |
| `INDEX.TXT` | Same parser as `FILES.BBS`. |

Comparisons are case-insensitive so `files.bbs`, `FILES.BBS`, and `Files.Bbs`
are all recognised.

### Subfolder navigation

The ISO directory tree is exposed as browsable subfolders in the file browser.
Each subdirectory is stored as an `iso_subdir` record in the `files` table —
a virtual row with no physical file that carries a human-readable label and
description for the folder.

- **Labels** — sysops can set a short description (folder label) and long
  description on any subfolder by clicking the pencil icon on the folder row.
  Labels are preserved across re-indexes.
- **Deletion** — admins can delete an entire subfolder (and all its nested
  content) from the folder row's trash icon. Only database records are removed;
  ISO files are not touched.

### Importing files (CLI)

The importer is also available from the command line:

```bash
php scripts/import_iso.php --area=<id> [--update] [--dry-run] [--verbose]
```

Files are imported from `FILES.BBS` / `DESCRIPT.ION` catalogues found in each
directory. If no catalogue is present, the filename is used as the description.

### Migration

This feature requires database migrations. Run `php scripts/setup.php` as
part of the standard upgrade procedure.

## File Area Comments

Each file area can now be linked to an echomail echo area that acts as a
comment thread for all files in that area.

### How it works

- When a user opens the file detail panel or the file preview modal, a
  **Comments** section appears below the file information (or below the
  preview content).
- Logged-in users can leave top-level comments or reply to existing ones
  (threaded up to three levels deep).
- Each comment is posted as a standard FTN echomail message in the linked
  echo area with a `^AFILEREF` kludge line identifying the file area tag,
  filename, and SHA-256 hash.  This means the comments propagate to other
  nodes that carry the echo, and other FTN software can see them.
- When a comment message is opened in the echomail reader, a banner appears
  at the top linking back to the file it refers to.
- A comment count badge is displayed next to filenames that have comments in
  the file listing.

### Setup — linking an echo area

1. In the admin panel, open **File Areas** and edit the file area you want to
   enable comments for.
2. In the **Comments Echo Area** dropdown at the bottom of the form, choose an
   existing echo area or select **✚ Create new echo area…** and enter a tag.
   The setting is saved together with the rest of the file area when you click
   **Save**.
3. For LovlyNet file areas the dropdown automatically pre-selects
   `LVLY_FILECHAT` if that echo area already exists.  If it does not exist
   yet, select **✚ Create new echo area…** — the tag `LVLY_FILECHAT` will be
   pre-filled and the area will be created with the correct LovlyNet domain
   and description on save.
4. To disable comments, choose **— None (Disabled) —** from the dropdown and
   save.

### Database migration

The migration `v1.11.0.32_file_area_comments.sql` adds:

- `comment_echoarea_id` column on `file_areas` (nullable FK to `echoareas`)
- `comment_count` column on `files` (integer, default 0)

This migration runs automatically as part of `php scripts/setup.php`.

## Global File Search

A **Search Files** card now appears in the file browser sidebar, between the
File Areas list and the Status panel. Typing two or more characters triggers a
server-side search across filename and short description for all accessible file
areas.

**Results table columns:** Filename (opens preview), Area (click to navigate
to that area), Description, Size, Uploaded date, Info button, Download button.

Results are limited to 100 entries ordered by area tag then filename.
Private areas belonging to other users are excluded.

### Search indexes

Migration `v1.11.0.27` enables the `pg_trgm` PostgreSQL extension and creates
GIN trigram indexes on `files.filename` and `files.short_description`. These
indexes make `ILIKE '%term%'` queries fast regardless of how many files are in
the database.

## Page Position Memory

The echomail and netmail readers now remember the last page you were viewing
and restore it automatically when you return.

- **Echomail** — the last-visited page is remembered per echo area, including
  the **All Messages** view. Navigating to a different echo area and back
  restores the correct page for each.
- **Netmail** — the last-visited page of your inbox is remembered.

This behaviour is **opt-in and disabled by default**. Users can enable it in
**Settings** under the new **Remember last page in echomail and netmail**
toggle. Existing users will have the setting off after upgrading.

Positions are stored per-user in the database and persist across browser
sessions and devices. Migration `v1.11.0.28` adds the `remember_page_position`
column to `user_settings` (default `FALSE`) and is applied automatically by
`setup.php`.

## Netmail Attachment Improvements

### Sender Copy

When a local netmail with a file attachment is delivered between two users on
the same system, the sender now receives a copy of the attachment in their own
private file area (under the `attachments` subfolder), tagged as
`source_type = netmail_sent`. The recipient's copy is unchanged.

Previously only the recipient received the file; the sender had no local copy.

### Attachment Viewer Filtering

When viewing a netmail that has attachments, each viewer now sees only the
copy stored in an area they can access:

- The **recipient** sees the file in their private area.
- The **sender** sees their own sent copy.
- If only one copy exists (e.g. historical messages from before this release),
  the single copy is shown to both parties as before.

### Duplicate Hash Constraint Removed

The `UNIQUE(file_area_id, file_hash)` constraint on the `files` table has been
replaced with a plain index. The unique constraint was causing INSERT failures
when the same file was attached to more than one netmail in the same area (for
example, a recurring attachment sent to the same recipient). The constraint
provided no meaningful integrity guarantee in private areas and has been
removed.

A new database migration (`v1.11.0.23`) performs this change automatically via
`setup.php`.

## BinkP Inbound File Collision Handling

When an inbound BinkP session delivers a file whose filename already exists in
the `data/inbound/` directory, the new file is now saved with a numeric suffix
(`filename_1.ext`, `filename_2.ext`, …) instead of silently overwriting the
existing file. The `M_GOT` acknowledgement still uses the original remote
filename as required by the BinkP protocol.

Previously a collision would clobber the existing inbound file with no
warning.

## Echo Area Management Improvements

The echo area management table (Admin → Echo Areas) has been updated with two
quality-of-life improvements:

**Sortable columns** — clicking any column header sorts the table by that
column. Clicking the same header again reverses the sort direction. An arrow
indicator shows the active sort column and direction. Numeric columns (`ID`,
`Messages`, `Subs`) sort numerically; all other columns sort alphabetically.
The default sort is by ID ascending.

**Subscriber count (Subs)** — a new **Subs** column shows how many users are
actively subscribed to each echo area, making it easy to see which areas are
most popular at a glance.

No database migration is required; subscriber counts are derived from the
existing `user_echoarea_subscriptions` table.

## Comment Echo Area Dropdown Grouping

The **Comment Echo Area** dropdown in the file area editor now groups echo areas
intelligently based on the network of the file area being edited.

- Echo areas whose `domain` matches the file area's own domain are listed
  **first**, making it easy to pick the correct echo for the same network.
- A visual divider (`────────`) separates the same-network group from the
  remaining echo areas.
- If the file area has no domain set, or no echo areas share the same domain,
  the full list is shown without a divider.

No configuration or migration is required.

## File Upload Filename Sanitization

Spaces in filenames are now replaced with underscores (`_`) when a file is
uploaded to a file area, both through the web interface and via the
`post_file.php` CLI script.

This ensures filenames stored on disk and in the database are always compatible
with FTN file transfer protocols (binkp and TIC), which treat spaces as
field delimiters in their wire formats.

**Existing files** with spaces in their names are not renamed automatically.
Use the **Rename** action in the file browser to update them if needed before
they are distributed to uplinks.

## Bug Fixes

### Crashmail AKA Selection

Crashmail delivery was presenting the primary (zone 1) AKA to all remote hosts,
even when the destination node is only an uplink for a different network. The
remote system would reject the session with "Bad password" because it had no
password configured for that address. Fixed — crashmail sessions now select the
correct AKA for the destination's network, matching the behaviour already in
place for other outbound BinkP connections.

### Crashmail FILE_ATTACH Filename

Crashmail delivery of FILE_ATTACH netmails was sending the attachment using the
internal staged file path as the filename on the wire (e.g.
`freq_abc123_ALLFILES.TXT`) instead of the actual filename stored in the
subject line (`ALLFILES.TXT`). The remote system received the file with the
wrong name. Fixed to use the subject line as the filename for all FILE_ATTACH
deliveries, matching the FTN convention.

### Message Reader Header Transparency

The sticky message header in the scrollable message reader was transparent in
the default (light) theme, allowing the message body text to show through as
the user scrolled. Fixed by adding an explicit white background to the sticky
header rule in `style.css`.

### Maximized Message Reader Gap

The maximized message reader modal had a visible gap along the left and top
edges. Bootstrap JS adds an inline `padding-right` to the modal container to
compensate for the scrollbar when a modal opens; in fullscreen mode this
padding is unnecessary and pushed the dialog away from the viewport edges.
Fixed by overriding the padding to zero when the `modal-fullscreen` class is
active.

### TIC Replace Existing Blocked by Duplicate Hash

When a file area had **Replace Existing Files** enabled and **Allow Duplicate
Content** disabled, TIC imports of updated files with the same content as the
existing file were rejected by the duplicate hash check before the replacement
logic could run. Fixed — when replacing a file by name, a hash match for that
same filename is now allowed through so the replacement proceeds.

### Subscription Toggle in Echomail Reader

The subscribe/unsubscribe button in the echomail info bar was not updating after
being clicked. `SubscriptionController` was not setting a `Content-Type:
application/json` response header, causing jQuery to treat the response body as
plain text rather than JSON. As a result `data.success` was always `undefined`
and the success branch never ran. Fixed by adding the missing header in
`SubscriptionController::handleUserSubscriptions()` and adding `dataType: 'json'`
to the `$.ajax` call in `echomail.js`.

### BinkP Filenames with Spaces

When sending a file whose name contained spaces, the `M_FILE` command was
emitted without any quoting — for example `FILE compufreak - sb - hf.prg 1035
…`. The binkp protocol has no quoting mechanism; remote binkd systems split on
spaces and treated the first token as the filename and the remaining tokens as
unknown options, rejecting the transfer.

Fixed by replacing spaces with underscores in the filename advertised over the
wire. The original file on disk is not renamed — the mapping is tracked
internally so the file is correctly deleted or preserved after the remote
acknowledges receipt with `M_GOT`.

The inbound `M_FILE` parser has also been made more defensive and will handle
quoted filenames sent by other implementations that do use quoting.

### File Comment Tearline Trimming

File area comments displayed in the file detail and preview modals were
sometimes showing FTN tearlines (`--- BinktermPHP vX.Y.Z`) appended to the
comment body. Comments are now trimmed at the last tearline before display,
matching the behaviour of the echomail and netmail message readers.

## Footer Registration Display

The "Registered to" line in the page footer has been simplified. Previously it
appeared as a separate line with a badge icon below the "Powered by
BinktermPHP" line. It is now displayed inline:

> Powered by BinktermPHP 1.8.7 - Registered to *System Name* | …

The badge icon has been removed. The change applies when a valid licence is
present; unlicensed installations are unaffected.

## Premium Features and Registration

This release introduces **Registration** as a way for sysops to support the
continued development of BinktermPHP. BinktermPHP remains fully functional
without registration — it is never required to run a BBS. Registered
installations unlock a small set of extras as a thank-you for contributing.

For registration options and instructions, see [REGISTER.md](../REGISTER.md).

### Registration Badge on Admin Dashboard

The admin dashboard (`/admin`) now includes a **Registration Status** row in the
system information section. It shows either an **Unregistered** badge with a
link to the licensing page, or a **Registered** badge with the licensed system
name and licensee when a valid license is present. No action is required to
enable this display.

### Branding Controls

Registered installations can now customise the site footer from the **Admin →
Settings → Branding** panel:

- **Custom footer text** — replace or supplement the default footer with any
  text you choose (plain text; rendered beneath the powered-by line).
- **Hide "Powered by BinktermPHP" attribution** — optionally remove the
  attribution line entirely for a fully branded experience.

These options are stored in `data/bbs.json` and take effect immediately on save.

### Message Templates

A **Templates** button (bookmark icon) now appears in the compose form toolbar
for registered installations. Templates are reusable subject/body pairs that
can be saved, named, and filtered by message type (netmail, echomail, or both).

To create a template: compose a message, click **Templates → Save as Template**,
give it a name and choose its type. To use a template: click **Templates** and
select one from the list to pre-fill the subject and body fields.

Templates are stored per-user in the database. No migration is required for
existing users — the table was added in a prior release.

### Economy Viewer Now Requires Registration

The **Economy Viewer** admin page (`/admin/economy`) now requires a valid
license. Unlicensed installations receive a 403 response. The Economy Viewer
displays credit transaction volume, top earners and spenders, richest accounts,
recent transactions, and overall economy health metrics (credits in circulation,
average and median balances).

### Referral Analytics

A new **Referral Analytics** admin page (`/admin/referral-analytics`) is
available to registered installations. It shows:

- Top referrers (users who have brought in the most sign-ups)
- Recent referred sign-ups with join dates
- Bonus credits earned through referrals
- Summary totals (total referrals, total bonus credits awarded)

This page requires a valid license and is accessible via **Admin → Analytics →
Referral Analytics**.

### Custom Login and Registration Splash Pages

Registered installations can configure custom Markdown content that appears
above the login and registration forms. This is useful for system announcements,
welcome messages, or instructions specific to your BBS.

Configure both pages from **Admin → Appearance → Splash Pages**. Each page has
its own editor — leave either blank to show nothing. Content is rendered as
Markdown and displayed in a card above the respective form.

**Legacy `config/welcome.txt`** — the old plain-text welcome file is still
supported as a fallback for unlicensed installations. If a registered
installation has a login splash configured, the splash takes precedence and
`welcome.txt` is ignored. `config/welcome.txt.example` has been removed from
the repository as it is superseded by the splash pages feature.

No configuration or migration is required.

### How to Register

Visit the **Admin → Licensing** page and click **How to Register** to open the
registration modal, which displays the `REGISTER.md` document with current
registration options and contact information.

The **Why Register?** panel on the licensing page summarises the benefits:
sustaining the project, custom branding controls, access to premium features,
and a perpetual license for the current version line.

## Netmail Forwarding to Email

Users can now opt in to have incoming netmail automatically forwarded to their
registered email address. This is a registered-only feature and requires SMTP
to be configured on the system.

**Enabling forwarding:**

1. Set a valid email address in your user profile.
2. Go to **Settings** and enable **Forward netmail to email** under the
   Notifications section. The toggle is visible to all users but only active
   on registered installations.

**What is forwarded:**

- The sender's name and FTN address (e.g. `Matthew Asham (1:123/456)`)
- The original subject and message body
- File attachments, when present

The forwarded email includes a notice that replies will not reach the netmail
sender and that the user should log in to the BBS to reply.

**Behaviour notes:**

- Opt-in, disabled by default for all users after upgrading.
- File attachments are included in the forwarded email for both locally
  composed netmail and inbound FTN netmail arriving via BinkP.

A database migration (`v1.11.0.30`) adds the `forward_netmail_email` column
to `user_settings` and is applied automatically by `setup.php`.

## Echomail Digest

Users can now opt in to a periodic echomail digest email that summarises new
messages across their subscribed echo areas.  The digest is available in two
frequencies — **Daily** and **Weekly** — and is configured in
**Settings → Notifications**.  The option is disabled (Off) by default.

This is a registered feature requiring a valid license.

Each digest email lists, per echo area, the number of new messages along with
subject lines and author names since the last digest was sent.  Full message
bodies are not included to keep the email concise.

**Cron setup** — Run the new script periodically to deliver digests.  Once per
hour is recommended; the script enforces the per-user frequency internally:

```bash
# Hourly via crontab
0 * * * * php /path/to/binkterm-php/scripts/send_echomail_digest.php
```

Test a specific user without sending:

```bash
php scripts/send_echomail_digest.php --dry-run --verbose --user=1
```

A database migration (`v1.11.0.31`) adds `echomail_digest` and
`echomail_digest_last_sent` to `user_settings` and is applied automatically by
`setup.php`.

## Shared File Preview for Unauthenticated Visitors

Shared file links (`/shared/file/{area}/{filename}`) previously displayed file
metadata but required the visitor to be logged in to see any preview. The inline
preview (images, video, audio, text/NFO, ANSI art, PETSCII/PRG) now works for
unauthenticated visitors who arrive via a valid, non-expired share link.

This covers all preview types, including:

- **PETSCII / PRG rendering** — the `/api/files/{id}/prgs` endpoint now accepts
  `share_area` and `share_filename` query parameters. When the share is valid
  and the file ID matches, the request is served without requiring a session.
- **Run on C64 emulator** — the C64 emulator iframe (`c64emu/index.html`) reads
  the share parameters from its own URL and forwards them to the `/prgs` fetch,
  so the emulator loads correctly for unauthenticated visitors too.

No configuration or migration is required.

## Telnet and SSH File Area Fixes

### Virtual Subfolder Navigation

The Telnet file area browser now supports the same virtual subfolder structure
as the web interface. Users navigating the file area over Telnet can enter
subfolders (e.g. *Netmail Attachments*, *FREQ Responses*, ISO subdirectories)
and return to the parent level, matching the web experience.

### Pagination Fix

The Telnet file area listing was previously capped at a single page of results,
meaning only the first N files in an area were shown. The listing now iterates
all pages and displays every file in the area.

### ISO File Download via ZMODEM

Files stored in ISO-backed file areas can now be downloaded over Telnet using
ZMODEM. Previously ZMODEM transfers from ISO areas were blocked because the
Telnet server did not resolve the physical path for ISO-backed files. The
transfer path is now resolved correctly before initiating the ZMODEM session.

### SSH User Identification

The SSH server now correctly associates the session with the authenticated user's
account. This fixes a regression where SSH sessions appeared as anonymous in
session logs despite the user having logged in successfully.

## Admin Menu Reorganization

The admin navigation dropdown has been reorganized to reduce clutter and group
related items into logical submenus.

### Analytics Submenu

A new **Analytics** submenu consolidates reporting pages:

| Page | Requires License |
|---|---|
| Activity Statistics | No |
| Sharing | No |
| Economy Viewer | Yes |
| Referral Analytics | Yes |

License-gated items remain visible in both the nav menu and the admin dashboard
but are non-functional — they link to `#`, show a lock icon, and carry a
`title` attribute reading *Registered Feature*.

### Community Submenu

A new **Community** submenu groups interactive community features:

- BBS Directory
- Chat (sub-submenu: Chat Rooms, MRC Settings)
- Polls
- Shoutbox

### Ads Top-Level Menu

Advertising management now lives under a separate **Ads** top-level admin menu:

- Advertisements
- Ad Campaigns

### Area Management Submenu Changes

**Auto Feed** has been moved inside the **Area Management** submenu (below a
divider), since it is directly related to echo area configuration. It was
previously a top-level item in the Admin dropdown.

### Chat Submenu Renamed

The admin submenu previously labelled *Local Chat* has been renamed to **Chat**,
since it contains configuration for both local chat rooms and remote MRC (Multi
Relay Chat) settings.

## Advertising System

The old flat-file `bbs_ads/` ad store has been replaced by a database-backed
advertising library.

### Migration

Running `php scripts/setup.php` applies the advertising migrations and imports
existing ANSI ads from `bbs_ads/`. Imported ads are marked enabled by default
so current dashboard inventory remains available after upgrade.

Existing flat-file ads from the legacy `bbs_ads/` directory are migrated into
the database automatically on upgrade, so you don't lose content switching from
the old system.

### Advertisements

A new admin page at **Admin -> Ads -> Advertisements** lets the sysop:

- upload ANSI ads into the library
- edit ANSI content and metadata in the browser
- preview ads in a modal
- tag ads with freeform labels
- choose whether an ad is eligible for dashboard display and/or campaign posting

### Campaigns

A new admin page at **Admin -> Ads -> Ad Campaigns** manages scheduled ad
posting.

Campaigns support:

- multiple targets (`echoarea@domain`)
- separate subject templates per target
- multiple schedules by day, time, and timezone
- weighted ad assignment
- post history for both manual and automatic runs

### Scheduler

`scripts/binkp_scheduler.php` now processes due advertising campaigns
automatically. For testing or manual execution, use:

```bash
php scripts/run_ad_campaigns.php
php scripts/run_ad_campaigns.php --campaign-id=3
php scripts/run_ad_campaigns.php --dry-run
```

For full operational details, see [docs/Advertising.md](Advertising.md).

## Echo List Network Filter

The `/echolist` page now includes a **network filter** dropdown in the Filter
card alongside the existing subscribed-only, unread-only, and text search
controls.

Clicking the dropdown shows a checkbox list of all networks present in your echo
area list, including **Local** areas. Check one or more networks to restrict the
listing to those networks. Unchecking everything (the default) shows all
networks.

The filter composes with the other controls — for example, you can show only
unread messages in FidoNet areas simultaneously.

No configuration or migration is required.

## LovlyNet Subscriptions

A new admin page at **Admin → Area Management → LovlyNet Areas**
(`/admin/lovlynet`) provides a central view of all echo and file areas
available on the LovlyNet hub, with one-click subscribe and unsubscribe
controls.

### Features

- **Echo Areas** and **File Areas** tabs, each listing every area published by
  the LovlyNet hub.
- Each row shows the area tag, description, current subscription status
  (Subscribed / Not subscribed), and a toggle button.
- A summary card at the top shows how many areas you are currently subscribed to.
- The page reads credentials from `config/lovlynet.json`, which is written
  automatically when your node is registered with LovlyNet.

### Requirements

`config/lovlynet.json` must exist and contain valid credentials:

```json
{
    "api_key": "...",
    "ftn_address": "227:1/N",
    "hub_hostname": "lovlynet.lovelybits.org"
}
```

These values are provided when your node registers with LovlyNet. If the file
is missing or incomplete, the page shows a configuration notice instead of the
area list.

No database migration is required for this feature.

### TIC File Password Field

TIC password handling now uses this precedence when generating outbound TIC
files:

1. file area TIC password
2. uplink TIC password
3. blank `Pw` value if neither is configured

For LovlyNet systems, this upgrade also backfills the uplink TIC password from
the configured Areafix password by taking the first 8 characters and converting
them to uppercase. The derived value is written to both `config/lovlynet.json`
and the LovlyNet uplink entry in `config/binkp.json`.

## Public File Areas

Individual file areas can now be flagged as **public**, allowing unauthenticated
visitors to browse the file listing and download files without a BBS account.
This is a registered (premium) feature.

### What guests can do on a public area

- Browse the file listing (filename, description, size, date)
- Download files
- Open the file preview modal (images, text/NFO, ANSI, ZIP browser, etc.)

### What guests cannot do

- Post or view file comments
- Upload files
- Access any area that is not flagged public

### Enabling public access on an area

In **Admin → Area Management → File Areas**, edit the area and check the
**Public** checkbox. The checkbox is only shown on registered installations;
on unregistered installations a lock notice appears in its place.

### Public file area index page

A new BBS setting **Enable Public Files Index** (Admin → BBS Settings → BBS
Features) controls whether an index page at `/public-files` is available.
When enabled, the index lists all public areas in a card grid and a
**Public Files** link appears in the navigation bar for guests.

When disabled (the default), public areas are still accessible via their
direct URL (`/files/AREATAG`) — they simply are not listed on a discovery page.

### Database migration

Migration `v1.11.0.33_public_file_areas.sql` adds an `is_public` boolean
column to the `file_areas` table. Run `php scripts/setup.php` to apply it.

## BinkP Status Page Improvements

### Log Search

The Logs tab now has an inline search box. Type any term to filter the
currently-loaded log lines in real time. Non-matching lines are hidden;
matching lines remain visible with the search term highlighted. A match
count (`N / M lines`) is shown while a filter is active. Click the × button
or clear the box to restore the full view.

### Advanced Log Search

A new **Advanced Search** panel (toggle button below the log view) searches
the **entire** log across all BinkP-related log files — `binkp_poll.log`,
`binkp_server.log`, `binkp_scheduler.log`, `admin_daemon.log`,
`mrc_daemon.log`, and `packets.log` — regardless of the line-count selector.

When a match is found, the search automatically expands the results to include
**all lines from the same PID** (i.e. the full session that produced the
match). Direct matches are shown at full brightness; session-context lines are
dimmed. The header shows a summary such as `4 matches across 2 session(s)`.

### Kept Packets Viewer

> **Registered feature** — requires a valid license.

A new **Kept Packets** tab appears in the BinkP status page for registered
installations. It lets you browse preserved packets in `data/inbound/keep/`
and `data/outbound/keep/` without needing shell access.

Packets are grouped by date directory (newest first). Each packet row shows:

| Column | Description |
|--------|-------------|
| Filename | Clickable — opens the packet inspector |
| From | Originating FTN address (from packet header) |
| To | Destination FTN address (from packet header) |
| Messages | Number of messages parsed from the packet |
| Size | File size |
| Date | Last-modified timestamp |

**Packet Inspector** — clicking a filename opens a modal with:

- Full packet header: from/to FTN addresses (with zone and point), creation
  timestamp, packet version, product code, and whether a password is set
- A table of every message header in the packet: from/to names, net:node
  addresses, subject, date string, and decoded attribute flags (Pvt, Crash,
  Att, Local, etc.)

Message body text is never shown or transmitted to the browser.

### Faster Poll Session Termination

BinkP poll sessions now terminate immediately once both sides have exchanged
`M_EOB` and no file transfer is active. Previously the mailer waited up to 30
seconds for a same-session response file that never materialises in practice —
response packets (e.g. areafix replies) always arrive in a subsequent poll.
This change removes the unnecessary wait from every outbound poll.

## QWK/QWKE Offline Mail

BinktermPHP now supports **QWK offline mail** — the classic BBS offline mail
exchange format popular since 1987. Users can download a QWK packet containing
all new messages from their personal mail (netmail) and every subscribed echo
area, take it offline to read and reply in a local mail reader, then upload the
resulting REP reply packet to post their replies.

### Supported Formats

| Format | Description |
|--------|-------------|
| **QWK** | Standard QWK format; compatible with most offline readers (MultiMail, OLX, Yarn, etc.) |
| **QWKE** | QWK Extended; backward-compatible with QWK readers, adds full FidoNet metadata (MSGID, REPLY, address kludges) for readers that support threading and netmail addressing (MultiMail, NeoQWK, Synchronet, etc.) |

### How to Use

1. Navigate to **QWK Offline Mail** in the web interface sidebar.
2. Select your preferred format (QWK or QWKE).
3. Click **Download .QWK** — the packet is built on demand and downloads
   immediately. The conference list on the right refreshes after each download
   to show how many new messages remain.
4. Open the packet in your offline reader, read messages, and compose replies.
5. Export the reply packet (`.REP` or `.ZIP`) from your reader.
6. Return to the **QWK Offline Mail** page and upload the REP packet.
   Replies are posted to the appropriate echo areas and your outbound netmail
   queue automatically.

### Database Migration

Migration `v1.11.0.34_qwk_support.sql` creates two new tables:

- **`qwk_conference_state`** — tracks the highest message ID seen per user per
  conference so successive downloads include only new messages.
- **`qwk_download_log`** — records every download with a conference-number map
  used to reverse-map conference numbers when a REP upload arrives.

This migration is applied automatically by `php scripts/setup.php`.

### Recommended Readers

- **MultiMail** (cross-platform, Windows/Linux/macOS) — full QWK and QWKE support
- **OLX** / **Yarn** — good QWK compatibility
- **NeoQWK** / **Synchronet** — recommended for QWKE extended format

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

```bash
wget https://raw.githubusercontent.com/awehttam/binkterm-php-installer/main/binkterm-installer.phar
php binkterm-installer.phar
scripts/restart_daemons.sh
```

### After Upgrading: Clear Browser Cache

After deploying this update, users upgrading from **1.8.5 or earlier** may see a
blank loading page, loading indicators that don't clear, or other malfunctions
on their first page load. This happens because the browser's service worker is
still serving an older cached copy of `app.js` that pre-dates the i18n system.

**What users should do:**

- Press **Ctrl+Shift+R** (Windows/Linux) or **Cmd+Shift+R** (Mac) to perform a
  hard refresh, bypassing the service worker cache.
- On mobile or if a hard refresh doesn't help, see **[FAQ: The page looks broken after an upgrade](../FAQ.md#q-the-page-looks-broken-after-an-upgrade--missing-features-broken-menus-or-loadi18nnamespaces-is-not-defined-errors)** for full browser-by-browser instructions including mobile.

After the first hard refresh the service worker will update automatically and
subsequent loads will work normally without any manual intervention.
