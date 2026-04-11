# Upgrading to 1.9.2

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [JS-DOS Doors](#js-dos-doors)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### JS-DOS Doors

- BinktermPHP now supports **JS-DOS Doors**, a new browser-side door type for classic DOS games such as Doom.
- Unlike traditional DOS doors, JS-DOS Doors run entirely in the user's browser using WebAssembly-powered DOS emulation. No per-session server-side DOSBox process is launched.
- JS-DOS games appear in the `/games` listing alongside DOS Doors, Native Doors, WebDoors, and C64 Doors with their own `[JSDOS]` badge.
- Games are defined by `jsdosdoor.json` manifests under `public_html/jsdos-doors/{game-id}/`.
- Save files can be synchronized back to the server, allowing users to keep their game progress between sessions.
- JS-DOS Doors also support multiple modes, including an optional admin-only configuration mode for running setup tools and saving shared defaults for all players.

## JS-DOS Doors

JS-DOS Doors introduce a new way to offer graphical DOS-era games through the BBS web interface. Instead of streaming terminal text or relaying a server-side emulator session, the browser loads the game assets directly and runs the emulator locally in a canvas.

This has a few important consequences:

- **Lower server overhead** - gameplay is handled by the user's browser instead of a dedicated server process.
- **Graphical browser experience** - games can render their original graphics, sound, and mouse-driven interfaces inside the existing game wrapper.
- **Persistent saves** - configured save files are synced to the server and restored on the next launch.
- **Admin setup workflow** - games can define a separate admin-only setup mode to adjust options like sound cards, controls, or shared default config files.

### How to enable it

1. Visit **Admin -> JS-DOS Doors**.
2. Activate the JS-DOS door system if it is not already enabled.
3. Enable one or more discovered games in `config/jsdosdoors.json`.
4. Place each game's files under `public_html/jsdos-doors/{game-id}/assets/` as required by its manifest.
5. Visit `/games` and launch the game from the normal doors listing.

### Doom included as the reference example

This release includes Doom as the reference JS-DOS door example. It demonstrates:

- standard play mode
- admin-only configuration mode
- per-user save slots
- shared admin-managed default configuration
- launch through the standard `/games/{game}` wrapper flow

See `docs/JSDOSDoors.md` for the full manifest format and admin workflow.

## Upgrade Instructions

### From Git

```bash
git pull
composer install
```

No database migration is required specifically for JS-DOS Doors. The feature is optional and remains inactive until you enable it and add game assets.

After upgrading, restart `admin_daemon.php` so the admin-side JS-DOS configuration tooling is running the new code.

### Using the Installer

If you upgrade using the web installer or your normal packaged deployment flow, no special migration step is required for JS-DOS Doors. After the upgrade, enable the feature from the admin interface, add the desired game assets, and restart `admin_daemon.php`.
