# JS-DOS Doors

## Table of Contents

- [Overview](#overview)
- [How It Works](#how-it-works)
- [Directory Structure](#directory-structure)
- [Manifest Format](#manifest-format)
  - [Top-Level Fields](#top-level-fields)
  - [modes](#modes)
  - [emulator\_config](#emulator_config)
  - [game\_files](#game_files)
  - [autoexec](#autoexec)
  - [saves](#saves)
  - [network](#network)
  - [credits](#credits)
- [Configuration File](#configuration-file)
- [Activation and Admin Interface](#activation-and-admin-interface)
- [Adding a Game: Doom Example](#adding-a-game-doom-example)
- [Troubleshooting](#troubleshooting)

---

## Overview

JS-DOS Doors run classic DOS games directly in the user's browser using [js-dos](https://js-dos.com/) - a WebAssembly port of DOSBox. Unlike DOS Doors and Native Doors, no server-side process is spawned per session. All emulation happens client-side; the server manages session tracking plus mode-aware file sync for per-user saves and shared admin-configured defaults.

JS-DOS Doors appear in the `/games` listing alongside WebDoors, DOS Doors, and Native Doors with a `[JSDOS]` badge.

---

## How It Works

1. A user visits `/games` and sees the JS-DOS door card.
2. They click **Launch Game**.
3. The browser calls `POST /api/jsdoor/session` to create a session record.
4. The player pre-fetches all game files listed in the manifest from their static asset paths under `public_html/jsdos-doors/{game-id}/assets/`.
5. The fetched files are written into the js-dos virtual filesystem using `fs.createFile()`.
6. DOSBox boots inside the browser using the autoexec commands from the manifest.
7. The browser creates a session, downloads game assets, and boots DOSBox. Modified files matching the mode's `save_paths` are synced back to the server every ~2 seconds and again on exit.
8. When the user exits (or navigates away), the session is closed and a final file sync is attempted.

Game assets are served as ordinary static files — no PHP processing, no authentication. Sysops are responsible for only placing files they are licensed to distribute in `public_html/`.

---

## Directory Structure

```
public_html/jsdos-doors/
└── doomsw/
    ├── jsdosdoor.json     ← required manifest
    ├── icon.png           ← optional game icon (96×96 recommended)
    └── assets/
        ├── DOOM.EXE
        ├── DOOM1.WAD
        └── ...            ← any other files the game needs
```

- Each game lives in its own subdirectory under `public_html/jsdos-doors/`.
- The subdirectory name is the **game path** (used in URLs). It does not have to match the `id` field in the manifest, but keeping them the same avoids confusion.
- Subdirectories starting with `_` are ignored by the scanner.
- Only the files listed in `emulator_config.game_files` are loaded into the emulator — placing extra files in `assets/` does not automatically include them.

---

## Manifest Format

Each game declares its capabilities in a `jsdosdoor.json` file at the root of its directory.

### Top-Level Fields

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | string | yes | Unique game identifier, e.g. `"doomsw"`. Must match the key used in `config/jsdosdoors.json`. |
| `name` | string | yes | Display name shown in the games listing. |
| `version` | string | no | Game version string, shown next to the author. |
| `author` | string | no | Game author/publisher. |
| `description` | string | no | Short description shown on the game card and launch page. |
| `icon` | string | no | Filename of the icon relative to the game directory. Defaults to `icon.png`. |
| `emulator` | string | yes | Emulator type. Use `"jsdos"` for DOS games. |
| `emulator_config` | object | yes (for jsdos play mode) | DOSBox configuration and file mapping for the default `play` mode. See below. |
| `saves` | object | no | Save file configuration for the default `play` mode. See below. |
| `modes` | object | no | Additional mode definitions. Used for admin-only config/setup modes with their own assets, autoexec, and save scope. |
| `network` | object | no | Multiplayer configuration. See below. |
| `credits` | object | no | Credit cost configuration. See below. |

### modes

JS-DOS manifests support a default `play` mode plus any number of named modes under `modes`.

- The top-level `emulator_config` and `saves` fields still define the default `play` mode for backward compatibility.
- `modes.play` can override the default play-mode settings.
- Additional modes such as `config` can declare their own `emulator_config`, `saves`, `label`, `description`, `admin_only`, and `keep_open` settings.

Example:

```json
"modes": {
    "config": {
        "label": "Admin Setup",
        "description": "Launches to a DOS prompt so the sysop can run SETUP.EXE.",
        "admin_only": true,
        "keep_open": true,
        "emulator_config": {
            "game_files": [
                {"asset_path": "assets/SETUP.EXE", "dos_path": "C:/GAME/SETUP.EXE"}
            ],
            "autoexec": [
                "C:",
                "cd GAME"
            ]
        },
        "saves": {
            "enabled": true,
            "scope": "shared",
            "save_paths": [
                "C:/GAME/DEFAULT.CFG"
            ],
            "max_size_kb": 512
        }
    }
}
```

`keep_open: true` tells the player not to append `exit` automatically, which allows an admin config mode to land at a DOS prompt.

### emulator\_config

Controls how DOSBox is configured and which files are loaded into the virtual filesystem.

| Field | Type | Default | Description |
|---|---|---|---|
| `output` | string | `"surface"` | DOSBox video output backend. `openglnb` can help enlarge low-resolution game modes, while `surface` is safer for text/config screens. |
| `autolock` | bool | `true` | Whether mouse lock is enabled automatically when the player is clicked. |
| `scaler` | string | `""` | Optional DOSBox scaler, such as `"normal3x forced"`. Prefer enabling this only for gameplay modes because it can make 80x25 text apps too large. |
| `cpu_cycles` | string | `"auto"` | DOSBox `cycles` setting. Use `"max 90%"` for performance-limited games or `"fixed 3000"` for speed-sensitive ones. |
| `memory_mb` | integer | `16` | Amount of emulated RAM in MB. |
| `machine` | string | `"svga_s3"` | DOSBox machine type. Common values: `svga_s3`, `vga`, `ega`, `cga`. |
| `game_files` | array | `[]` | Files to load into the virtual filesystem. See below. |
| `autoexec` | array of strings | `[]` | Commands to run at DOSBox startup, after `mount c .`. See below. |

### game\_files

An array of objects, each mapping a static asset file to a path inside the DOS virtual filesystem.

```json
"game_files": [
    {"asset_path": "assets/DOOM.EXE", "dos_path": "C:/DOOM/DOOM.EXE"},
    {"asset_path": "assets/DOOM1.WAD", "dos_path": "C:/DOOM/DOOM1.WAD"}
]
```

| Field | Description |
|---|---|
| `asset_path` | Path to the file relative to the game directory (e.g. `assets/DOOM1.WAD`). This is fetched as a static URL from the browser. |
| `dos_path` | Where the file should appear inside DOSBox. The drive letter (e.g. `C:`) is stripped and the rest becomes the path in the virtual FS root, which is mounted as `C:`. |
| `optional` | Optional boolean. When `true`, a missing static asset (`404`) is skipped instead of aborting launch. This helps with staged installs and first-time setup flows. |

Every file the game needs to run must be listed here. Files in `assets/` that are not listed are not available inside DOSBox.

### autoexec

An array of DOSBox command strings to execute at startup. The system prepends `mount c .` before these commands, so `C:` is always mounted to the virtual FS root.

```json
"autoexec": [
    "C:",
    "cd DOOM",
    "DOOM.EXE"
]
```

Keep the autoexec minimal. Normal play modes usually end by launching the game executable. Admin config modes can stop at a DOS prompt instead when paired with `keep_open: true`.

### saves

Controls save file sync between the browser and the server.

```json
"saves": {
    "enabled": true,
    "scope": "user",
    "save_paths": [
        "C:/DOOM/DOOMSAV*.DSG",
        "C:/DOOM/DEFAULT.CFG"
    ],
    "max_size_kb": 512
}
```

| Field | Type | Default | Description |
|---|---|---|---|
| `enabled` | bool | `false` | Enables file sync for this mode. |
| `scope` | string | `"user"` | Storage target. Use `"user"` for per-user private files or `"shared"` for admin-maintained defaults shared by all players. |
| `save_paths` | array | `[]` | Allowed DOS paths or globs. Only matching files are loaded or written back. |
| `max_size_kb` | integer | `512` | Maximum size per synced file. |

For `scope: "user"`, files are stored under the user's private file area.

For `scope: "shared"`, files are stored in a shared JS-DOS defaults directory outside `public_html` and are **read by all players** when the game launches. However, writes to a shared scope are **only permitted from `admin_only` modes** for admin users. The write is routed through the admin daemon, which enforces:

- The caller is authenticated as admin by the web layer before the daemon is contacted
- The manifest's `save_paths` allowlist is re-validated inside the daemon (the daemon loads the manifest itself)
- A `realpath()` containment check prevents any path traversal outside `data/jsdos-shared/{gameId}/`
- The `max_size_kb` cap is enforced per-file

Setting `scope: "shared"` on a mode that is **not** `admin_only` has no effect — the write will be rejected at the route layer with a 403.

### network

Multiplayer configuration (Phase 4 — not yet implemented).

```json
"network": {
    "enabled": true,
    "protocol": "ipx",
    "max_players": 4,
    "max_rooms": 10
}
```

### credits

```json
"credits": {
    "session_cost": 0
}
```

`session_cost` must be `0` for now. Sessions with a cost greater than zero are rejected until credit-gated session support is implemented.

---

## Configuration File

`config/jsdosdoors.json` controls which games are visible in the listing. It is written by the admin daemon — never edited directly by the web process.

Each key is a game `id` matching the manifest. Supported per-game fields:

| Field | Type | Description |
|---|---|---|
| `enabled` | bool | Whether the game appears in the listing. |
| `display_name` | string | Optional override for the manifest `name`. |
| `display_description` | string | Optional override for the manifest `description`. |

Example:

```json
{
    "doomsw": {
        "enabled": true,
        "display_name": "Doom",
        "display_description": "The classic first-person shooter. Fight through the demon-infested UAC base."
    }
}
```

If `config/jsdosdoors.json` does not exist, no JS-DOS doors are shown and no error is produced. The file is created by clicking **Activate JS-DOS Doors** in the admin interface.

---

## Activation and Admin Interface

1. Visit `/admin/jsdosdoors`.
2. If no config exists, click **Activate JS-DOS Doors** to create `config/jsdosdoors.json` from the example.
3. Edit the JSON to enable the games you want.
4. Click **Save**.

The door list on the left sidebar of the admin page shows all discovered manifests and whether each game is enabled. Games not present in the config JSON are shown as `(not in config)`.

---

## Adding a Game: Doom Example

The Doom shareware manifest is included at `public_html/jsdos-doors/doomsw/jsdosdoor.json`. It demonstrates both normal play mode and an admin-only `config` mode.

A complete working manifest for Doom shareware looks like this:

```json
{
    "id": "doomsw",
    "name": "Doom",
    "version": "1.9",
    "author": "id Software",
    "description": "The classic first-person shooter. Fight through the demon-infested UAC base.",
    "icon": "icon.png",
    "emulator": "jsdos",
    "emulator_config": {
        "cpu_cycles": "max 90%",
        "memory_mb": 16,
        "machine": "svga_s3",
        "game_files": [
            {"asset_path": "assets/DOOM.EXE", "dos_path": "C:/DOOM/DOOM.EXE"},
            {"asset_path": "assets/DOOM1.WAD", "dos_path": "C:/DOOM/DOOM1.WAD"}
        ],
        "autoexec": [
            "C:",
            "cd DOOM",
            "SET BLASTER=A220 I7 D1 T4",
            "DOOM.EXE"
        ]
    },
    "modes": {
        "config": {
            "label": "Admin Setup",
            "admin_only": true,
            "keep_open": true,
            "emulator_config": {
                "game_files": [
                    {"asset_path": "assets/DOOM.EXE", "dos_path": "C:/DOOM/DOOM.EXE", "optional": true},
                    {"asset_path": "assets/DOOM1.WAD", "dos_path": "C:/DOOM/DOOM1.WAD", "optional": true},
                    {"asset_path": "assets/SETUP.EXE", "dos_path": "C:/DOOM/SETUP.EXE"}
                ],
                "autoexec": [
                    "C:",
                    "cd DOOM",
                    "SET BLASTER=A220 I7 D1 T4"
                ]
            },
            "saves": {
                "enabled": true,
                "scope": "shared",
                "save_paths": [
                    "C:/DOOM/DEFAULT.CFG"
                ],
                "max_size_kb": 512
            }
        }
    },
    "saves": {
        "enabled": true,
        "scope": "user",
        "save_paths": [
            "C:/DOOM/DOOMSAV*.DSG"
        ],
        "max_size_kb": 512
    },
    "network": {
        "enabled": true,
        "protocol": "ipx",
        "max_players": 4,
        "max_rooms": 10
    },
    "credits": {
        "session_cost": 0
    }
}
```

**Step-by-step setup:**

1. Place game files in `public_html/jsdos-doors/doomsw/assets/`. For play mode you need `DOOM.EXE` and `DOOM1.WAD`. For first-time admin setup, `SETUP.EXE` can be present by itself; the optional gameplay assets are skipped until you add them.
2. Create or update `public_html/jsdos-doors/doomsw/jsdosdoor.json` with the manifest above.
3. Place a 96×96 `icon.png` in `public_html/jsdos-doors/doomsw/` (optional but recommended).
4. Ensure `config/jsdosdoors.json` exists and has `"doomsw": {"enabled": true}`. Use `/admin/jsdosdoors` to create and edit it.
5. Visit `/games` — Doom should appear with the `[JSDOS]` badge.
6. If you are an admin on a first-time install, open **Admin Config** from the wrapper page and run `SETUP.EXE` to generate `DEFAULT.CFG`.
7. `DEFAULT.CFG` syncs to the server automatically every ~2 seconds while the session is active (and again on exit), via the admin daemon. It is stored as a shared default for all players.
8. Click **Launch Game**. The browser will download the play-mode files and boot DOSBox, loading the shared `DEFAULT.CFG` if one has already been created.

> **Note:** Game asset files in `public_html/` are publicly accessible without authentication. Only place files you are licensed to distribute there. The Doom shareware release (`DOOM1.WAD`) is freely distributable; the commercial `DOOM.WAD` is not.

---

## Troubleshooting

**Game does not appear in the `/games` listing**
- Check that `config/jsdosdoors.json` exists and contains the game ID with `"enabled": true`.
- Check that `public_html/jsdos-doors/{game-id}/jsdosdoor.json` exists and contains a valid `id` field.
- Check that the game directory name does not start with `_`.

**"Failed to load game assets" error on launch**
- Open the browser developer console and check which asset URL returned a 404.
- Verify the file listed in `game_files[].asset_path` exists under `public_html/jsdos-doors/{game-id}/`.
- Asset paths in the manifest are relative to the game directory, not `assets/` — the `assets/` prefix must be included explicitly (e.g. `"asset_path": "assets/DOOM1.WAD"`).
- If a file is intentionally absent during initial setup, mark that `game_files[]` entry with `"optional": true` so launch can continue without it.

**DOSBox boots but the game does not start**
- Check the `autoexec` commands in the manifest. The first `game_files` entry determines where the executable lives; the autoexec must navigate to that directory and run the correct filename.
- All files the game needs at runtime must be in `game_files`. Files not listed are not present in the virtual filesystem.

**`wdosbox.wasm.js` 404 in the browser console**
- The js-dos library files may be missing or the `wdosbox.js` patch was not applied. Run `php scripts/download_jsdos.php` to re-download and patch the files.
