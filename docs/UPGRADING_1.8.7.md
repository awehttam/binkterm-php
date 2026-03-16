# Upgrading to 1.8.7

⚠️ Make sure you've made a backup of your database and files before upgrading.

⏳ This upgrade rebuilds trigram indexes on the message tables. On large
message databases the migration step may take several minutes or more. The
upgrade will appear to pause — this is normal. Do not interrupt it.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Enhanced Message Search](#enhanced-message-search)
  - [Search Reindexing](#search-reindexing)
- [Message Artwork Encoding Editor](#message-artwork-encoding-editor)
- [Echomail Art Format Detection](#echomail-art-format-detection)
  - [Existing Misdetected Messages](#existing-misdetected-messages)
  - [psql Instructions](#psql-instructions)
  - [Notes](#notes)
- [Database Statistics Page](#database-statistics-page)
- [Credits System Updates](#credits-system-updates)
- [Database Performance Improvements](#database-performance-improvements)
- [Nodelist Map](#nodelist-map)
- [Message Reader Improvements](#message-reader-improvements)
- [Echomail Reader Navigation](#echomail-reader-navigation)
- [Gemini File Areas](#gemini-file-areas)
- [FREQ Enhancements](#freq-enhancements)
- [Nodelist Enhancements](#nodelist-enhancements)
- [Node Address Links](#node-address-links)
- [Outbound FREQ (File Request)](#outbound-freq-file-request)
- [Crashmail Logging and Packet Preservation](#crashmail-logging-and-packet-preservation)
- [File Area Subfolder Navigation](#file-area-subfolder-navigation)
- [File Preview](#file-preview)
- [ISO-Backed File Areas](#iso-backed-file-areas)
  - [Creating an ISO area](#creating-an-iso-area)
  - [Import preview](#import-preview)
  - [Catalogue formats](#catalogue-formats)
  - [Subfolder navigation](#subfolder-navigation)
  - [Importing files (CLI)](#importing-files-cli)
  - [Behaviour](#behaviour)
  - [Migration](#migration)
- [Global File Search](#global-file-search)
- [Page Position Memory](#page-position-memory)
- [Netmail Attachment Improvements](#netmail-attachment-improvements)
- [BinkP Inbound File Collision Handling](#binkp-inbound-file-collision-handling)
- [Bug Fixes](#bug-fixes)
  - [Maximized Message Reader Gap](#maximized-message-reader-gap)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

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

**Echomail**
- Advanced message search with per-field filtering (poster name, subject, body)
  and date range support.
- Search performance significantly improved via trigram GIN indexes.
- Sysops can edit artwork encoding metadata directly from the message reader.
- Fixed a false-positive PETSCII detection bug on import.

**Netmail**
- Netmail senders and receivers can correct artwork encoding on their own messages.
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
- Netmail attachment delivery now stores a copy in the sender's private area as
  well as the recipient's.
- Fixed: TIC file import with **Replace Existing Files** enabled was blocked by
  the duplicate content hash check when the incoming file had the same content as
  the file it was meant to replace.
- File areas can now be published to the Gemini capsule server. Gemini clients
  can browse area listings and download files directly over the Gemini protocol.
- **ISO-backed file areas** — a file area can now be backed by a CD/DVD ISO
  image (or any readable directory). The sysop mounts the ISO using any
  suitable method and enters the mount point path in the file area properties.
  Files are imported from the ISO's directory tree using `FILES.BBS`,
  `DESCRIPT.ION`, `00INDEX.TXT`, `00_INDEX.TXT` (Simtel block format), or
  `INDEX.TXT` catalogues. ISO areas are read-only; description edits are
  stored in the database. The directory tree is exposed as browsable subfolders
  with editable labels. A preview modal lets sysops review and customise the
  import before committing. ZIP files inside the ISO show `FILE_ID.DIZ` in the
  preview modal. Partial imports (selecting child directories without their
  parent) are supported; ancestor directories are created automatically so
  navigation remains intact.
- **Global file search** — a search box in the file browser sidebar searches
  filename and description across all accessible file areas. Results include
  file size, upload date, a file-info button, and a direct link to the area
  containing each result.


**Nodelist**
- New interactive map tab powered by Leaflet, with zone colour coding and marker
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

**Admin**
- New **Database Statistics** page (`/admin/database-stats`) showing size and
  growth, activity metrics, query performance, replication status, maintenance
  health, and index health.
- Configurable file upload and download credit costs/rewards in the **Credits
  System Configuration** page.
- Database performance improvements: new indexes on `mrc_outbound`, `users`,
  `shared_messages`, and `saved_messages`. Chat notification polling rewritten to
  use the primary key index instead of a full table count.

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

## Message Artwork Encoding Editor

The message reader now includes an **Edit** button (pencil icon) in the message
header toolbar. This lets you correct artwork rendering metadata that was
auto-detected incorrectly at import time, without touching the database manually.

**Who can use it:**
- **Echomail** — sysops (admin users) only.
- **Netmail** — the sender or receiver of the message.

**What you can change:**
- **Art Format** — override the detected artwork type (`Auto`, `Plain Text`,
  `ANSI`, `Amiga ANSI`, or `PETSCII / C64`). Setting it to `Auto` clears the
  stored override and lets the renderer decide.
- **Art Encoding** — the raw byte encoding used when rendering artwork
  (e.g. `CP437`, `PETSCII`, `UTF-8`). Leave blank for the default.

This is the **preferred way** to fix misdetected messages going forward. The SQL
approach below remains available for bulk corrections or when direct database
access is more convenient.

## Echomail Art Format Detection

- Fixed a false-positive PETSCII detection bug when importing echomail and
  netmail without a valid `CHRS` kludge.
- Previously, some non-UTF-8 messages containing arbitrary high-bit bytes could
  be incorrectly stored with:
  - `message_charset = null`
  - `art_format = petscii`
- This was most visible in file listing / file echo announcement messages whose
  body included 8-bit text from other character sets.
- PETSCII auto-detection is now more conservative. Messages are only tagged as
  PETSCII when the raw body has stronger PETSCII-like characteristics. Unknown
  8-bit text should now remain untagged instead of being misclassified.

### Existing Misdetected Messages

If you already imported messages that were incorrectly stored with
`art_format = 'petscii'`, upgrading the code will not change those existing
rows automatically.

If you want those messages to fall back to normal text rendering, reset the
stored metadata in PostgreSQL for the affected messages.

### psql Instructions

Start `psql` and connect to your BinktermPHP database:

```bash
psql -U your_db_user -d your_db_name
```

Preview the rows that currently look misdetected:

```sql
SELECT id, echoarea_id, subject, message_charset, art_format, date_written
FROM echomail
WHERE art_format = 'petscii'
  AND message_charset IS NULL
ORDER BY id;
```

If that result set matches what you want to fix, reset those columns:

```sql
UPDATE echomail
SET message_charset = NULL,
    art_format = NULL
WHERE art_format = 'petscii'
  AND message_charset IS NULL;
```

If you want to target only a specific message first, for example message
`39898`, do this instead:

```sql
UPDATE echomail
SET message_charset = NULL,
    art_format = NULL
WHERE id = 39898;
```

Check the result:

```sql
SELECT id, message_charset, art_format
FROM echomail
WHERE id = 39898;
```

Then exit `psql`:

```sql
\q
```

### Notes

- Resetting these columns only affects rendering hints stored in the database.
- It does not alter the message body text itself.
- For individual messages the in-browser editor (see above) is easier and safer
  than direct SQL. Use the SQL approach for bulk resets or scripted corrections.

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
- Markers are colour coded by zone (Z1 = blue, Z2 = green, Z3 = amber,
  Z4 = red, Z5 = purple, Z6 = teal). Systems on multiple zones use gold.
  A legend is displayed in the bottom-right corner of the map.
- Map data loads lazily — only fetched when the Map tab is first opened.

**Geocoding:**

Coordinates are populated by a new CLI script:

```bash
php scripts/geocode_nodelist.php
```

Options:
- `--limit=N` — process at most N nodes (default: 100 per run)
- `--force` — re-geocode nodes that already have coordinates
- `--dry-run` — show what would be geocoded without making any changes

The script calls the Nominatim geocoding API (rate-limited to one request per
second) and caches results permanently in the `geocode_cache`
table so the same location string is never looked up twice.

Run it once after upgrading to seed initial coordinates, then add it to cron
to pick up newly imported nodes:

```
0 3 * * * php /path/to/scripts/geocode_nodelist.php --limit=200
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

Subfolders are a view-only concept — no physical subdirectories are created.
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
| Text / NFO | txt, log, nfo, diz, md, cfg, ini, json, xml, … | Rendered in a scrollable panel; NFO and DIZ files are automatically converted from CP437 to UTF-8 so ANSI art displays correctly |
| Unknown | everything else | Download prompt with a Download button |

The modal header includes:
- **◀ ▶ navigation arrows** to move through the current file list without
  closing the modal. Left/right arrow keys work too.
- **ⓘ (File Info)** button to switch to the full file-details view for the
  current file.
- **⬇ (Download)** button to download the file at any time.

No configuration or migration is required for this feature.

## ISO-Backed File Areas

A file area can now be backed by a CD/DVD ISO image on the server, allowing
sysops to expose large shareware CD collections (Simtel, Walnut Creek, etc.)
without copying files to local storage. Plain directories work too — the
system only requires a readable path in the **Mount Point** field.

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

### Behaviour

- ISO areas are **read-only** — uploads and moves are blocked; admin file
  deletion removes the database record only (no disk change).
- Description edits (short/long description) are stored in the database and
  are always permitted.
- If the mount point is not accessible when a file is requested, the server
  returns 503.
- ZIP files inside the ISO display `FILE_ID.DIZ` in the preview modal.

### Migration

This feature requires database migrations. Run `php scripts/setup.php` as
part of the standard upgrade procedure.

## Global File Search

A **Search Files** card now appears in the file browser sidebar, between the
File Areas list and the Status panel. Typing two or more characters triggers a
server-side search across filename and short description for all accessible file
areas.

**Results table columns:** Filename (opens preview), Area (click to navigate
to that area), Description, Size, Uploaded date, Info button, Download button.

Results are limited to 100 entries ordered by area tag then filename.
Password-protected areas are excluded unless the session has them unlocked.

### Search indexes

Migration `v1.11.0.27` enables the `pg_trgm` PostgreSQL extension and creates
GIN trigram indexes on `files.filename` and `files.short_description`. These
indexes make `ILIKE '%term%'` queries fast regardless of how many files are in
the database.

**On large file databases the index build may take a minute or two.** The
upgrade will appear to pause at this migration step — this is normal. Do not
interrupt it.

## Page Position Memory

The echomail and netmail readers now remember the last page you were viewing
and restore it automatically when you return.

- **Echomail** — the last-visited page is remembered per echo area, including
  the **All Messages** view. Navigating to a different echo area and back
  restores the correct page for each.
- **Netmail** — the last-visited page of your inbox is remembered.

Positions are stored per-user in the database (`users_meta` table under the
keys `web_echomail_positions` and `web_netmail_page`) and persist across browser
sessions and devices. No migration is required.

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

## Bug Fixes

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
