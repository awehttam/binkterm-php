# Upgrading to 1.8.5

Make sure you've made a backup of your database and files before upgrading.

## Summary of Changes

**New Features**
- Native Doors: run native Linux binaries and Windows executables as BBS doors via PTY — no emulator required; manage via Admin → Native Doors (see below)
- Door `launch_command`: all door types (DOS and native) now support a `{user_number}` placeholder that is substituted with the BBS user's numeric ID at launch time; native doors also receive it as the `DOOR_USER_NUMBER` environment variable
- Telnet gateway: native doors now appear alongside DOS doors in the telnet door menu
- Markup kludge: outbound messages now use `^AMARKUP: Markdown 1.0` per LSC-001 Draft 2; the legacy `^AMARKDOWN:` kludge continues to be recognised in received messages for backwards compatibility
- StyleCodes rendering: messages with `^AMARKUP: StyleCodes 1.0` (Synchronet Message Markup) are now rendered in the message reader; supported codes: `*bold*`, `/italics/`, `_underlined_`, `#inverse#`

**Improvements**
- Outbound dispatch: newly spooled netmail and echomail now trigger an
  immediate poll of the specific routed uplink instead of waiting for the
  scheduler's next loop
- Outbound dispatch: web message sends no longer block waiting for the
  outbound poll to complete before returning success to the browser
- Scheduler config reload: `binkp_scheduler.php` now reloads
  `config/binkp.json` during its daemon loop so schedule and uplink
  changes are picked up without restarting the scheduler
- Admin daemon: now forks a child process per connection so long-running
  commands such as manual polls no longer block other admin requests
- MRC daemon: logging now goes to `data/logs/mrc_daemon.log` instead of
  the PHP error log; log level is controllable via `--log-level`
- Message reader: ANSI art in message bodies no longer displays inside a
  black box with a scrollbar; styling is now consistent with standalone
  ANSI art displays
- BinkP session: `binkp_poll` now completes promptly after sending mail
  to non-conformant remotes (those that send `M_EOB` without `M_GOT`);
  sessions terminate after 30 seconds of inactivity rather than the full
  session timeout, while preserving a window for areafix and similar
  systems to process an inbound packet and return a response in the same
  session; sent packets are cleaned up correctly regardless of whether the
  remote sends `M_GOT` before or after `M_EOB`

**Bug Fixes**
- Markdown renderer: fixed inline code parsing so identifiers with
  underscores such as `send_domain_in_addr` and `M_ADR` render correctly
  in upgrade notes and other locally rendered Markdown documents
- Markdown renderer: fixed wrapped unordered-list items so Upgrade Notes
  render correctly in the admin viewer instead of splitting a single bullet
  into separate paragraphs
- BinkP scheduler: fixed outbound-triggered polling so the scheduler no
  longer polls every enabled uplink once per minute whenever any outbound
  packet exists; outbound polls now only target uplinks that actually have
  queued outbound traffic for them
- Scheduler logging: corrected outbound polling log messages so "triggering
  poll" is only logged when an uplink will actually be polled
- Scheduler shutdown: fixed `Ctrl-C`/`SIGINT` handling so
  `binkp_scheduler.php` exits immediately instead of continuing into the
  next polling loop
- Admin daemon client: fixed stale reused connections that could produce
  intermittent "Admin daemon closed connection" errors after the daemon
  timed out an idle socket
- Bundle processing: fixed inbound ArcMail day-bundle detection so files
  such as `.sua` are recognized and extracted instead of being rejected as
  an unknown bundle format
- Cron schedule clarification: `* */1 * * *` means every minute of every
  hour, not hourly; use `0 * * * *` or `0 */1 * * *` for hourly polling
- Packet processor: fixed echomail misclassified as netmail when the
  incoming packet is missing its `AREA:` line; the secondary scan loop had
  a logic error causing it to exit after one iteration, and `SEEN-BY`/`PATH`
  detection now scans the full message instead of only the first ten lines
- Mobile message reader: fixed swipe-to-navigate triggering while scrolling
  wide ANSI art horizontally; the boundary check now uses the scroll
  position captured at touch start rather than the position after native
  scrolling has already occurred
- BinkP server: fixed inbound sessions not including the network domain in
  the `M_ADR` address; the `send_domain_in_addr` flag was only applied to
  outbound calls — inbound connections now respect it too
- Native doors: fixed icon and screenshot assets not being served; the
  `/door-assets/` route was only looking in the DOS door directory

## Native Door Support

BinktermPHP now supports **native doors** — Linux binaries, shell scripts, and Windows executables that run directly as BBS doors via PTY with no emulator required. User data is passed via DOOR.SYS drop file and environment variables.

Install doors by dropping a subdirectory with a `nativedoor.json` manifest into `native-doors/doors/`, then enable them via **Admin → Native Doors**. Two test doors (`linuxdoortest`, `windoortest`) are included and disabled by default.

**BBSLink** is included as a native door (`bbslinknative`). Copy `native-doors/doors/bbslinknative/vars.sh.example` to `vars.sh` and fill in your BBSLink credentials before enabling it.

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
