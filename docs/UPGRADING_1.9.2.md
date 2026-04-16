# Upgrading to 1.9.2

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [AI Assistant](#ai-assistant)
- [Admin Credit Grants](#admin-credit-grants)
- [JS-DOS Doors](#js-dos-doors)
  - [Manifest Creator Script](#manifest-creator-script)
- [Terminal Registration Handling](#terminal-registration-handling)
- [Image Rendering in Terminal Services](#image-rendering-in-terminal-services)
- [Sixel Login and Menu Screens](#sixel-login-and-menu-screens)
- [Door Session Expiry Enforcement](#door-session-expiry-enforcement)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### AI Assistant

- BinktermPHP 1.9.2 introduces an optional **AI Assistant** for the web message readers.
- In echomail, users can open the assistant from the area toolbar or directly from the message reader modal, where the current message is pre-selected as context.
- The assistant can summarize a message, explain terminology, and summarize the surrounding thread by retrieving real message data through the built-in MCP server integration.
- Enablement is controlled by `ai_assistant.enabled` in BBS settings.
- The current reader assistant implementation requires an Anthropic API key and a reachable MCP server URL.
- A new credits setting, `credits.ai_credits_per_milli_usd`, lets sysops optionally charge BBS credits based on estimated AI request cost.

### Admin Credit Grants

- Admins can now add credits directly to a user's account with an explicit note recorded in the ledger.
- Manual grants are stored as `admin_adjustment` transactions, so they remain auditable alongside the normal credit history.
- The current production admin users screen at `/admin/users` includes a **Grant Credits** section in the edit-user modal.
- A separate preview endpoint, `/admin/users-new`, renders the newer in-progress admin users interface against the newer `/admin/api/users/...` backend.

### JS-DOS Doors

- BinktermPHP now supports **JS-DOS Doors**, a new browser-side door type for classic DOS games such as Doom.
- Unlike traditional DOS doors, JS-DOS Doors run entirely in the user's browser using WebAssembly-powered DOS emulation. No per-session server-side DOSBox process is launched.
- JS-DOS games appear in the `/games` listing alongside DOS Doors, Native Doors, WebDoors, and C64 Doors with their own `[JSDOS]` badge.
- Games are defined by `jsdosdoor.json` manifests under `public_html/jsdos-doors/{game-id}/`.
- Save files can be synchronized back to the server, allowing users to keep their game progress between sessions.
- JS-DOS Doors also support multiple modes, including an optional admin-only configuration mode for running setup tools and saving shared defaults for all players.
- A new interactive command-line wizard, `scripts/jsdosdoor_createmanifest.php`, guides you through creating a `jsdosdoor.json` for any DOS game. It scans the `assets/` directory, prompts for title and executable, suggests AI-generated author and description metadata, and produces a ready-to-use manifest including an admin setup mode and a placeholder `icon.png`.

### Terminal Registration Handling

- Telnet and SSH registrations now preserve the applicant's real reason for joining instead of replacing it with a transport label.
- Browser-only anti-spam bypass for terminal-origin registrations now uses dedicated transport signaling rather than overloading the visible reason field.
- `TERMINAL_REGISTRATION_SECRET` now defaults to `Chang3Me`, so fresh installs work without extra setup, but production systems should replace it with a site-specific value.

### Image Rendering in Terminal Services

- Telnet and SSH message readers can now render inline Markdown images as Sixel graphics when both the server and client support it.
- Messages with images expose an `I` keybinding and direct number shortcuts so users can open embedded images from the terminal reader.
- Systems without `img2sixel` or clients without Sixel support fall back gracefully to text placeholders.

### Sixel Login and Menu Screens

- The terminal server now supports optional Sixel Welcome, Main Menu, and Goodbye screens in addition to the existing ANSI art workflow.
- Sixel files are managed from **Admin → Appearance → Terminal Server → Sixel Graphics** and are shown only to clients that advertise Sixel support.
- No database migration is required; systems without Sixel files continue using ANSI or the built-in text banner.

### Door Session Expiry Enforcement

- Door session expiry is now enforced when checking capacity, allocating nodes, listing active sessions, and resuming existing sessions.
- Stale abandoned sessions are cleaned automatically when new door sessions start and during periodic web-request maintenance.
- No database migration is required, and existing stale sessions are cleaned up automatically after upgrade.

## AI Assistant

This release adds a new optional AI assistant to the web message readers. The feature is intended as a reading and comprehension aid for echomail and netmail rather than an automated posting system.

In the echomail reader, the assistant appears in two places:

- as an **AI Assistant** button in the page toolbar
- as an AI button in the message reader modal header

When opened from a message, the assistant receives the current message ID as context. It can then summarize the message, explain jargon, or summarize the full thread. The implementation does not hand raw database access to the model. Instead, it routes requests through the built-in MCP server so the model can fetch only the message, thread, and echomail data it needs while staying within the user's normal access scope.

### How to enable it

The AI assistant is only active when BBS configuration enables the feature:

```json
{
  "ai_assistant": {
    "enabled": true
  }
}
```

You can manage this setting from **Admin → BBS Settings → Features**.

The default setting is off until you enable it.

### Current configuration requirements

The current 1.9.2 implementation requires:

- `ANTHROPIC_API_KEY` to be configured
- the MCP server to be reachable at `MCP_SERVER_URL`

If the feature is disabled in BBS settings, the UI hides the assistant controls. If the feature is enabled but Anthropic is not configured, the API returns a configuration error when the user tries to run a request.

### Optional credit charging

This release also adds `credits.ai_credits_per_milli_usd` to BBS credit settings. This allows AI usage to debit BBS credits based on the request's estimated USD cost.

Set it to `0` to allow the assistant without charging credits, or to a positive integer to convert AI usage cost into your local credit economy.

See `docs/AIAssistant.md` for the full operational documentation.

## Admin Credit Grants

This release adds a direct admin workflow for manually increasing a user's credit balance. The goal is to cover operational cases that do not fit the automated reward and debit rules, such as contest prizes, goodwill adjustments, reimbursement, migration cleanup, or support corrections.

Manual grants are not silent balance edits. Each change is written through the normal `UserCredit` transaction path and recorded as an `admin_adjustment` entry with the administrator's note attached to the ledger description.

### Where to use it

In the currently active admin users screen:

- open **Admin → Users**
- click **Edit** on the target user
- use the **Grant Credits** section in the edit modal

That section shows:

- the user's current credit balance
- an amount field
- a required reason or note field
- an **Add Credits** action button

The grant will be rejected if:

- the credits system is disabled
- the amount is not a positive integer
- no note is provided

### Preview route for the newer admin users interface

This release branch also exposes a separate preview route:

```text
/admin/users-new
```

That page renders the newer `templates/admin/users.twig` implementation without replacing the existing `/admin/users` screen yet. It is intended for side-by-side evaluation while both admin user-management interfaces still exist in the codebase.

### Terminal Registration Handling

- Telnet and SSH registrations no longer place a transport label such as `Telnet registration` into the applicant's **Reason for Joining** field.
- Terminal registrations now submit the applicant's actual reason text, so the Pending Users admin screen and sysop registration notifications show the same kind of information that web registrations already provided.
- Browser-only anti-spam checks on `/api/register` are now bypassed only for authenticated terminal-origin requests. The transport is identified separately from the applicant's reason text.
- Fresh installs work without extra setup because `TERMINAL_REGISTRATION_SECRET` now defaults to `Chang3Me`. Existing installations can leave the variable unset, but should change it to a site-specific value for production use.

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

### Manifest Creator Script

`scripts/jsdosdoor_createmanifest.php` is an interactive CLI wizard that generates a `jsdosdoor.json` manifest for a DOS game. Authoring a manifest by hand requires understanding the emulator configuration fields, mapping every asset file to a DOS path, and writing both a play mode and an admin setup mode. The wizard automates all of that so you can set up a new game in a few minutes without editing JSON directly.

**How to use it:**

Run the script from within the game's door directory — the directory that contains the `assets/` folder — or pass the directory as an argument:

```bash
cd public_html/jsdos-doors/mygame
php scripts/jsdosdoor_createmanifest.php

# or from anywhere:
php scripts/jsdosdoor_createmanifest.php public_html/jsdos-doors/mygame
```

The wizard prompts for:

- Game title and the executable path relative to `assets/` (e.g. `QUAKE.EXE` or `QUAKE/QUAKE.EXE`)
- CPU cycles, machine type, and emulated memory
- Whether to include a Sound Blaster environment variable
- Per-user save file paths (glob patterns, with sensible defaults if left blank)
- The shared config file path used by the admin setup mode

It then:

1. Scans every file in `assets/` and maps each one to a DOS filesystem path automatically.
2. Detects common setup executables (`SETUP.EXE`, `INSTALL.EXE`, etc.) and adds them to the admin config mode autoexec.
3. Calls the configured AI provider (Anthropic or OpenAI, whichever is set up in `.env`) to suggest the game's original author, a short description, and a version string. All three can be edited before the file is written.
4. Shows a preview of the complete JSON and asks for confirmation before writing `jsdosdoor.json`.
5. Offers to generate a placeholder `icon.png` (96×96, requires the PHP GD extension).

The generated manifest includes a `config` admin-only mode with `keep_open: true` and `scope: shared` saves, matching the pattern used by the Doom example. After writing the file, the wizard prints the next steps needed to activate the door in `config/jsdosdoors.json`.

AI metadata lookup is optional. If no AI provider is configured the wizard skips that step and leaves the author and description fields blank for manual entry.

## Terminal Registration Handling

Terminal and SSH users can register without opening the web form, but the terminal client cannot satisfy browser-only anti-spam checks such as the hidden honeypot field or the registration-page timing check. In earlier 1.9.2 builds, the terminal registration flow reused the visible `reason` field as an internal bypass signal by sending values like `Telnet registration`. That had two side effects:

- the applicant's real reason for joining was discarded from terminal registrations
- the bypass decision depended on a user-submitted field instead of a dedicated transport signal

This release changes that behavior.

### What changed

- The telnet and SSH registration prompts now include **Reason for joining (optional)**.
- The terminal client sends the applicant's answer as the normal `reason` value stored with the pending registration.
- The web API now uses dedicated terminal-origin headers for the anti-spam bypass instead of inspecting the `reason` field.
- SSH and Telnet registrations now follow the same bypass path instead of relying on different literal reason text.

### Operational impact

If your sysop or admin workflow reviews registrations from **Admin → Pending Users** or from the automatic registration netmail notice, terminal applicants now appear with their actual stated reason for joining instead of a transport placeholder.

### Configuration

The terminal registration bypass uses `TERMINAL_REGISTRATION_SECRET`.

| Variable | Default | Description |
|---|---|---|
| `TERMINAL_REGISTRATION_SECRET` | `Chang3Me` | Shared secret used by telnet/SSH registration requests when bypassing browser-only anti-spam checks. Change this to a site-specific value for production deployments. |

If you already have live telnet or SSH service enabled, update both the web environment and the terminal daemon environment together if you decide to change this value from the default.

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

If you run telnet or SSH registration, review `TERMINAL_REGISTRATION_SECRET`. New installs work without setting it because the default is `Chang3Me`, but production systems should replace that default with a site-specific secret value before exposing terminal registration publicly.

After upgrading, restart `admin_daemon.php` so the new admin-side commands (JS-DOS configuration and Sixel screen management) are available:

```bash
# restart the admin daemon (adjust to your init system)
php scripts/admin_daemon.php &
```

JS-DOS Doors are optional and remain inactive until you enable the feature and add game assets. Sixel screens are also optional; the terminal server falls back to ANSI or the built-in text banner if no Sixel files are installed.

### Using the Installer

If you upgrade using the web installer or your normal packaged deployment flow, no special migration step is required. After the upgrade, restart `admin_daemon.php`, review `TERMINAL_REGISTRATION_SECRET` if you allow terminal-side registration, then optionally enable JS-DOS Doors from the admin interface and upload Sixel screen files from **Admin → Appearance → Terminal Server → Sixel Graphics**.
