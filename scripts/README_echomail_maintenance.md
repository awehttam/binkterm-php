# Echomail Maintenance Script

## Overview

The `echomail_maintenance.php` script helps manage echomail message storage by automatically purging old messages based on age or message count limits. This is useful for:

- Preventing database bloat from high-volume echoes
- Maintaining consistent storage requirements
- Keeping only relevant, recent messages
- Running automated cleanup via cron jobs

## Features

- **Age-based deletion**: Remove messages older than a specified number of days
- **Count-based deletion**: Keep only the newest N messages per echo area
- **Per-echo processing**: Target specific echo areas or all at once
- **Dry-run mode**: Preview what would be deleted before making changes
- **Quiet mode**: Run silently for cron jobs (errors only)
- **Safe operation**: Updates message counts automatically after deletion

## Usage

### Basic Syntax

```bash
php scripts/echomail_maintenance.php --echo=TAG --max-age=DAYS [options]
php scripts/echomail_maintenance.php --echo=TAG --max-count=NUM [options]
php scripts/echomail_maintenance.php --echo=TAG --max-age=DAYS --max-count=NUM [options]
```

### Required Parameters

- `--echo=TAG` - Echo area tag name (e.g., `COOKING`, `FIDONET.NA`) or use `all` for all echo areas

### Deletion Criteria (at least one required)

- `--max-age=DAYS` - Delete messages older than this many days
- `--max-count=NUM` - Keep only the newest NUM messages per echo area

Both parameters can be combined. When both are specified:
1. Age-based deletion runs first
2. Count-based deletion runs on remaining messages

### Optional Parameters

- `--dry-run` - Preview deletions without making changes
- `--quiet` - Suppress output except errors (useful for cron jobs)
- `--help` - Display help message

## Examples

### Delete Old Messages from a Single Echo

Delete messages older than 90 days from the COOKING echo:

```bash
php scripts/echomail_maintenance.php --echo=COOKING --max-age=90
```

### Limit Message Count Across All Echoes

Keep only the 500 newest messages in each echo area:

```bash
php scripts/echomail_maintenance.php --echo=all --max-count=500
```

### Combined Age and Count Limits

Delete messages older than 180 days, but also ensure each echo has no more than 1000 messages:

```bash
php scripts/echomail_maintenance.php --echo=all --max-age=180 --max-count=1000
```

### Preview Changes (Dry Run)

See what would be deleted without actually deleting:

```bash
php scripts/echomail_maintenance.php --echo=all --max-age=365 --dry-run
```

### Quiet Mode for Cron Jobs

Run silently (only shows errors):

```bash
php scripts/echomail_maintenance.php --echo=all --max-age=90 --max-count=2000 --quiet
```

## Sample Output

### Normal Mode

```
========================================
Echomail Maintenance Utility
========================================

Processing 16 echo area(s)

Processing: COOKING
  Current messages: 2181
  Deleted by age (>90 days): 364
  Deleted by count (keep 1000): 817
  New message count: 1000
  ✓ Deleted 1181 message(s)

Processing: SYNCDATA
  Current messages: 4441
  Deleted by age (>90 days): 535
  Deleted by count (keep 2000): 1906
  New message count: 2000
  ✓ Deleted 2441 message(s)

...

========================================
Summary
========================================
Total messages deleted: 5832

✓ Maintenance completed successfully
```

### Dry-Run Mode

```
========================================
Echomail Maintenance Utility
========================================

*** DRY RUN MODE - No changes will be made ***

Processing 1 echo area(s)

Processing: COOKING
  Current messages: 2181
  Deleted by age (>90 days): 364
  Deleted by count (keep 1000): 817
  ✓ Would delete 1181 message(s)

========================================
Summary
========================================
Total messages would be deleted: 1181

Run without --dry-run to actually delete messages.
```

## Scheduling with Cron

### Daily Cleanup

Delete messages older than 90 days every night at 2 AM:

```cron
0 2 * * * cd /path/to/binktest && php scripts/echomail_maintenance.php --echo=all --max-age=90 --quiet
```

### Weekly Cleanup with Count Limit

