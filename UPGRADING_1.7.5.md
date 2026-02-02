# Upgrading to 1.7.5

This release includes major performance improvements for message threading, file area support, and various fixes and enhancements.

## Before You Upgrade
- Make a backup of your database
- If you have large echoareas (thousands of messages), this upgrade will significantly improve performance

## Database Migration
- Run the upgrade script to apply migrations:
  - `php scripts/upgrade.php`
- This version includes migration `database/migrations/v1.7.5_echoarea_is_local.sql`
  - Adds `echoareas.is_local` flag for local-only message areas that are not transmitted to uplinks

## Backfill Script (Required for Threading)

**IMPORTANT:** This version refactors message threading to use database relationships instead of text parsing. For existing messages to display proper threading, you must run the backfill script once:

```bash
php scripts/backfill_reply_to_id.php
```

### Backfill Options

- `--dry-run` - Preview changes without modifying data
- `--limit=N` - Process only N messages per type (for testing)
- `--echomail` - Process only echomail
- `--netmail` - Process only netmail

### Example

Test first:
```bash
php scripts/backfill_reply_to_id.php --dry-run --limit=100
```

Then run full backfill:
```bash
php scripts/backfill_reply_to_id.php
```

### Expected Output

```
=== Reply-To-ID Backfill Script ===
Processing echomail messages...
Found 5432 echomail messages to process

Echomail Results:
  Updated: 4521
  Parent not found: 823
  No REPLY kludge: 88
```

**Notes:**
- "Parent not found" is normal - parent message doesn't exist in database
- "No REPLY kludge" is normal - message is not a reply
- Safe to run multiple times
- New messages automatically have threading data populated

## What's New

### File Areas
- Added file area support for uploading and downloading files
- Integrated with credit system
- TIC file processing for Fidonet file distribution
- ClamAV virus scanning support
- Upload permission controls
- Admin interface for managing file areas

### Threading Performance & Memory Optimization
- **Fixed:** Memory exhaustion (256MB+) on large echoareas
- **Fixed:** Broken pagination in threaded views
- **Improved:** Threading now uses indexed database relationships instead of text parsing
- **Improved:** Significantly faster query performance

### Local-Only Echo Areas
- Echo areas can be marked as `is_local` to prevent transmission to uplinks
- Useful for local discussion areas

### Bug Fixes & Enhancements
- Fixed netmail query schema mismatches
- Fixed memory usage in message list views
- Fixed undefined message_id warnings in threading
- Added support for 4-part version numbers in database migrations
- Various UI improvements and bug fixes

## After You Upgrade

1. Run the backfill script (see above)
2. Verify threading works:
   - Navigate to an echoarea and enable threaded view
   - Check messages are properly nested
   - Verify pagination shows correct page numbers
3. Monitor performance - large echoareas should load significantly faster

## Breaking Changes

None. This version is fully backward compatible.

## Troubleshooting

**Threading not working:**
- Verify you ran the backfill script
- Check browser console for JavaScript errors
- Clear browser cache

**Memory issues persist:**
- Verify upgrade completed successfully
- Check PHP memory_limit: `php -i | grep memory_limit`
- Review error logs in `data/logs/`
