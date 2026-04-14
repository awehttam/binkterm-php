# Upgrading to 1.9.2

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [JS-DOS Doors](#js-dos-doors)
- [Image Rendering in Terminal Services](#image-rendering-in-terminal-services)
- [Sixel Login and Menu Screens](#sixel-login-and-menu-screens)
- [Door Session Expiry Enforcement](#door-session-expiry-enforcement)
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
- When a message contains images, an **`I` — Image** keybinding appears in the message viewer status bar. Press `I` to open the image picker, type a number (1–99), and press Enter. For single-image messages, pressing `I` opens it immediately. You can also press a digit key (`1`–`9`) directly to jump to that image without the picker.
- Image rendering requires `img2sixel` (from the `libsixel` package) to be installed on the server. Without it, a placeholder label is shown instead.
- The terminal must support Sixel graphics (e.g. xterm with `-ti 340`, mlterm, WezTerm, SyncTERM). Non-Sixel terminals gracefully see `[Image N: alt text]` placeholders in the message body.

### Sixel Login and Menu Screens

- The terminal server's Welcome, Main Menu, and Goodbye screens now support **Sixel graphics** as an alternative to ANSI art files.

- Sixel images are displayed only on clients that advertise Sixel support during the connection handshake. Clients that do not support Sixel continue to see the existing ANSI screen or the built-in text banner.
- Sixel screen files are managed from **Admin → Appearance → Terminal Server → Sixel Graphics**. Upload a `.sixel` file for any of the three slots (Welcome, Main Menu, Goodbye). The filename for each slot is shown in the dropdown so it is clear which file is being managed.
- No database migration is required. Sixel screens are optional; the system falls back to ANSI if no sixel file is installed for a given slot.

### Door Session Expiry Enforcement

- Door game sessions (DOS Doors, Native Doors including Public Terminal) now have their expiry time actively enforced. Sessions that were left open because a user closed the browser tab or the underlying process exited without calling the end-session API no longer accumulate and block new connections. Stale sessions are automatically cleared before each new session is started, and periodically during normal web requests.

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
2. If any are found, an **`I` — Image** or **`I` — Images** prompt appears in the status bar at the bottom of the viewer.
3. For a single image, press `I` to open it immediately. For multiple images, press `I` to open the number picker — type a number (supports 1–99) and press Enter. You can also press a digit key (`1`–`9`) directly from the message view to jump straight to that image.
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

## Sixel Login and Menu Screens

The terminal server can now display Sixel graphics at the Welcome screen (shown at connection before login), the Main Menu screen (shown behind the menu after login), and the Goodbye screen (shown on disconnect). These supplement the existing ANSI art support rather than replacing it.

### How it works

During the connection handshake, the terminal server sends a Primary Device Attributes request (`ESC [ c`) and reads the client's response. If the response includes attribute `4`, the client is considered Sixel-capable. This detection takes place before the banner is displayed, so the correct screen type is known by the time anything is shown.

When a Sixel file is installed for a slot and the client supports Sixel, the Sixel file is sent raw to the terminal. If no Sixel file is installed for that slot, or the client does not support Sixel, the system falls back to the ANSI file for that slot (if one is installed), and then to the built-in text banner.

### Managing Sixel screens

Sixel screens are managed from **Admin → Appearance → Terminal Server**. The existing ANSI/Text Screens section is unchanged. Below it, a new **Sixel Graphics** section provides a slot dropdown, an upload button, and a remove button.

The slot dropdown for both sections now shows the filename alongside the slot name (for example, `Welcome (login.ans)` and `Welcome (login.sixel)`), so it is clear which file on disk corresponds to each slot.

Supported file extensions are `.sixel` and `.six`. The maximum file size is 5 MB. Files are stored in `telnet/screens/` alongside the existing ANSI files.

The admin daemon (`scripts/admin_daemon.php`) handles all file writes. Restart it after upgrading so the new sixel screen commands are available.

### Terminal compatibility

Sixel graphics are supported by terminals including mlterm, WezTerm, xterm (compiled with Sixel support, or launched as `xterm -ti 340`), and SyncTERM. Terminals that do not advertise Sixel support receive the ANSI screen or text banner as before — no configuration is needed to maintain compatibility with non-Sixel clients.

## Door Session Expiry Enforcement

Door game sessions are stored in the `door_sessions` table and carry an `expires_at` timestamp set to two hours after the session starts. Previously, the code that checks whether a door has available node slots only tested whether a session's `ended_at` column was null — it never checked `expires_at`. A session left open because the user closed the browser without using the End Session button, or because the underlying process exited abnormally, would remain indefinitely in the table and count against the door's node limit. On a door configured with a small number of nodes (such as Public Terminal, which defaults to 5), a handful of abandoned sessions could prevent any new connections.

The expiry timestamp is now enforced in every place that counts active sessions:

- All capacity checks — per-door max node limit, per-door guest concurrency limit — exclude sessions whose `expires_at` is in the past.
- The node allocation query used when a new session is starting excludes expired sessions from the set of occupied nodes.
- The `getActiveSessions()` and `getUserSession()` methods in `DoorSessionManager` exclude expired sessions, so the admin sessions list and the "resume existing session" check also reflect reality.
- A new `cleanExpiredSessions()` method on `DoorSessionManager` writes `ended_at` and `exit_status = 'expired'` for any session that has passed its expiry time. This is called automatically each time a new session is started, and also runs during the periodic maintenance that already fires on roughly 5% of web requests (alongside the existing auth session cleanup).

No database migration is required. Stale sessions already present in your database will be cleaned up the first time a new door session is started after upgrading.

If you want to clear them immediately without waiting for a new session, run the following directly against your database:

```sql
UPDATE door_sessions
SET ended_at = NOW(), exit_status = 'expired'
WHERE ended_at IS NULL AND expires_at < NOW();
```

## Upgrade Instructions

### From Git

```bash
git pull
composer install
```

No database migration is required for this release.

After upgrading, restart `admin_daemon.php` so the new admin-side commands (JS-DOS configuration and Sixel screen management) are available:

```bash
# restart the admin daemon (adjust to your init system)
php scripts/admin_daemon.php &
```

JS-DOS Doors are optional and remain inactive until you enable the feature and add game assets. Sixel screens are also optional; the terminal server falls back to ANSI or the built-in text banner if no Sixel files are installed.

### Using the Installer

If you upgrade using the web installer or your normal packaged deployment flow, no special migration step is required. After the upgrade, restart `admin_daemon.php`, then optionally enable JS-DOS Doors from the admin interface and upload Sixel screen files from **Admin → Appearance → Terminal Server → Sixel Graphics**.
