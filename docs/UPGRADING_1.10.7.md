# Upgrading to 1.10.7

BinktermPHP 1.10.7 is a hardening and reliability release. It improves mail routing, AreaFix/FileFix handling and BinkP session reliability, and adds new features for terminal users and door games, including ANSI avatars and mobile touch controls. It also includes a broad set of security improvements. Some changes alter existing behavior, so review the notes below before upgrading.

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Messaging](#messaging)
  - [Date Display Preferences](#date-display-preferences)
  - [Fixed: Inbound Echomail Landed in Areas With an Empty Domain](#fixed-inbound-echomail-landed-in-areas-with-an-empty-domain)
  - [Message Search Scoped by Network and Interest](#message-search-scoped-by-network-and-interest)
  - [Echomail No Longer Sent Through Another Network's Uplink](#echomail-no-longer-sent-through-another-networks-uplink)
  - [Inbound Echomail Refused When It Would Land in a Local or Ambiguous Area](#inbound-echomail-refused-when-it-would-land-in-a-local-or-ambiguous-area)
- [AreaFix / FileFix](#areafix-filefix)
  - [Structural Reply Parsing Across More Hub Mailers](#structural-reply-parsing-across-more-hub-mailers)
  - [Mandatory Preview Before Syncing Areas](#mandatory-preview-before-syncing-areas)
  - [Data-Driven Grammar Definitions](#data-driven-grammar-definitions)
  - [Per-Uplink Format Memory](#per-uplink-format-memory)
  - [Automatic Area Sync on Reply Now Opt-In](#automatic-area-sync-on-reply-now-opt-in)
  - [Fixed: AreaFix Sync Set an Override Address on Echo Areas](#fixed-areafix-sync-set-an-override-address-on-echo-areas)
- [BinkP](#binkp)
  - [Slow Peers No Longer Stall Frame Reads](#slow-peers-no-longer-stall-frame-reads)
  - [Sessions That End Abnormally Reported as Failed](#sessions-that-end-abnormally-reported-as-failed)
  - [Fixed: Wrong Packet Password Stopped All Inbound Processing](#fixed-wrong-packet-password-stopped-all-inbound-processing)
  - [Fixed: process_packets.php Lock File Errors Reported as Already Running](#fixed-process_packetsphp-lock-file-errors-reported-as-already-running)
  - [Fixed: Inbound Connections Closed Reliably](#fixed-inbound-connections-closed-reliably)
  - [Scheduled Polls Run One at a Time, and Never Overlap on the Same Host](#scheduled-polls-run-one-at-a-time-and-never-overlap-on-the-same-host)
  - [Fixed: Mail Posted During a Scheduled Poll Waited Up to 30 Minutes](#fixed-mail-posted-during-a-scheduled-poll-waited-up-to-30-minutes)
- [Administration](#administration)
  - [Fixed: user-manager.php create Command](#fixed-user-managerphp-create-command)
  - [Log Rotation by Size](#log-rotation-by-size)
  - [SQL Migrations Split on Real Statement Boundaries](#sql-migrations-split-on-real-statement-boundaries)
  - [Docker: telnet Client Added for the BBSLink Door](#docker-telnet-client-added-for-the-bbslink-door)
  - [Admin Daemon Keeps Log Lines It Cannot Write](#admin-daemon-keeps-log-lines-it-cannot-write)
  - [Fixed: Each Login Recorded Exactly Once](#fixed-each-login-recorded-exactly-once)
  - [Downlink Queue Sorted by Owner and Shows Network Domain](#downlink-queue-sorted-by-owner-and-shows-network-domain)
- [Web Interface](#web-interface)
  - [Fresh Assets After an Upgrade](#fresh-assets-after-an-upgrade)
  - [Login Page Honors "Remember Me"](#login-page-honors-remember-me)
  - [Recovery From a Stale CSRF Token](#recovery-from-a-stale-csrf-token)
  - [Telnet Address on the Dashboard](#telnet-address-on-the-dashboard)
  - [ANSI Avatars](#ansi-avatars)
  - [Fixed: BBS Menu Shell Scripts](#fixed-bbs-menu-shell-scripts)
- [Web Doors](#web-doors)
  - [Longer Browser Caching for Door Assets](#longer-browser-caching-for-door-assets)
  - [RLogin Door Asset Sizes Stored in the Database](#rlogin-door-asset-sizes-stored-in-the-database)
  - [MRC Chat Loads Its Libraries From the Bundled Copies](#mrc-chat-loads-its-libraries-from-the-bundled-copies)
  - [DOS Doors Redraw After the Browser Reconnects](#dos-doors-redraw-after-the-browser-reconnects)
  - [Mobile Touch Controls and Scaling for Door Games](#mobile-touch-controls-and-scaling-for-door-games)
  - [Door Connections Kept Alive Through Proxies](#door-connections-kept-alive-through-proxies)
  - [DOS Door ANSI.SYS Screen Clear Homes the Cursor](#dos-door-ansisys-screen-clear-homes-the-cursor)
  - [Door Screens Flushed Before the Connection Closes](#door-screens-flushed-before-the-connection-closes)
  - [Fixed: Door Player Limits Could Be Exceeded by Simultaneous Launches](#fixed-door-player-limits-could-be-exceeded-by-simultaneous-launches)
  - [Fixed: PubTerm Native Door Block Graphics](#fixed-pubterm-native-door-block-graphics)
- [MeshCore](#meshcore)
  - [Radio Settings Link on the Dashboard](#radio-settings-link-on-the-dashboard)
- [Networks](#networks)
  - [SysopNet Added to the Networks List](#sysopnet-added-to-the-networks-list)
  - [Networks Listed Alphabetically](#networks-listed-alphabetically)
- [BBS Directory](#bbs-directory)
  - [Geocoding Provider Failures No Longer Cached](#geocoding-provider-failures-no-longer-cached)
  - [Geocoding Backfill Skips Known No-Match Locations](#geocoding-backfill-skips-known-no-match-locations)
- [Terminal Server](#terminal-server)
  - [Cursor Keys, Bracketed Paste and Lone ESC](#cursor-keys-bracketed-paste-and-lone-esc)
  - [UTF-8 Safe Line Editing and Paste Containment](#utf-8-safe-line-editing-and-paste-containment)
  - [Nagle's Algorithm Disabled on Telnet and SSH Connections](#nagles-algorithm-disabled-on-telnet-and-ssh-connections)
  - [SSH Connection Rate Limit and Session Cap](#ssh-connection-rate-limit-and-session-cap)
  - [RLogin Clients Recognized on the Telnet Ports](#rlogin-clients-recognized-on-the-telnet-ports)
  - [Dialup Speed Simulation for Telnet and SSH](#dialup-speed-simulation-for-telnet-and-ssh)
- [Security](#security)
  - [Secure Flag on Session Cookies](#secure-flag-on-session-cookies)
  - [Default Terminal Registration Secret No Longer Trusted](#default-terminal-registration-secret-no-longer-trusted)
  - [Failed-Login Throttle](#failed-login-throttle)
  - [Retro Audio Proxy Hardened](#retro-audio-proxy-hardened)
  - [Telnet and SSH Sessions Disconnected When Their Web Session Is Revoked](#telnet-and-ssh-sessions-disconnected-when-their-web-session-is-revoked)
  - [PacketBBS Sessions End When Their Web Session Is Revoked](#packetbbs-sessions-end-when-their-web-session-is-revoked)
  - [Docker: Config JSON Files No Longer World-Readable](#docker-config-json-files-no-longer-world-readable)
  - [BinkP Debug Logging No Longer Records Password Material](#binkp-debug-logging-no-longer-records-password-material)
  - [Auto Feed Verifies TLS and Allows Only http(s) Feeds](#auto-feed-verifies-tls-and-allows-only-https-feeds)
  - [Telnet TLS Offers TLS 1.3 and Validates Your Certificate](#telnet-tls-offers-tls-13-and-validates-your-certificate)
  - [Login Takes the Same Time for Unknown Usernames](#login-takes-the-same-time-for-unknown-usernames)
  - [WebDoor API Only Serves Installed, Enabled Games](#webdoor-api-only-serves-installed-enabled-games)
  - [Telnet Drops Idle Connections Sooner Before Login](#telnet-drops-idle-connections-sooner-before-login)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)
- [Thanks](#thanks)

## Summary of Changes

### Messaging

- **Date display preferences:** users and sysops can now choose between relative timestamps ("4d ago") and exact date/time for message lists and headers, and choose whether echomail is ordered and displayed by received date or written date.
- **Message search scoped by network and interest:** searching for messages from the Echo Areas page now respects the network and interest filters selected there, and searching while browsing a single interest on the Echomail page now stays within that interest's echo areas, instead of always searching every echo area.
- **Fixed: inbound echomail landed in areas with an empty domain:** the network domain for incoming echomail is now taken from the uplink that delivered the packet, instead of only from the message author's address. Authors outside the uplink's routing patterns no longer produce messages with an empty domain.
- **Echomail no longer sent through another network's uplink:** when an echo area's network has no uplink configured, its outbound echomail used to be sent through the default uplink, which belongs to a different network. It now stays local and a warning is logged.
- **Inbound echomail refused when it would land in a local or ambiguous area:** incoming echomail is now dropped, with a log line, instead of being filed in an area that is flagged local, in an area with no network domain, or in one of several areas whose domains differ only by letter case.

### AreaFix / FileFix

- **Structural reply parsing across more hub mailers:** AreaFix and FileFix replies are now parsed by recognizing the concrete layout each hub mailer actually sends — Mystic BBS/MBSE command blocks, delimited and columnar tables, BBBS/Li6-style quoted address lists, and HPT-style flag-prefixed quoted lists — instead of scanning for keywords. Real echo areas with common names such as `LINUX`, `WINDOWS`, or `BASE` are no longer mistaken for header text or help output.
- **Mandatory preview before syncing areas:** clicking "Sync Areas to Local BBS" (from the latest reply, or from any individual incoming message in the Message History table) now shows a preview of exactly which areas will be created, reactivated, deactivated, or left unchanged. Nothing is written to the database until this preview is explicitly confirmed.
- **Data-driven grammar definitions:** a new **Admin -> Area Management -> AreaFix Grammars** page lets a sysop teach AreaFix a new hub reply format without a code change, either by hand or by pasting a sample reply and asking the built-in AI assistant to suggest one. Suggestions are always added disabled for review before saving.
- **Per-uplink format memory:** BinktermPHP now remembers which reply format last matched each hub's confirmed sync, tries that format first on the hub's next reply, and flags it on the preview screen if the format changes unexpectedly. The remembered format for each uplink can be viewed, forced, or cleared from **Admin -> BBS Settings -> BinkP Uplinks -> Edit Uplink**.
- **Automatic area sync on reply is now opt-in:** receiving an AreaFix/FileFix reply from a hub that looks like an area list no longer automatically creates or activates local echo areas / file areas by default. Set `AREAFIX_AUTOIMPORT_ENABLED=true` in `.env` to restore the previous automatic behavior.
- **Fixed: AreaFix sync set an override address on echo areas:** syncing areas from a hub's AreaFix reply filled in the echo area's **Uplink Address** ("Override Uplink FidoNet address") field on every area it created or touched. The sync no longer sets it, and deactivating areas missing from the hub's list is now scoped by network domain and tag instead of by that address.

### BinkP

- **Slow peers no longer stall frame reads:** a BinkP peer that sends a frame in pieces, with a pause between them, used to hold the session in a single read for the whole BinkP timeout. Each polling read now takes only the bytes that have arrived and picks the frame up where it left off on the next pass.
- **Sessions that end abnormally are reported as failed:** a BinkP session that ended on the hard EOB or inactivity timeout, or because the peer disconnected before the EOB exchange finished, was logged and reported as successful. It is now recorded as failed.
- **Fixed: wrong packet password stopped all inbound processing:** one inbound packet with the wrong packet password raised an uncaught error that aborted the whole inbound run, and the packet was tried again, with the same result, on every later run. The packet is now rejected and recorded as an error, and processing continues with the next packet.
- **Fixed: `process_packets.php` lock file errors reported as "already running":** when `data/run/process_packets.lock` could not be opened, for example because of its owner or permissions, the script logged that another instance was running and exited successfully. It now logs the real error and exits with status 1.
- **Fixed: inbound connections closed reliably:** the BinkP server did not reliably release an accepted connection when a session failed, and an error while sending the "maximum connections" busy reply could escape into the accept loop. Every accepted connection is now closed exactly once, and a failure while handling one can no longer affect the server process.
- **Scheduled polls run one at a time, and never overlap on the same host:** the scheduler now waits for each poll to finish and processes the packets it received before starting the next one. Outbound sessions to the same host and port are also serialized, so uplinks on different networks that share a hub no longer collide. A poll that finds the host busy is deferred, not failed.
- **Fixed: mail posted during a scheduled poll waited up to 30 minutes:** a message queued while a scheduled poll was running, or just after it, was skipped as already delivered and then held back by the outbound queue timer. The scheduler now polls again straight away when new outbound files appeared after the scheduled poll started.

### Administration

- **Fixed `scripts/user-manager.php create`:** the operator CLI's `create` command failed on PostgreSQL with `column "is_active" is of type boolean but expression is of type integer`, because it inserted the literal `1` instead of a boolean. This is now fixed.
- **Log rotation by size:** `scripts/logrotate.php` has a new `--max-size` option that rotates only logs that have grown past a size, so the script can run often without touching small logs. Docker installs can set `LOGROTATE_MAX_SIZE` to use it. The script also gained `--logs-dir`, and lowering `--keep` now removes the extra old generations on the next run.
- **Admin daemon keeps log lines it cannot write:** when a process cannot write its own log file and forwards the line to the admin daemon, and the daemon cannot write that file either, the line is now recorded in `data/logs/admin_daemon.log` as a warning instead of being discarded.
- **SQL migrations split on real statement boundaries:** the upgrade script now finds the end of each statement in a `.sql` migration by reading the SQL, instead of by pattern matching. A `;` or `--` inside a quoted string, a function body or a block comment no longer corrupts the migration. Every migration shipped with BinktermPHP is split exactly as before.
- **Docker: telnet client added for the BBSLink door:** the container image now includes the `telnet` package, which the BBSLink native door (`bbslinknative`) needs. Rebuild the image to get it.
- **Fixed: each login is recorded exactly once:** Telnet and SSH logins were recorded twice, which inflated the total login count and Today's Callers, and credited half of them to the web. PacketBBS logins were not recorded at all and did not update the user's last login time. Each successful login now produces one login event tagged with the service it came in on (`web`, `telnet`, `ssh` or `packetbbs`).
- **Downlink queue sorted by owner and shows network domain:** the summary table on the **Downlink Queue** tab of the BinkP status page now lists downlinks grouped by owner, then by address, and shows each downlink's network domain after its address, for example `21:3/236.10@examplenet`.

### Web Interface

- **Fresh assets after an upgrade:** the service worker now asks the server whether a file has changed before saving it to its cache after an upgrade, so browsers no longer keep an old copy of a CSS or JavaScript file from before the upgrade.
- **Login page honors "Remember me":** the checkbox on the login page was never sent to the server, so every web login got a 30-day session cookie. Leaving it unchecked now gives a cookie that the browser discards when it closes.
- **Recovery from a stale CSRF token:** logging in as the same user somewhere else, in another browser or over Telnet or SSH, used to make every already-open page and terminal session fail with "Invalid CSRF token" until it was reloaded or reconnected. Web pages and terminal sessions now fetch the current token and recover on their own. A new `GET /api/auth/csrf-token` endpoint supplies the token.
- **Telnet address on the dashboard:** the System Information card now shows the address Telnet users connect to, and shrinks the text to fit on one line.
- **ANSI avatars:** each user can paint or import one 10 by 6 Synchronet-compatible CP437 avatar on their profile. It appears on public profiles and in the terminal profile viewer. A new `user_ansi_avatars` table is created by the migration.
- **Fixed: BBS menu shell scripts:** pages in the BBS menu shell now load the per-user storage helper and receive the user's settings, and the ANSI.SYS script is no longer loaded twice.

### Web Doors

- **Longer browser caching for door assets:** icons and screenshots served from `/door-assets/` now use `Cache-Control: public, max-age=604800, stale-while-revalidate=86400` (up from a 24-hour max-age), plus ETag/Last-Modified conditional requests, so repeat visits reload door pages faster and generate less server load.
- **RLogin door asset sizes stored in the database:** icon and screenshot byte sizes for RLogin doors are now stored alongside the image data instead of being recomputed on every request, reducing memory overhead when serving those assets.
- **MRC chat loads its libraries from the bundled copies:** the MRC web door now loads Bootstrap, jQuery and its icons from the copies bundled with BinktermPHP instead of public CDNs, so it works under a strict Content Security Policy and without access to those CDNs.
- **DOS doors redraw after the browser reconnects:** when a browser's connection to a running DOS door drops and comes back within `DOSDOOR_RECONNECT_TIMEOUT`, the terminal now shows the current door screen again instead of staying blank until the door next writes.
- **Mobile touch controls and scaling for door games:** DOS and RLogin doors now fit a phone screen, with a docked touch gamepad (arrow pad, Esc, Enter, Space, Backspace, number row and hotkeys), ready-made layouts for some popular games, zoom controls, and a high-contrast color mode that brightens dim colors for phone displays.
- **Door connections kept alive through proxies:** the DOS door bridge now pings every connected browser every 20 seconds (`DOSDOOR_WS_HEARTBEAT_INTERVAL_MS`), and the DOS and RLogin door pages send their own ping every 25 seconds, so a door sitting at an idle screen is no longer dropped by Cloudflare, nginx, Apache or Caddy. A browser that stops answering is disconnected. The default `DOSDOOR_RECONNECT_TIMEOUT` is now 90 seconds, up from 30.
- **DOS door ANSI.SYS screen clear homes the cursor:** for DOS, RLogin and CP437 native doors, a clear-screen sequence now also moves the cursor to the top left, as ANSI.SYS does, so doors that rely on that line up correctly.
- **Door screens flushed before the connection closes:** when a door exits, the bridge waits 800 ms before closing the browser connection, so the last screen the door wrote, such as a game-over screen, is shown.
- **Fixed: door player limits could be exceeded by simultaneous launches:** several players launching the same door at the same moment could all pass the door's maximum-players check, or be given the same node number. The check and node assignment are now done as one step. A launch that loses the race for the last slot gets an HTTP `503` "Door at capacity" response.
- **Fixed: PubTerm native door block graphics:** the PubTerm native door now uses CP437 output and an 8-bit Telnet connection, so ANSI block graphics display correctly instead of as replacement characters.

### MeshCore

- **Radio settings link on the dashboard:** the PacketBBS Nodes card on the main dashboard now includes a "My MeshCore radios" link that opens the MeshCore tab of your user settings, where you manage your radios.

### Networks

- **SysopNet added to the networks list:** SysopNet (zone 23, hub 23:1/1), an FTN for sysops run by sysops, is now registered automatically on upgrade, so it appears in **Admin -> Networks** without being created by hand.
- **Networks listed alphabetically:** the network list in **Admin -> Networks** and the network dropdown when editing an uplink are now sorted purely by name.

### BBS Directory

- **Geocoding provider failures no longer cached:** a failed request to the geocoding provider, such as a timeout or an outage, was stored the same way as a genuine "no match" answer, so the location was never looked up again. Failures are now never cached and are retried on the next run.
- **Geocoding backfill skips known no-match locations:** locations the provider has already said it cannot find no longer take up places in a limited backfill batch, so newer entries further down the directory are no longer starved of coordinates.

### Terminal Server

- **Cursor keys, bracketed paste and lone ESC:** Telnet and SSH sessions now understand arrow and navigation keys that terminals send with modifiers or in application cursor-key mode, ignore the markers many terminals put around pasted text, and report a lone ESC press as ESC. Before, these were dropped as terminal reports, typed into the screen as stray characters, or treated as unknown keys.
- **UTF-8 safe line editing and paste containment:** text typed at terminal prompts and input dialogs is now handled one character at a time, so accented and other non-ASCII characters are no longer split or lost, the left and right arrows edit in the middle of a line, and the rest of a multi-line paste is discarded after the first line instead of being run against the next menu or password prompt. CR-LF counts as one Enter.
- **Nagle's algorithm disabled on Telnet and SSH connections:** the Telnet, TLS-Telnet and SSH daemons now turn off Nagle's algorithm on accepted connections, so single keystroke echoes and screen redraws are sent immediately instead of being held back, which was noticeable over higher-latency links. Set `TERMINAL_TCP_NODELAY=false` in `.env` to keep Nagle enabled. It needs the PHP `sockets` extension, and without it the setting is skipped.
- **SSH connection rate limit and session cap:** the SSH daemon now limits new connections per source address (`SSH_RATE_LIMIT_MAX`, default 5 per `SSH_RATE_LIMIT_WINDOW`, default 60 seconds) and caps the number of simultaneous sessions (`SSH_MAX_CHILDREN`, default 32). Rejected connections are closed before the daemon forks or starts the SSH handshake. The Telnet daemon now shares the same rate limiter, so a `TELNET_RATE_LIMIT_WINDOW` of `0` or less now means 60 seconds instead of no limit.
- **RLogin clients recognized on the Telnet ports:** a client that connects with an RLogin handshake (RFC 1282) is now recognized, answered correctly, and no longer sent Telnet negotiation that appears on its screen as stray characters. Native door games also fall back to the user's alias or real name when no handle or username is available.
- **Dialup speed simulation for Telnet and SSH:** sysops can now pace terminal output at a classic modem speed, so ANSI screens draw line by line instead of appearing at once. It is off by default and is set under **Admin → BBS Settings → Terminal Server Settings**.

### Security

- **Secure flag on session cookies:** the `binktermphp_session` cookie now sets the `Secure` flag whenever the site is served over HTTPS, so the cookie is no longer sent over a plain HTTP connection even if one is reachable.
- **Default terminal registration secret no longer trusted:** an unset `TERMINAL_REGISTRATION_SECRET`, or the published default `Chang3Me`, is no longer accepted as proof that a request came from the telnet/SSH daemons. Docker installs generate a site-specific secret automatically; other installs must set one in `.env`.
- **Failed-login throttle:** repeated failed logins are now limited per account and per source IP across the web login, telnet, SSH, FTP, NNTP and QWK HTTP downloads. By default an account allows 5 failures and an IP allows 20 within 15 minutes; once either limit is reached, further attempts fail exactly like a wrong password until the window passes. Set `AUTH_LOGIN_USER_MAX`, `AUTH_LOGIN_IP_MAX` and `AUTH_LOGIN_WINDOW` in `.env` to change the limits (see [CONFIGURATION.md](CONFIGURATION.md#failed-login-throttle)). The upgrade migration creates the `auth_login_attempts` table.
- **Telnet and SSH sessions disconnected when their web session is revoked:** a connected Telnet or SSH user, including one playing a door, is now signed out shortly after their web session is revoked, expires, or is deleted by a password reset. Previously the terminal session kept running with full access until the user disconnected.
- **PacketBBS sessions end when their web session is revoked:** a radio node that logged in to PacketBBS stayed authenticated after its linked web session was revoked, expired or deleted, and could keep sending commands and receiving private messages. The next command or bridge poll now clears the node's login and answers `Session expired. LOGIN again.`. Pending chat notifications are also discarded on QUIT, on LOGIN and on expiry, and stale sender-row cleanup now has its own `PACKETBBS_SESSION_RETENTION_SECONDS` setting.
- **Docker: config JSON files no longer world-readable:** the container now restricts the top-level `config/*.json` files, which hold uplink passwords and API keys, to the owner and group at startup. Previously they were readable by any user in the container.
- **BinkP debug logging no longer records password material:** with debug logging on, BinkP authentication logged the first characters of the received and expected passwords and the CRAM-MD5 challenge with its digest. Only outcomes, lengths and a hint about why a password did not match are logged now, unless you set `BINKP_LOG_SENSITIVE_AUTH=true`.
- **Auto Feed verifies TLS and allows only http(s) feeds:** RSS and Atom feeds are now fetched with HTTPS certificate and host name verification, and only `http://` and `https://` URLs are accepted. A feed with an invalid or self-signed certificate now fails instead of being fetched.
- **Telnet TLS offers TLS 1.3 and validates your certificate:** the TLS Telnet listener now accepts TLS 1.3 as well as 1.0 to 1.2. `TELNET_TLS_MIN_VERSION` sets the oldest version accepted and `TELNET_TLS_CIPHERS` sets the cipher list. A certificate and key you supply are checked at start-up, and TLS stays off, with the reason logged, if they are unusable. The generated self-signed certificate is now ECDSA so that SyncTERM can connect; `TELNET_TLS_KEY_TYPE=rsa` restores RSA.
- **Login takes the same time for unknown usernames:** a login with a username that does not exist used to fail faster than a login with a real username and a wrong password, which let anyone tell which accounts exist by timing the responses. Both failures now take about the same time.
- **WebDoor API only serves installed, enabled games:** the session, storage and leaderboard endpoints used under `/api/webdoor/` now accept only a `game_id` that names an installed WebDoor which is enabled and whose requirements are met. Any other id gets a `404` and nothing is read or written. Before, any string was accepted and created session, save and leaderboard rows.
- **Telnet drops idle connections sooner before login:** a Telnet connection that sits silent at the login, register or reset-password prompts is now disconnected after 90 seconds instead of the 7 minutes allowed for a signed-in session. Set `TELNET_PREAUTH_IDLE_TIMEOUT` in `.env` to change it.

## Messaging

### Date Display Preferences

Two new preferences control how dates are shown across echo area listings, echomail message headers, and netmail lists:

- **Date display style** — choose relative time (e.g. "4d ago", "2h ago") or exact date and time, formatted using the user's selected date format locale and timezone.
- **Echomail date field** — choose whether echomail lists and ordering use the received date (when the message arrived on this system) or the written date (when the original author composed it).

Both preferences default to "System Default," which follows a BBS-wide default that sysops can set in **Admin -> BBS Settings -> Features**. Users can override the system default for either preference individually in **Settings -> Preferences**.

Previously, only admin users could choose to order echomail by written date; this option is now available to all users. If your installation currently sets `ECHOMAIL_ORDER_DATE=written`, non-admin users will now see that ordering apply to them as well, following the same fallback chain (user preference, then BBS default, then this environment variable).

### Message Search Scoped by Network and Interest

The Echo Areas page lets you filter the area list down to one or more networks and interests using the **Network** and **Interests** dropdowns. The "Search Messages" box on that same page now carries those selections into the search, so results are limited to matching echo areas instead of every echo area on the system. Leaving both dropdowns on their "All" default still searches everything.

On the Echomail page, searching while browsing a single interest under the Interests tab is likewise scoped to that interest's echo areas. Searching from a specific echo area continues to scope to that single area, as before, taking priority over any network or interest scope.

### Fixed: Inbound Echomail Landed in Areas With an Empty Domain

When echomail arrived in a packet, the network domain was worked out only from the message author's address, by matching it against the routing patterns of your configured uplinks. Authors on systems outside those patterns do not match, which is the usual case for echomail, so their messages were stored with an empty domain and ended up in echo areas not tied to any network.

The domain is now taken from the uplink the packet came from, which is authoritative whatever the author's address is. The author's address is still used first for a message when it resolves to a network, and the packet's uplink domain is used when it does not.

Messages already stored with an empty domain are not changed by the upgrade.

### Echomail No Longer Sent Through Another Network's Uplink

Outbound echomail for a networked echo area goes to the area's **Uplink Address** if one is set (see **Admin -> Echo Areas**), and otherwise to the uplink configured for the area's network in **Admin -> BBS Settings -> BinkP Uplinks**. When the network had no uplink, BinktermPHP used to fall back to the default uplink, which can belong to an unrelated network. Messages posted to the area, and inbound echomail relayed onward, were then written into that other network's packet.

That fallback is gone. If an area's network has no uplink, the message stays on your system, is not queued for sending, and `data/logs/server.log` records a warning like `No uplink configured for network 'examplenet' (echoarea EXAMPLE_TAG); not routing to another network's uplink`.

After upgrading, check the log for this warning. For each network named in it, add the uplink for that network under **Admin -> BBS Settings -> BinkP Uplinks**, then post the message again. Messages that were kept local before you added the uplink are not sent automatically.

### Inbound Echomail Refused When It Would Land in a Local or Ambiguous Area

A network echo area has a network domain. A local-only area has no domain and is flagged as local in **Admin -> Echo Areas**. Incoming echomail was looked up by tag and domain, and when the domain could not be worked out, the lookup matched any area with that tag and no domain. That is how local areas are stored, so network mail could be filed in a local area, or a new area with no domain could be created for it. A tag could also match two areas whose domains differ only by letter case, and the first one silently won.

Incoming echomail is now dropped, and nothing is created, in these cases:

- the network domain of the message could not be determined;
- the area it matches is flagged local;
- more than one area matches the tag, with domains that differ only by letter case.

Each dropped message is recorded in the log with the area tag, the sender, the packet name and the reason, for example `[BINKD] Dropping echomail AREA:EXAMPLE_TAG from Some Name <999:1/2> packet=0001abcd.pkt: area EXAMPLE_TAG@examplenet is a local area`. A dropped message is not stored anywhere and is not retried. Areas that have a domain and are not local, and the automatic creation of an area for a message with a known domain, work as before.

After upgrading, check `data/logs/` for `Dropping echomail AREA:` lines. If a network echo area has no domain, set its domain in **Admin -> Echo Areas**. If two areas share a tag and differ only in the case of their domain, delete or merge one of them. If an area should be local-only, mark it local.

## AreaFix / FileFix

### Structural Reply Parsing Across More Hub Mailers

AreaFix and FileFix replies from a hub are parsed by matching the actual layout the hub's mailer software produces, rather than by scanning line-by-line for known words and phrases. The parser recognizes:

- Mystic BBS and MBSE `Command:`/`Result:` blocks, including stacked multi-command replies and `%LIST`/`%QUERY`/`%LINKED`/`%UNLINKED` result listings.
- Colon- and pipe-delimited tables (Husky, Clearing Houz, FastEcho, FrontDoor, InterMail).
- Columnar and dotted-leader tables (HPT, Husky), including table headers that name the tag column something other than the literal word "Area" (for example "Message area").
- BBBS/Li6-style quoted address lists (`+TAG (address) "description"`), including descriptions that wrap onto a continuation line and a single reply that lists both echo areas and file areas.
- HPT-style flag-prefixed dotted-leader lists with quoted descriptions (`*S   TAG ....... "description"`).
- As a last resort, a conservative bare `TAG   Description` line matcher for hub replies that don't match any of the above, which never marks a matched area as subscribed on its own.

Because this approach recognizes real structure instead of matching words, an echo area named the same as an ordinary English word or a common piece of software (`LINUX`, `WINDOWS`, `BASE`, and similar) is preserved correctly instead of being mistaken for a header, a help topic, or unrelated prose.

A Mystic BBS/MBSE `%QUERY` reply that lists both linked and unlinked areas in a single block, with individual rows explicitly annotated `(linked)`, `(unlinked)`, or `(not linked)`, now honors each row's own annotation instead of marking every row in the block the same way.

### Mandatory Preview Before Syncing Areas

Previously, clicking "Sync Areas to Local BBS" on the AreaFix / FileFix Manager page applied the parsed area list to your local echo areas or file areas immediately, with no chance to review it first. It now opens a preview dialog instead, and nothing is written to your database until you explicitly confirm it there. This preview is available in two places: from the "Latest Reply" panel's sync button, and per-message from a sync button next to each incoming reply in the Message History table, so you can also review and apply an older reply without it needing to still be the most recent one.

The preview lists every area found in the reply as a row with a checkbox, its tag, its description, and a status badge:

- **New** — the area doesn't exist locally yet and will be created.
- **Reactivate** — the area exists but is currently inactive and will be turned on.
- **Deactivate** — the area is currently active and the reply says to unsubscribe from it.
- **Updated** — the area's activation state isn't changing, but its description will be filled in or updated to match the hub's reply.
- **Unchanged** — nothing about the area differs from what the reply says; selecting it has no effect.

For an "Updated" row, the description cell shows your current description struck through above the incoming one when it will actually be replaced. A description is only ever replaced when your current one is empty, an auto-generated placeholder, or you've explicitly selected that row for sync (see below) — a real, sysop-set description is never silently overwritten. If the hub's reply lists a different description for an area whose own real description would otherwise be left alone, the preview still shows what the hub sent underneath it, so the mismatch doesn't go unnoticed just because it's not required to be applied.

Every row starts checked except a genuine no-op "Unchanged" row — including every "New", "Reactivate", "Deactivate", and "Updated" row, so the normal case (review, then confirm) still applies everything in one click. Use the checkboxes, or the "Select All" / "Select None" buttons above the list, to apply only a subset instead. Confirming a checked "Updated" row is what actually lets a hub's description win over your own where it otherwise wouldn't — uncheck that specific row first if you'd rather keep your own description for that one area.

### Data-Driven Grammar Definitions

The structural parser recognizes several hub mailer formats out of the box, but a new or unusual format can still come back as an empty reply. A new admin page, **Admin -> Area Management -> AreaFix Grammars** (`/admin/areafix-grammars`), lets a sysop describe a new format as data instead of waiting for a code change:

- Each grammar definition is a JSON object specifying a header pattern (to detect the format), a per-row pattern (to extract the area tag, description, and status), and how status text maps to subscribed/unsubscribed/available. The full schema is documented on the page and in `docs/AreaFix.md`.
- A **Paste from AreaFix Message** button lets you paste the raw text of a hub reply and have the configured AI provider suggest a grammar definition for it. The suggestion is always added disabled, and every regex in it is validated, so nothing starts matching mail until you review and explicitly enable it.
- A **Populate from Example** button loads a starter definition from `config/areafix_grammars.json.example`, which ships disabled and has no effect until you edit and save it.
- A **Test Against Sample** button lets you paste a sample reply and see exactly what your grammars (saved or not, enabled or not) would extract from it before you save — which format matched and every tag, description, and action it found. Nothing is written to disk by this button.
- Grammars you define are tried after the built-in structural formats and before the last-resort freeform line matcher, in the order they appear on the page.

### Per-Uplink Format Memory

A given hub's AreaFix/FileFix robot always replies in the same format, so BinktermPHP now remembers which format matched the last confirmed sync for each uplink and robot (AreaFix and FileFix are tracked separately). On the next reply from that uplink, the remembered format is tried first, and if a reply no longer matches it, the sync preview shows a warning naming the old and new format — a concrete signal that the hub's mailer software may have changed or been reconfigured.

The remembered format for each uplink is visible and directly editable from **Admin -> BBS Settings -> BinkP Uplinks -> Edit Uplink**: a "Remembered Reply Format" panel shows the current format for AreaFix and FileFix, with buttons to force it to a specific format or clear it. Clearing is useful after you've confirmed a hub's format really did change; forcing is useful to pre-seed a known format for a brand-new uplink before its first reply arrives.

### Automatic Area Sync on Reply Now Opt-In

When your BBS receives a netmail reply from a hub's AreaFix or FileFix robot that looks like an area list (for example, the response to a `%LIST` or `%QUERY` command), BinktermPHP can automatically create matching `echoareas` or `file_areas` rows and activate them, using the descriptions the hub reports.

Starting with this release, that automatic sync is **disabled by default**. Incoming AreaFix/FileFix replies are still stored and viewable as normal netmail; they simply no longer create or activate local areas on their own. Sysops who want to review and apply a hub's area list continue to do so from **Admin -> AreaFix / FileFix**, using the **Sync to Echo Areas** button on a parsed reply.

If you relied on the previous automatic behavior — for example, to pick up new areas from your hub without visiting the admin page — set the following in `.env` to restore it:

```
AREAFIX_AUTOIMPORT_ENABLED=true
```

### Fixed: AreaFix Sync Set an Override Address on Echo Areas

Each echo area has an optional **Uplink Address** field in **Admin -> Echo Areas**, described as "Override Uplink FidoNet address". When it is empty, echomail for that area is sent to the uplink configured for the area's network. When it is set, echomail for that area is sent to that address instead, which is only wanted when a sysop deliberately routes one area differently.

Syncing areas from an AreaFix reply (the **Sync to Echo Areas** button, or automatic sync when `AREAFIX_AUTOIMPORT_ENABLED=true`) was filling this field in with the hub's address on every area it created, and on existing areas where it was empty. Newly created echo areas therefore appeared to have an override that nobody had set. The sync no longer writes this field.

When the *deactivate missing* option is used, it now deactivates active areas in the same network domain whose tag is not in the hub's list. Previously it only considered areas whose Uplink Address matched the hub, which would have skipped areas that have no override.

Echo areas that were already given an Uplink Address by an earlier sync keep it, because it cannot be distinguished from an address a sysop entered on purpose. If you see an override you did not intend, open the area in **Admin -> Echo Areas** and clear the **Uplink Address** field.

## BinkP

### Slow Peers No Longer Stall Frame Reads

While a session is transferring files, BinkpSession and the crashmail sender poll the connection for the next frame. The poll first checked that at least one byte was waiting, and then read the rest of the frame with a read that waits for every byte. If the peer sent a frame in pieces, for example the header and command byte, a pause, and then the payload, the poll sat in that read until the BinkP timeout ran out. Nothing else in the session loop ran during that time, so a session with a slow or fragmenting peer hung until it timed out.

A poll now reads only the bytes already available and returns at once. The part of the frame received so far is kept for that connection, and the next poll continues from it, so the rest of the payload is never mistaken for the start of a new frame. End-of-file and read errors are reported as before. The handshake still waits for each frame, as it did.

Sessions with well-behaved peers behave the same. Sessions with a peer that delivers frames slowly now complete instead of waiting out the timeout.

### Sessions That End Abnormally Reported as Failed

A BinkP session can only finish cleanly by reaching its terminated state, which happens after both sides have exchanged M_EOB and the connection closes in the expected order. Two other endings were possible: the hard EOB or inactivity timeout expiring, and the peer disconnecting before the M_EOB exchange completed. In both cases the session logged a warning, `Session ended (final state: N)`, and then reported success. The session log recorded `success`, a poll was shown as successful, and nothing was retried.

A session now reports success only when it reached the terminated state. In the other cases:

- the protocol log records `Session ended abnormally without reaching clean termination (final state: N)` as a warning;
- an outbound poll fails with the message `Session ended abnormally without reaching clean termination`, so the scheduler treats it like any other failed poll;
- an inbound session is recorded as `failed` in the session log.

After upgrading, you may see failed sessions with peers that stall or cut the connection early. Those sessions were failing before, and were reported as successful.

### Fixed: Wrong Packet Password Stopped All Inbound Processing

When an inbound packet carried a packet password that did not match, the packet handler closed the packet file and then raised an exception. The handler that caught the exception closed the same file a second time. On PHP 8 that raises a `TypeError`, which neither the packet handler nor the inbound loop catches. One bad packet therefore ended the whole inbound run. The packet was not marked as failed, so the next run read the same packet, failed the same way, and all inbound mail behind it stayed unprocessed.

The packet file is now closed in exactly one place, so the wrong-password packet is rejected with `Packet password mismatch`, recorded as an error, and the run continues with the next packet. The file handle is also released when processing fails for any other reason.

If inbound mail has been stuck behind a packet with a bad password, it is processed again on the next run after the upgrade, and the bad packet is rejected.

### Fixed: process_packets.php Lock File Errors Reported as Already Running

`scripts/process_packets.php` takes a lock on `data/run/process_packets.lock` so only one copy runs at a time. If the lock file could not be opened, because the file or directory belonged to another user, could not be written, or was missing, the script logged `Another instance of process_packets.php is already running` and exited with status 0. Cron and the scheduler saw a clean run, and inbound packet processing silently stopped.

The three cases are now told apart:

- another copy holds the lock: the script logs the same warning and exits with status 0, as before;
- the lock file cannot be opened: the script logs an error that includes the reason from the operating system and exits with status 1;
- the lock call fails for any other reason: the script logs an error and exits with status 1.

If you see the new error after upgrading, correct the owner or permissions of `data/run/` and `data/run/process_packets.lock` so the user running the script can write to them.

### Fixed: Inbound Connections Closed Reliably

The BinkP server decided whether to close an accepted connection by testing it with `is_resource()`. On PHP 8 an accepted socket is a `Socket` object, so that test was always false and the close never ran. Once the connection had been converted to a stream, nothing recorded which of the two handles owned the descriptor. When a session failed with an exception, the session was not closed at all, and the descriptor stayed open until PHP freed it.

Two related problems are also fixed:

- the busy reply sent when the maximum number of connections is reached only caught `Exception`. On PHP 8 a call on a bad or closed socket throws `Error`, which escaped into the accept loop;
- if setting up the stream failed after the socket had been converted, the caller could try to close the already-converted socket, which throws on PHP 8.

Each accepted connection is now closed exactly once, through whichever handle owns it, whether the session succeeds, fails, or fails while being cleaned up. Errors raised during cleanup are logged and do not propagate. The forked child process for a connection can no longer return into the parent's accept loop.

### Scheduled Polls Run One at a Time, and Never Overlap on the Same Host

The scheduler started each poll through the admin daemon's background `binkp_poll` command. That command returns as soon as the poll process is launched, so the scheduler recorded every poll as successful and ran inbound packet processing before anything had been received. Separately, several networks are often served by the same hub. Their polls ran at the same moment, and many mailers allow only one session per address, so they collided. The admin daemon client also retried a failed command once, so a synchronous poll that took longer than the read timeout was dialed a second time while the first was still running.

**Polls now run one at a time.** Scheduled polls, outbound-triggered polls and hub-node pushes wait for the poll to finish. Packets are then processed after each poll, using the result of that poll. A slow or unreachable hub delays the other polls in the same pass, and the BinkP timeout in the admin interface limits how long a session may be silent, not how long it may run in total.

**One session per host at a time.** Before dialing, BinkP takes a lock on the remote `host:port`. The scheduler, the admin daemon, `scripts/binkp_poll.php` and `scripts/freq_getfile.php` all use it. If another session to that host is still running after 90 seconds, the poll is deferred instead of dialing alongside it:

- the scheduler logs it as deferred (a warning), not failed, and the uplink is polled again on its next schedule;
- `scripts/binkp_poll.php` and `scripts/freq_getfile.php` exit with status 75 when a poll was deferred and nothing failed, and with status 1 if any poll failed;
- a deferred FREQ request does not use up one of its `FREQ_MAX_ATTEMPTS`; an attempt is counted only when the session is about to dial.

The lock is keyed by host name, so two names for the same machine, such as a host name and its IP address, are not serialized. Use the same host name for every uplink served by one machine. Connection tests and the authentication test in the admin interface do not take the lock.

**Synchronous polls are not retried.** If the admin daemon does not answer in time, the client reports that the response timed out and the command may still be running, and does not send it again. The wait is the BinkP timeout from the admin interface, plus the 90-second lock wait, plus 60 seconds, multiplied by the number of enabled uplinks when polling all of them. If a session is still transferring after that, it finishes in the background and the host lock keeps any later poll from overlapping it.

**Lock files.** Lock files are kept in `data/run/binkp-host-locks/`. To use another directory, set `BINKP_HOST_LOCK_DIR` in `.env` and restart the scheduler and admin daemon. The directory is created group-writable with the setgid bit (`2775`), and lock files are created `0664` whatever the umask, so the scheduler, the admin daemon and any cron job that run as different accounts in the same group share the same locks. A directory this account already owns is corrected to that mode. If an account cannot open a lock file, the poll is not dialed and an error naming the lock path is logged. The poll is never run without the lock. If you see that error, give the accounts that poll uplinks a shared group on the directory:

```bash
chgrp <shared-group> data/run/binkp-host-locks
chmod 2775 data/run/binkp-host-locks
chgrp <shared-group> data/run/binkp-host-locks/*.lock
chmod 664 data/run/binkp-host-locks/*.lock
```

The admin daemon runs each client in its own process only when the PHP `pcntl` extension is available. Without it, a synchronous poll holds up every other admin daemon command until it finishes, so scheduled polling is meant for installations that have `pcntl`, which includes the Docker image.

### Fixed: Mail Posted During a Scheduled Poll Waited Up to 30 Minutes

When outbound mail is queued for an uplink, the scheduler polls it within about a minute. While the same files stay queued, it does not poll that uplink again until the outbound queue timer has passed since the last poll, 30 minutes by default. Every scheduled poll also counts as a poll for this timer, and an uplink that had a scheduled poll in the current scheduler pass is not polled again for outbound mail, on the assumption that the session carried the queued files.

That assumption fails for mail queued after the scheduled session had started. Because scheduled polls now wait for the session to finish, that window lasts as long as the session. A message posted in it was skipped as already delivered, the queue timer then held it back, and the scheduler logged `Outbound files found for <address> but queue timer has <n>s remaining` until the timer ran out.

The scheduler now records which outbound files existed when each scheduled poll started. If files for that uplink appeared after that point, the uplink is polled again in the same pass, without waiting for the queue timer, and the scheduler logs `New outbound files for <address> arrived after its scheduled poll started, polling now` at the DEBUG level. Files that were already queued when the scheduled poll started are handled as before.

## Administration

### Fixed: user-manager.php create Command

`scripts/user-manager.php create` previously failed on every PostgreSQL install with:

```
SQLSTATE[42804]: column "is_active" is of type boolean but expression is of type integer
```

This was left over from the project's earlier SQLite-based schema, where `is_active` accepted an integer. The command now inserts a proper boolean and reads back the new user's id via `RETURNING id` instead of `lastInsertId()`. If you were creating operator accounts by editing the database directly to work around this, you can now use `scripts/user-manager.php create` normally again.

### Log Rotation by Size

`scripts/logrotate.php` rotated every log on each run, so keeping a fast-growing log such as `binkp_poll.log` small meant rotating all logs more often. It now takes a size threshold:

```bash
php scripts/logrotate.php --keep=5 --max-size=10M
```

- **`--max-size=SIZE`** rotates only a `*.log` file that is at least this large. SIZE is a number of bytes or a number with a `K`, `M` or `G` suffix. A value that is not a valid size makes the script exit with an error. Without the option every log is rotated, as before.
- **`--logs-dir=PATH`** rotates a different directory from `data/logs`. Rotated copies go in `old/` under that directory.
- **`--keep=N`** now also deletes old generations beyond N when you lower it between runs. Before, only the single oldest generation was removed, so lowering it left the extra files in place.
- **`--dry-run`** now starts its "Rotated" lines with `[dry-run]`.

To cap a fast-growing log, schedule the script more often and let `--max-size` decide what is rotated. For example, an hourly cron entry with `--keep=5 --max-size=10M` leaves small logs alone.

**Docker:** the container's log rotation job accepts an optional `LOGROTATE_MAX_SIZE`, for example `10M`, which is passed to the script as `--max-size`. Set it in the `environment` section of `docker-compose.override.yml`, and also set `LOGROTATE_SCHEDULE` to something more frequent than the weekly default (`0 0 * * 0`). A value that is not a number with an optional `K`, `M` or `G` suffix is ignored, with a warning in the container log. When it is unset, behavior is unchanged. See [DOCKER.md](DOCKER.md) and [CLI.md](CLI.md).

### SQL Migrations Split on Real Statement Boundaries

`scripts/upgrade.php` runs a `.sql` migration one statement at a time. It used to find the statement boundaries with two patterns: delete everything from `--` to the end of the line, then split wherever a `;` ended a line. Valid SQL could break under that. A `--` inside a string, such as `DEFAULT '--'`, cut the line short. A `;` at the end of a line inside a quoted string, a function body or a `DO` block, or a block comment, split the statement in the middle.

The upgrade script now uses `src/PostgresSqlSplitter.php`, which reads the SQL the way PostgreSQL does. It leaves quoted strings and identifiers (including doubled quotes and `E'...'` escape strings), dollar-quoted bodies and tags, line comments and nested block comments intact. It reads the connection's `standard_conforming_strings` setting before each statement, so a `SET` earlier in the same migration is respected. If a string, quoted identifier, comment or dollar-quoted body is never closed, the migration stops with an error naming the line and byte offset, and the migration is rolled back as for any other error.

Every `.sql` migration shipped with BinktermPHP produces the same statements as before. The difference for you is in what reaches PostgreSQL: comments in a migration are now sent along with the statement they belong to, so they can appear in a PostgreSQL error message, and several statements written on one line are now run as separate statements. PHP migrations are not affected.

Only the upgrade script uses the new splitter. `scripts/install.php`, which loads the base schema on a new install, keeps its existing parser. See [PostgreSQLDependencies.md](PostgreSQLDependencies.md).

### Docker: telnet Client Added for the BBSLink Door

The BBSLink native door (`bbslinknative`) uses the `telnet` command to connect to BBSLink. The container image did not include it, so the door could not make that connection inside Docker. The image now installs the `telnet` package. Rebuild the image to include it: `docker compose build` followed by `docker compose up -d`.

### Admin Daemon Keeps Log Lines It Cannot Write

When a BinktermPHP process cannot append to its own log file, for example because the file was created by a different system user than the one the process runs as, it sends the log line to the admin daemon over UDP, and the daemon writes it for them. The daemon runs as a single service account, so the same ownership mismatch can stop it from writing that file too. The daemon ignored that failure, and the line was lost without a trace. This most often cost the detailed protocol trace of a BinkP session, which is the information needed to diagnose a session that stalled or hung.

When the daemon cannot write the target file, it now logs a warning to `data/logs/admin_daemon.log` that names the intended log file and the process id, and contains the full text of the lost line. The line is therefore kept, though in `admin_daemon.log` instead of the file it was meant for. If the file that cannot be written is `admin_daemon.log` itself, nothing is recorded, because there is nowhere else to write it.

This does not fix the underlying permissions problem. If you see these warnings, correct the owner or mode of the named log file so the process that writes it can append to it.

### Fixed: Each Login Recorded Exactly Once

Login events feed Today's Callers, the total login count and the administrator's logins-by-source breakdown. Two problems made those numbers wrong:

- A Telnet, TLS-Telnet or SSH login was recorded twice: once, untagged, when the terminal server authenticated the user through the web login API, and again by the terminal session itself. The total login count and Today's Callers were inflated, and the untagged row was counted as a web login.
- A PacketBBS LOGIN was never recorded, and did not update the user's last login time.

The login API is now the single place a login is recorded. It tags the event with the service the login arrived on and the caller's real IP address, and the terminal session no longer records a second one. A successful PacketBBS LOGIN now updates the user's last login time and records one event tagged `packetbbs`. Later PacketBBS commands do not record another, and a revoked session followed by a new LOGIN counts as a new login. An auto-approved terminal registration is recorded once in the same way.

Login events recorded before the upgrade are not changed, so historical counts for Telnet and SSH callers stay higher than they should be.

### Downlink Queue Sorted by Owner and Shows Network Domain

The **Downlink Queue** tab on the BinkP status page summarizes the outbound packets queued for each downlink node or point, with its pending, failed, held and sent counts. The summary table was sorted by address alone, so downlinks belonging to the same user were scattered through the list, and an address did not show which network it belonged to.

- **Order:** downlinks are now sorted by owner username first, ignoring letter case, and by address second. Addresses compare numerically within an owner, so `21:3/236.10` comes before `21:3/236.11`. Downlinks with no owner are listed first.
- **Domain:** each address is now followed by its network domain, for example `21:3/236.10@examplenet`. For a node the domain is looked up from its address, and for a point it is the domain of the boss address it is attached to. If no domain can be determined, the address is shown without one.

The queue list API behind this tab, `getHubOutboundQueue()`, now returns a `domain` field on each row.

## Web Interface

### Fresh Assets After an Upgrade

BinktermPHP's service worker keeps its own cache of CSS, JavaScript and other static files, and starts a new, empty cache whenever a release changes the cache name. It filled the new cache with ordinary requests, which a browser may answer from its own HTTP cache. A browser that still held a file from before the upgrade could therefore put that old copy into the new cache, and keep serving it until the cache name changed again. The symptom was a page that looked or behaved like the previous version after an upgrade, until the user force-reloaded.

Files that are not yet in the service worker's cache are now requested with revalidation: the browser asks the server whether its copy is still current, which costs a small "not modified" reply when it is, and downloads the file when it is not.

### Login Page Honors "Remember Me"

The login page has a "Remember me" checkbox, but its state was never sent to the server. Every web login received a session cookie that lasts 30 days, whether the box was checked or not.

The login page now sends the checkbox state:

- **Checked:** the session cookie lasts 30 days, as before.
- **Unchecked:** the session cookie has no expiry, so the browser discards it when it closes and the user must sign in again the next time they open the browser.

A client that does not send the setting at all, such as an older script calling `POST /api/auth/login`, still receives the 30-day cookie. Only a value of `true` counts as checked once the setting is sent.

### Recovery From a Stale CSRF Token

Each user has one CSRF token, which is replaced every time that user logs in. A browser page keeps the token it was given when it loaded, and a Telnet or SSH session keeps the token from its own login. When the same user logged in anywhere else, for example in a second browser or by connecting over Telnet while a tab was open, the token held by every other open page and terminal session became stale. Their next save, post or send was rejected with "Invalid CSRF token", and kept being rejected until the page was reloaded or the terminal session reconnected.

Stale tokens are now repaired automatically:

- **Pages using `fetch()`:** when a same-origin POST, PUT, PATCH or DELETE is rejected because of a stale token, the page fetches the current token, updates itself and repeats the request once. The user sees nothing.
- **Pages using jQuery:** the rejected request is not repeated. The page fetches the current token so that the user's next action succeeds, so that one action may still need to be tried again.
- **Telnet and SSH sessions:** a rejected request is repeated once with the current token, and later requests in that session use it.

A rejected request was never carried out, so repeating it cannot do the same thing twice. The server still checks every request against the live token, and other 403 errors are not repeated.

The token comes from the new `GET /api/auth/csrf-token`, which requires a signed-in session and returns the session's current token without changing it. It exposes nothing that signed-in pages do not already contain, and a page on another site cannot read it. See [API.md](API.md).

Restart the telnet and SSH daemons so they load the new code; `scripts/restart_daemons.sh` does this.

### Telnet Address on the Dashboard

The System Information card on the main dashboard now shows a **Telnet** line with the address users connect to, for example `bbs.example.com:2323`. The host comes from `SITE_URL` and the port from `TELNET_PORT` in `.env` (default `2323`); the port is left off when it is `23`. The line is hidden when `SITE_URL` has no host. The text shrinks to fit on a single line, so long host names no longer wrap.

### ANSI Avatars

Each account can now have one optional avatar: a 10 column by 6 row block of colored CP437 characters, stored in the Synchronet-compatible 120-byte BIN format. The avatar is plain glyph and color data and is never sent to a terminal as raw escape codes.

- **Editing:** signed-in users paint an avatar on the **Profile** page, or import a 120-byte `.bin` file. Any other file size is refused, as is an image that is entirely blank on a black background. Users can also remove their avatar.
- **Viewing:** other signed-in users see the avatar on `/profile/{username}`. Users without one see the first two letters of their username instead.
- **Terminal:** the public profile viewer, opened from Who's Online or from the **View ANSI avatar** settings action, draws the avatar on color-capable full-screen sessions. Sessions with ANSI color turned off, and the line shell, show the username initials. Raw CP437 bytes are only written to sessions whose saved terminal character set is CP437; UTF-8 and ASCII sessions receive equivalent characters.
- **API:** `GET /api/user/ansi-avatar`, `GET /api/user/ansi-avatar/{id}`, `POST /api/user/ansi-avatar` and `DELETE /api/user/ansi-avatar` read, replace and remove avatars, and `GET /api/user/public-profile/{id}` now includes `profile.ansi_avatar`. See `docs/API.md`.
- **Database:** a new table, `user_ansi_avatars`, holds the avatars and is removed with the account.

Details of the format and rendering rules are in `docs/AnsiAvatars.md`.

### Fixed: BBS Menu Shell Scripts

Pages rendered with the BBS menu shell did not load `user-storage.js`, so scripts that keep per-user browser state could not run correctly there, and `window.userSettings` was not defined. The shell now loads `user-storage.js` and sets `window.userSettings` from the signed-in user's settings, falling back to the `America/Los_Angeles` timezone when the user has none saved.

The shell also loaded `ansisys.js` unconditionally in its base template, while individual pages loaded it again. It is now loaded once, and only when the shell's appearance variant is `ansi`.

## Web Doors

### Longer Browser Caching for Door Assets

Door icons and screenshots served through `/door-assets/{doorid}/{asset}` — whether stored as files or as database blobs — now set:

```
Cache-Control: public, max-age=604800, stale-while-revalidate=86400
```

- **`max-age=604800`** (7 days, up from 1 day) tells the browser it can reuse a cached copy of the asset for up to a week without re-checking with the server.
- **`stale-while-revalidate=86400`** (1 day) lets the browser keep serving its cached copy for up to a day past that while it revalidates in the background, instead of blocking the page on a fresh request.

Requests also now include an `ETag` (and, for filesystem-backed assets, a `Last-Modified` header), so once the 7-day cache does expire, the browser can send a conditional request and get a lightweight `304 Not Modified` response instead of re-downloading the asset if it hasn't changed.

Door game listing pages also load door icons with `loading="lazy"`, so icons off-screen are not fetched until the user scrolls to them.

If you update a door's icon or screenshot file, its changed modification time (or content hash, for database-stored assets) invalidates the old cached copy automatically.

### RLogin Door Asset Sizes Stored in the Database

RLogin doors store their icon and screenshot images as binary data directly in the `rlogin_doors` table, since these doors have no directory on disk. Their byte sizes are now stored in new `icon_size` and `screenshot_size` columns on that table, populated whenever an icon or screenshot is uploaded through **Admin -> RLogin Doors**. Existing icons and screenshots are backfilled automatically by the upgrade migration, so their sizes are recorded immediately without needing to re-upload anything.

### MRC Chat Loads Its Libraries From the Bundled Copies

The MRC chat page loaded Bootstrap 5.1.3, Bootstrap Icons and jQuery 3.6.0 from jsDelivr and code.jquery.com. With a strict Content Security Policy, or on a server without access to those hosts, the page rendered without styling, scripts or icons. It now uses the Bootstrap 5.3.0, jQuery 3.7.1 and Font Awesome 6.4.0 copies that the rest of BinktermPHP already serves from `/vendor/`, so nothing is fetched from a third party.

### DOS Doors Redraw After the Browser Reconnects

If the browser's connection to a running DOS door dropped and came back within `DOSDOOR_RECONNECT_TIMEOUT` (90 seconds by default), the session was reattached but the terminal stayed blank until the door next wrote something, and anything the door wrote while the connection was down was discarded. After a short network drop or a page reload, the player was left looking at an empty screen over a game that was still running.

The DOS door bridge now keeps the most recent terminal output of each session, up to 512 Ki characters, and sends it to the browser as soon as it reconnects, so the current screen is drawn again. Output the door produces while the connection is down is kept the same way. The log line `WebSocket not ready, dropping N bytes` is replaced by one saying the output was buffered for replay.

Only the most recent output is kept, so a very long session replays its latest part rather than everything since the door started. Restart the DOS door bridge so it loads the new code.

### Mobile Touch Controls and Scaling for Door Games

On phones and tablets, door sessions showed a cropped 80x25 screen, dim green and magenta text that was hard to read, no Esc, arrow or hotkey keys on the on-screen keyboard, and a page that overflowed under the browser's address bar. DOS doors and RLogin doors, and the guest door player, now load a mobile terminal add-on that addresses these:

- **Scaling:** the 80x25 screen is scaled to fit the width and height of the viewport and anchored to the top. It follows the viewport when the browser's address bar or the on-screen keyboard appears or goes away. The door title bar is made compact on small screens.
- **Touch gamepad:** a docked three-row control strip. The first row has Esc, Enter, Space, Backspace, a button to show or hide the on-screen keyboard, a contrast button and zoom controls. The second has an arrow pad and the Y, N and Q keys. The third is a scrollable row of the digits 0 to 9 plus hotkeys.
- **Game layouts:** layouts are chosen from the door's ID or name for Operation Overkill II, Legend of the Red Dragon and Trade Wars 2002, and a standard layout is used for other doors. A layout button opens a picker to switch to another layout.
- **Contrast modes:** Vivid brightens the dim CGA colors for OLED phone screens, and Amber and Standard are also available from the contrast button.
- **Mouse wheel:** scrolling the mouse wheel over a door terminal no longer sends stray escape codes or periods to the door.

Desktop browsers keep the existing mouse and keyboard behavior. The door pages request the new stylesheet and script with a version taken from the file's modification time, and the service worker cache version was raised, so browsers load the new files without a manual refresh.

### Door Connections Kept Alive Through Proxies

Door sessions reach the DOS door bridge through the web server and often through a reverse proxy or CDN such as Cloudflare, nginx, Apache or Caddy. Many of these close a WebSocket that carries no data for a fixed period. A door waiting at a menu produces no output, and the browser sends nothing until the player types, so the connection was dropped and the player saw the connection close.

Two keepalives now run:

- The bridge sends a WebSocket ping to each connected browser every 20 seconds. Browsers answer automatically. If a browser has not answered by the next ping, the bridge ends that connection, and the session is held for `DOSDOOR_RECONNECT_TIMEOUT` as for any other dropped connection. Change the interval with `DOSDOOR_WS_HEARTBEAT_INTERVAL_MS` (milliseconds) in `.env`.
- The DOS door and RLogin door pages send a small ping message to the bridge every 25 seconds and ignore its reply. This keeps proxies that only count data messages, and mobile browsers that pause timers, from timing out the connection.

`DOSDOOR_RECONNECT_TIMEOUT` now defaults to 90 seconds instead of 30, which gives a player longer to come back after a page reload or a network change. Set it in `.env` to use another value. Restart the DOS door bridge so it loads the new code.

### DOS Door ANSI.SYS Screen Clear Homes the Cursor

MS-DOS ANSI.SYS clears the screen and moves the cursor to the top left when it receives `ESC [ 2 J`. The terminal in the browser clears the screen but leaves the cursor where it was, so DOS doors that expected the cursor at the top left drew their next screen in the wrong place. The bridge now adds the cursor move for DOSBox, RLogin and CP437 native doors, and the door pages do the same for output they receive. A clear-screen sequence that is already followed by a cursor-home sequence is left as it is. The door pages also hold back an escape sequence cut off at the end of a message until the rest arrives.

### Door Screens Flushed Before the Connection Closes

When a door process exits, the bridge used to close the browser's connection at once, which could discard output still on its way, such as a game-over screen or the details of a character's death. It now waits 800 ms before closing, so the final screen reaches the browser.

### Fixed: Door Player Limits Could Be Exceeded by Simultaneous Launches

The door launch routes (`/api/door/launch` and `/api/door/guest/launch`) checked a door's maximum number of players, and then started the session as a separate step. If several players launched the same door at the same moment, each could see free space and start a session, so the door ended up with more players than its limit. Picking the next free node number had a similar gap: with no session rows to lock, two launches could be given the same node.

Door admission now runs under a PostgreSQL transaction-level advisory lock. The per-door player limit, the node selection and the session insert all happen inside it, so simultaneous launches are handled one at a time and each sees the others' sessions. A launch that arrives when the door is already full is refused with HTTP `503` and the message "Door at capacity".


### Radio Settings Link on the Dashboard

The PacketBBS Nodes card on the main dashboard, shown when MeshCore is enabled, now has a "My MeshCore radios" link beside "View all nodes". It opens **Settings** directly on the **MeshCore** tab, where you can add, edit, and remove your own radios. The settings page also accepts `/settings#meshcore` as a direct link to that tab.

### Fixed: PubTerm Native Door Block Graphics

The PubTerm native door connects to a Telnet host and relays its output to the caller. Its manifest declared UTF-8 output, so the CP437 block and line-drawing characters sent by ANSI art were corrupted into Unicode replacement characters. The manifest now declares `cp437` output, and the launch script starts the Telnet client with `-8` so all 8 bits of each byte are passed through. The script is also now marked executable.

If you copied or customized the PubTerm manifest (`native-doors/doors/pubterm/nativedoor.json`), set `output_encoding` to `cp437` in your copy.

## Networks

### SysopNet Added to the Networks List

The upgrade migration registers SysopNet (domain `sysopnet`, https://sysopnet.com) in the networks table, using the real-name posting policy and CP437 as the default code page. You can review or change these in **Admin -> Networks**. To join SysopNet you still need to request a node at sysopnet.com/node-request/ and add the uplink details your hub gives you. If a network with the domain `sysopnet` already exists, the migration leaves it unchanged.

### Networks Listed Alphabetically

The network list in **Admin -> Networks**, and the network dropdown in the uplink editor under **Admin -> BBS Settings -> BinkP Uplinks**, previously showed all built-in networks first and then any other networks (such as locally created ones, or SysopNet) in a separate group below them. They are now sorted by name in a single list, regardless of whether a network is built in.

## BBS Directory

### Geocoding Provider Failures No Longer Cached

The BBS directory looks up map coordinates for each listed location and keeps the answers in the `geocode_cache` table. A failed request to the provider (a network error, timeout, error response or unreadable reply) used to be saved as an empty answer, exactly like the provider replying that it found no match. A short outage during an automated backfill could therefore leave locations permanently without coordinates.

Now only real answers are cached: a result with coordinates, or a successful reply with no match. A failed request is not cached, never replaces an existing cache entry, and is tried again on the next run.

The upgrade migration adds a `status` column to `geocode_cache`. Existing rows that have coordinates are marked as successes, and existing rows without coordinates are marked as no-match, because there is no way to tell whether an old empty row was a real no-match or an earlier failure. The migration does not look those locations up again.

### Geocoding Backfill Skips Known No-Match Locations

`scripts/geocode_bbs_directory.php` backfills coordinates for directory entries that have a location but no coordinates. When run with a limit, it picked the entries with the lowest ids first. Entries whose locations can never be found stayed at the front on every run and used up the whole batch, so newer entries were never reached.

The backfill now leaves out locations already cached as no-match before applying the limit, and examines at most 2000 candidate entries per run (or 20 times the limit, if that is larger). The script prints a new line, "Rows excluded (known permanent no_result)", with the number of entries it left out.

An entry whose location text is changed is looked up again automatically, because the cache is keyed on the location text. To retry a location without changing its text, delete its row from `geocode_cache`.

## Terminal Server

### Cursor Keys, Bracketed Paste and Lone ESC

The terminal server reads keys from Telnet and SSH sessions one escape sequence at a time. Three kinds of input were handled badly:

- Cursor keys sent with a parameter, such as `ESC [ 1 D` for Left or `ESC [ 1 ; 5 D` for Ctrl-Left, were discarded as if they were terminal status reports.
- Terminals in application cursor-key mode send arrows as `ESC O A` to `ESC O D`, and Home and End as `ESC O H` and `ESC O F`. These leaked into the screen as typed characters.
- Many terminals wrap pasted text in `ESC [ 200 ~` and `ESC [ 201 ~` markers, even when the server has not asked for it. These appeared as unknown keys.

Parameterised and application-mode cursor keys are now treated the same as the plain arrow keys. Paste markers are ignored and the pasted text passes through. Other terminal-generated reports are still discarded, and they no longer count as user activity. Pressing ESC on its own is reported as ESC, where it used to be reported as an empty key.

### UTF-8 Safe Line Editing and Paste Containment

The three places that read a line of text from a terminal user were fixed to share one editor:

- A multi-byte UTF-8 character was split across reads, which either cancelled the input dialog or dropped the character. Characters are now read whole, and the editor works in characters, not bytes.
- The left and right arrow keys, which the boxed input dialog ignored, now move within the line, and text can be inserted or deleted at that point.
- When a pasted block had several lines, the lines after the first stayed queued and ran as keystrokes at the next menu, prompt or password field. Anything still queued is now discarded when a line is submitted or cancelled. Input typed after that point is not affected.
- The line-mode shell treated CR-LF as two Enter presses and submitted twice. It is now one.
- A paste is drawn once when it finishes rather than once per character, which on some clients made a paste look as if it had vanished.

Prompts can keep a short recall list of lines entered earlier in the same session. It holds up to 30 entries, is kept only in memory for that session, and is never used for passwords or other secret prompts. Pressing ESC alone at a plain line prompt does nothing, as before.

### Nagle's Algorithm Disabled on Telnet and SSH Connections

Telnet and SSH send mostly very small writes: a single echoed keystroke, or a small part of a redrawn screen. With Nagle's algorithm on, the operating system holds each small write until the previous one has been acknowledged. Over a link with noticeable latency, typing echo and screen updates arrived in bursts.

The Telnet, TLS-Telnet and SSH daemons now set `TCP_NODELAY` on each accepted connection, before any TLS handshake starts. This needs the PHP `sockets` extension, which the Docker image includes. If the extension is missing or the call fails, the connection carries on unchanged.

To keep Nagle's algorithm on, set `TERMINAL_TCP_NODELAY=false` in `.env` and restart the Telnet and SSH daemons. `0`, `no` and `off` also turn it off. Restart the daemons after upgrading so they load the new code.

### SSH Connection Rate Limit and Session Cap

The SSH daemon accepted every TCP connection, forked a process for it, and began the SSH key exchange. A flood of connections to the SSH port could therefore use up the server's processes and CPU before any login was attempted. The Telnet daemon already limited connections per address; the SSH daemon did not.

The SSH daemon now applies two checks as soon as it accepts a connection, before it forks or starts the key exchange:

- **Per-address rate limit.** Each source address may open `SSH_RATE_LIMIT_MAX` connections (default 5) in each `SSH_RATE_LIMIT_WINDOW` (default 60 seconds). Further connections from that address are closed with a short notice until the window ends. Set `SSH_RATE_LIMIT_MAX=0` to turn the limit off.
- **Session cap.** At most `SSH_MAX_CHILDREN` SSH sessions (default 32) can be open at once across all addresses. When the cap is reached, new connections are closed with a "Server is at capacity" notice until a session ends. A value of `0` or less is not treated as "no cap": the daemon logs a message and uses 32. To raise the cap, set a larger number.

Both settings go in `.env`. Restart the SSH daemon after changing them.

The SSH daemon does not read PROXY protocol headers. If SSH reaches the daemon through a proxy or port forwarder that opens its own connection to the daemon, every client appears to come from the proxy's address and they all share one rate-limit counter. In that case raise `SSH_RATE_LIMIT_MAX` or set it to `0`, and limit connections at the proxy instead. The session cap is unaffected, because it does not depend on the client address.

The Telnet and SSH daemons now use the same rate limiter. Addresses are normalized before they are counted, so an IPv4 client that arrives on a dual-stack listener as `::ffff:192.0.2.1` is counted as `192.0.2.1`. The Telnet settings, defaults and log messages are unchanged, except that a `TELNET_RATE_LIMIT_WINDOW` of `0` or less now falls back to 60 seconds. Previously it made every connection start a new window, which in effect disabled the limit. To disable the Telnet limit, set `TELNET_RATE_LIMIT_MAX=0`.

### RLogin Clients Recognized on the Telnet Ports

An RLogin client (RFC 1282) opens the connection by sending a null byte, then its own user name, the server user name, and the terminal type and speed, each ending in a null byte. The Telnet daemon treated that as ordinary Telnet input and sent its own option negotiation, so an RLogin client showed the negotiation bytes as junk on screen and the terminal type was never picked up.

The Telnet and TLS-Telnet daemons now look at the first byte of each new connection. If it is a null byte, the daemon reads the handshake and answers with the single null-byte acknowledgment RLogin expects. It also turns on ANSI color, records the terminal type the client sent, and stops sending Telnet negotiation and echo commands on that connection. Telnet clients, and anything else that does not begin with a null byte, behave as before. The SSH daemon is not affected.

The user name the RLogin client sends is read and kept for the session, but it is not used to sign anyone in. Users still log in at the BBS login prompt.

The daemon waits up to 0.2 seconds for the first byte of a new connection, so a Telnet client that sends nothing first starts that much later than before.

Native door games now fill in their user name from the handle, then the username, then the alias, then the real name. The real name, in turn, falls back to the alias. Previously a user with no handle or username was shown as `Guest`.

Restart the Telnet daemon after upgrading so it loads the new code.

### Dialup Speed Simulation for Telnet and SSH

BBS screens and ANSI art were designed to draw at modem speeds. Over a modern connection they appear instantly, which some sysops and callers do not want. The terminal server can now slow its output to a chosen baud rate.

To turn it on, go to **Admin → BBS Settings → Terminal Server Settings**, tick **Throttle Telnet server speed (dialup simulation)** and pick an **Emulated baud rate**. The choices run from 300 to 57,600 baud, and 28,800 is the default and the recommended setting. Tick **Also apply to SSH connections** to apply the same speed to SSH sessions. Without that box ticked, SSH is never throttled. Save the settings with **Save Terminal Server Settings**.

The speed is read when a caller connects, so it applies to new connections only. Sessions already open keep the speed they started with.

While throttling is on:

- output is sent in small bursts about 35 times a second, at the speed of the chosen baud rate, counting 10 bits for each character;
- a single typed character is echoed at once, so typing does not lag;
- pressing a key while a long screen is drawing skips the delay for the rest of that screen.

The setting is saved in `data/bbs.json` under `terminal_server` as `throttle_speed`, `throttle_baud` and `throttle_ssh`. A baud rate below 300 is replaced with 28,800 when the settings are saved.

Restart the Telnet and SSH daemons after upgrading so they load the new code.

## Security

### Secure Flag on Session Cookies

The `binktermphp_session` cookie is now marked `Secure` whenever the site's effective URL uses HTTPS, determined from the `SITE_URL` environment variable (or, if that isn't set, from the request's own HTTPS signal). This prevents the browser from sending the session cookie over a plain HTTP connection, closing off a path where the session id could otherwise be exposed on the wire. Installations that serve BinktermPHP over HTTPS behind a reverse proxy should ensure `SITE_URL` in `.env` is set to the `https://` URL so this detection works correctly.

### Default Terminal Registration Secret No Longer Trusted

The telnet and SSH daemons send `TERMINAL_REGISTRATION_SECRET` to the web API to report the connecting user's real IP address and to mark registrations as terminal-originated (which skips the browser-only anti-spam checks). Until now an unset value fell back to the published default `Chang3Me`, so any HTTP client could send that value to set its own recorded session IP and to skip the registration anti-spam checks.

The web side now treats an unset, empty, or `Chang3Me` value as "no secret configured" and ignores those headers.

**Docker:** the container generates a random `TERMINAL_REGISTRATION_SECRET` on first start when your `.env` leaves it unset or set to `Chang3Me`, and reuses it on later restarts. Web and terminal daemons read the same generated value.

**Other installs:** if your `.env` does not set a site-specific value, set one now and restart the web server and the telnet/SSH daemons:

```ini
TERMINAL_REGISTRATION_SECRET=<a long random string, e.g. the output of: openssl rand -hex 32>
```

Until it is set:

- telnet/SSH sessions are recorded with the server's own address instead of the caller's IP;
- telnet/SSH registrations are treated like browser registrations and are rejected by the browser timing check ("Session expired");
- `scripts/setup.php` prints a warning with a generated value you can paste into `.env`, and the server log records a warning when a terminal registration arrives.

### Failed-Login Throttle

Nothing used to limit how many times a password could be guessed. Repeated failed logins are now limited by two independent counters, each over a rolling window:

- **Per account:** 5 failures. This stops guessing against one account. Logging in by username and by real name for the same account share one counter.
- **Per source IP:** 20 failures. This is more generous, so a shared address such as a NAT gateway is not locked out by a few mistakes, while still stopping one source from trying many usernames.

The default window is 15 minutes. A login is refused while either counter is at its limit. The refusal is the same `401` "invalid credentials" answer a wrong password gets, so it does not reveal that a limit was reached or that an account exists, and nothing is locked permanently: attempts work again once the failures age out of the window. A successful login clears that account's counter but not the source IP's counter, so holding one valid account does not reset an IP that has been guessing at others.

The throttle covers every place a username and password is checked: the web login, Telnet, TLS-Telnet and SSH (which sign in through the web login), FTP, NNTP and QWK HTTP downloads and uploads. A throttled FTP login gets `530 Login incorrect` and a throttled NNTP login gets `481 Authentication failed`. Anonymous FTP has no credentials and is not throttled. Loopback and the server's own address are not counted against the IP limit, so a Telnet or SSH installation whose sessions all appear to come from the server cannot lock out every terminal user. The per-account limit still applies.

To change the limits, set these in `.env`:

```ini
AUTH_LOGIN_USER_MAX=5
AUTH_LOGIN_IP_MAX=20
AUTH_LOGIN_WINDOW=900
```

`AUTH_LOGIN_WINDOW` is in seconds. A value that is not a positive number uses the default, so the throttle cannot be switched off by a bad value. The counters are kept in a new `auth_login_attempts` table, created by the upgrade migration, so they are shared by all web workers and the terminal daemons. If the table cannot be reached, for example because the migration has not run, logins proceed unthrottled and the error is written to the server log.

Restart the telnet and SSH daemons so they load the new code; `scripts/restart_daemons.sh` does this. See [CONFIGURATION.md](CONFIGURATION.md#failed-login-throttle).

### Retro Audio Proxy Hardened

The retro audio player loads tracker, SID and MIDI files linked from messages through `GET /api/media/raw`, which fetches the remote file on behalf of the browser. The endpoint had two weaknesses that are now fixed:

- **Active content served from the BBS origin.** The remote server's `Content-Type` was passed through and the file was served inline, so a link ending in `.mid` could return HTML or JavaScript that ran as the BBS for anyone who opened the proxy URL. The response is now always an opaque `application/octet-stream` download with `nosniff` and a sandboxing `Content-Security-Policy`. The player reads the file with `fetch()`, so playback is unaffected.
- **Fetches to internal addresses.** The host was checked with an IPv4-only lookup, and the fetch then resolved the name again, so a host with an internal IPv6 address, or one that changed its DNS answer between the check and the fetch, could make the server connect to an internal service. Every IPv4 and IPv6 address of the host must now be public, and the connection is pinned to exactly those addresses on every redirect hop. Only `http` on port 80 and `https` on port 443 are fetched, proxy settings in the server environment are ignored, and the 8 MB limit is enforced while downloading, so it also applies to responses that do not announce a length.

The endpoint remains available to visitors who are not logged in, so retro audio keeps playing on public shared messages.

### Telnet and SSH Sessions Disconnected When Their Web Session Is Revoked

A Telnet or SSH login creates a web auth session, which was checked only once, at login. If that session was later removed, the terminal session stayed connected with full access, and so did any door the user was playing. A session can be removed by:

- **Revoke** or **Revoke all sessions** in **Settings**;
- a password reset, which deletes all of the user's sessions;
- the session expiring, or the account being deactivated.

The terminal server now re-checks the session at most every 30 seconds while the user is at a prompt or waiting for a keypress, and while a door is running. Once the session is gone, the user sees "Your session was signed out elsewhere - disconnecting..." and is disconnected. A user inside a door is taken out of the door first. Once a session is found revoked it stays revoked.

### PacketBBS Sessions End When Their Web Session Is Revoked

A PacketBBS login, made from a radio node, is linked to a web auth session. That link was not checked after login. If the web session was removed, the node stayed authenticated and kept running commands, and bridge polls kept delivering private messages to it. A session can be removed by:

- **Revoke** or **Revoke all sessions** in **Settings**, or a logout;
- an administrator ending the session;
- the session expiring, or the account being deactivated.

PacketBBS now checks the linked session on every command and every bridge poll. When it is gone:

- the node's identity, linked session, navigation context, drafts and pending chat notifications are cleared, the same as when a login expires from inactivity;
- a command is not run, and answers `Session expired. LOGIN again.`;
- a poll delivers nothing, and the expiry notice is kept for the node's next command;
- no replacement web session is created, so the user has to LOGIN again.

Related changes to PacketBBS sessions:

- Unsent chat notifications are discarded on QUIT, on a successful LOGIN and on expiry, so they cannot be delivered under a different login. Ordinary activity by the same user and already-delivered history are not affected.
- When a login expires from inactivity, the node's session row is now reset instead of deleted, and it keeps its bridge binding. Removal of stale rows is controlled separately by `PACKETBBS_SESSION_RETENTION_SECONDS` in `.env`, which defaults to `86400` (24 hours). A value of zero or less uses the default. This setting has no admin page.
- A session created before this release is bound to its bridge the first time it is used by an authorized command.

A database error during the re-check is logged and is not treated as a revocation, so a brief database outage does not disconnect users. Nothing is checked before login.

The message is a new `ui.terminalserver.server.session_revoked` key, added to all six locales. Restart the telnet and SSH daemons so they load the new code; `scripts/restart_daemons.sh` does this.

### Docker: Config JSON Files No Longer World-Readable

The container entrypoint set `config/`, `data/` and `dosbox-bridge/` to mode 775 on every start. That left files such as `config/binkp.json` (BinkP uplink passwords) and `config/lovlynet.json` (LovlyNet keys) readable by every user in the container.

After setting those permissions, the entrypoint now sets every top-level `config/*.json` file to mode 640: readable and writable by the `binkterm` user and group, with no access for anyone else. The web server user (`www-data`) belongs to that group, so PHP still reads the files. The `config/` directory stays at 775 so the admin daemon can still create and replace files.

This applies on the next container start. If you mount `config/` from the host, the files on the host are changed to 640 as well, so check that any host-side tools or backup jobs reading them run as a user or group that can still do so.

### BinkP Debug Logging No Longer Records Password Material

With debug logging enabled, BinkP authentication wrote the first characters of both the password received from the remote system and the password expected for it to the log. For CRAM-MD5 it also wrote the challenge together with the digest. Anyone who can read the log can use a logged challenge and digest to guess the shared session password offline.

By default the log now records only the outcome of each authentication, the password lengths, and a hint that does not reveal the password: whether the lengths differ, the letter case differs, or there is stray whitespace.

When you need the full detail to troubleshoot authentication with a particular peer, add this to `.env` and restart the BinkP daemons:

```ini
BINKP_LOG_SENSITIVE_AUTH=true
```

While it is on, the password prefixes and CRAM-MD5 challenge and digest values are logged again, and a warning is logged once per session to show that it is enabled. Remove the setting when you are done, and treat any log written while it was on as containing secrets. See [CONFIGURATION.md](CONFIGURATION.md) for the setting.

### Auto Feed Verifies TLS and Allows Only http(s) Feeds

The RSS poster fetched feeds with HTTPS certificate and host name checks turned off, followed redirects automatically, and accepted a URL with any scheme. Anyone able to tamper with the connection to a feed, or to redirect it, could supply content that was then posted into echo areas.

Feeds are now fetched with these rules:

- Only `http://` and `https://` URLs are fetched. Any other scheme, such as `file://`, fails.
- The HTTPS certificate must be trusted by the server's system certificate store and must match the feed's host name.
- Redirects are followed one at a time, up to 5. Each one must stay on `http` or `https`, and a feed fetched over `https://` is not allowed to redirect to plain `http://`.

A feed that fails these checks is not posted for that run, and the error is recorded in the log of the RSS poster. Error messages leave out any user name, password and query string from the feed URL.

Check **Admin -> Auto Feed** after upgrading. A feed whose server has an expired, self-signed or mismatched certificate used to work and will now fail until the server's certificate is fixed. There is no setting to turn verification off. A feed that redirects from `https://` to `http://` also fails, so change the feed's URL to the final `https://` address. See [Autofeed.md](Autofeed.md).

### Telnet TLS Offers TLS 1.3 and Validates Your Certificate

The TLS Telnet listener (port 8023 by default) only offered TLS 1.0 to 1.2, with a fixed cipher list. It now offers every version from a minimum you choose up to TLS 1.3, so a modern client negotiates TLS 1.3 and an old BBS terminal program can still connect with an older version. Two new settings in `.env` control this:

```ini
TELNET_TLS_MIN_VERSION=1.0
TELNET_TLS_CIPHERS=DEFAULT:@SECLEVEL=0
```

- **`TELNET_TLS_MIN_VERSION`** is the oldest version accepted: `1.0`, `1.1`, `1.2` or `1.3`. The default is `1.0`, so existing callers keep working. Once you know none of your users need the older versions, set it to `1.2` or higher. An invalid value is logged as a warning and treated as `1.0`.
- **`TELNET_TLS_CIPHERS`** is the OpenSSL cipher list. The default matches what was used before. Set a stricter list to harden the listener at the cost of older clients.

If you set `TELNET_TLS_CERT` and `TELNET_TLS_KEY` to your own certificate and key, the daemon now checks them when it starts: both files must exist and be readable, be valid PEM, and the certificate must match the key. If any check fails, the daemon logs the exact reason and starts with the TLS listener off, while the plain Telnet listener keeps running. It does not create a self-signed certificate in place of the one you configured. A self-signed pair is generated under `data/telnet/` only when both settings are left unset.

The self-signed certificate the daemon generates now uses an ECDSA P-256 key instead of RSA. With an RSA certificate, OpenSSL signs the TLS handshake with RSA-PSS, and SyncTERM cannot verify that signature: it fails with `TLS: Could not find any algorithm named 'PSS(SHA-256,MGF1,32)'`. A new setting selects the key type for generated certificates:

```ini
TELNET_TLS_KEY_TYPE=ec
```

- **`TELNET_TLS_KEY_TYPE`** is `ec` (the default) or `rsa`. Set `rsa` only if an old terminal program cannot handle ECDSA certificates.

The setting only applies when a certificate is generated. A certificate created by an earlier version stays RSA, so SyncTERM keeps failing until you replace it: stop the telnet daemon, delete `data/telnet/telnetd.crt` and `data/telnet/telnetd.key`, and start it again. A certificate you supply through `TELNET_TLS_CERT` and `TELNET_TLS_KEY` is never replaced; use an ECDSA certificate there if SyncTERM users connect.

Check that the TLS listener is up after restarting the telnet daemon. Each TLS connection is logged with the negotiated version, cipher and key size, for example `TLS connection from 192.0.2.10 [TLSv1.3 TLS_AES_256_GCM_SHA384 256-bit]`. See [TelnetServer.md](TelnetServer.md).

### Login Takes the Same Time for Unknown Usernames

Checking a password is deliberately slow. When a login named an account that exists, BinktermPHP checked the password and the attempt took that long to fail. When the username did not exist, there was no password to check, so the attempt failed almost at once. Anyone who timed the responses could tell which usernames are registered, and then concentrate guessing on those.

A login with an unknown username now checks the password against a placeholder hash that no password matches, using the same algorithm and cost that new passwords are stored with. A wrong password for a real account and any password for an unknown account therefore take about the same time and give the same error. The placeholder is built without hashing anything, so this adds no extra work per request beyond the one check.

This applies to every place that checks a username and password: the web login, Telnet and SSH (which sign in through the web login), FTP, NNTP and QWK HTTP downloads.

### WebDoor API Only Serves Installed, Enabled Games

WebDoor games call `/api/webdoor/session`, `/api/webdoor/storage` and `/api/webdoor/leaderboard` to keep sessions, save slots and high scores. The game is identified by the `game_id` query parameter or, when that is absent, by the `/webdoors/{id}/` page in the request's referer. The API used to accept any value, and fell back to `unknown` when there was none, so a signed-in user could create session, save and leaderboard rows for a game that does not exist, is switched off, or does not meet its requirements.

The id must now name an installed WebDoor, matched by its directory name or its manifest `game.id`, that is enabled and whose manifest requirements are met. The API then uses the manifest's own id, whichever of the two the request used. For any other id, or when no id can be determined, the API answers `404` with the error `errors.webdoor.game_unavailable` ("This game is not available") and reads or writes nothing.

Rows already stored under ids that no longer match an installed WebDoor are left in place and are not served. A game that is disabled in **Admin -> Doors -> WebDoors** stops being able to load or save data until it is enabled again.

The error is a new key in `errors.php`, added to all six locales. See [WebDoors.md](WebDoors.md).

### Telnet Drops Idle Connections Sooner Before Login

Port scanners often open a Telnet connection, read the banner and then say nothing. Each such connection holds a handler process until the idle timer ends it, and before login that timer was the same 7 minutes (420 seconds) used for signed-in users.

The login, register and reset-password prompts now use a shorter idle limit of 90 seconds. Any keystroke resets it, so a person reading the login screen and typing at a normal pace is not cut off. Telnet option negotiation and terminal reports sent by the client do not count as activity, so a client that only sends those is disconnected on schedule. Once the user signs in, the session goes back to the normal idle warning and disconnect times.

To change the limit, set this in `.env` and restart the telnet daemon:

```ini
TELNET_PREAUTH_IDLE_TIMEOUT=90
```

The value is in seconds. A value below `15` is ignored and the default of 90 is used, because it could disconnect someone who is still typing their username.

This frees idle sockets; it does not prevent abuse. A client that sends a keystroke every so often can still hold a pre-login connection open. SSH users sign in at the SSH layer before reaching the BBS, so this limit does not apply to them. See [TelnetServer.md](TelnetServer.md).

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.

---

## Thanks

Thanks to **TheWebExpert** and **Skrawl** for their contributions to this release.
