# Upgrading to 1.8.5

Make sure you've made a backup of your database and files before upgrading.

## Summary of Changes

**New Features**
- Native Doors: run native Linux binaries and Windows executables as BBS doors via PTY — no emulator required; manage via Admin → Native Doors (see below)

**Bug Fixes**
- Markdown renderer: fixed inline code parsing so identifiers with
  underscores such as `send_domain_in_addr` and `M_ADR` render correctly
  in upgrade notes and other locally rendered Markdown documents
- Markdown renderer: fixed wrapped unordered-list items so Upgrade Notes
  render correctly in the admin viewer instead of splitting a single bullet
  into separate paragraphs
- Binkp scheduler: fixed outbound-triggered polling so the scheduler no
  longer polls every enabled uplink once per minute whenever any outbound
  packet exists; outbound polls now only target uplinks that actually have
  queued outbound traffic for them
- Outbound dispatch: newly spooled netmail and echomail now trigger an
  immediate poll of the specific routed uplink instead of waiting for the
  scheduler's next loop
- Outbound dispatch: web message sends no longer wait for that immediate
  outbound poll to finish before returning success to the browser
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
- Bundle processing: fixed inbound ArcMail day-bundle detection so files
  such as `.sua` are recognized and extracted instead of being rejected as
  an unknown bundle format
- Cron schedule clarification: `* */1 * * *` means every minute of every
  hour, not hourly; use `0 * * * *` or `0 */1 * * *` for hourly polling
- Message reader: ANSI-decoded message bodies no longer display inside a black box; the art container styling is now only applied to standalone ANSI art displays
- Message reader: fixed spurious border and vertical scrollbar on ANSI art in message bodies caused by Bootstrap's global `pre { overflow: auto; border }` Reboot styles leaking into the ANSI renderer
- Packet processor: fixed echomail misclassified as netmail when the incoming packet is missing its `AREA:` line; the secondary scan loop had a logic error causing it to exit after one iteration, and `SEEN-BY`/`PATH` detection now scans the full message instead of only the first ten lines
- Mobile message reader: fixed swipe-to-navigate triggering while scrolling wide ANSI art horizontally; the boundary check now uses the scroll position captured at touch start rather than the position after native scrolling has already occurred

## Native Door Support

BinktermPHP now supports **native doors** — Linux binaries, shell scripts, and Windows executables that run directly as BBS doors via PTY with no emulator required. User data is passed via DOOR.SYS drop file and environment variables.

Install doors by dropping a subdirectory with a `nativedoor.json` manifest into `native-doors/doors/`, then enable them via **Admin → Native Doors**. Two test doors (`linuxdoortest`, `windoortest`) are included and disabled by default.

See **[docs/NativeDoors.md](NativeDoors.md)** for the full manifest format reference, environment variable list, and setup instructions.

> **Security note:** Native doors run as the same OS user as the BinktermPHP bridge. Only install doors from trusted sources, and never install a door that can drop to a shell. See the Security Warning section in NativeDoors.md for details.

---

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
