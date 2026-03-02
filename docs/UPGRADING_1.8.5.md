# Upgrading to 1.8.5

Make sure you've made a backup of your database and files before upgrading.

## Summary of Changes

**Bug Fixes**
- Markdown renderer: fixed inline code parsing so identifiers with
  underscores such as `send_domain_in_addr` and `M_ADR` render correctly
  in upgrade notes and other locally rendered Markdown documents
- Binkp scheduler: fixed outbound-triggered polling so the scheduler no
  longer polls every enabled uplink once per minute whenever any outbound
  packet exists; outbound polls now only target uplinks that actually have
  queued outbound traffic for them
- Outbound dispatch: newly spooled netmail and echomail now trigger an
  immediate poll of the specific routed uplink instead of waiting for the
  scheduler's next loop
- Scheduler logging: corrected outbound polling log messages so "triggering
  poll" is only logged when an uplink will actually be polled
- Scheduler shutdown: fixed `Ctrl-C`/`SIGINT` handling so
  `binkp_scheduler.php` exits immediately instead of continuing into the
  next polling loop
- Scheduler config reload: `binkp_scheduler.php` now reloads
  `config/binkp.json` during its daemon loop so schedule and uplink
  changes are picked up without restarting the scheduler
- Admin daemon client: fixed stale reused connections that could produce
  intermittent "Admin daemon closed connection" errors after the daemon
  timed out an idle socket
- Cron schedule clarification: `* */1 * * *` means every minute of every
  hour, not hourly; use `0 * * * *` or `0 */1 * * *` for hourly polling

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
