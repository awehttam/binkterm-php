# UPGRADING_1.7.0

## Summary
BinktermPHP 1.7.0 introduces a new cron/system startup model that uses a long-running admin daemon and scheduler. The scripts `binkp_poll.php` and `process_packets.php` are **still supported and still used**, but running them directly from cron is now **deprecated**.

## Why This Change
The new approach centralizes polling and packet processing through a single service:
- **Consistent execution**: one daemon is responsible for running tasks.
- **Better coordination**: the scheduler can poll and then immediately process packets.
- **Easier operations**: a small set of services started at boot instead of multiple cron entries.
- **Cross-component reuse**: the admin daemon is used by the web UI and scheduler for admin tasks.

## Old Cron Method (Deprecated)
These cron entries still work, but are no longer recommended:
```
*/3 * * * * /usr/bin/php /path/to/binkterm/scripts/process_packets.php
*/5 * * * * /usr/bin/php /path/to/binkterm/scripts/binkp_poll.php --all
```

## New Method (Recommended)
Start the following at system startup (systemd, @reboot cron, or service manager):
1) `admin_daemon.php`
2) `binkp_scheduler.php`
3) `binkp_server.php`

The scheduler reads `poll_schedule` for each uplink in `config/binkp.json` and uses the admin daemon to:
- run `binkp_poll` for the uplink
- then run `process_packets`

## Migration Steps
1. **Disable old cron jobs** that directly invoke `binkp_poll.php` and `process_packets.php`.
2. **Enable services at boot**:
   - `php scripts/admin_daemon.php` (pid defaults to `data/run/admin_daemon.pid`)
   - `php scripts/binkp_scheduler.php --daemon` (pid defaults to `data/run/binkp_scheduler.pid`)
   - `php scripts/binkp_server.php --daemon` (pid defaults to `data/run/binkp_server.pid`, Linux/macOS; Windows should run in foreground)
3. **Verify `poll_schedule`** in `config/binkp.json` for each uplink.
4. **Confirm admin daemon access**:
   - Set `ADMIN_DAEMON_SECRET` in `.env`
   - Use `scripts/admin_client.php` to test (`process-packets`, `binkp-poll`)

## Notes
- The admin daemon runs long-lived and executes tasks requested by the scheduler and web UI.
- The scheduler and web UI still rely on `binkp_poll.php` and `process_packets.php` internally.
- Direct cron usage of those scripts is deprecated, but the scripts remain supported.
