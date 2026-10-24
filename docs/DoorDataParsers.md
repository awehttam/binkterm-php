# Door Data Parsers

Door data parsers read a DOS door game's own data files (player records, scores) and return a normalized structure. BinktermPHP uses them to expose per-game leaderboards and to copy scores into the `/games` Top Scores table. This document is for developers adding support for a new game.

## Table of Contents

- [Overview](#overview)
- [Components](#components)
- [Enabling a Parser for a Door](#enabling-a-parser-for-a-door)
- [Normalized Player Shape](#normalized-player-shape)
- [Writing a Parser](#writing-a-parser)
  - [1. Find the Record Layout](#1-find-the-record-layout)
  - [2. Implement the Interface](#2-implement-the-interface)
  - [3. Register It](#3-register-it)
  - [4. Test It](#4-test-it)
- [BinaryRecordReader Reference](#binaryrecordreader-reference)
- [Leaderboard Sync](#leaderboard-sync)
- [Leaderboard API](#leaderboard-api)
- [Rules for Parsers](#rules-for-parsers)
- [Reference Implementation: LORD](#reference-implementation-lord)

## Overview

```text
dosdoor.jsn "data_parser"  -->  DoorDataParserRegistry  -->  DoorDataParserInterface implementation
                                         |
                                 DoorDataService
              (resolves + validates data dir, runs parser, drops deleted, sorts by score)
                                         |
              +--------------------------+---------------------------+
              |                                                      |
   GET /api/door/leaderboard                          DoorLeaderboardSync
   (routes/door-routes.php)                    (scripts/door_data_sync.php, run by the
                                                bridge when the door exits)
```

A parser only turns bytes into arrays. Finding the files, validating paths, ranking, user matching and database writes are handled by the surrounding classes, so a new game needs one class plus a registration line.

## Components

| File | Role |
|------|------|
| `src/DoorData/DoorDataParserInterface.php` | Contract every parser implements |
| `src/DoorData/DoorDataParserRegistry.php` | Maps parser ids to classes; built-ins are registered in `loadBuiltins()` |
| `src/DoorData/DoorDataService.php` | Resolves the data directory, runs the parser, filters deleted slots, sorts by score descending |
| `src/DoorData/BinaryRecordReader.php` | Bounds-checked little-endian reads for Pascal and C style records |
| `src/DoorData/DoorDataParseException.php` | Thrown for missing, unreadable or malformed data |
| `src/DoorData/DoorLeaderboardSync.php` | Matches players to BBS users and writes `webdoor_leaderboards` rows |
| `src/DoorData/Parsers/LordParser.php` | Legend of the Red Dragon parser |
| `src/DoorData/Parsers/TheWireParser.php` | The Wire parser |
| `scripts/door_data_sync.php` | CLI entry point for the sync; started by the bridge on door exit |
| `tests/Unit/DoorDataParserTest.php` | Reader, registry and LORD tests |

## Enabling a Parser for a Door

Add two optional keys to the door's `dosdoor.jsn`:

```json
{
  "type": "dosdoor",
  "data_parser": "lord",
  "data_dir": "."
}
```

| Key | Description |
|-----|-------------|
| `data_parser` | Parser id, as returned by the parser's `getId()`. Case-insensitive. |
| `data_dir` | Directory holding the data files, relative to the door's directory. Defaults to `.`. |

Both keys can also be set in the Door Manifest Editor (Game Data Parser and Data Directory, under door setup), which builds the parser list from `DoorDataParserRegistry`. Native doors use the same two keys at the top level of `nativedoor.json`. `DosBoxDoorManifest::parseManifest()` and `NativeDoorManifest::parseManifest()` expose these as `data_parser` and `data_dir` in the normalized manifest. `DoorDataService` resolves the directory with `realpath()` and rejects anything outside `dosbox-bridge/dos/DOORS/` (DOS doors) or `native-doors/doors/` (native doors).

## Normalized Player Shape

`parsePlayers()` returns a list of arrays with these keys:

| Field | Type | Description |
|-------|------|-------------|
| `handle` | string | In-game name |
| `real_name` | string or null | Real or BBS name stored by the game. Used by the leaderboard sync to find the BBS user. |
| `score` | int | Primary ranking value, chosen by the parser |
| `score_label` | string | What `score` means, e.g. `Experience` |
| `last_played` | string or null | ISO 8601 UTC timestamp, or null when the game only stores in-game day numbers |
| `deleted` | bool | True for empty or vacated slots. The service removes these. |
| `stats` | array | Game-specific fields with lowercase snake_case keys |

`stats` values should be scalars (strings, ints, bools) so they serialize cleanly to JSON.

## Writing a Parser

### 1. Find the Record Layout

Most DOS doors were written in Turbo Pascal or C and store fixed-size records. Sources of layout information, best first:

1. A structure file shipped with the game. LORD ships `LORDSTRC.PAS` with the exact Pascal `record`.
2. Documentation or editor source from the game's community.
3. Differential analysis: copy the data file, make exactly one change in the game, copy it again, and diff the bytes.

Turbo Pascal records are unaligned. Sizes: `integer` and `word` 2 bytes, `longint` 4, `shortint` and `boolean` 1, `string[N]` N+1 (length byte, then N bytes). C structs may be padded, so check the compiler's packing.

Sanity-check your layout: the file size must be an exact multiple of the record size, and the offset of every field in the first record should land on plausible values.

### 2. Implement the Interface

Create `src/DoorData/Parsers/<Game>Parser.php`:

```php
<?php

namespace BinktermPHP\DoorData\Parsers;

use BinktermPHP\DoorData\BinaryRecordReader;
use BinktermPHP\DoorData\DoorDataParserInterface;

class ExampleParser implements DoorDataParserInterface
{
    private const RECORD_SIZE = 64;

    public static function getId(): string
    {
        return 'example';
    }

    public function getGameName(): string
    {
        return 'Example Game';
    }

    public function getRequiredFiles(): array
    {
        return ['PLAYERS.DAT'];
    }

    public function parsePlayers(string $dataDir): array
    {
        $path = rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . 'PLAYERS.DAT';
        $players = [];
        foreach (BinaryRecordReader::fromFile($path)->records(self::RECORD_SIZE) as $slot => $r) {
            $handle = trim($r->pascalString(0, 30));
            $players[] = [
                'handle' => $handle,
                'real_name' => null,
                'score' => $r->int32(40),
                'score_label' => 'Gold',
                'last_played' => null,
                'deleted' => $handle === '',
                'stats' => ['slot' => $slot, 'level' => $r->int16(44)],
            ];
        }
        return $players;
    }
}
```

Split the byte handling into a public method that takes a `BinaryRecordReader` (as `LordParser::parseBuffer()` does) so tests can feed it constructed bytes without touching the filesystem.

Document the full field offset table in the class phpDoc.

### 3. Register It

Add the class to `DoorDataParserRegistry::loadBuiltins()`:

```php
self::add(ExampleParser::class);
```

Ids must be unique; registering a duplicate throws `InvalidArgumentException`. Then set `data_parser` in the door's `dosdoor.jsn`.

### 4. Test It

Add cases to `tests/Unit/DoorDataParserTest.php` (or a new file under `tests/Unit/`):

- Build a record in memory with `pack()`/`str_pad()` and fictitious handles, parse it with the buffer-level method, and assert every field.
- A blank slot is reported as `deleted`.
- A buffer that is not a multiple of the record size throws `DoorDataParseException`.

Run with `php vendor/bin/phpunit tests/Unit/DoorDataParserTest.php`. To check against a real install, run `php scripts/door_data_sync.php <DOORID>`; this writes leaderboard rows (see below), so on a production system prefer a quick script that calls `DoorDataService::getPlayers()` and prints the result.

## BinaryRecordReader Reference

| Method | Description |
|--------|-------------|
| `BinaryRecordReader::fromFile($path)` | Reads the whole file once (max 10 MB). Throws if missing or unreadable. |
| `new BinaryRecordReader($bytes)` | Wraps an existing string, for tests |
| `records($size)` | Splits into one reader per fixed-size record. Throws if the length is not a multiple of `$size`. |
| `pascalString($offset, $maxLen)` | Length byte plus `$maxLen` bytes, trailing NULs trimmed, converted from CP437 to UTF-8 |
| `uint8`, `int8` | Pascal `byte` and `shortint` |
| `uint16`, `int16` | Pascal `word` and `integer` |
| `int32`, `uint32` | Pascal `longint` |
| `bytes($offset, $length)` | Raw bytes |
| `size()` | Buffer length |

Every read is bounds-checked and throws `DoorDataParseException` instead of reading past the buffer. CP437 conversion uses `iconv`; mbstring does not support CP437. Pascal `Real` (6-byte) values and Turbo Pascal `Single`/`Double` are not implemented; add decoders to the reader when a game needs them.

## Leaderboard Sync

`DoorLeaderboardSync::syncDoor($doorId)` copies scores into `webdoor_leaderboards` so they appear in the `/games` Top Scores table. `scripts/door_data_sync.php <door_id>` wraps it, and the bridge (`scripts/dosbox-bridge/multiplexing-server.js`, `syncDoorData()`) runs it in the background when a DOS or native door exits, for doors whose manifest names a `data_parser`. The PHP binary comes from `DOSDOOR_PHP_PATH`, default `php`.

Rules:

- **User matching:** the player's `real_name` is matched case-insensitively against `users.username` (also with `_` treated as a space) and `users.real_name`. Exactly one matching user is required; none or several means the player is skipped. Parsers that can supply the BBS name in `real_name` get leaderboard entries; parsers that cannot will still work for the API.
- **When a row is written:** only if the player's score is greater than zero and differs from their most recent row for that door. Unchanged players add nothing.
- **Row contents:** `game_id` is the door id (directory name, e.g. `LORD`), `board` is `default`, `score` is the parser's score, and `metadata` holds the handle and `stats`.
- **Output:** a summary line (`inserted`, `unchanged`, `unmatched`) is logged to `dosdoor.log`.

Because `/games` ranks by the best score per user in the current month, a parser's `score` should be a value where higher is better.

## Leaderboard API

`GET /api/door/leaderboard?door=<DOORID>&limit=<1-100>` (login required, default limit 25) returns the parsed players ranked by score. It reads the data files directly and does not use the database.

| Field | Type | Description |
|-------|------|-------------|
| `success` | bool | True on success |
| `door` | string | Door id |
| `players` | array of objects | Ranked players |
| `players[].rank` | int | 1-based rank |
| `players[].handle` | string | In-game name |
| `players[].score` | int | Ranking value |
| `players[].score_label` | string | Meaning of the score |
| `players[].last_played` | string or null | ISO 8601 UTC timestamp when known |
| `players[].stats` | object | Parser-specific fields |

`real_name` is deliberately not returned. Errors use `error_code` `errors.door.leaderboard_not_supported` (404, no parser configured) and `errors.door.leaderboard_failed` (500, data could not be parsed).

## Rules for Parsers

- Read-only. Never write to game files.
- Read the whole file once into memory (`fromFile()`) and parse the buffer; the game may be writing while you read.
- Never take paths from users. Parsers receive an already-validated directory.
- Treat all strings as untrusted player input; escape on output.
- Throw `DoorDataParseException` for bad data. Do not log from parsers; the caller logs through `BinktermPHP\Binkp\Logger` to `dosdoor.log`.
- Do not apply credits or rewards from within a parser. Any credit changes belong in server-side PHP following the project's credit rules.
- Keep stats keys lowercase snake_case and values scalar.

## Reference Implementation: LORD

`LordParser` reads `PLAYER.DAT`: 236-byte unaligned records defined by `LORDSTRC.PAS` (shipped in the LORD directory; valid for LORD 3.20 to 4.08). The full offset table is in the class phpDoc. Notes:

- The handle is a `string[20]` at offset 0 and the BBS name is a `string[50]` at offset 21 (used for user matching).
- The score is experience, a `longint` at offset 158.
- A blank handle marks a deleted slot.
- `time` and `last_alive_time` are in-game day numbers, not dates, so `last_played` is null and the value is exposed as `stats.last_day`.
- Flags such as `dead` use the value 5 for true.

## Reference Implementation: The Wire

`TheWireParser` (`thewire`) reads `WIRE.DAT`: 347-byte records. No structure file was available, so the layout was derived from hexdumps and checked against the in-game leaderboard, which is the differential-analysis route described under [Find the Record Layout](#1-find-the-record-layout). Notes:

- The handle is a `string[30]` at offset 0, uppercase. The BBS username is a `string[22]` at offset 92 in its original case, and is used for user matching.
- The score is Street Cred, a 32-bit value at offset 66. A second 32-bit value at offset 70 equals it in every sample; it is exposed as `stats.street_cred_2` because it is not known which of the two the game shows if they diverge.
- Offset 74 is a 16-bit value, 1 for every player seen, exposed as `stats.level`. That it is a level is an assumption.
- A record with no handle or no BBS name is an unfinished account and is hidden as deleted.
- The title shown in the game (for example Script Kiddie) is not stored as text and is not returned.
