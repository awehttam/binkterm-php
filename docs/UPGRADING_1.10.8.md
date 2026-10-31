# Upgrading to 1.10.8

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Web Doors](#web-doors)
  - [Leaderboards Read From DOS and Native Door Game Data](#leaderboards-read-from-dos-and-native-door-game-data)
  - [Door Manifest Editor Changes](#door-manifest-editor-changes)
  - [Internet Archive Games](#internet-archive-games)
- [Dashboard](#dashboard)
  - [Door Activity on the Mail, Areas & Doors Card](#door-activity-on-the-mail-areas--doors-card)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

BinktermPHP 1.10.8 adds the ability to read a DOS door game's own data files, starting with Legend of the Red Dragon (LORD) and The Wire, and publish its player scores on the BBS. The Door Manifest Editor can set the data parser for a door and can now edit manifests that ship with BinktermPHP. Sysops can also add browser-playable games hosted on the Internet Archive to the `/games` page. The dashboard's Mail & Areas card is now Mail, Areas & Doors and shows recent door activity.

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

## Dashboard

### Door Activity on the Mail, Areas & Doors Card

The dashboard's **Mail & Areas** card is renamed **Mail, Areas & Doors**. Below the new echo areas it has a new door activity section with two lists:

- **Most Popular.** The five doors with the most sessions started in the past 30 days, each with its play count.
- **Recent Players.** The five most recent players from the door leaderboards, with the door they played and the date. Scores are not shown.

Doors that are disabled, hidden from the web, or admin-only (for non-admin users) are left out. The section does not appear when the games system is disabled. The data comes from the new login-required `GET /api/dashboard/door-activity` endpoint.

Recent Players is drawn from the leaderboard data, so it only lists players of doors that publish scores, such as those described under [Leaderboards Read From DOS and Native Door Game Data](#leaderboards-read-from-dos-and-native-door-game-data).

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
