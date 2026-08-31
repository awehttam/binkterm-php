# Upgrading to 1.10.5

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Message Composition](#message-composition)
- [Admin BBS Settings](#admin-bbs-settings)
- [MeshCore Enable/Disable](#meshcore-enabledisable)
- [Docker WebSocket Proxy](#docker-websocket-proxy)
- [RLogin Door Player Backspace Fix](#rlogin-door-player-backspace-fix)
- [CLI Script Fixes](#cli-script-fixes)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Message Composition

- The compose editor's automatic line-wrap column now defaults to **72** instead of 79, and 72 is offered as the recommended choice in the compose Advanced Options. Wrapping at 72 leaves room for quote-attribution prefixes (for example ` AB> `) so that quoted reply lines stay within the 79-column width that FidoNet readers expect. The 79-column option is still available for users who prefer it, and anyone who has already chosen a wrap width keeps their setting.

### Admin BBS Settings

- The **Admin -> BBS Settings** page is now organized into four tabs: **System & Features**, **Credit System**, **Tag Lines**, and **Registration Screening**. All settings and their save buttons are unchanged; they are only regrouped so the page is shorter and easier to navigate.

### MeshCore Enable/Disable

- A new **Enable MeshCore** toggle in **Admin -> BBS Settings -> System & Features** turns the whole MeshCore / PacketBBS radio subsystem on or off. It defaults to **on**, so existing MeshCore setups keep working. Turning it off makes the bridge API unreachable and hides every MeshCore surface (user settings tab, public nodes page, admin page, dashboard card, navigation links).

### Docker WebSocket Proxy

- The bundled Docker image now proxies the realtime WebSocket stream (`/ws`) and the DOS door bridge (`/dosdoor`) through Apache, so the event bus and browser-side DOS door games work in container deployments. Rebuild the image to pick this up.

### RLogin Door Player Backspace Fix

- The browser-based RLogin door player (`public_html/webdoors/rlogindoors/index.php`) now remaps the DEL byte (`0x7f`) that modern browsers send for the Backspace/Delete key to the Backspace byte (`0x08`) that RLogin door servers expect. Previously the Backspace key was ignored in RLogin doors (for example DOS doors run through DOSEMU/DOSBox, or MajorBBS). The equivalent DOS door players already had this remap; this brings the RLogin player in line.

### CLI Script Fixes

- `scripts/admin_daemon.php`, `scripts/install.php`, `scripts/setup.php`, and `scripts/upgrade.php` now load `src/functions.php` alongside the Composer autoloader, so the global helper functions it defines (such as `getServerLogger()`) are always available to those entrypoints.

---

## Message Composition

When composing netmail or echomail, the editor can hard-wrap long lines automatically as you type. The wrap column is a per-user preference in the compose form's **Advanced Options**.

Previously the default was 79 columns. When replying to a message, each quoted line is prefixed with an attribution string such as ` AB> `, which pushed quoted lines past 79 columns and caused readers to wrap them a second time. The default is now 72 columns, which keeps quoted lines within 79 after the prefix is added. A new **72 characters (recommended)** option appears in the wrap selector; the **79** option remains for users who want it. Existing saved preferences are unchanged.

## Admin BBS Settings

The **Admin -> BBS Settings** page previously presented every section as a long vertical stack of cards. It is now split into four tabs:

- **System & Features** - system identity, terminal server / idle timeouts, Packet BBS, and the BBS feature toggles
- **Credit System** - the full Credit System Configuration panel
- **Tag Lines** - the tagline list editor
- **Registration Screening** - new-user IP screening and its signal weights

Each tab keeps its own **Save** button and saves independently, exactly as before.

## MeshCore Enable/Disable

MeshCore (also referred to as PacketBBS — the mesh-radio / low-bandwidth access path) can now be switched off entirely from **Admin -> BBS Settings -> System & Features** with the **Enable MeshCore** checkbox. This corresponds to a new `features.meshcore` key in `config/bbs.json`.

The toggle defaults to **enabled**, so a system that is already using MeshCore sees no change after upgrading.

When it is disabled:

- All bridge endpoints (`/api/packetbbs/*` and `/api/meshcore/*`) return `404`, so no radio bridge can authenticate or relay commands.
- The **MeshCore** tab in each user's **Settings** page is hidden.
- The public **Meshcore Nodes** page (`/packetbbs-nodes`), the "Meshcore Nodes" navigation links, and the dashboard "Packet BBS Status" card are hidden.
- The **Admin -> Packet BBS** management page returns `404` and its navigation link is hidden.

Node records, radio sessions, and stored contacts are not deleted while MeshCore is disabled; re-enabling the toggle restores everything.

## Docker WebSocket Proxy

The bundled Docker image now proxies the realtime WebSocket stream and the DOS door bridge through Apache. The image enables `mod_proxy`, `mod_proxy_http`, and `mod_proxy_wstunnel`, and ships a `docker/000-default.conf` virtual host that forwards `/ws` to the BinkStream server on `127.0.0.1:6010` and `/dosdoor` to the door bridge on `127.0.0.1:6001`.

Previously these WebSocket endpoints were not reachable from inside the container, which broke the realtime event bus and browser-side DOS door games for Docker deployments. Rebuilding the image from the updated `Dockerfile` picks up the change.

If you run your own reverse proxy in front of the container, make sure it also passes `/ws` and `/dosdoor` through as WebSocket upgrades to Apache on port 80.

## RLogin Door Player Backspace Fix

On modern browsers — particularly on macOS and iOS — xterm.js emits ASCII `0x7f` (DEL) when the user presses the Backspace or Delete key. RLogin door servers, and the DOS doors they front (DOSEMU/DOSBox, MajorBBS, and similar), expect ASCII `0x08` (BS) instead, so the Backspace key did nothing inside an RLogin door.

The browser DOS door players (`public_html/webdoors/dosdoors/index.php` and `guest-door-player.php`) already translated `0x7f` to `0x08` in their `term.onData()` handler. That same one-line translation is now applied in the RLogin door player (`public_html/webdoors/rlogindoors/index.php`), so Backspace works consistently across all browser-side door players. Clearing the browser/service-worker cache (or a hard reload) ensures clients pick up the updated script.

## CLI Script Fixes

`scripts/admin_daemon.php`, `scripts/install.php`, `scripts/setup.php`, and `scripts/upgrade.php` now load `src/functions.php` in addition to the Composer autoloader.

The helper functions in `src/functions.php` (such as `getServerLogger()`) are plain global functions, not PSR-4 autoloaded classes, so they are only available where the file is explicitly required. These CLI entrypoints did not require it. Some code they can reach — the admin daemon's BBS Settings handling, and individual migration files — calls those helpers, so this closes a latent `Call to undefined function BinktermPHP\getServerLogger()` risk on those paths.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
