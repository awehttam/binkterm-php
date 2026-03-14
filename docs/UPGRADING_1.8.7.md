# Upgrading to 1.8.7

⚠️ Make sure you've made a backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Message Artwork Encoding Editor](#message-artwork-encoding-editor)
- [Echomail Art Format Detection](#echomail-art-format-detection)
  - [Existing Misdetected Messages](#existing-misdetected-messages)
  - [psql Instructions](#psql-instructions)
  - [Notes](#notes)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

- Sysops can now edit artwork encoding metadata on any echomail message directly
  from the message reader — no more manual SQL updates for misdetected art format
  or encoding.
- Netmail senders and receivers can similarly correct artwork encoding on their
  own messages.
- Fixed a false-positive PETSCII detection bug on import.

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

- This release does not add a schema migration.
- Resetting these columns only affects rendering hints stored in the database.
- It does not alter the message body text itself.
- For individual messages the in-browser editor (see above) is easier and safer
  than direct SQL. Use the SQL approach for bulk resets or scripted corrections.

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
