# Upgrading to 1.8.7

⚠️ Make sure you've made a backup of your database and files before upgrading.

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
- [Gemini File Areas](#gemini-file-areas)
- [FREQ Enhancements](#freq-enhancements)
- [Nodelist Enhancements](#nodelist-enhancements)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

- FREQ response delivery now uses crashmail (direct) as the primary path and a
  per-node hold directory (`data/outbound/hold/<address>/`) as the fallback.
  Routed FILE_ATTACH (which hubs strip) is no longer used. Hold files are
  delivered at the next binkp session regardless of which side initiates.
- New `scripts/freq_pickup.php` — lets you connect outbound to a node to
  collect FREQ files they have staged for you.
- BinkP now advertises the closest network AKA when connecting to a node that
  is not a configured uplink, ensuring the remote system identifies you by the
  correct address.
- File area browser shows **Gemini** and **FREQ** capability badges next to
  each area name, along with the area description.
- Nodelist node viewer includes an **ALLFILES FREQ** button to request a file
  listing from any node that advertises the FREQ flag. A warning is shown for
  nodes without the flag.
- Nodelist search supports a multi-select flag filter to narrow results by
  nodelist flags (CM, IBN, INA, FREQ, etc.).

- Added advanced message search with per-field filtering (poster name, subject,
  message body) and date range support for both echomail and netmail.
- Search performance significantly improved via trigram GIN indexes on subject
  and message body columns.
- Sysops can now edit artwork encoding metadata on any echomail message directly
  from the message reader — no more manual SQL updates for misdetected art format
  or encoding.
- Netmail senders and receivers can similarly correct artwork encoding on their
  own messages.
- Fixed a false-positive PETSCII detection bug on import.
- New admin **Database Statistics** page (`/admin/database-stats`) showing size
  and growth, activity metrics, query performance, replication status, maintenance
  health, and index health.
- Added configurable file upload and file download credit costs/rewards in the
  **Credits System Configuration** page.
- Database performance improvements: new indexes on `mrc_outbound`, `users`,
  `shared_messages`, and `saved_messages` eliminate millions of unnecessary
  sequential scans. Chat notification polling rewritten to use primary key
  index instead of full table count.
- Kludge lines in the echomail and netmail message readers are now hidden by
  default and toggled via a small icon button in the modal header toolbar.
- A print button in the message reader opens the message in a clean popup
  window for printing, with no modal chrome or page background.
- New interactive nodelist map tab powered by Leaflet. Nodes are geocoded from
  their location field and grouped by system name, with zone colour coding and
  per-network popup detail. A CLI geocoding script (`scripts/geocode_nodelist.php`)
  can be run manually or via cron to populate coordinates.
- File areas can now be published to the Gemini capsule server. Gemini clients
  can browse area listings and download files directly over the Gemini protocol.

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

1. Go to **Admin → File Areas**
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

### FREQ File Pickup Script

A new CLI script lets you connect outbound to a remote system to collect FREQ
files they have staged for you (the "reverse crash" case):

```bash
php scripts/freq_pickup.php 1:123/456
php scripts/freq_pickup.php 1:123/456 --hostname=bbs.example.com
php scripts/freq_pickup.php 1:123/456 --hostname=bbs.example.com --port=24554 --password=secret
```

The script resolves the hostname from the nodelist if omitted, advertises your
correct network AKA, and also sends any outbound packets queued for that node.
See [CLI.md](CLI.md#freq-file-pickup) for full option reference.

### BinkP AKA Selection Fix

When connecting to a node that is not a configured uplink (for example, via
`freq_pickup.php`), BinktermPHP now selects the uplink whose network covers the
destination address and advertises that uplink's `me` address in `M_ADR`. This
ensures the remote system identifies you by the same AKA used in your original
FREQ request rather than your primary zone address.

## Nodelist Enhancements

### Flag Filter

The nodelist search page now includes a **multi-select flag filter**. Select one
or more flags (CM, IBN, INA, FREQ, MO, etc.) to narrow the results to nodes
that carry all of the chosen flags.

### ALLFILES FREQ Modal

The nodelist node detail view now includes a **Request ALLFILES** button. This
sends an ALLFILES FREQ to the selected node and allows you to download their
file listing in one click. A warning is shown if the node does not advertise the
FREQ flag in its nodelist entry.

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
