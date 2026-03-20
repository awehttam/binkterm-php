# Echomail Digest

The echomail digest sends each opted-in user a periodic email summarising new
messages in their subscribed echo areas.  Instead of receiving individual
notifications, users get a single email at a chosen frequency that lists every
echo area that had activity, with the subject and author of each new message.
Full message bodies are not included — the digest is a prompt to log in and
read, not a replacement for the BBS.

This is a registered feature and requires a valid BinktermPHP license.

## Requirements

- Valid BinktermPHP license
- SMTP configured and enabled in `.env` (`SMTP_ENABLED=true`)
- User must have an email address set in their profile
- User must be subscribed to at least one active echo area
- User must opt in to digest emails (off by default)

## How It Works

The digest is driven by `scripts/send_echomail_digest.php`, which is designed
to run on a cron schedule.  Each run:

1. Finds all active users with digest set to `daily` or `weekly`
   and a non-empty email address.
2. For each user, checks whether enough time has passed since the last digest
   was sent (24 hours for daily, 7 days for weekly).  A user who has never
   received a digest is always due.
3. Queries new echomail received after `last_sent` across the user's active
   subscriptions.
4. If there are new messages, groups them by echo area (subject and author
   only) and sends the digest via PHPMailer.  The digest is capped at the
   **top 20 most active areas**, with up to **20 messages shown per area**.
   The lookback window is always the user's chosen frequency — 24 hours for
   daily, 7 days for weekly — even on the very first digest.
5. Updates `echomail_digest_last_sent` to the current time, even if there were
   no new messages, so the next check is deferred correctly.

## User Configuration

Users configure their digest frequency in **Settings → Notifications**:

| Option | Behaviour |
|---|---|
| Off (default) | No digest emails sent |
| Daily | One email per day when there is new activity |
| Weekly | One email per week when there is new activity |

The setting is disabled with a "Registered Feature" badge on unlicensed
installations.

## Cron Setup

Run the script hourly.  The per-user frequency is enforced inside the script,
so running it more often than the shortest frequency (daily) is safe and
ensures daily digests are delivered at a consistent time rather than drifting
by run intervals.

```cron
# /etc/cron.d/binkterm-digest  (or add to the user's crontab)
0 * * * * www-data php /home/claudebbs/binkterm-php/scripts/send_echomail_digest.php
```

Adjust the path and user (`www-data`) to match your installation.

## Testing

### Dry run — see what would be sent without sending anything

```bash
php scripts/send_echomail_digest.php --dry-run --verbose
```

### Dry run for a specific user

```bash
php scripts/send_echomail_digest.php --dry-run --verbose --user=3
```

### Actually send for a specific user

```bash
php scripts/send_echomail_digest.php --verbose --user=3
```

### Force a re-send (reset last-sent timestamp)

If a digest has already been sent and you want to test again without waiting
for the frequency window to expire, reset the timestamp in the database:

```sql
UPDATE user_settings SET echomail_digest_last_sent = NULL WHERE user_id = 3;
```

Then run the script again.

### Verify user configuration

```sql
SELECT u.id, u.email, us.echomail_digest, us.echomail_digest_last_sent
FROM users u
JOIN user_settings us ON us.user_id = u.id
WHERE u.id = 3;
```

## Script Options

| Flag | Description |
|---|---|
| `--dry-run` | Show what would be sent without sending or updating timestamps |
| `--verbose` | Print per-user status to stdout |
| `--user=ID` | Process only the specified user ID |
| `--help` | Show usage information |

Exit code is `0` on success (including zero eligible users), `1` if any
individual sends failed.

## Database Columns

Both columns live on the `user_settings` table:

| Column | Type | Default | Description |
|---|---|---|---|
| `echomail_digest` | `VARCHAR(10)` | `'none'` | Frequency: `none`, `daily`, or `weekly` |
| `echomail_digest_last_sent` | `TIMESTAMP` | `NULL` | When the last digest was sent; `NULL` means never |

These are added by migration `v1.11.0.31`.

## Troubleshooting

**Script exits with "Echomail digest requires a valid BinktermPHP license."**
The install is not registered.  See Admin → Help → Register BinktermPHP.

**User is skipped with "not due yet"**
The last digest was sent within the frequency window.  Reset
`echomail_digest_last_sent` to `NULL` to force a send (see above).

**User is skipped with "no new messages"**
No echomail was received in the user's subscribed areas since the last digest.
Check that the user has active subscriptions (Settings → Subscriptions) and
that the echo areas are receiving traffic.

**Send fails silently**
Check `data/logs/error.log` (or your PHP error log) for PHPMailer errors.
Confirm `SMTP_ENABLED=true` and that the SMTP credentials in `.env` are
correct.  You can test SMTP independently by using the netmail forwarding
feature, which uses the same mail stack.
