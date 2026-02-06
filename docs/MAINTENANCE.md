# Database Maintenance Guide

BinktermPHP includes a comprehensive database maintenance script that performs routine cleanup tasks to keep your database healthy and performant.

## Maintenance Script

**Location:** `scripts/database_maintenance.php`

### What It Does

The maintenance script performs the following cleanup operations:

1. **Registration Attempts** - Removes attempts older than 30 days
2. **Soft-Deleted Netmail** - Permanently deletes netmail deleted by both sender and recipient
3. **Password Reset Tokens** - Removes expired tokens older than 24 hours
4. **Gateway Tokens** - Removes expired or used gateway authentication tokens
5. **Webshare Links** - Removes expired share links
6. **Rejected Pending Users** - Removes rejected applications older than 90 days
7. **Login Attempts** - Removes login attempts older than 30 days (if table exists)
8. **Database Vacuum** - Runs PostgreSQL VACUUM and ANALYZE on key tables

### Usage

```bash
# Run maintenance with default settings
php scripts/database_maintenance.php

# Run with verbose output
php scripts/database_maintenance.php --verbose

# Dry run - see what would be cleaned without making changes
php scripts/database_maintenance.php --dry-run

# Verbose dry run
php scripts/database_maintenance.php --verbose --dry-run
```

### Output Example

```
Database Maintenance Script
Started: 2025-01-15 03:00:00
============================================================

[1] Cleaning old registration attempts...
    Deleted 47 old registration attempts

[2] Cleaning soft-deleted netmail...
    Permanently deleted 12 netmail messages

[3] Cleaning expired password reset tokens...
    Deleted 3 expired password reset tokens

[4] Cleaning expired gateway tokens...
    Deleted 8 expired/used gateway tokens

[5] Cleaning expired webshare links...
    Deleted 2 expired webshare links

[6] Cleaning old rejected pending users...
    Deleted 1 old rejected pending users

[7] Cleaning old login attempts...
    Table 'login_attempts' does not exist, skipping

[8] Running VACUUM and ANALYZE...
    Database vacuum and analyze completed

============================================================
Maintenance completed: 2025-01-15 03:01:23
Total records cleaned: 73
```

## Automated Maintenance with Cron

It's recommended to run the maintenance script automatically using cron.

### Suggested Cron Schedule

Add to your crontab (`crontab -e`):

```bash
# Run database maintenance daily at 3 AM
0 3 * * * cd /path/to/binkterm-php && php scripts/database_maintenance.php >> data/logs/maintenance.log 2>&1

# Or run weekly on Sunday at 2 AM
0 2 * * 0 cd /path/to/binkterm-php && php scripts/database_maintenance.php >> data/logs/maintenance.log 2>&1
```

### Windows Task Scheduler

For Windows servers, create a scheduled task:

```batch
Program: php.exe
Arguments: C:\path\to\binkterm-php\scripts\database_maintenance.php
Start in: C:\path\to\binkterm-php
Schedule: Daily at 3:00 AM
```

## Retention Periods

The script uses the following retention periods:

| Data Type | Retention Period | Reasoning |
|-----------|------------------|-----------|
| Registration attempts | 30 days | Anti-spam tracking, audit trail |
| Soft-deleted netmail | Immediate* | Once both parties delete, no need to keep |
| Password reset tokens | 24 hours | Security best practice |
| Gateway tokens | Immediate** | One-time use or expired |
| Webshare links | Per link expiry | User-configurable expiration |
| Rejected pending users | 90 days | Audit trail for rejections |
| Login attempts | 30 days | Security monitoring |

\* Only deleted when both sender AND recipient have marked as deleted
\** Deleted when expired or used

## Customizing Retention Periods

To customize retention periods, edit `scripts/database_maintenance.php` and modify the INTERVAL values:

```php
// Change from 30 days to 60 days
WHERE attempt_time < NOW() - INTERVAL '30 days'
// Becomes:
WHERE attempt_time < NOW() - INTERVAL '60 days'
```

## Monitoring

### Checking Maintenance Logs

If you're logging output to a file:

```bash
# View recent maintenance runs
tail -n 100 data/logs/maintenance.log

# Watch maintenance in real-time
tail -f data/logs/maintenance.log
```

### Manual Inspection

Check table sizes before and after maintenance:

```sql
-- Check registration attempts
SELECT COUNT(*) FROM registration_attempts;

-- Check soft-deleted netmail
SELECT COUNT(*) FROM netmail
WHERE deleted_by_sender = TRUE AND deleted_by_recipient = TRUE;

-- Check database size
SELECT pg_size_pretty(pg_database_size(current_database()));
```

## Troubleshooting

### Script Fails with Permission Error

Ensure the script has execute permissions:
```bash
chmod +x scripts/database_maintenance.php
```

### Database Connection Failed

Verify your `.env` file has correct database credentials and the database server is running.

### "Table does not exist" Warnings

Some tables are optional or from newer versions. The script will skip tables that don't exist - this is normal.

### VACUUM Errors

If VACUUM fails, ensure:
- You're not running multiple maintenance scripts simultaneously
- No long-running queries are blocking the vacuum
- You have sufficient disk space

## Performance Considerations

### When to Run

- **Low-traffic hours** - 2-4 AM in your timezone
- **Not during backup windows** - Avoid conflicts with database backups
- **Before packet processing** - Run before heavy binkp activity if possible

### Frequency Recommendations

| Installation Size | Recommended Frequency |
|-------------------|----------------------|
| Small (< 100 users) | Weekly |
| Medium (100-500 users) | Every 3 days |
| Large (500+ users) | Daily |
| Very active BBS | Twice daily |

### Impact

- **Disk I/O** - VACUUM operations can be I/O intensive
- **Locking** - Some operations acquire brief locks on tables
- **Duration** - Typically completes in under 1 minute for small-medium installations

## Best Practices

1. **Always test first** - Run with `--dry-run` before setting up automated runs
2. **Monitor logs** - Check maintenance logs regularly for errors
3. **Schedule appropriately** - Run during low-activity periods
4. **Backup first** - Ensure you have recent backups before bulk deletions
5. **Adjust as needed** - Tune retention periods based on your requirements

## Related Documentation

- [README.md](../README.md) - Main documentation
- [FAQ.md](FAQ.md) - Frequently asked questions
- [Upgrading Guide](UPGRADING_*.md) - Version-specific upgrade instructions
