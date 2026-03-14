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
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

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
