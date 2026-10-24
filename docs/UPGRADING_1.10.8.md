# Upgrading to 1.10.8

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Web Doors](#web-doors)
  - [Leaderboards Read From DOS and Native Door Game Data](#leaderboards-read-from-dos-and-native-door-game-data)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

BinktermPHP 1.10.8 adds the ability to read a DOS door game's own data files, starting with Legend of the Red Dragon (LORD) and The Wire, and publish its player scores on the BBS.

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

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
