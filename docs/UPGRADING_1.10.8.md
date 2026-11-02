# Upgrading to 1.10.8

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Web Doors](#web-doors)
  - [Leaderboards Read From DOS and Native Door Game Data](#leaderboards-read-from-dos-and-native-door-game-data)
  - [Door Manifest Editor Changes](#door-manifest-editor-changes)
  - [Internet Archive Games](#internet-archive-games)
  - [Top Scores Month Uses Your Timezone](#top-scores-month-uses-your-timezone)
  - [MeshCore Public Key QR Codes](#meshcore-public-key-qr-codes)
- [Dashboard](#dashboard)
  - [Door Activity on the Mail, Areas & Doors Card](#door-activity-on-the-mail-areas--doors-card)
- [BBS Directory](#bbs-directory)
  - [Sortable Columns in the Admin Entry List](#sortable-columns-in-the-admin-entry-list)
  - [Entry Source Tags](#entry-source-tags)
  - [Sources Listed on the Public Directory Page](#sources-listed-on-the-public-directory-page)
- [File Areas](#file-areas)
  - [Tabbed Files Page With a Forum-Style Area List](#tabbed-files-page-with-a-forum-style-area-list)
  - [File Counts and Sizes After Moving Files](#file-counts-and-sizes-after-moving-files)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

BinktermPHP 1.10.8 adds the ability to read a DOS door game's own data files, starting with Legend of the Red Dragon (LORD) and The Wire, and publish its player scores on the BBS. The Door Manifest Editor can set the data parser for a door and can now edit manifests that ship with BinktermPHP. Sysops can also add browser-playable games hosted on the Internet Archive to the `/games` page. The dashboard's Mail & Areas card is now Mail, Areas & Doors and shows recent door activity. MeshCore node details now show a QR code beside the node's public key. The admin BBS Directory list has sortable columns, and each entry records a free-text source tag that importers set. The `/files` page is now tabbed, with a forum-style list of file areas and a separate closable tab for each area you open.

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

**Unmanaged manifests can be edited.** Previously, a manifest without `"managed": "web"` could not be changed in the editor. This covers the manifests that ship with BinktermPHP. These manifests now open for editing, with a warning banner and an **Unmanaged (edits may be overwritten)** status badge. Saving a manifest marks it `"managed": "web"`. Because a later BinktermPHP upgrade can replace the manifests that ship with it, changes made to one of them may be lost. To keep your changes, copy the door directory to a new name, such as `LORD2`, and edit the copy instead.

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

The **Top Scores (All Games)** list on the `/games` page covers one calendar month. The start and end of that month were previously calculated in UTC, so for users west of UTC the page switched to the next month several hours early. For example, at 5:52 PM Pacific on October 31 it showed "No scores recorded for November" even though October had not ended.

The month now begins and ends at midnight in the viewer's own timezone, taken from their user settings. If a user has no timezone saved, or the saved value is not a valid timezone name, the server's PHP `date.timezone` is used instead. Set `date.timezone` in `php.ini` to your local timezone, for example `America/Vancouver`, so users without a saved timezone see the month roll over at local midnight.

### MeshCore Public Key QR Codes

Node detail windows now show a QR code directly beneath a MeshCore node's public key and its copy button, so the key can be scanned instead of typed.

- **Community Wireless Node List.** The QR code encodes the node's public key. It is served by a new `qr` action on the door's own `api.php`.
- **MeshCore Nodes page.** The QR code that was already shown at the bottom of the node window has moved up to sit under the public key. It encodes a MeshCore contact link, so scanning it in a MeshCore app adds the node as a contact.

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

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
