# Upgrading to 1.7.5

This release addresses issues with message threading, file area support, binkp and various fixes and enhancements.

 * Memory optimizations to prevent excessive memory usage on large echoso
 * Threading now uses new parent message id column in database
 * Introduction of file areas and inbound file processing.  Outbound is untested.  Support for virus scanning (untested)
 * Echo Area manager now uses a color palette rather than a full spectrum color picker for color coding
 * The Admin dashboard now displays which Git branch is in use (alongside git commit #)
 * Fix an issue with binkp M_GOT sending an incorrect timestamp that would cause the remote sender to re-send the message
 * Added Forum List view - a phpBB-style echo area browser that groups forums by network (Local, FidoNet, etc.)
 * Users can set their preferred default view (Reader or Forum List) in Settings → Display Preferences
 * Forum List is accessible via the list icon in the echo areas sidebar or directly at /echolist
 * Echo area lists now include search functionality to filter by tag or description 
 * Echo area message search results now update the message counts to be relevant to the search results
 * Fixes for binkp transmissions where the remote side would not receive our EOB properly
 * Fixes for binkp packet creation where packets would be malformed
 * Miscellaneous fixes and enhancements

## Table of Contents

- [Before You Upgrade](#before-you-upgrade)
- [Database Migration](#database-migration)
- [Backfill Script (Required for Threading)](#backfill-script-required-for-threading)
  - [Backfill Options](#backfill-options)
  - [Example](#example)
  - [Expected Output](#expected-output)
- [After You Upgrade](#after-you-upgrade)
- [Breaking Changes](#breaking-changes)
- [Troubleshooting](#troubleshooting)

## Before You Upgrade
- Make a backup of your database
- If you have large echoareas (thousands of messages), this upgrade will significantly improve performance

## Database Migration
- Run the upgrade script to apply migrations:
  - `php scripts/upgrade.php`

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
