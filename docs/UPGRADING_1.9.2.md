# Upgrading to 1.9.2

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [JS-DOS Doors](#js-dos-doors)
- [Image Rendering in Terminal Services](#image-rendering-in-terminal-services)
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

### Image Rendering in Terminal Services

- Echomail and netmail messages containing inline images (Markdown `![alt](url)` syntax) can now be rendered as **Sixel graphics** directly in the telnet and SSH terminal readers.
- When a message contains images, an **`I` — Image** keybinding appears in the message viewer status bar. Press `I` to view image 1, or press a digit key (`1`–`9`) to jump directly to a numbered image.
- Image rendering requires `img2sixel` (from the `libsixel` package) to be installed on the server. Without it, a placeholder label is shown instead.
- The terminal must support Sixel graphics (e.g. xterm with `-ti 340`, mlterm, WezTerm, SyncTERM). Non-Sixel terminals gracefully see `[Image N: alt text]` placeholders in the message body.

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

## Image Rendering in Terminal Services

Echomail and netmail messages that include inline images (written using Markdown `![alt](url)` syntax) can now display those images as **Sixel graphics** inside the telnet and SSH terminal readers.

### How it works

1. When a message is opened in the terminal viewer, the server scans the message body for Markdown image references.
2. If any are found, an **`I` — Image** prompt appears in the status bar at the bottom of the viewer. For messages with more than one image, the prompt shows `I Image (1-N)`.
3. Press `I` to view the first image, or press a digit key (`1`–`9`) to jump to a specific numbered image.
4. The image viewer clears the screen, downloads the image, converts it to Sixel, and renders it inline. Press any key to return to the message.

If `img2sixel` is not installed or the terminal does not support Sixel, the image references are shown as styled `[Image N: alt text]` placeholders in the message body — no configuration is required to fall back gracefully.

### Requirements

- **`img2sixel`** must be installed on the server (provided by the `libsixel-bin` package on Debian/Ubuntu). Without it, images are shown as text placeholders.
- The connecting terminal must support Sixel graphics (e.g. xterm compiled with Sixel support, mlterm, WezTerm, SyncTERM).

### Optional `.env` tuning

| Variable | Default | Description |
|---|---|---|
| `IMG2SIXEL_PATH` | *(auto-detected)* | Absolute path to the `img2sixel` binary. Set this if `img2sixel` is installed in a non-standard location. |
| `SIXEL_FETCH_TIMEOUT` | `10` | Seconds to wait when downloading an external image URL. |
| `SIXEL_CONVERT_TIMEOUT` | `15` | Seconds to allow `img2sixel` to run before timing out. |
| `SIXEL_WRITE_TIMEOUT` | `15` | Seconds to allow writing the Sixel data stream to the terminal. |
| `SIXEL_IMAGE_MAX_BYTES` | `5242880` (5 MB) | Maximum image file size to download. |
| `SIXEL_PIXELS_PER_COL` | `9` | Estimated pixel width of one terminal column, used to calculate maximum image width. |
| `SIXEL_PIXELS_PER_ROW` | `16` | Estimated pixel height of one terminal row, used to calculate maximum image height. |

No database migration is required for this feature.

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
