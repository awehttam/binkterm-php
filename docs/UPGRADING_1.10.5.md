# Upgrading to 1.10.5

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Message Composition](#message-composition)
- [Admin BBS Settings](#admin-bbs-settings)
- [MeshCore Enable/Disable](#meshcore-enabledisable)
- [NNTP Server](#nntp-server)
- [Docker WebSocket Proxy](#docker-websocket-proxy)
- [Door Player Backspace Handling](#door-player-backspace-handling)
- [BBSDEV.DRP Drop File (Experimental)](#bbsdevdrp-drop-file-experimental)
- [CLI Script Fixes](#cli-script-fixes)
- [CP437 Login ANSI Art](#cp437-login-ansi-art)
- [Terminal Registration House Rules](#terminal-registration-house-rules)
- [Terminal Full-Screen Editor Flicker](#terminal-full-screen-editor-flicker)
- [Community Mods List](#community-mods-list)
- [Docker Stale Apache PID Cleanup](#docker-stale-apache-pid-cleanup)
- [Navbar Active Section Indicator](#navbar-active-section-indicator)
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

### NNTP Server

- BinktermPHP can now serve its echoareas as Usenet-style newsgroups over NNTP (RFC 3977), so members can read — and optionally post — echomail with a standard newsreader such as Thunderbird. It runs as a new optional daemon, `scripts/nntp_server.php`, and is **disabled by default**. Enable it, and configure rate limits and the plaintext-authentication policy, in the new **Admin -> NNTP Server** page. Posting from a newsreader is a second toggle on that page, also off by default.
- Each member also gets a private **netmail** newsgroup whose articles are that member's own netmail; posting into it sends netmail. It is enabled by default when the NNTP server is on, with its own settings on the **Admin -> NNTP Server** page (group name, whether sending is allowed, whether sent mail is included, and a separate send rate limit).
- New database tables (`nntp_article_numbers`, `nntp_area_watermark`) are created and populated from your existing echomail during the upgrade, plus `nntp_netmail_article_numbers` and `nntp_netmail_watermark` (per member, filled on first read) and a `tearline_component` column on `netmail`.
- Transport settings — bind address, ports, and TLS certificate paths — are read from `.env`. New keys: `NNTP_BIND_HOST`, `NNTP_PORT` (default `8119`), `NNTP_TLS_PORT` (default `8563`), `NNTP_TLS_CERT_PATH`, `NNTP_TLS_KEY_PATH`. The ports default to an unprivileged range; redirect the standard `119` / `563` to them with a firewall rule.
### Docker WebSocket Proxy

- The bundled Docker image now proxies the realtime WebSocket stream (`/ws`) and the DOS door bridge (`/dosdoor`) through Apache, so the event bus and browser-side DOS door games work in container deployments. Rebuild the image to pick this up.

### Door Player Backspace Handling

- The browser-based RLogin door player (`public_html/webdoors/rlogindoors/index.php`) now remaps the DEL byte (`0x7f`) that modern browsers send for the Backspace/Delete key to the Backspace byte (`0x08`) that RLogin door servers expect. Previously the Backspace key was ignored in RLogin doors (for example DOS doors run through DOSEMU/DOSBox, or MajorBBS). The equivalent DOS door players already had this remap; this brings the RLogin player in line.
- All three browser door players (`rlogindoors/index.php`, `webdoors/dosdoors/index.php`, `guest-door-player.php`) now also translate an inbound `0x7f` (DEL) coming *from* the door engine into a destructive backspace sequence (`\b \b`) before writing it to the terminal. Some door engines emit a bare DEL to erase the last character; xterm.js would otherwise render it as a visible glyph instead of erasing. Binary WebSocket frames are left untouched.

### BBSDEV.DRP Drop File (Experimental)

- Door manifests can now select `BBSDEV.DRP` as their `dropfile_format`, for both native doors and DOS doors, in the **Admin -> Door Manifest Editor**. BBSDEV.DRP is a modern 19-line drop file (UTF-8, one value per line) defined by [its own specification](https://realdeuce.github.io/bbsdev.drp/). A door set to this format receives only `BBSDEV.DRP` — no `DOOR.SYS` — and the drop file's absolute path is passed in the `BBSDEV_DRP` environment variable (native doors) or a guest environment variable of the same name (DOS doors). This support is **experimental and has not been tested against a real door**; `DOOR.SYS` remains the default and is unaffected.

### CLI Script Fixes

- `scripts/admin_daemon.php`, `scripts/install.php`, `scripts/setup.php`, and `scripts/upgrade.php` now load `src/functions.php` alongside the Composer autoloader, so the global helper functions it defines (such as `getServerLogger()`) are always available to those entrypoints.

### CP437 Login ANSI Art

- The ANSI login screen (`ansi_prompt` display mode) now accepts `.ans` files saved in Code Page 437 by DOS / Synchronet tools. The high-byte box-drawing and block characters are converted to UTF-8 for display, and a trailing SAUCE / EOF record is stripped. Previously these bytes rendered as replacement characters, and the admin appearance editor could not load or save such art.

### Terminal Registration House Rules

- The terminal server's **Register new account** flow now shows the house rules in a paged box and requires the prospective user to type `YES` to accept them before any account details are collected. Declining aborts registration. Custom house rules from **Admin -> Appearance -> Content -> House Rules** are shown when set; otherwise the built-in default rule set is used. The browser registration page already linked to the same rules.

### Terminal Full-Screen Editor Flicker

- The terminal server's full-screen message editor (used automatically when the terminal has 15 or more rows) no longer erases and repaints the entire screen after every keystroke. Typing within a line now updates only that line, cursor movement emits only a cursor move, and structural edits repaint just the text area — borders and the footer stay put. This removes the constant blue-background blink that was visible while composing, especially on larger terminals or higher-latency connections. Terminals with ANSI colour disabled keep the previous full-redraw behaviour. Fixes issue #432.

### Community Mods List

- A new `docs/MODS.md` file is a curated list of third-party mods and extensions for BinktermPHP, linked from the Customization section of the README. It seeds with two mods by TheWebExpert: the Door Button Filter Mod (category filter bar on `/games`) and the Echo Area Button Mod (network-filter and quick-action bar on `/echolist`). Contributors add their own mods by pull request. Listed mods are maintained by their individual authors and have not necessarily been reviewed or tested by the BinktermPHP maintainer; review a mod's source before installing it.

### Docker Stale Apache PID Cleanup

- `docker/entrypoint.sh` now removes any stale `/var/run/apache2/apache2.pid` (and other `/var/run/apache2/*.pid`) files during container initialization, before starting the main process. This prevents a crash loop after an abrupt Docker host shutdown or a killed container leaves a stale Apache PID file behind on a persisted volume.

### Navbar Active Section Indicator

- The web navigation bar now marks the section for the page you are on: the matching top-level menu item is shown in bold with a short underline bar beneath it, in the navigation link colour of whatever theme is active. The section is worked out from the page URL, so a page with no menu entry of its own still highlights its parent — a message thread or the compose page marks **Messaging**, and a door launcher marks **Doors**.
- The **Files** menu's new-files cue is now shown on the file icon only. Previously an incoming file also turned the word "Files" yellow, which looked like the active-section highlight. The file icon and the Files link inside the dropdown still turn yellow; only the top-level text label no longer does.

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

## NNTP Server

An NNTP server lets members connect with any standard newsreader (Thunderbird, slrn, tin, Forte Agent) and read FTN echoareas as if they were newsgroups. Each echoarea a member is subscribed to appears as a newsgroup named `<Network>.<AreaTag>`, for example `FidoNet.GENERAL` or `LovlyNet.LVLY_BINKTERMPHP`. Members sign in with their normal BinktermPHP username and password.

### Enabling it

Turn the server on in **Admin -> NNTP Server** (*Enable the NNTP server*). The same page has the newsgroup-name prefix style, a per-IP connection limit, per-member posting rate limits, and a *plaintext authentication* switch. These are stored in a new `config/nntp.json`, written through the admin daemon. A disabled server answers connections with a `400` error and closes; you must restart the daemon after switching it on.

Set the transport in `.env` and restart the daemon for the change to take effect. New variables:

| Key | Default | Purpose |
|---|---|---|
| `NNTP_BIND_HOST` | `0.0.0.0` | Address to bind |
| `NNTP_PORT` | `8119` | Plaintext + `STARTTLS` port |
| `NNTP_TLS_PORT` | `8563` | Implicit-TLS port; leave empty to disable |
| `NNTP_TLS_CERT_PATH` | `data/nntp/server.crt` | PEM certificate, or a combined cert+key PEM |
| `NNTP_TLS_KEY_PATH` | `data/nntp/server.key` | PEM private key |

The ports default to the unprivileged `8119` and `8563` so the daemon runs as an ordinary user. Newsreaders expect NNTP on `119` and `563`; on a public server, redirect those to `8119` / `8563` with an `iptables` or `nftables` rule (see `docs/NNTP.md`), or set `NNTP_PORT` / `NNTP_TLS_PORT` to `119` / `563` and run the daemon with permission to bind them.

If `NNTP_TLS_CERT_PATH` is left at its default and no file exists there, the daemon generates a self-signed certificate on first start; point it at a real certificate (for example the one your web server uses) for clients that reject self-signed certs. A path that is set but points at a missing file stops the daemon with an error.

Start the daemon. It is an optional daemon, so `scripts/restart_daemons.sh` with no arguments only restarts it if it was already running:

```bash
scripts/restart_daemons.sh --start nntp_daemon
```

On Windows it is not part of `start_daemons_windows.*` and must be started by hand with `php scripts/nntp_server.php`. In Docker, set `ENABLE_NNTP: "true"` in `docker-compose.override.yml` and uncomment its port lines (`119:8119`, `563:8563`).

### Posting

Reading works as soon as the server is enabled. To also let members compose and reply from their newsreader, turn on *Allow posting from newsreaders* in **Admin -> NNTP Server**. Posted articles go through the same path as a web or terminal post, so the echoarea's posting-name policy and echomail moderation apply, and the message is attributed to the signed-in member regardless of the `From:` line the newsreader sends. A post whose `Newsgroups:` header names more echoareas than the configured cross-post limit is rejected rather than trimmed.

### Netmail newsgroup

Every signed-in member sees one more group — `netmail` by default — that works like a personal mail folder. Its articles are that member's own netmail: received mail, plus mail they sent unless you turn that off. Two members connected to the same server see completely different articles under the same group name, and a member can never read another member's netmail through it. New inbound netmail appears as new articles automatically.

With *Allow posting from newsreaders* on, a second switch, *Allow sending netmail from newsreaders*, controls whether posting into the group sends netmail. When it sends, the message goes through the same path as web and terminal netmail — origin-address selection, the destination network's posting-name policy, charset, credit costs and spooling all apply — with an attributed tearline (`--- BinktermPHP NNTP vX.Y.Z`). Replying to a netmail article needs no addressing; composing a fresh one needs an `X-FTN-To:` header or an address in the `To:` field (see `docs/NNTP.md`).

The **Admin -> NNTP Server** page adds: *Offer the netmail newsgroup* (on by default), the group name, *Allow sending netmail from newsreaders*, *Include sent netmail as articles*, and *Netmail per minute / hour* send limits (separate from the echomail posting limits).

### Article numbering

NNTP requires per-newsgroup article numbers that are never reused. The `nntp_article_numbers` and `nntp_area_watermark` tables track them for echoareas, and `nntp_netmail_article_numbers` / `nntp_netmail_watermark` track them per member for the netmail group. The daemon assigns numbers the first time a member opens the group. If echomail is pruned or a netmail is deleted, the numbers it held are retired, not reissued.

The `20260829200318_nntp_article_numbers` migration backfills a number for every existing approved echomail message in one large `INSERT ... SELECT`. On a big message base this takes a while — roughly 36 seconds for 100,000+ messages on Claude's. A pause of that length while `php scripts/setup.php` runs the migration is normal; let it finish rather than interrupting it.

See `docs/NNTP.md` for connecting a newsreader and troubleshooting.
## Docker WebSocket Proxy

The bundled Docker image now proxies the realtime WebSocket stream and the DOS door bridge through Apache. The image enables `mod_proxy`, `mod_proxy_http`, and `mod_proxy_wstunnel`, and ships a `docker/000-default.conf` virtual host that forwards `/ws` to the BinkStream server on `127.0.0.1:6010` and `/dosdoor` to the door bridge on `127.0.0.1:6001`.

Previously these WebSocket endpoints were not reachable from inside the container, which broke the realtime event bus and browser-side DOS door games for Docker deployments. Rebuilding the image from the updated `Dockerfile` picks up the change.

If you run your own reverse proxy in front of the container, make sure it also passes `/ws` and `/dosdoor` through as WebSocket upgrades to Apache on port 80.

## Door Player Backspace Handling

### Outbound: Backspace key to the door

On modern browsers — particularly on macOS and iOS — xterm.js emits ASCII `0x7f` (DEL) when the user presses the Backspace or Delete key. RLogin door servers, and the DOS doors they front (DOSEMU/DOSBox, MajorBBS, and similar), expect ASCII `0x08` (BS) instead, so the Backspace key did nothing inside an RLogin door.

The browser DOS door players (`public_html/webdoors/dosdoors/index.php` and `guest-door-player.php`) already translated `0x7f` to `0x08` in their `term.onData()` handler. That same translation is now applied in the RLogin door player (`public_html/webdoors/rlogindoors/index.php`), so Backspace works consistently across all browser-side door players. The RLogin handler now uses a global replace, so a DEL byte inside a pasted or multi-character chunk is remapped too, not only a lone keystroke.

### Inbound: DEL echo from the door

All three players (`rlogindoors/index.php`, `webdoors/dosdoors/index.php`, `guest-door-player.php`) now rewrite an inbound `0x7f` (DEL) in the stream from the door engine to a destructive backspace sequence (`\b \b`) before `term.write()`. Some door engines send a bare DEL to erase the previously typed character; without this, xterm.js drew it as a visible glyph and the erase never happened. Only string WebSocket frames are affected; binary frames pass through unchanged.

Clearing the browser/service-worker cache (or a hard reload) ensures clients pick up the updated scripts.

## BBSDEV.DRP Drop File (Experimental)

BinktermPHP has always written a `DOOR.SYS` drop file (and, for native doors, optionally `DOOR32.SYS`) into the per-node drop directory before launching a door game. `BBSDEV.DRP` is a third option, defined by an independent specification at <https://realdeuce.github.io/bbsdev.drp/>. It is a plain-text file of exactly 19 lines, one field per line, encoded as UTF-8 without a byte-order mark, that replaces the ambiguous numeric fields of the legacy formats with explicit values (screen size, IANA encoding name, BCP 47 language tag, and so on).

To use it, edit a door in **Admin -> Door Manifest Editor** (native or DOS) and set **Drop file format** to **BBSDEV.DRP**, then save. From then on that door receives only a `BBSDEV.DRP` file — `DOOR.SYS` is not written for it, the same way selecting `DOOR32.SYS` works today. The file is written and closed before the door starts and removed after it exits.

The door finds the file through the `BBSDEV_DRP` environment variable, which holds the file's absolute path. For native doors this is set directly in the door process environment (the older `DOOR_DROPFILE` variable is still set too). For DOS doors the DOSBox autoexec sets a guest `BBSDEV_DRP` variable and the `{dropfile}` launch-command macro resolves to `BBSDEV.DRP`.

Field values are taken from the session: the user's name and numeric ID, whether the account is an administrator (reported as access level `sysop`, otherwise `50`), the user's locale as a language tag, the door's configured terminal size (default 80x25), the encoding (`IBM437` when the door's output encoding is CP437, otherwise `UTF-8`), and the BBS name, sysop name, and BinktermPHP version. The full line-by-line mapping is in `docs/NativeDoors.md`.

This feature is **experimental**. It follows the specification as published but has not yet been exercised against a real door that consumes `BBSDEV.DRP`. Leave `dropfile_format` at its default (`DOOR.SYS`) unless you are specifically testing a BBSDEV.DRP-aware door, and report problems on the GitHub issue tracker.

## CLI Script Fixes

`scripts/admin_daemon.php`, `scripts/install.php`, `scripts/setup.php`, and `scripts/upgrade.php` now load `src/functions.php` in addition to the Composer autoloader.

The helper functions in `src/functions.php` (such as `getServerLogger()`) are plain global functions, not PSR-4 autoloaded classes, so they are only available where the file is explicitly required. These CLI entrypoints did not require it. Some code they can reach — the admin daemon's BBS Settings handling, and individual migration files — calls those helpers, so this closes a latent `Call to undefined function BinktermPHP\getServerLogger()` risk on those paths.

## CP437 Login ANSI Art

When the login screen display mode is set to **ANSI prompt**, the uploaded `.ans` file is shown to connecting terminal users. ANSI art produced by DOS and Synchronet tools (TheDraw, PabloDraw, and similar) is normally encoded in Code Page 437, using high-byte characters for the box-drawing and block glyphs (`░▒▓█`, `╔═╗`, `║`, `╚═╝`).

Those raw CP437 bytes are not valid UTF-8. When passed through template output they were rejected by `htmlspecialchars()` and every affected character was replaced with the Unicode replacement character, corrupting the art. Files that ended with a SAUCE metadata record (introduced by an `0x1A` EOF byte) also had that record passed straight through.

`AppearanceConfig::getLoginScreenAnsi()` now truncates the content at the `0x1A` delimiter to drop any EOF / SAUCE block, and converts non-UTF-8 content from CP437 to UTF-8 with `iconv()` (falling back to `mb_convert_encoding()`), matching how shell art is already handled elsewhere. `AdminDaemonServer::getAppearanceConfig()` performs the same conversion before returning the JSON payload, so the **Admin -> Appearance** editor can load, edit, and save CP437 ANSI art without encoding errors.

## Terminal Registration House Rules

When a user chooses **N — Register new account** at the terminal server pre-login menu, the registration flow now begins by displaying the house rules in a scrollable paged box. After reading them the user is prompted to type `YES` to continue; any other response (or `cancel`) aborts registration before a username, password, or any other detail is entered.

The rules text is resolved the same way the browser's House Rules modal resolves it: the Markdown from **Admin -> Appearance -> Content -> House Rules** is used when it has been set, and the built-in default rule set (civility, no spam, respect privacy, FidoNet etiquette, sysop decisions are final) is shown otherwise. Both sources are localized to the connecting user's language.

## Terminal Full-Screen Editor Flicker

The terminal server's full-screen message editor is the framed, blue-background editor shown when composing a message on a terminal with at least 15 rows. Previously it repainted the whole screen — clearing to the background colour, hiding the cursor, and redrawing the borders, every text row, and the footer — after every keypress. On larger terminals and on connections with any latency this produced a constant visible flicker while typing.

The editor now redraws incrementally:

- Typing a character, backspace, or delete that does not change the number of lines repaints only the row the cursor is on.
- Moving the cursor (arrow keys, Home/End) emits only a cursor-position update and paints nothing.
- Splitting or joining lines, deleting a line, or scrolling the view repaints just the text area; the borders and footer are left untouched.
- A full screen redraw still happens on entry, on terminal resize, on returning from the Ctrl+K help screen, and when the draft-save footer notice appears or clears.

Terminals connected with ANSI colour disabled continue to use the original full-redraw path unchanged.

## Community Mods List

`docs/MODS.md` is a curated list of third-party mods, extensions, and tweaks for BinktermPHP. The Customization section of the README links to it.

The list launches with two entries, both by TheWebExpert (The Adventure BBS, 227:1/22):

- **Door Button Filter Mod** — adds a category filter bar to the Doors page (`/games`) for filtering door games by type (RLOGIN, WEB, NATIVE, DOS, JS-DOS, ALL) with live badge counts and client-side filtering, defaulting to RLOGIN and supporting `/games#rlogin` hash deep-links.
- **Echo Area Button Mod** — adds a network-filter and quick-action bar to the Echo List page (`/echolist`) that auto-discovers connected FTN networks with live area counts and offers one-click subscribed-only, unread-only, new-post, and manage-subscriptions toggles.

Both use the `templates/custom/header.insert.twig` customization hook.

Contributors with a mod to share add a section to `docs/MODS.md` by pull request against the `claudesbbs` branch, following the existing entry format. Mods in the list are written and maintained by their individual authors and **have not necessarily been reviewed or tested by the BinktermPHP maintainer** — review a mod's source code before installing it on your system.

## Docker Stale Apache PID Cleanup

If a Docker host shuts down or restarts abruptly, or a container is stopped with `docker stop` past its timeout (or killed with `SIGKILL`), Apache can leave behind a stale `/var/run/apache2/apache2.pid` on the persisted volume. On the next container start, `apache2-foreground` sees the existing PID file and exits immediately, putting the container into a crash loop until the PID file (or volume) is removed by hand.

`docker/entrypoint.sh` now removes `/var/run/apache2/apache2.pid` and any other `/var/run/apache2/*.pid` files during initialization, before the main command starts. Rebuild the image to pick up the fix.

## Navbar Active Section Indicator

The top navigation bar of the web interface now shows which section you are viewing. The menu item that matches the current page is rendered in bold with a 3-pixel indicator bar along its lower edge. The bar takes its colour from the navigation link's active colour, so it adapts to every bundled theme — the Bootswatch themes (Slate, Cyborg, Darkly, Solar, and the rest) and the terminal-style themes (amber, dark, greenterm, cyberpunk) — with no per-theme configuration.

The active section is determined from the browser's current path matched against the navbar links, not from a fixed list, so pages that do not have their own menu entry still highlight the menu they belong to:

- Reading a message or composing one marks **Messaging**.
- The RLogin, DOS, JS-DOS, and web door launchers mark **Doors**.
- A link inside a dropdown menu also marks its parent top-level menu.

Separately, the **Files** menu's indicator for newly arrived files has been narrowed. It previously turned both the file icon and the top-level "Files" text label yellow. A yellow label was easily mistaken for the active-section highlight, implying you were on the Files page when you were not. The cue is now carried by the file icon alone — matching how the Messaging, Chat, and Mail menus already indicate unread items — along with the Files entry inside the dropdown. Whether new files are signalled is unchanged; only where the colour appears.

A hard reload, or clearing the browser and service-worker cache, ensures clients load the updated navbar script.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
