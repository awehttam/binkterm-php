# Upgrading to 1.10.8

BinktermPHP 1.10.8 focuses on door games, security hardening and reliability. Door leaderboards can now be built from the player files of DOS and native doors such as LORD and The Wire, and sysops can add browser-playable games hosted on the Internet Archive. Native doors start with a clean environment that no longer exposes the secrets in `.env`, and can optionally run as a separate unprivileged operating system user. The dashboard and terminal server show door activity and Top Scores, the Files page is tabbed with a forum-style area list, and the BBS Directory records where each entry came from. Background services now reconnect to PostgreSQL after a restart and wait for it when started at boot, so a database outage no longer leaves daemons stopped or failing.

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Web Doors](#web-doors)
  - [Leaderboards Read From DOS and Native Door Game Data](#leaderboards-read-from-dos-and-native-door-game-data)
  - [Door Manifest Editor Changes](#door-manifest-editor-changes)
  - [Internet Archive Games](#internet-archive-games)
  - [Top Scores Month Uses Your Timezone](#top-scores-month-uses-your-timezone)
  - [MeshCore Public Key QR Codes](#meshcore-public-key-qr-codes)
- [Native Doors](#native-doors)
  - [Doors Receive a Clean Environment](#doors-receive-a-clean-environment)
  - [Running Native Doors as a Separate User](#running-native-doors-as-a-separate-user)
- [Dashboard](#dashboard)
  - [Door Activity on the Mail, Areas & Doors Card](#door-activity-on-the-mail-areas--doors-card)
- [BBS Directory](#bbs-directory)
  - [Sortable Columns in the Admin Entry List](#sortable-columns-in-the-admin-entry-list)
  - [Entry Source Tags](#entry-source-tags)
  - [Sources Listed on the Public Directory Page](#sources-listed-on-the-public-directory-page)
- [File Areas](#file-areas)
  - [Tabbed Files Page With a Forum-Style Area List](#tabbed-files-page-with-a-forum-style-area-list)
  - [File Counts and Sizes After Moving Files](#file-counts-and-sizes-after-moving-files)
  - [Admin File Areas Page: Full-Width List and Virus Scanning Badge](#admin-file-areas-page-full-width-list-and-virus-scanning-badge)
  - [TIC Files Log Why a Virus Scan Was Skipped](#tic-files-log-why-a-virus-scan-was-skipped)
- [Terminal Server](#terminal-server)
  - [Door Games Hub With Activity and Top Scores](#door-games-hub-with-activity-and-top-scores)
  - [Status Bar No Longer Scrolls the Screen](#status-bar-no-longer-scrolls-the-screen)
  - [Menus Repaint Immediately When the Window Is Resized](#menus-repaint-immediately-when-the-window-is-resized)
- [Admin Dashboard](#admin-dashboard)
  - [NNTP and Matterbridge in Service Status](#nntp-and-matterbridge-in-service-status)
- [Database Connection Recovery](#database-connection-recovery)
  - [Background Services Reconnect After PostgreSQL Restarts](#background-services-reconnect-after-postgresql-restarts)
  - [BinkP Session Logging Can No Longer Abort a Mail Transfer](#binkp-session-logging-can-no-longer-abort-a-mail-transfer)
  - [FTP and NNTP Replies While the Database Is Down](#ftp-and-nntp-replies-while-the-database-is-down)
  - [Terminal Server Sessions Recover After a Restart](#terminal-server-sessions-recover-after-a-restart)
  - [Command-Line Scripts Report a Failed Connection as an Error](#command-line-scripts-report-a-failed-connection-as-an-error)
  - [Daemons Wait for PostgreSQL at Startup](#daemons-wait-for-postgresql-at-startup)
- [Support Bot](#support-bot)
  - [Embedding Server Can Run as a Daemon](#embedding-server-can-run-as-a-daemon)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Web Doors

- **Leaderboards read from DOS and native door game data:** a door can name a data parser that reads the game's own player files, starting with Legend of the Red Dragon (LORD) and The Wire, and publishes each player's score on the `/games` Top Scores leaderboard.
- **Door Manifest Editor changes:** the editor can set the data parser for a door and can now edit manifests that ship with BinktermPHP, even though it's not recommended to do so.
- **Internet Archive games:** sysops can add browser-playable games hosted on the Internet Archive to the `/games` page.
- **Top Scores month uses your timezone:** the monthly Top Scores leaderboard now follows each user's own timezone.
- **MeshCore public key QR codes:** MeshCore node details now show a QR code beside the node's public key.

### Native Doors

- **Doors receive a clean environment:** native doors, DOSBox and DOSEMU no longer inherit the door bridge's environment, which included the database, SMTP and admin daemon secrets from `.env`.
- **Running native doors as a separate user:** a native door can now run as an unprivileged account that cannot read `.env` or your configuration, chosen per door in Admin → Native Doors.

### Dashboard

- **Door activity on the Mail, Areas & Doors card:** the dashboard's Mail & Areas card is now Mail, Areas & Doors and shows recent door activity.

### BBS Directory

- **Sortable columns in the admin entry list:** the admin BBS Directory list has sortable columns.
- **Entry source tags:** each entry records a free-text source tag that importers set, and the sources are listed on the public directory page.

### File Areas

- **Tabbed Files page with a forum-style area list:** the `/files` page is now tabbed, with a forum-style list of file areas and a separate closable tab for each area you open.
- **File counts and sizes after moving files:** file area counts and sizes are now corrected after files are moved between areas.
- **Admin File Areas page:** the file area list now uses the full page width and shows a badge on each area that has virus scanning enabled.
- **TIC files log why a virus scan was skipped:** when a file received by TIC is not scanned, the TIC log now says why.

### Terminal Server

- **Door Games hub with activity and Top Scores:** the Door Games menu item in the Telnet and SSH terminal server now opens a screen showing the most popular doors, recent players and the monthly Top Scores, with a key to continue to the list of playable doors.
- **Status bar no longer scrolls the screen:** drawing the full-width status bar on the last terminal row no longer scrolls the screen up one line, which had pushed screen headers off the top.
- **Menus repaint immediately when the window is resized:** the main menu and list screens now redraw as soon as the terminal window is resized, instead of waiting for the next keypress.

### Admin Dashboard

- **NNTP and Matterbridge in Service Status:** the admin dashboard's Service Status card now lists the NNTP server and the Matterbridge daemon, and `binktop` lists the Matterbridge daemon.

### Database Connection Recovery

- **Background services reconnect after PostgreSQL restarts:** the realtime server, FTP server, MRC daemon, Matterbridge daemon, AI bot daemon and Admin Daemon now reconnect to PostgreSQL after it restarts or briefly goes away, instead of exiting or failing until they are restarted.
- **BinkP session logging can no longer abort a mail transfer:** a database failure while recording session statistics no longer ends a BinkP session, which could previously leave a received file saved but unacknowledged and cause the remote system to send it again.
- **FTP and NNTP replies while the database is down:** FTP and NNTP now answer with their protocols' temporary-failure replies instead of dropping connections without explanation.
- **Terminal server sessions recover after a restart:** long-lived Telnet and SSH sessions reconnect to the database, so features that read it directly and the periodic session-revocation check resume working.
- **Command-line scripts report a failed connection as an error:** a command-line script that cannot connect to the database now exits with an error status instead of exiting successfully.
- **Daemons wait for PostgreSQL at startup:** the BinkP scheduler, realtime server, FTP server, NNTP server, MRC daemon, Matterbridge daemon and AI bot daemon now wait for PostgreSQL to accept connections when they start, instead of exiting. This matters when they are started at boot, for example from a crontab `@reboot` entry.

### Support Bot

- **Embedding server can run as a daemon:** `tools/support-bot/embed_server.py` accepts `--daemon`, `--pid-file` and `--log-file`, so it can be started from a crontab `@reboot` entry.

## Web Doors

### Leaderboards Read From DOS and Native Door Game Data

A DOS door can now name a data parser in its `dosdoor.jsn` manifest. The parser reads the game's own player files and returns each player's handle, score and game-specific statistics. Parsers for LORD (`PLAYER.DAT`, parser id `lord`) and The Wire (`WIRE.DAT`, parser id `thewire`) are included. The bundled LORD door is already configured to use its parser:

```json
{
  "data_parser": "lord",
  "data_dir": "."
}
```

`data_dir` is the directory holding the game's data files, relative to the door directory, and must resolve inside `dosbox-bridge/dos/DOORS/`.

Two things use the parsed data:

- **Leaderboard API.** `GET /api/door/leaderboard?door=LORD&limit=25` (login required) returns the players ranked by score. Real names are never included in the response.
- **Top Scores sync.** When a door with a `data_parser` exits, the DOS door bridge runs `scripts/door_data_sync.php`, which copies each player's score into the `/games` Top Scores leaderboard. Players are matched to BBS users by the real name stored in the game record, compared against the BBS username and real name. Unmatched or ambiguous players are skipped, and a row is added only when a player's score has changed since their last row. You can also run it manually with `php scripts/door_data_sync.php LORD`.

Restart the DOS door bridge after upgrading so it picks up the exit hook. If `php` is not on the bridge's PATH, set `DOSDOOR_PHP_PATH` in `.env` to the full path of the PHP binary.

Native doors can use data parsers too. Add the same `data_parser` and optional `data_dir` keys at the top level of the door's `nativedoor.json`:

```json
{
  "data_parser": "lord",
  "data_dir": "."
}
```

For a native door, `data_dir` is relative to `native-doors/doors/<door_id>/` and must resolve inside `native-doors/doors/`. The leaderboard API and the Top Scores sync work the same way as for DOS doors, and the sync runs when the native door exits.

Developers adding support for another game should see `docs/DoorDataParsers.md`.

### Door Manifest Editor Changes

The Door Manifest Editor, reached from the door admin pages, has two changes.

**Game data parser fields.** The door setup section of the DOS and native door editors now has a **Game Data Parser** dropdown and a **Data Directory** field. These set the `data_parser` and `data_dir` manifest keys described above, so a door no longer needs its manifest edited by hand to publish its scores. The dropdown lists every parser the BBS knows about. Choose **None** for a door without a parser.

**Unmanaged manifests can be edited.** Previously, a manifest without `"managed": "web"` could not be changed in the editor. This covers the manifests that ship with BinktermPHP. These manifests now open for editing, even though it is not recommended to do so, with a warning banner and an **Unmanaged (edits may be overwritten)** status badge. Saving a manifest marks it `"managed": "web"`. Because a later BinktermPHP upgrade can replace the manifests that ship with it, changes made to one of them may be lost. To keep your changes, copy the door directory to a new name, such as `LORD2`, and edit the copy instead.

The Admin Daemon caches its code, so restart it after upgrading for the editor changes to take effect. `scripts/restart_daemons.sh` does this.

### Internet Archive Games

A new admin page, **Admin -> Doors -> Archive.org Games** (`/admin/archive-doors`), lets you add games that the Internet Archive streams in the browser. The game runs in an embedded `archive.org` player on its own page, so nothing is installed or run on your server.

To add a game:

1. Click to add a new game and paste the Internet Archive item ID or URL, for example `https://archive.org/details/<item>`.
2. Use the fetch button to fill in the title, author, year, description and icon from the Internet Archive. You can adjust any field before saving.
3. Set a Door ID (letters, numbers, hyphens and underscores only), the genre, player count, and whether the game is enabled, open to anonymous visitors, or hidden from the web.

Enabled games appear on the `/games` page with an **ARCHIVE** badge, in the **Internet Archive Games** category unless you choose other genres. Icons and screenshots are stored in the database rather than on disk.

The games are streamed from `archive.org`, so players' browsers need to reach that site, and availability depends on the Internet Archive.

Each game can have a credit cost, a play time limit, and optional anonymous access. See `docs/ArchiveDoors.md` for the full field reference and limitations.

### Top Scores Month Uses Your Timezone

The **Top Scores (All Supported Games)** list on the `/games` page covers one calendar month. The start and end of that month were previously calculated in UTC, so for users west of UTC the page switched to the next month several hours early. For example, at 5:52 PM Pacific on October 31 it showed "No scores recorded for November" even though October had not ended.

The month now begins and ends at midnight in the viewer's own timezone, taken from their user settings. If a user has no timezone saved, or the saved value is not a valid timezone name, the server's PHP `date.timezone` is used instead. Set `date.timezone` in `php.ini` to your local timezone, for example `America/Vancouver`, so users without a saved timezone see the month roll over at local midnight.

### MeshCore Public Key QR Codes

Node detail windows now show a QR code directly beneath a MeshCore node's public key and its copy button, so the key can be scanned instead of typed.

- **Community Wireless Node List.** The QR code encodes the node's public key. It is served by a new `qr` action on the door's own `api.php`.
- **MeshCore Nodes page.** The QR code that was already shown at the bottom of the node window has moved up to sit under the public key. It encodes a MeshCore contact link, so scanning it in a MeshCore app adds the node as a contact.

## Native Doors

### Doors Receive a Clean Environment

Native doors used to start with a copy of the door bridge's environment. That environment held the secrets from `.env` (database login, SMTP login, admin daemon secret), so any door could read them. A native door now starts with only these variables:

- `PATH`, set to `/usr/local/bin:/usr/bin:/bin`. Set `NATIVEDOOR_PATH` in `.env` to change it.
- `HOME` (the door's private directory for that user, the same as `DOOR_HOME`), `USER`, `LOGNAME`.
- `TERM` (`xterm-256color`), `LANG` (`C.UTF-8`), `COLUMNS` and `LINES`.
- The `DOOR_*` variables and `BBSDEV_DRP` described in `docs/NativeDoors.md`.
- Anything the door's manifest lists under `door.env`, plus any variables you approve as described below.

The DOSBox and DOSEMU emulators receive `PATH`, `HOME`, `LANG` and the display variables they need, and nothing else. The bridge also reads only the names it uses from `.env`.

A door that relied on another inherited variable, such as `TMPDIR`, `http_proxy` or a library path, now runs without it. A door asks for such a variable by listing its name under `door.env_passthrough` in its manifest. The request has no effect until you approve it: open **Admin → Native Doors**, click the edit button for the door, and tick the variable under **Approved Environment Variables**. A door with requests you have not approved shows a badge listing the names, and the bridge log lists them each time the door launches. Names that look like secrets (for example `DB_PASS`, `SMTP_PASSWORD`, anything ending in `_SECRET`, `_TOKEN` or `_KEY`) are refused and cannot be approved. Names are never approved on a door's behalf, so a door update that adds a new request must be approved again.

PubTerm always receives its target host and port from the bridge, so a `PUBTERM_HOST` or `PUBTERM_PORT` value in `.env` now applies whether or not the connection is proxied. The bundled SyncDOOM manifest now passes `-home "{homedir}"`, so its save files go to the per-user door directory.

The door bridge must be restarted for these changes to take effect.

A door still runs as the same operating system user as the bridge and can read the files that user can read, including `.env`, unless you run it as a separate user as described next. A cleaner environment closes the leak through inherited variables only.

### Running Native Doors as a Separate User

On Linux and BSD, a native door can now run as a dedicated unprivileged operating system account, so it cannot read `.env`, `config/binkp.json` or the rest of your data. Open **Admin → Native Doors**, click the edit button for a door and choose a **Launch Mode**:

- **Direct** runs the door as the bridge's user, as before. This is the default.
- **Sudo** runs the door with `sudo -n -u <door user>`. Choose the **Door User** from the list of members of the `doors` group.
- **Socket** asks a launcher process that already runs as the door user to start the door. Choose the **Launcher Socket** from the sockets that exist under `/run/binkterm-doors/`.

Nothing changes until you set this up. On a bare-metal install, run `scripts/setup_door_user.sh` as root to create the door accounts and groups and install the sudoers rule (sudo mode) or the launcher units (socket mode); `--dry-run` shows what it would do first. The script does not change the ownership or permissions of any of your files. You decide who can read what: a door user can write only its own `data/users/<id>/<door>` directory, which the bridge checks before each launch, and a door can read `.env`, `config/` and `data/` unless you make them unreadable to the door user. Add `--apply-permissions` to have the script remove world access from those paths, but only if your web server and door bridge share a group that owns the files; on an install where the web server reaches them through world access, that would lock it out. See `docs/CLI.md` (Door User Setup) and `docs/NativeDoors.md` (Launch Modes).

In Docker, set `NATIVEDOOR_DOOR_USERS` for the `binkterm` service, for example `syncdoom bbslinknative=door_bbs`, and the container creates the users and a launcher for each on every start. Until you choose **Socket** for a door, it still runs as `binkterm` and can read `.env`.

The Docker image also stops leaving `data/`, `config/` and `dosbox-bridge/` readable by other users. They are now `770` for directories and `660` for files, owned by `binkterm` and shared with the web server through its group. Anything else that read those paths through world access no longer can. Before every launch the bridge confirms the door user exists and can be reached and can write its own directory, and it refuses to start the door if any check fails. It never falls back to the bridge's user. Once any door uses `sudo` or `socket`, a door with no launch mode set is also refused, so set `direct` explicitly on doors that should stay as they are.

The bridge logs whether each non-direct door is healthy when it starts and when you reload it with `kill -HUP`.

## Dashboard

### Door Activity on the Mail, Areas & Doors Card

The dashboard's **Mail & Areas** card is renamed **Mail, Areas & Doors**. Below the new echo areas it has a new door activity section with two lists:

- **Most Popular.** The five doors with the most launches in the past 30 days, each with its play count.
- **Recent Players.** The five most recent players in the past 30 days, with the door they played and the date. Scores are not shown.

Doors that are disabled, hidden from the web, or admin-only (for non-admin users) are left out. The section does not appear when the games system is disabled. The data comes from the new login-required `GET /api/dashboard/door-activity` endpoint.

Both lists are built from recorded door launches, so they cover web, DOS, native, rlogin, JS-DOS and Internet Archive doors whether or not the door publishes scores. Internet Archive games are counted once per play session, so reloading the page does not add another play. Plays by anonymous visitors are not recorded.

## BBS Directory

### Sortable Columns in the Admin Entry List

In **Admin > BBS Directory**, click a column heading in the All Entries list to sort by Name, Sysop, Location, Telnet, Source or Last Seen. Click the same heading again to reverse the order. The sort applies to the whole list, not only the current page, and empty values sort last.

### Entry Source Tags

Each BBS Directory entry now records where it came from as a source tag, instead of only Manual or Auto. In **Admin > BBS Directory**, the **Source** column shows the tag and can be sorted, and the Add/Edit Entry window has a **Source** field. Type any tag you like, or pick one already in use. Tags are lowercased and limited to letters, digits, underscores, dots and hyphens.

Importers set their own tag: `telnet_bbs_guide` for `scripts/import_bbslist.php`, `syncdata` for the Syncdata robot and `ibbslastcall` for the IBBS Last Call robot. `import_bbslist.php` accepts `--source=<tag>` to use a different tag. Entries marked `manual` keep that tag when an importer updates them.

Entries imported before this release keep the tag `auto`, because the original importer was not recorded. They receive a specific tag the next time an import or robot updates them.

### Sources Listed on the Public Directory Page

The description at the top of the public BBS Directory page now names the sources in use, for example "automatically discovered through various sources such as Syncdata, Telnet BBS Guide, and supplemented by manually curated entries." The names come from the source tags of publicly listed entries. The `manual` and `auto` tags are not listed. When no entry has a named source, the page shows the previous wording.

## File Areas

### Tabbed Files Page With a Forum-Style Area List

The `/files` page now has a tab bar above the main panel:

- **File Areas.** Lists every file area in cards grouped by network, in the same style as the echo area list. Local areas come first, then one card per network in alphabetical order. Each card header shows the number of areas and the total files and size, and each row shows the area tag, its description, the file count and size, and an **Uploads** badge when you are allowed to upload there. Logged-in users also get a Personal card containing My Uploads and My Files. The area search box in the sidebar filters this list as well as the sidebar list.
- **Recent Uploads.** The existing list of the most recent uploads.
- **An area tab.** Opening an area, My Files or My Uploads, from either the File Areas tab or the sidebar, adds a third tab named after it. Opening a different area replaces that tab's contents, so at most three tabs are open at once. The **x** on the tab closes it and returns you to Recent Uploads.
- **Optional: multiple area tabs.** Under **Admin > Appearance > File Areas**, check **Allow multiple open area tabs** to give every area you open its own tab instead. Opening an area that already has a tab switches to it, each tab remembers the subfolder you were viewing, and closing the active tab moves you to a neighbouring tab, or to Recent Uploads if none remain. This option is off by default.

The page opens on the File Areas tab and remembers whether you last used File Areas or Recent Uploads. Opening a direct link such as `/files/<AREA>` or `/files?view=my-uploads` goes straight to that area's tab. Open area tabs are not kept when the page is reloaded.

### File Counts and Sizes After Moving Files

Moving a file to another file area did not update the file count and total size shown for the old and new area, so an area filled by moving files could show 0 files and 0 B. Moving a file now updates both areas, and an upgrade migration recalculates the counts for every area.

### Admin File Areas Page: Full-Width List and Virus Scanning Badge

On **Admin > File Areas**, the list of file areas now takes the full width of the page. The Statistics box and the File Area Settings help box that used to sit beside it have been removed.

Each area that has **Virus Scanning** enabled now shows a green shield badge in its status column, next to the Active, Local, Replace and other badges, so you can see at a glance which areas are scanned. Hover over the badge to see its label. An area with no badge is not scanned.

### TIC Files Log Why a Virus Scan Was Skipped

Files received by TIC are only scanned when virus scanning is enabled for the file area they are stored in. When it is not, ClamAV is never called, so nothing appeared in the logs to explain why a file was left unscanned. The TIC log now records a line for each file that is not scanned, naming the reason:

- Virus scanning is turned off for the file area, with the area tag.
- `VIRUS_SCAN_DISABLED=true` is set in `.env`.
- `VIRUS_SCAN_NOAUTO=true` is set in `.env`.
- No scanner was available, for example because `clamdscan` was not found or clamd did not answer. `data/logs/server.log` has the details.

To find files that arrived unscanned, search the TIC log for "Virus scan skipped", and check which file areas show the shield badge on **Admin > File Areas**.

## Terminal Server

### Door Games Hub With Activity and Top Scores

Choosing **Door Games** from the terminal server main menu previously went straight to the list of doors. It now opens a hub screen that shows the same door information as the web interface:

- **Top Scores.** The current month's best WebDoor scores, using the same month boundaries as the `/games` page. This section is hidden when **Hide Top Scores** is enabled under **Admin > Appearance**.
- **Most Popular.** The five doors with the most launches in the past 30 days, with play counts.
- **Recent Players.** The five most recent players in the past 30 days, with the door they played and when, shown in the user's own timezone.

Press `U` or `D` to scroll, `P` to open the list of playable doors, or `Q` to return to the main menu. Quitting the door list returns to the hub. Disabled doors, doors hidden from the web, and admin-only doors (for non-admin users) are left out of the activity lists.

The hub works in both the full-screen and line-mode shells. Its text is translated in every installed language.

### Status Bar No Longer Scrolls the Screen

Terminal server screens draw a status bar across the full width of the last terminal row. Some clients, including SyncTERM, wrap the cursor to the next line as soon as the last column is written. On the last row this scrolled the whole screen up one line, which pushed the screen header off the top and left an empty colored line at the bottom. The status bar is now drawn with automatic line wrapping turned off and turned back on afterward, so the screen stays in place. This applies to every Telnet and SSH screen that shows a status bar.

### Menus Repaint Immediately When the Window Is Resized

When a user resized their terminal window, the terminal server received the new size but the main menu and list screens did not redraw until the user pressed a key. The key that triggered the redraw was also easy to lose. These screens now repaint at the new size as soon as the resize is received. Text input fields do not repaint on resize.

## Admin Dashboard

### NNTP and Matterbridge in Service Status

The **Service Status** card on the admin dashboard shows whether each background service is running. It now includes two more rows, **NNTP Server** and **Matterbridge Daemon**, in the same style as the FTP, Gemini and MRC rows: a green Running or red Stopped badge, the process ID while it is running, and an "(optional)" label because most installations do not run them.

The dashboard decides whether a service is running from its PID file, so an optional service that is not installed, or that has never been started, shows as Stopped:

| Service | PID file | Environment variable to override the path |
|---|---|---|
| NNTP Server | `data/run/nntpd.pid` | `NNTPD_PID_FILE` |
| Matterbridge Daemon | `data/run/matterbridge_daemon.pid` | `MATTERBRIDGE_PID_FILE` |

`scripts/restart_daemons.sh` starts both daemons with these PID file paths. If you start either daemon yourself with a different `--pid-file`, set the matching variable in `.env` so the dashboard checks that file.

The terminal monitor `scripts/binktop.php` also lists the Matterbridge daemon, using the same PID file. It already listed the NNTP server.

## Database Connection Recovery

BinktermPHP opens one database connection per process. Web requests are short, so each one gets a fresh connection. The background services run for days, and a connection held by one of them stops working when PostgreSQL restarts, is upgraded, or loses network connectivity. Before this release, most services either exited when that happened, or kept running but failed every database operation until they were restarted by hand.

### Background Services Reconnect After PostgreSQL Restarts

These services now detect a lost database connection, reconnect, and carry on:

- **Realtime server (BinkStream).** A client whose request was in flight when the connection dropped is disconnected. The server itself stays up, retries the connection every three seconds while PostgreSQL is unreachable, and resumes delivering events when it returns.
- **FTP server.** The server no longer exits and no longer disconnects every user when the database drops. See [FTP and NNTP Replies While the Database Is Down](#ftp-and-nntp-replies-while-the-database-is-down).
- **MRC daemon, Matterbridge daemon and AI bot daemon.** Each checks its database connection on every pass through its main loop. If PostgreSQL is still down, it logs the problem, waits, and tries again instead of exiting. The AI bot daemon also keeps retrying its realtime listener connection, where it previously exited after one failed attempt.
- **Admin Daemon.** File virus scans requested through the Admin Daemon reconnect first if the connection has gone stale.
- **BinkP scheduler.** Its existing reconnect behavior is unchanged.

Messages about lost and restored connections are written to each service's normal log.

The services listed here wait for PostgreSQL if it is not yet available when they start. See [Daemons Wait for PostgreSQL at Startup](#daemons-wait-for-postgresql-at-startup).

### BinkP Session Logging Can No Longer Abort a Mail Transfer

Each BinkP session writes a row to the session log and updates its file and byte counts as files transfer. If the database connection was lost mid-session, that update raised an error and ended the session. When it happened right after a file was received, the file had already been saved to the inbound directory but the remote system was never told it arrived. The remote system then sent the file again on its next connection, and the second copy was saved next to the first with a `_1` suffix.

Session logging is now best-effort. If a log write fails, the transfer continues and the failure is written once to the server log. The session logger reconnects at most once every five seconds so later updates in the same session can succeed. A session that could not create its log row at the start is not shown on the session history page, but the session itself is not refused because of it.

### FTP and NNTP Replies While the Database Is Down

**FTP.** If the database connection is lost while a user is connected, the failing command is answered with `451 Local error in processing, please try again` once the server has reconnected, and the user can repeat the command. If the database is still unreachable, the server answers `421 Service not available, closing control connection` and closes only that user's connection. Other connected users are not affected.

**NNTP.** A new connection made while the database is down is answered with `400 Service temporarily unavailable` and closed. A connected session whose database connection is lost receives the same reply and is closed, so the newsreader reconnects and gets a working connection. Previously these sessions answered `403 Internal error` to every remaining command.

### Terminal Server Sessions Recover After a Restart

Each Telnet and SSH session is a separate long-running process that holds its own database connection. After a PostgreSQL restart, the parts of a session that read the database directly stopped working until the user disconnected: the Node List and BBS Directory screens, the door activity on the Door Games hub, the main menu's Node List entry, and the check that ends a session when it is revoked or expires elsewhere. That check logged an error every thirty seconds and never ended the session.

These now reconnect and continue once PostgreSQL is back. While the database is still unreachable, the Door Games hub shows no activity and the Node List menu entry is hidden, as before, but the Node List and BBS Directory screens can still end the session when they cannot read the database.

### Command-Line Scripts Report a Failed Connection as an Error

When a script run from the command line could not connect to the database, it printed `Database connection failed: ...` and exited with status 0, which made cron jobs and service managers treat the run as a success. These scripts now stop with a PHP error and a non-zero exit status. Web pages are unchanged and still show the `Database connection failed` message.

If you monitor script exit codes, a database outage now shows up as a failed run.

### Daemons Wait for PostgreSQL at Startup

A background service started from a crontab `@reboot` entry or an init script can begin running before PostgreSQL is ready to accept connections. Several services connected to the database once at startup, and when that failed they exited. The services were started by cron but were not running after the system came up, and because they had already detached from the terminal, nothing was printed.

These daemons now wait for the database at startup, retrying with a delay that grows to ten seconds, for up to five minutes:

- BinkP scheduler (`scripts/binkp_scheduler.php`)
- Realtime server (`scripts/realtime_server.php`)
- FTP server (`scripts/ftp_daemon.php`)
- NNTP server (`scripts/nntp_server.php`)
- MRC daemon (`scripts/mrc_daemon.php`)
- Matterbridge daemon (`scripts/matterbridge_daemon.php`)
- AI bot daemon (`scripts/ai_bot_daemon.php`)

The first failed attempt is logged as "Database not available yet, waiting for it to start", and the daemon logs how long it waited once PostgreSQL is reachable. If the database is still unreachable after five minutes, the daemon logs an error and exits. Each daemon writes to its own log file in `data/logs/`.

The Telnet, SSH, BinkP server, Admin Daemon, Gemini and DOS door bridge daemons do not connect to the database at startup and are unchanged.

## Support Bot

### Embedding Server Can Run as a Daemon

The support bot's embedding server, `tools/support-bot/embed_server.py`, previously ran only in the foreground or under a systemd unit. It now accepts these options:

| Option | Description |
|---|---|
| `--daemon` | Detach from the terminal and run in the background. Not available on Windows. |
| `--pid-file PATH` | Where to write the process ID. Defaults to `data/run/embed_server.pid`. |
| `--log-file PATH` | Where to append output when running with `--daemon`. Defaults to `data/logs/embed_server.log`. |

The server refuses to start if the PID file names a process that is still running, and removes the PID file when it exits. Sending it `SIGTERM` shuts it down cleanly.

To start it at boot from cron, use the Python interpreter from the virtual environment that has the support bot's requirements installed:

```cron
@reboot /home/youruser/.local/share/virtualenvs/support-bot/bin/python3 /home/youruser/binkterm-php/tools/support-bot/embed_server.py --daemon
```

Running `embed_server.py` without `--daemon`, or through `embed_server.service`, works as before.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
