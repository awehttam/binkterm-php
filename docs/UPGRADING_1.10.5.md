# Upgrading to 1.10.5

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Web Interface](#web-interface)
  - [Message Composition](#message-composition)
  - [Draft Handling on Send](#draft-handling-on-send)
  - [Bulk Delete on the Drafts Tab](#bulk-delete-on-the-drafts-tab)
  - [Admin BBS Settings](#admin-bbs-settings)
  - [Navbar Active Section Indicator](#navbar-active-section-indicator)
  - [Themed Message Threading Colors](#themed-message-threading-colors)
- [Messaging / FTN](#messaging--ftn)
  - [NNTP Server](#nntp-server)
  - [AreaFix Reply Sync](#areafix-reply-sync)
  - [BinkP Schedule Status Panel](#binkp-schedule-status-panel)
- [Terminal Server](#terminal-server)
  - [Registration House Rules](#registration-house-rules)
  - [Full-Screen Editor Flicker](#full-screen-editor-flicker)
  - [CP437 Login ANSI Art](#cp437-login-ansi-art)
  - [Message Body Escape-Sequence Filtering](#message-body-escape-sequence-filtering)
- [Door Games](#door-games)
  - [Door Player Backspace Handling](#door-player-backspace-handling)
  - [BBSDEV.DRP Drop File (Experimental)](#bbsdevdrp-drop-file-experimental)
- [MeshCore / PacketBBS](#meshcore--packetbbs)
  - [Enable/Disable Toggle](#enabledisable-toggle)
- [Docker](#docker)
  - [WebSocket Proxy](#websocket-proxy)
  - [Stale Apache PID Cleanup](#stale-apache-pid-cleanup)
- [CLI Scripts](#cli-scripts)
  - [Helper Function Loading](#helper-function-loading)
- [Documentation](#documentation)
  - [Community Mods List](#community-mods-list)
- [Installation](#installation)
  - [Caddy Reverse Proxy Example](#caddy-reverse-proxy-example)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Web Interface

- **Message composition:** the compose editor's automatic line-wrap column now defaults to **72** instead of 79, and 72 is offered as the recommended choice in the compose Advanced Options. Wrapping at 72 leaves room for quote-attribution prefixes (for example ` AB> `) so that quoted reply lines stay within the 79-column width that FidoNet readers expect. The 79-column option is still available for users who prefer it, and anyone who has already chosen a wrap width keeps their setting.
- **Draft handling on send:** when a message is sent successfully from the web compose page, the draft it was composed from is now deleted automatically — including a draft that was only ever created by the 2-minute auto-save. Previously an auto-saved draft could be left behind after the message had already gone out, cluttering the drafts list.
- **Draft handling on send:** a failed send (validation error, server error, or a netmail attachment upload failure) no longer silently disables auto-save for the rest of the editing session; the auto-save timer is restored so continued edits keep being saved.
- **Bulk delete on the Drafts tab:** the netmail page's **Select** button now works on the **Drafts** tab. Previously the drafts list rendered no selection checkboxes at all, so multi-select and the **Delete Selected** action were unavailable there — you could only delete drafts one at a time. Selecting drafts and choosing Delete Selected now removes them together via a new `POST /api/messages/drafts/bulk-delete` endpoint (each delete is scoped to the signed-in user).
- **Admin BBS Settings:** the **Admin -> BBS Settings** page is now organized into four tabs: **System & Features**, **Credit System**, **Tag Lines**, and **Registration Screening**. All settings and their save buttons are unchanged; they are only regrouped so the page is shorter and easier to navigate.
- **Navbar active section indicator:** the web navigation bar now marks the section for the page you are on: the matching top-level menu item is shown in bold with a short underline bar beneath it, in the navigation link colour of whatever theme is active. The section is worked out from the page URL, so a page with no menu entry of its own still highlights its parent — a message thread or the compose page marks **Messaging**, and a door launcher marks **Doors**.
- **Navbar active section indicator:** the **Files** menu's new-files cue is now shown on the file icon only. Previously an incoming file also turned the word "Files" yellow, which looked like the active-section highlight. The file icon and the Files link inside the dropdown still turn yellow; only the top-level text label no longer does.
- **Themed message threading colors:** the threaded-view accent colors on the echomail and netmail pages — the coloured left border on a thread root, the "N replies" badge, the reply arrow icon, and the row hover tint — were hardcoded to Bootstrap blue (`#0d6efd`) and stayed blue on every theme. They now derive from the theme's `--fidonet-blue` variable (falling back to `#0d6efd`), so dark, amber, greenterm, cyberpunk, and custom themes tint the threading UI to match. The badge additionally honours optional `--thread-badge-bg` / `--thread-badge-color` overrides.

### Messaging / FTN

- **NNTP server:** BinktermPHP can now serve its echoareas as Usenet-style newsgroups over NNTP (RFC 3977), so members can read — and optionally post — echomail with a standard newsreader such as Thunderbird. It runs as a new optional daemon, `scripts/nntp_server.php`, and is **disabled by default**. Enable it, and configure rate limits and the plaintext-authentication policy, in the new **Admin -> NNTP Server** page. Posting from a newsreader is a second toggle on that page, also off by default.
- **NNTP server:** each member also gets a private **netmail** newsgroup whose articles are that member's own netmail; posting into it sends netmail. It is enabled by default when the NNTP server is on, with its own settings on the **Admin -> NNTP Server** page (group name, whether sending is allowed, whether sent mail is included, and a separate send rate limit).
- **NNTP server:** new database tables (`nntp_article_numbers`, `nntp_area_watermark`) are created and populated from your existing echomail during the upgrade, plus `nntp_netmail_article_numbers` and `nntp_netmail_watermark` (per member, filled on first read) and a `tearline_component` column on `netmail`.
- **NNTP server:** transport settings — bind address, ports, and TLS certificate paths — are read from `.env`. New keys: `NNTP_BIND_HOST`, `NNTP_PORT` (default `8119`), `NNTP_TLS_PORT` (default `8563`), `NNTP_TLS_CERT_PATH`, `NNTP_TLS_KEY_PATH`. The ports default to an unprivileged range; redirect the standard `119` / `563` to them with a firewall rule.
- **AreaFix reply sync:** AreaFix and FileFix replies from an uplink are now recognised in the web UI even when the uplink adds or drops the `.0` point on its netmail address, so the conversation threads under **Admin → AreaFix** no longer break apart when a hub answers from a 3D address one way and a 4D address the next.
- **AreaFix reply sync:** the area-list parser now ignores command receipts, execution logs, help text, and rescan confirmations instead of treating their contents as echo tags. Previously an uplink's "request processed" acknowledgement or quoted-header block could create bogus, inactive echo areas in the database.
- **AreaFix reply sync:** inbound AreaFix / FileFix area-list replies received over an **authenticated (secure) BinkP session** are now synced into the local `echoareas` / `file_areas` tables automatically as packets are processed. Replies arriving over an insecure session, or whose packet origin does not match the configured uplink address, are ignored.
- **AreaFix reply sync:** **Admin → AreaFix** gains a **Sync Areas to Local BBS** button on the latest-reply preview, backed by a new `POST /api/admin/areafix/sync-latest` endpoint, for manually syncing the most recent area list on demand.
- **AreaFix reply sync:** box-art / decorative-line detection in the parser no longer discards area rows whose description contains accented UTF-8 characters.
- **BinkP schedule status panel:** the BinkP status view in the admin **System Information** area no longer breaks when an uplink's poll schedule has extra or irregular whitespace between its cron fields. Such a schedule previously showed **next poll: Unknown**, and a badly-formed field (for example a stray `/` or an empty step) could make `GET /api/binkp/status` return HTTP 500 so the whole panel failed to load. The schedule string is now split the same way the rest of the scheduler splits it, so leading, trailing, and repeated whitespace are tolerated.

### Terminal Server

- **Registration house rules:** the terminal server's **Register new account** flow now shows the house rules in a paged box and requires the prospective user to type `YES` to accept them before any account details are collected. Declining aborts registration. Custom house rules from **Admin -> Appearance -> Content -> House Rules** are shown when set; otherwise the built-in default rule set is used. The browser registration page already linked to the same rules.
- **Full-screen editor flicker:** the terminal server's full-screen message editor (used automatically when the terminal has 15 or more rows) no longer erases and repaints the entire screen after every keystroke. Typing within a line now updates only that line, cursor movement emits only a cursor move, and structural edits repaint just the text area — borders and the footer stay put. This removes the constant blue-background blink that was visible while composing, especially on larger terminals or higher-latency connections. Terminals with ANSI colour disabled keep the previous full-redraw behaviour. Fixes issue #432.
- **CP437 login ANSI art:** the ANSI login screen (`ansi_prompt` display mode) now accepts `.ans` files saved in Code Page 437 by DOS / Synchronet tools. The high-byte box-drawing and block characters are converted to UTF-8 for display, and a trailing SAUCE / EOF record is stripped. Previously these bytes rendered as replacement characters, and the admin appearance editor could not load or save such art.
- **Message body escape-sequence filtering (security fix, GHSA-4225-c933-76f3):** echomail and netmail bodies, kludge lines, subjects, and author names are now stripped of terminal control sequences before being shown to a Telnet or SSH reader. Previously a message containing raw ANSI/VT escape codes could move the reader's cursor, repaint or erase their screen, spoof displayed content, and — on terminal emulators that honour them — set the window title, write the clipboard, or inject input via an answerback query. Because echomail is FidoNet-federated, such a message could originate from any user on any connected node. ANSI colour (SGR) codes are preserved; cursor positioning, screen clears, and OSC/DCS sequences are removed, so genuine ANSI-art messages keep their colours but lose absolute cursor placement when read on a terminal.

### Door Games

- **Door player backspace handling:** the browser-based RLogin door player (`public_html/webdoors/rlogindoors/index.php`) now remaps the DEL byte (`0x7f`) that modern browsers send for the Backspace/Delete key to the Backspace byte (`0x08`) that RLogin door servers expect. Previously the Backspace key was ignored in RLogin doors (for example DOS doors run through DOSEMU/DOSBox, or MajorBBS). The equivalent DOS door players already had this remap; this brings the RLogin player in line.
- **Door player backspace handling:** all three browser door players (`rlogindoors/index.php`, `webdoors/dosdoors/index.php`, `guest-door-player.php`) now also translate an inbound `0x7f` (DEL) coming *from* the door engine into a destructive backspace sequence (`\b \b`) before writing it to the terminal. Some door engines emit a bare DEL to erase the last character; xterm.js would otherwise render it as a visible glyph instead of erasing. Binary WebSocket frames are left untouched.
- **BBSDEV.DRP drop file (experimental):** door manifests can now select `BBSDEV.DRP` as their `dropfile_format`, for both native doors and DOS doors, in the **Admin -> Door Manifest Editor**. BBSDEV.DRP is a modern 19-line drop file (UTF-8, one value per line) defined by [its own specification](https://realdeuce.github.io/bbsdev.drp/). A door set to this format receives only `BBSDEV.DRP` — no `DOOR.SYS` — and the drop file's absolute path is passed in the `BBSDEV_DRP` environment variable (native doors) or a guest environment variable of the same name (DOS doors). This support is **experimental and has not been tested against a real door**; `DOOR.SYS` remains the default and is unaffected.

### MeshCore / PacketBBS

- **Enable/disable toggle:** a new **Enable MeshCore** toggle in **Admin -> BBS Settings -> System & Features** turns the whole MeshCore / PacketBBS radio subsystem on or off. It defaults to **on**, so existing MeshCore setups keep working. Turning it off makes the bridge API unreachable and hides every MeshCore surface (user settings tab, public nodes page, admin page, dashboard card, navigation links).

### Docker

- **WebSocket proxy:** the bundled Docker image now proxies the realtime WebSocket stream (`/ws`) and the DOS door bridge (`/dosdoor`) through Apache, so the event bus and browser-side DOS door games work in container deployments. Rebuild the image to pick this up.
- **Stale Apache PID cleanup:** `docker/entrypoint.sh` now removes any stale `/var/run/apache2/apache2.pid` (and other `/var/run/apache2/*.pid`) files during container initialization, before starting the main process. This prevents a crash loop after an abrupt Docker host shutdown or a killed container leaves a stale Apache PID file behind on a persisted volume.

### CLI Scripts

- **Helper function loading:** `scripts/admin_daemon.php`, `scripts/install.php`, `scripts/setup.php`, and `scripts/upgrade.php` now load `src/functions.php` alongside the Composer autoloader, so the global helper functions it defines (such as `getServerLogger()`) are always available to those entrypoints.

### Documentation

- **Community mods list:** a new `docs/MODS.md` file is a curated list of third-party mods and extensions for BinktermPHP, linked from the Customization section of the README. It seeds with two mods by TheWebExpert: the Door Button Filter Mod (category filter bar on `/games`) and the Echo Area Button Mod (network-filter and quick-action bar on `/echolist`). Contributors add their own mods by pull request. Listed mods are maintained by their individual authors and have not necessarily been reviewed or tested by the BinktermPHP maintainer; review a mod's source before installing it.

### Installation

- **Caddy reverse proxy example:** the Caddy site block in `docs/INSTALL.md` now wraps the `/ws` (realtime WebSocket) and `/dosdoor` (DOS door bridge) proxies in their own `handle` blocks. In the previous example these were bare `reverse_proxy` directives; mixed in with the `handle` blocks used for the rest of the site they were shadowed by the catch-all handler, so WebSocket requests fell through to PHP and stalled while holding the per-user session lock, making every following page load hang for several seconds. If you copied the old block, update your `Caddyfile` to match.

---

## Web Interface

### Message Composition

When composing netmail or echomail, the editor can hard-wrap long lines automatically as you type. The wrap column is a per-user preference in the compose form's **Advanced Options**.

Previously the default was 79 columns. When replying to a message, each quoted line is prefixed with an attribution string such as ` AB> `, which pushed quoted lines past 79 columns and caused readers to wrap them a second time. The default is now 72 columns, which keeps quoted lines within 79 after the prefix is added. A new **72 characters (recommended)** option appears in the wrap selector; the **79** option remains for users who want it. Existing saved preferences are unchanged.

### Draft Handling on Send

The web compose page auto-saves an in-progress netmail or echomail message as a draft every two minutes, and you can also save a draft by hand. Sending the message now cleans that draft up:

- On a successful send, the compose page tells the server which draft the message came from, and the server deletes it. This covers a draft you opened and finished, a draft you saved manually, and a draft that only exists because the auto-save timer fired while you were writing. If the compose page cannot identify a specific draft, the server removes the most recent draft that matches the message's area (echomail) or recipient (netmail) and subject. Previously an auto-saved draft was frequently left behind after its message had already been sent.
- The compose page now waits for an in-flight auto-save to finish before sending, instead of cancelling it. Cancelling it client-side did not stop the save from completing on the server, which is how the leftover drafts were being created.
- If a send fails — a validation error, a server error, or (for netmail) an attachment that fails to upload — the two-minute auto-save timer is now restarted when the form is re-enabled. Before, a failed send left auto-save switched off for the rest of the session, so later edits were not being saved until the page was reloaded.

### Bulk Delete on the Drafts Tab

The netmail page (`/netmail`) has a **Select** button that turns on a column of checkboxes so you can act on several messages at once. It worked on the All, Unread, Sent, and Saved tabs, but the **Drafts** tab rendered its own table with no checkboxes, so turning on Select did nothing there and drafts could only be removed one at a time with the per-row trash button.

The Drafts tab now renders the same selection checkboxes and a select-all control. With one or more drafts selected, **Delete Selected** removes them in a single step, after a confirmation prompt. The list then reloads.

This is backed by a new endpoint, `POST /api/messages/drafts/bulk-delete`, which takes `{ "message_ids": [ ... ] }` and deletes only drafts owned by the requesting user; IDs that belong to someone else or no longer exist are skipped. See `docs/API.md` for the full contract.

### Admin BBS Settings

The **Admin -> BBS Settings** page previously presented every section as a long vertical stack of cards. It is now split into four tabs:

- **System & Features** - system identity, terminal server / idle timeouts, Packet BBS, and the BBS feature toggles
- **Credit System** - the full Credit System Configuration panel
- **Tag Lines** - the tagline list editor
- **Registration Screening** - new-user IP screening and its signal weights

Each tab keeps its own **Save** button and saves independently, exactly as before.

### Navbar Active Section Indicator

The top navigation bar of the web interface now shows which section you are viewing. The menu item that matches the current page is rendered in bold with a 3-pixel indicator bar along its lower edge. The bar takes its colour from the navigation link's active colour, so it adapts to every bundled theme — the Bootswatch themes (Slate, Cyborg, Darkly, Solar, and the rest) and the terminal-style themes (amber, dark, greenterm, cyberpunk) — with no per-theme configuration.

The active section is determined from the browser's current path matched against the navbar links, not from a fixed list, so pages that do not have their own menu entry still highlight the menu they belong to:

- Reading a message or composing one marks **Messaging**.
- The RLogin, DOS, JS-DOS, and web door launchers mark **Doors**.
- A link inside a dropdown menu also marks its parent top-level menu.

Separately, the **Files** menu's indicator for newly arrived files has been narrowed. It previously turned both the file icon and the top-level "Files" text label yellow. A yellow label was easily mistaken for the active-section highlight, implying you were on the Files page when you were not. The cue is now carried by the file icon alone — matching how the Messaging, Chat, and Mail menus already indicate unread items — along with the Files entry inside the dropdown. Whether new files are signalled is unchanged; only where the colour appears.

A hard reload, or clearing the browser and service-worker cache, ensures clients load the updated navbar script.

### Themed Message Threading Colors

The threaded message view on `/echomail` and `/netmail` draws several accent elements: a coloured left border and background wash on the root message of a thread, a small "replies" badge, a reply-arrow icon on each reply, and a slightly stronger background on row hover. These were written with literal Bootstrap-blue values (`#0d6efd` and `rgba(13, 110, 253, …)`), so they rendered blue regardless of the active theme, clashing with the terminal-style themes and ignoring custom theme palettes.

`templates/echomail.twig` and `templates/netmail.twig` now express those colours as `var(--fidonet-blue, #0d6efd)`, and the translucent border/hover washes use `color-mix(in srgb, var(--fidonet-blue, #0d6efd) N%, transparent)`. Any theme that defines `--fidonet-blue` (the bundled amber, dark, greenterm, and cyberpunk themes, and any custom theme) now colours the threading UI to match, and themes that do not define it fall back to the original blue.

The replies badge also reads two optional variables, `--thread-badge-bg` and `--thread-badge-color`, before falling back to `--fidonet-blue` and white, so a theme can style that badge independently if needed.

A hard reload, or clearing the browser and service-worker cache, ensures clients pick up the updated templates.

## Messaging / FTN

### NNTP Server

An NNTP server lets members connect with any standard newsreader (Thunderbird, slrn, tin, Forte Agent) and read FTN echoareas as if they were newsgroups. Each echoarea a member is subscribed to appears as a newsgroup named `<Network>.<AreaTag>`, for example `FidoNet.GENERAL` or `LovlyNet.LVLY_BINKTERMPHP`. Members sign in with their normal BinktermPHP username and password.

#### Enabling it

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

#### Posting

Reading works as soon as the server is enabled. To also let members compose and reply from their newsreader, turn on *Allow posting from newsreaders* in **Admin -> NNTP Server**. Posted articles go through the same path as a web or terminal post, so the echoarea's posting-name policy and echomail moderation apply, and the message is attributed to the signed-in member regardless of the `From:` line the newsreader sends. A post whose `Newsgroups:` header names more echoareas than the configured cross-post limit is rejected rather than trimmed.

#### Netmail newsgroup

Every signed-in member sees one more group — `netmail` by default — that works like a personal mail folder. Its articles are that member's own netmail: received mail, plus mail they sent unless you turn that off. Two members connected to the same server see completely different articles under the same group name, and a member can never read another member's netmail through it. New inbound netmail appears as new articles automatically.

With *Allow posting from newsreaders* on, a second switch, *Allow sending netmail from newsreaders*, controls whether posting into the group sends netmail. When it sends, the message goes through the same path as web and terminal netmail — origin-address selection, the destination network's posting-name policy, charset, credit costs and spooling all apply — with an attributed tearline (`--- BinktermPHP NNTP vX.Y.Z`). Replying to a netmail article needs no addressing; composing a fresh one needs an `X-FTN-To:` header or an address in the `To:` field (see `docs/NNTP.md`).

The **Admin -> NNTP Server** page adds: *Offer the netmail newsgroup* (on by default), the group name, *Allow sending netmail from newsreaders*, *Include sent netmail as articles*, and *Netmail per minute / hour* send limits (separate from the echomail posting limits).

#### Article numbering

NNTP requires per-newsgroup article numbers that are never reused. The `nntp_article_numbers` and `nntp_area_watermark` tables track them for echoareas, and `nntp_netmail_article_numbers` / `nntp_netmail_watermark` track them per member for the netmail group. The daemon assigns numbers the first time a member opens the group. If echomail is pruned or a netmail is deleted, the numbers it held are retired, not reissued.

The `20260829200318_nntp_article_numbers` migration backfills a number for every existing approved echomail message in one large `INSERT ... SELECT`. On a big message base this takes a while — roughly 36 seconds for 100,000+ messages on Claude's. A pause of that length while `php scripts/setup.php` runs the migration is normal; let it finish rather than interrupting it.

See `docs/NNTP.md` for connecting a newsreader and troubleshooting.

### AreaFix Reply Sync

BinktermPHP tracks AreaFix and FileFix conversations with each uplink and shows them under **Admin → AreaFix**. Several problems in how those replies were matched and parsed have been fixed.

#### 3D / 4D address matching

FidoNet nodes can be written as a 3D address (`999:100/10`) or a 4D address that includes an explicit point of zero (`999:100/10.0`); the two refer to the same node. Some hubs reply to an AreaFix request from one form and to the next from the other. Previously the AreaFix history query matched the uplink address exactly, so a hub that switched forms appeared as two unrelated conversations and the outgoing request could not be paired with its reply. The query now matches both the 3D and 4D form of your own address and of each uplink, so the thread stays intact regardless of which form the hub uses.

#### Receipt and help-text guarding

An AreaFix area list and an AreaFix acknowledgement are both plain netmail from the same robot. The parser used to scan any such message for lines that looked like `TAG  Description` and, on a "your request has been processed" receipt or a block of quoted headers, would occasionally register those lines as new echo areas — created inactive, with an `Auto-created:` description, cluttering the echo area list.

The parser now rejects a message as an area list when its subject indicates a result, help, or node-change response (unless it also says "list" or "query"), or when its body contains receipt markers such as `<-- COMMAND PROCESSED`, `[ BEGIN MESSAGE ]`, a commands-help listing, quoted original message text, or a rescan confirmation. Decorative box-drawing lines are also skipped; that check no longer discards a genuine area row that happens to contain an accented character such as `é` or `ü`.

If earlier releases created spurious areas on your system, they will be inactive echo areas with an `Auto-created:` description and no messages — safe to delete from **Admin → Echo Areas**.

#### Automatic ingestion of inbound replies

When an AreaFix or FileFix reply arrives in an inbound packet over an **authenticated BinkP session**, BinktermPHP now parses the area list and synchronises it into the local `echoareas` (or `file_areas`) table as the packet is processed: areas in the list that do not exist locally are created active, and existing areas that were inactive are re-activated. This previously required copying the reply text into the admin tool by hand.

For safety this runs only when the session was password-authenticated as the sending uplink and the packet's origin address matches the configured uplink. A reply received over an insecure session is not auto-imported; use the manual sync button below after confirming the reply is genuine.

Automatic ingestion never deactivates or deletes local areas — it only adds and re-activates.

#### Manual sync button

**Admin → AreaFix** now has a **Sync Areas to Local BBS** button on the preview of the latest reply from an uplink. It calls the new endpoint:

```
POST /api/admin/areafix/sync-latest
{ "uplink": "999:100/10", "robot": "areafix" }
```

The endpoint finds the most recent incoming area-list reply from that uplink, parses it, and performs the same create/re-activate synchronisation described above, returning a summary of how many areas were created and re-activated. It is documented in `docs/API.md`.

#### Client cache

This change bumps the service-worker cache version. Users should hard-reload the admin interface, or clear the browser and service-worker cache, so the updated AreaFix page and its new button load.

### BinkP Schedule Status Panel

The admin **System Information** area shows a BinkP status panel, fed by `GET /api/binkp/status`, that lists each configured uplink with its poll schedule, last poll time, and computed next poll time. The next poll time is worked out by reading the uplink's `poll_schedule` — a five-field cron expression such as `0 */4 * * *` — and finding the next minute that matches.

The routine that computes the next poll time split the schedule on single spaces only. A schedule that is functionally correct but formatted with a tab, a double space, or a leading or trailing space between fields therefore produced the wrong number of parts and the next poll time could not be computed. On the panel this appeared as **next poll: Unknown**, even though the same schedule polled correctly, because every other part of the scheduler already tolerates that whitespace.

A separate consequence: a schedule field that is malformed rather than just oddly spaced — for example a bare `/`, or a step of zero like `*/0` — could raise a PHP error while the panel was being built. That error was not caught by the status route, so `GET /api/binkp/status` returned HTTP 500 and the whole panel failed to render.

The schedule string is now tokenised with the same whitespace-normalising split the rest of the scheduler uses, so irregular spacing between fields is accepted and the next poll time is computed for any schedule that the scheduler itself accepts. A schedule that was showing **Unknown**, or a panel that was failing to load with a 500, should display correctly after upgrading without any change to the schedule itself.

## Terminal Server

### Registration House Rules

When a user chooses **N — Register new account** at the terminal server pre-login menu, the registration flow now begins by displaying the house rules in a scrollable paged box. After reading them the user is prompted to type `YES` to continue; any other response (or `cancel`) aborts registration before a username, password, or any other detail is entered.

The rules text is resolved the same way the browser's House Rules modal resolves it: the Markdown from **Admin -> Appearance -> Content -> House Rules** is used when it has been set, and the built-in default rule set (civility, no spam, respect privacy, FidoNet etiquette, sysop decisions are final) is shown otherwise. Both sources are localized to the connecting user's language.

### Full-Screen Editor Flicker

The terminal server's full-screen message editor is the framed, blue-background editor shown when composing a message on a terminal with at least 15 rows. Previously it repainted the whole screen — clearing to the background colour, hiding the cursor, and redrawing the borders, every text row, and the footer — after every keypress. On larger terminals and on connections with any latency this produced a constant visible flicker while typing.

The editor now redraws incrementally:

- Typing a character, backspace, or delete that does not change the number of lines repaints only the row the cursor is on.
- Moving the cursor (arrow keys, Home/End) emits only a cursor-position update and paints nothing.
- Splitting or joining lines, deleting a line, or scrolling the view repaints just the text area; the borders and footer are left untouched.
- A full screen redraw still happens on entry, on terminal resize, on returning from the Ctrl+K help screen, and when the draft-save footer notice appears or clears.

Terminals connected with ANSI colour disabled continue to use the original full-redraw path unchanged.

### CP437 Login ANSI Art

When the login screen display mode is set to **ANSI prompt**, the uploaded `.ans` file is shown to connecting terminal users. ANSI art produced by DOS and Synchronet tools (TheDraw, PabloDraw, and similar) is normally encoded in Code Page 437, using high-byte characters for the box-drawing and block glyphs (`░▒▓█`, `╔═╗`, `║`, `╚═╝`).

Those raw CP437 bytes are not valid UTF-8. When passed through template output they were rejected by `htmlspecialchars()` and every affected character was replaced with the Unicode replacement character, corrupting the art. Files that ended with a SAUCE metadata record (introduced by an `0x1A` EOF byte) also had that record passed straight through.

`AppearanceConfig::getLoginScreenAnsi()` now truncates the content at the `0x1A` delimiter to drop any EOF / SAUCE block, and converts non-UTF-8 content from CP437 to UTF-8 with `iconv()` (falling back to `mb_convert_encoding()`), matching how shell art is already handled elsewhere. `AdminDaemonServer::getAppearanceConfig()` performs the same conversion before returning the JSON payload, so the **Admin -> Appearance** editor can load, edit, and save CP437 ANSI art without encoding errors.

### Message Body Escape-Sequence Filtering

This release fixes a stored terminal-escape-injection vulnerability, tracked as **GHSA-4225-c933-76f3** (severity: medium). It affects the Telnet and SSH terminal server; the browser interface was never exposed, because HTML output escapes these bytes.

#### The problem

A FidoNet message body is stored and later displayed to a terminal reader with very little transformation. When the reader opened an echomail or netmail message over Telnet or SSH, the terminal server passed the body through a word-wrapper (or the Markdown/StyleCodes renderer) and then a character-set conversion, and wrote the result straight to the socket. None of those steps removed ANSI/VT control sequences that were already in the body.

An ANSI/VT terminal interprets escape sequences in the byte stream as commands. A message body could therefore contain sequences that:

- move the cursor, scroll the screen, or clear regions of it, to garble or hide other content;
- redraw parts of the screen to impersonate a system prompt or another user's message (display spoofing);
- on emulators that honour them, set the terminal window title, write to the system clipboard (OSC 52), or issue a device-status / answerback query whose reply is injected back into the session as if the user had typed it.

Any account that can post a message could target any reader. Because echomail is federated across FidoNet, a crafted body could also arrive from a user on any connected uplink — the attacker did not need an account on your board. The subject line and author name shown in the message header and message list were exposed the same way. This is terminal manipulation on the reader's client, not code execution on the server.

#### The fix

A new filter, `BinktermPHP\TerminalTextSanitizer`, is applied to untrusted text on every terminal read path — the echomail and netmail message viewers (body and kludge lines), quoted and forwarded text placed in the composer, and the message-list rows and header fields. The same filter replaces the narrower escape strip that was already present on the MeshCore / PacketBBS radio renderer.

The filter keeps SGR (colour and text-style) sequences — `ESC [ … m` — and the TAB, CR, and LF whitespace controls. Everything else is removed: cursor movement, erase and scroll commands, mode changes, OSC and DCS strings, character-set designation, other escape sequences, and stray C0/C1 control bytes.

The visible effect for readers is that message colours are unchanged, but a message that relied on cursor positioning to draw ANSI art (as opposed to plain coloured text) will show that art without the positioning when read on a terminal. This path never rendered positioned art correctly in any case.

#### If you run a public terminal server

Upgrade promptly; there is no workaround short of disabling terminal access to messages. Filtering happens at display time, so it also covers messages that are already stored.

## Door Games

### Door Player Backspace Handling

#### Outbound: Backspace key to the door

On modern browsers — particularly on macOS and iOS — xterm.js emits ASCII `0x7f` (DEL) when the user presses the Backspace or Delete key. RLogin door servers, and the DOS doors they front (DOSEMU/DOSBox, MajorBBS, and similar), expect ASCII `0x08` (BS) instead, so the Backspace key did nothing inside an RLogin door.

The browser DOS door players (`public_html/webdoors/dosdoors/index.php` and `guest-door-player.php`) already translated `0x7f` to `0x08` in their `term.onData()` handler. That same translation is now applied in the RLogin door player (`public_html/webdoors/rlogindoors/index.php`), so Backspace works consistently across all browser-side door players. The RLogin handler now uses a global replace, so a DEL byte inside a pasted or multi-character chunk is remapped too, not only a lone keystroke.

#### Inbound: DEL echo from the door

All three players (`rlogindoors/index.php`, `webdoors/dosdoors/index.php`, `guest-door-player.php`) now rewrite an inbound `0x7f` (DEL) in the stream from the door engine to a destructive backspace sequence (`\b \b`) before `term.write()`. Some door engines send a bare DEL to erase the previously typed character; without this, xterm.js drew it as a visible glyph and the erase never happened. Only string WebSocket frames are affected; binary frames pass through unchanged.

Clearing the browser/service-worker cache (or a hard reload) ensures clients pick up the updated scripts.

### BBSDEV.DRP Drop File (Experimental)

BinktermPHP has always written a `DOOR.SYS` drop file (and, for native doors, optionally `DOOR32.SYS`) into the per-node drop directory before launching a door game. `BBSDEV.DRP` is a third option, defined by an independent specification at <https://realdeuce.github.io/bbsdev.drp/>. It is a plain-text file of exactly 19 lines, one field per line, encoded as UTF-8 without a byte-order mark, that replaces the ambiguous numeric fields of the legacy formats with explicit values (screen size, IANA encoding name, BCP 47 language tag, and so on).

To use it, edit a door in **Admin -> Door Manifest Editor** (native or DOS) and set **Drop file format** to **BBSDEV.DRP**, then save. From then on that door receives only a `BBSDEV.DRP` file — `DOOR.SYS` is not written for it, the same way selecting `DOOR32.SYS` works today. The file is written and closed before the door starts and removed after it exits.

The door finds the file through the `BBSDEV_DRP` environment variable, which holds the file's absolute path. For native doors this is set directly in the door process environment (the older `DOOR_DROPFILE` variable is still set too). For DOS doors the DOSBox autoexec sets a guest `BBSDEV_DRP` variable and the `{dropfile}` launch-command macro resolves to `BBSDEV.DRP`.

Field values are taken from the session: the user's name and numeric ID, whether the account is an administrator (reported as access level `sysop`, otherwise `50`), the user's locale as a language tag, the door's configured terminal size (default 80x25), the encoding (`IBM437` when the door's output encoding is CP437, otherwise `UTF-8`), and the BBS name, sysop name, and BinktermPHP version. The full line-by-line mapping is in `docs/NativeDoors.md`.

This feature is **experimental**. It follows the specification as published but has not yet been exercised against a real door that consumes `BBSDEV.DRP`. Leave `dropfile_format` at its default (`DOOR.SYS`) unless you are specifically testing a BBSDEV.DRP-aware door, and report problems on the GitHub issue tracker.

## MeshCore / PacketBBS

### Enable/Disable Toggle

MeshCore (also referred to as PacketBBS — the mesh-radio / low-bandwidth access path) can now be switched off entirely from **Admin -> BBS Settings -> System & Features** with the **Enable MeshCore** checkbox. This corresponds to a new `features.meshcore` key in `config/bbs.json`.

The toggle defaults to **enabled**, so a system that is already using MeshCore sees no change after upgrading.

When it is disabled:

- All bridge endpoints (`/api/packetbbs/*` and `/api/meshcore/*`) return `404`, so no radio bridge can authenticate or relay commands.
- The **MeshCore** tab in each user's **Settings** page is hidden.
- The public **Meshcore Nodes** page (`/packetbbs-nodes`), the "Meshcore Nodes" navigation links, and the dashboard "Packet BBS Status" card are hidden.
- The **Admin -> Packet BBS** management page returns `404` and its navigation link is hidden.

Node records, radio sessions, and stored contacts are not deleted while MeshCore is disabled; re-enabling the toggle restores everything.

## Docker

### WebSocket Proxy

The bundled Docker image now proxies the realtime WebSocket stream and the DOS door bridge through Apache. The image enables `mod_proxy`, `mod_proxy_http`, and `mod_proxy_wstunnel`, and ships a `docker/000-default.conf` virtual host that forwards `/ws` to the BinkStream server on `127.0.0.1:6010` and `/dosdoor` to the door bridge on `127.0.0.1:6001`.

Previously these WebSocket endpoints were not reachable from inside the container, which broke the realtime event bus and browser-side DOS door games for Docker deployments. Rebuilding the image from the updated `Dockerfile` picks up the change.

If you run your own reverse proxy in front of the container, make sure it also passes `/ws` and `/dosdoor` through as WebSocket upgrades to Apache on port 80.

### Stale Apache PID Cleanup

If a Docker host shuts down or restarts abruptly, or a container is stopped with `docker stop` past its timeout (or killed with `SIGKILL`), Apache can leave behind a stale `/var/run/apache2/apache2.pid` on the persisted volume. On the next container start, `apache2-foreground` sees the existing PID file and exits immediately, putting the container into a crash loop until the PID file (or volume) is removed by hand.

`docker/entrypoint.sh` now removes `/var/run/apache2/apache2.pid` and any other `/var/run/apache2/*.pid` files during initialization, before the main command starts. Rebuild the image to pick up the fix.

## CLI Scripts

### Helper Function Loading

`scripts/admin_daemon.php`, `scripts/install.php`, `scripts/setup.php`, and `scripts/upgrade.php` now load `src/functions.php` in addition to the Composer autoloader.

The helper functions in `src/functions.php` (such as `getServerLogger()`) are plain global functions, not PSR-4 autoloaded classes, so they are only available where the file is explicitly required. These CLI entrypoints did not require it. Some code they can reach — the admin daemon's BBS Settings handling, and individual migration files — calls those helpers, so this closes a latent `Call to undefined function BinktermPHP\getServerLogger()` risk on those paths.

## Documentation

### Community Mods List

`docs/MODS.md` is a curated list of third-party mods, extensions, and tweaks for BinktermPHP. The Customization section of the README links to it.

The list launches with two entries, both by TheWebExpert (The Adventure BBS, 227:1/22):

- **Door Button Filter Mod** — adds a category filter bar to the Doors page (`/games`) for filtering door games by type (RLOGIN, WEB, NATIVE, DOS, JS-DOS, ALL) with live badge counts and client-side filtering, defaulting to RLOGIN and supporting `/games#rlogin` hash deep-links.
- **Echo Area Button Mod** — adds a network-filter and quick-action bar to the Echo List page (`/echolist`) that auto-discovers connected FTN networks with live area counts and offers one-click subscribed-only, unread-only, new-post, and manage-subscriptions toggles.

Both use the `templates/custom/header.insert.twig` customization hook.

Contributors with a mod to share add a section to `docs/MODS.md` by pull request against the `claudesbbs` branch, following the existing entry format. Mods in the list are written and maintained by their individual authors and **have not necessarily been reviewed or tested by the BinktermPHP maintainer** — review a mod's source code before installing it on your system.

## Installation

### Caddy Reverse Proxy Example

The bare-metal install guide, `docs/INSTALL.md`, includes an example Caddy site block. That block uses `handle` blocks to route requests, and in the previous version the two supporting proxies were written as plain directives outside any `handle` block:

```caddyfile
reverse_proxy /ws 127.0.0.1:6010 { ... }
reverse_proxy /dosdoor 127.0.0.1:6001 { ... }
```

In Caddy, a bare `reverse_proxy` with a path matcher and a `handle` block are different directive types, and when both appear in one site the `handle` blocks take over routing. The bare `/ws` and `/dosdoor` proxies were shadowed by the catch-all `handle` that forwards everything else to PHP. As a result:

- The realtime WebSocket connection (`/ws`, served by `scripts/realtime_server.php`) was sent into PHP instead of the WebSocket daemon. The request never completed, and it held the per-user PHP session lock while it hung, so every other request for that user — normal page loads — blocked for several seconds behind it.
- The DOS door bridge (`/dosdoor`, served by the multiplexing server) was likewise routed to PHP and did not work.

The example now wraps both proxies in their own exact-match `handle` blocks so they are matched and short-circuited before the PHP fallback:

```caddyfile
handle /ws {
    reverse_proxy 127.0.0.1:6010 {
        header_up Host {host}
        header_up X-Real-IP {remote_host}
    }
}

handle /dosdoor {
    reverse_proxy 127.0.0.1:6001 {
        header_up Host {host}
        header_up X-Real-IP {remote_host}
    }
}
```

If you built your `Caddyfile` from the earlier example and see slow page loads or a non-working realtime connection, copy the updated `/ws` and `/dosdoor` blocks from `docs/INSTALL.md` and reload Caddy. Nginx and Apache examples in the guide are unaffected.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