Every Sunday at 3 AM, keep only 5000 messages per echo:

```cron
0 3 * * 0 cd /path/to/binktest && php scripts/echomail_maintenance.php --echo=all --max-count=5000 --quiet
```

### Monthly Aggressive Cleanup

First day of each month, delete messages older than 180 days and limit to 2000 per echo:

```cron
0 4 1 * * cd /path/to/binktest && php scripts/echomail_maintenance.php --echo=all --max-age=180 --max-count=2000 --quiet
```

## How It Works

### Age-Based Deletion

- Uses the `date_received` timestamp field (stored in UTC)
- Calculates cutoff date as current date minus specified days
- Deletes all messages with `date_received < cutoff_date`
- Indexed for performance

### Count-Based Deletion

- Counts current messages in the echo area
- If count exceeds `max-count`, calculates how many to delete
- Deletes oldest messages first (by `date_received` and `id`)
- Keeps the newest N messages

### Combined Operation

When both parameters are specified:

1. **Age deletion** runs first and removes old messages
2. **Count deletion** runs on remaining messages
3. Ensures both criteria are satisfied in correct order

Example: `--max-age=90 --max-count=1000`
- If echo has 2000 messages, 500 are older than 90 days
- Delete 500 by age → 1500 remain
- Delete 500 more by count → 1000 remain (newest)

### Database Updates

After deletions, the script automatically:
- Updates `echoareas.message_count` to reflect new totals
- Maintains referential integrity (no orphaned records)
- Logs operations for auditing

## Technical Details

### Database Tables Affected

- **echomail**: Messages are deleted from this table
- **echoareas**: `message_count` field is updated

### Performance Considerations

- Indexed on `date_received` for fast age-based queries
- Uses `LIMIT` with `ORDER BY` for count-based deletions
- Transaction-safe (all-or-nothing deletions)
- Dry-run mode doesn't lock tables

### Exit Codes

- `0` - Success
- `1` - Error (invalid parameters, database error, echo not found)

## Recommendations

### Conservative Approach

Start with dry-run mode and generous limits:

```bash
# Safe starting point
php scripts/echomail_maintenance.php --echo=all --max-age=365 --dry-run
```

### For High-Volume Echoes

Very active echoes like SYNCDATA or WEATHER may need more aggressive limits:

```bash
# Keep only recent messages in high-volume echoes
php scripts/echomail_maintenance.php --echo=SYNCDATA --max-age=60 --max-count=2000
php scripts/echomail_maintenance.php --echo=WEATHER --max-age=30 --max-count=1000
```

### For Low-Volume Echoes

Preserve history in low-traffic echoes:

```bash
# Keep more history for discussion echoes
php scripts/echomail_maintenance.php --echo=COOKING --max-age=180
php scripts/echomail_maintenance.php --echo=HOROSCOPE --max-age=90
```

## Backup Recommendation

**Always** back up your database before running large deletions:

```bash
# Backup first
php scripts/backup_database.php

# Then run maintenance
php scripts/echomail_maintenance.php --echo=all --max-age=90 --max-count=2000
```

## Troubleshooting

### Script Won't Run

- Verify PHP is in your PATH: `php --version`
- Check file permissions: `chmod +x scripts/echomail_maintenance.php`
- Ensure database connection works: `php scripts/binkp_status.php`

### Echo Area Not Found

- Check the exact tag name: `psql -d binkterm -c "SELECT tag FROM echoareas;"`
- Tags are case-sensitive
- Use `--echo=all` to process all areas

### No Messages Deleted

- Verify messages exist: `psql -d binkterm -c "SELECT COUNT(*) FROM echomail WHERE echoarea_id = X;"`
- Check date range: All messages might be newer than `max-age`
- Ensure count limit is less than current message count

## Related Scripts

- `backup_database.php` - Backup database before maintenance
- `binkp_status.php` - Check current message counts
- `upgrade.php` - Database migrations and schema updates

## Version History

- **v1.0** (2025-01-07) - Initial release
  - Age-based deletion
  - Count-based deletion
  - Dry-run mode
  - Per-echo and all-echoes support
