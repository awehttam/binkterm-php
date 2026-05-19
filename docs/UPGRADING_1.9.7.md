# Upgrading to 1.9.7

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
  - [Runtime Requirements](#runtime-requirements)
  - [Terminal Server](#terminal-server)
  - [Configurable Border Style](#configurable-border-style)
  - [Terminal Charset Setting Honoured](#terminal-charset-setting-honoured)
  - [PacketBBS](#packetbbs)
    - [Local Chat](#local-chat-packetbbs)
    - [PacketBBS Node Directory](#packetbbs-node-directory)
    - [New Interface Types](#new-interface-types)
    - [Auto-add policy sync change](#auto-add-policy-sync-change)
  - [Web Interface](#web-interface)
    - [Chat Inline Media](#chat-inline-media)
  - [Developer Tooling](#developer-tooling)
- [Runtime Requirements](#runtime-requirements-1)
- [Terminal Server](#terminal-server-1)
  - [Configurable Border Style](#configurable-border-style)
  - [Terminal Charset Setting Honoured](#terminal-charset-setting-honoured)
- [PacketBBS](#packetbbs-1)
  - [Local Chat](#local-chat-packetbbs-1)
  - [PacketBBS Node Directory](#packetbbs-node-directory-1)
    - [Location Description field](#location-description-field)
    - [Node description field](#node-description-field)
    - [Admin node list](#admin-node-list)
    - [Admin node edit modal](#admin-node-edit-modal)
    - [Node info modal](#node-info-modal)
  - [New Interface Types](#new-interface-types-1)
  - [Auto-add policy sync change](#auto-add-policy-sync-change-1)
- [Web Interface](#web-interface-1)
  - [Chat Inline Media](#chat-inline-media-1)
- [Developer Tooling](#developer-tooling-1)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Runtime Requirements

- BinktermPHP now requires PHP 8.2 or newer. Systems still running PHP 8.1 or earlier must upgrade PHP before deploying this release.

### Terminal Server

- The shared terminal server now includes **Local Chat** from the main menu. Telnet and SSH users can open room chat, switch rooms and DMs, view online users from the left navigation pane, read Markdown-rendered messages, and send new messages without leaving the terminal session.
- The terminal main menu now reacts to live terminal resize events. On Telnet NAWS updates and SSH window-change events, the menu redraws to the new dimensions without requiring an extra keypress, and dashboard widgets are re-laid out using cached stats rather than triggering another API call.
- The terminal netmail reader now provides a **Sent folder**. Users can press `S` from the message list to toggle between the Inbox and Sent views. The active folder is remembered across sessions.
- The terminal message viewer now exposes inline image viewing more broadly. The existing `I` image-viewer flow still supports Markdown `![alt](url)` images, and now also detects bare direct image URLs in regular message bodies when they end in `.png`, `.jpg`, `.jpeg`, or `.gif`.
- SSH terminal startup now discards any client input bytes already queued during PTY/shell setup before handing control to the BBS session. This prevents some SSH clients from accidentally skipping login or menu screens with phantom startup keypresses.
- **Configurable main menu keys**: every terminal main menu action can be remapped to a custom letter or digit via **Admin → BBS Settings → Appearance → Terminal Server → Main Menu Keys**. Actions with no assigned key are removed from the menu. When all actions in a section are disabled the section header is suppressed and the remaining items reflow. The admin UI shows the factory default for each action for reference.
- **Configurable terminal idle timeout**: the idle warning and disconnect thresholds for terminal sessions are now configurable from **Admin → BBS Settings → Terminal Idle Timeout** rather than being hardcoded. The defaults remain 5 minutes to warning and 7 minutes to disconnect.
- **Configurable border style**: the box-drawing style used for all terminal frames and viewers can now be set per-system via **Admin → BBS Settings → Appearance → Terminal Server → Border Style**. Nine styles are available (Classic, Double, Single, Heavy, Rounded, Minimal, Mixed, Shadow, ASCII). Styles that require characters not supported by the connecting client's character set fall back automatically.
- **Terminal charset preference honoured**: the user's saved terminal character set preference (ASCII, CP437, or UTF-8) is now correctly respected. Previously a saved ASCII preference could be silently overridden by terminal auto-detection on UTF-8 capable clients.

### PacketBBS

#### Local Chat

- PacketBBS operators can now participate in local BBS chat rooms. The `CHAT` command (short form `C`) enters the default room. `CHAT <room>` enters a named room.
- While in a chat room, any text sent is posted automatically. Exit with `Q` or `/C`. `M` pages back through older history; `B` returns toward the latest messages. `WHO`, `STATUS`, `HELP`, and `LOGIN` are intercepted as commands and are never posted.
- Messages posted by web and terminal users are delivered to radio operators currently in the same room via the outbound queue, without any bridge code changes.

#### PacketBBS Node Directory

- A new **PacketBBS Nodes** map and list has been added to the **BBS Lists** menu and is visible when PacketBBS nodes are registered to the BBS.
- Bridge nodes now have a **Location Description** field (free text, e.g. "Lower Mainland BC"). The public node table and the dashboard widget now show this descriptor instead of GPS coordinates.
- The **PacketBBS Nodes** dashboard card now appears in the sidebar (between the Voting Booth and Echo Areas cards). Each node name is a link that opens its info modal. The location description is shown beneath the name; nodes with no description show a placeholder.
- The node edit modal in **Admin → Packet BBS Nodes** is now a two-column layout. The **Auto-Add Contact Policy** section occupies the right column. The **Link to BBS Account** field has been removed; use the standard `LOGIN <user> <code>` authenticator flow instead. The Handle/Callsign field now shows the BBS hostname as placeholder text and includes a note that the value should match the MeshCore node name.

#### New Interface Types

- The Interface Type dropdown in the node registration modal now includes **Meshtastic** (experimental) and **AX.25 TNC (KISS)** alongside MeshCore. Companion bridge adapters are available as separate packages: [awehttam/binktermphp-meshtasticbridge](https://github.com/awehttam/binktermphp-meshtasticbridge) for Meshtastic (TCP or USB serial) and [awehttam/binktermphp-ax25kiss](https://github.com/awehttam/binktermphp-ax25kiss) for AX.25 packet radio.

#### Auto-add policy sync change

- The auto-add contact policy is no longer pushed to the device on every save — a `set_autoadd_config` device command is queued only when the bitmask actually changes.

### Web Interface

- Links posted to chat rooms and direct messages are now processed by the inline media player. Images, video files, retro audio files, and platform embeds (YouTube, etc.) render automatically below the link in the chat thread.
- Inline code (`code`) in Markdown-rendered content now renders in the theme's normal text color on all dark themes (dark, amber, greenterm, cyberpunk). Bootstrap's default pink/red code color was difficult to read against dark backgrounds.

### Developer Tooling

- The root `CLAUDE.md` contributor guide has been split into subdirectory-scoped files and on-demand skill scripts, reducing context load when working in specific parts of the codebase. No action required for sysops.
- A `session-start.php` script has been added to `.claude/` to print available project skills at the start of each Claude Code session.

---

## Runtime Requirements

This release raises the minimum supported PHP version to 8.2. The project metadata, build image, and operator-facing guidance now all assume PHP 8.2 or newer.

If your server is still on PHP 8.1, upgrade PHP first and verify the runtime before replacing the application files or running `php scripts/setup.php`. The application will not run correctly on older PHP versions.

---

## Terminal Server

### Responsive Terminal Resizing

The terminal server interface now responds more broadly to terminal window resizing over both Telnet and SSH. When the user resizes the terminal, screens that support responsive layout redraw to the new width and height immediately. This includes the main menu and dashboard widgets, which switch between the wide sidebar and narrow bottom-bar layouts as needed without making another `/api/dashboard/stats` request.

### Local Chat

Terminal users can now access Local Chat directly from the shared BBS main menu by pressing `C`.

The terminal client currently provides:

- room and DM selection from the left navigation pane
- online-user summary in that same pane
- a larger message pane with Markdown rendering
- a bottom compose box with `Enter` to send and `Ctrl+E` for multiline compose
- API-backed polling for live updates while the chat screen is open

No post-upgrade admin action is required for this feature. If Local Chat is already enabled in **Admin -> BBS Settings**, it becomes available automatically to terminal users after the upgraded daemons are restarted.

### Terminal Inline Image Viewer Expansion

The terminal message viewer's existing `I` image-viewer flow now covers more than just Markdown image syntax. Markdown messages still support `![alt](url)` inline images as before, and the viewer now also scans regular message bodies for bare direct image URLs ending in `.png`, `.jpg`, `.jpeg`, or `.gif`.

When any supported inline images are found, the message body shows numbered `[Image N: …]` placeholders and pressing `I` opens the same Sixel image viewer used previously. This applies across the shared terminal readers rather than only to Markdown-rendered messages.

For safety and predictable `img2sixel` behavior, the terminal-side URL scan is limited to raster formats known to work in this environment. SVG, WebP, and other extensions are not included in the terminal scan. Hosts that serve valid image files as `application/octet-stream` are now accepted when the URL path ends in one of the supported raster extensions, and the downloaded file is validated before conversion.

No sysop configuration is required. If `img2sixel` is already installed, the expanded image-viewer behavior becomes available after the upgraded daemons are restarted.

### SSH Startup Input Drain

Some SSH clients send buffered input bytes immediately during PTY or shell startup. Previously those bytes could reach the shared BBS session as if the user had already pressed keys, causing the terminal UI to skip past login screens, menu screens, or even enter a message viewer unexpectedly.

The SSH daemon now discards any channel-data bytes that are already queued at the moment the shell channel is established, before handing the connection to the shared `BbsSession`. Normal interactive input after startup is unaffected.

No configuration change is required. The fix takes effect when the upgraded SSH daemon is restarted.

### Netmail Sent Folder in Terminal Reader

The terminal netmail message list now includes a Sent folder. From the message list, pressing `S` switches between the Inbox view (all received messages) and the Sent view (messages sent from this system). Pressing `S` again returns to the Inbox. The last-used folder is saved per user and restored the next time they open netmail from the terminal.

When reading a message in the Sent view, the header shows the recipient (`To:`) rather than the sender, since the sender is always the logged-in user. Pressing `R` to reply from the Sent view pre-fills the recipient fields with the original message's addressee rather than the sender.

No sysop configuration is required. The daemon restart that follows a normal upgrade is sufficient.

### Configurable Terminal Idle Timeout

The idle warning and disconnect timeouts for terminal sessions (Telnet and SSH) are now configurable from **Admin → BBS Settings → Terminal Idle Timeout**. Previously these were hardcoded at 5 minutes to warning and 7 minutes to disconnect; those values are now the defaults and can be changed without editing code or config files. The disconnect timeout must always be set greater than the warning timeout.

No additional admin setup is required. The daemon restart that follows a normal upgrade is sufficient.

### Configurable Terminal Main Menu Keys

Every action in the terminal main menu can now be remapped to a custom key via **Admin → BBS Settings → Appearance → Terminal Server → Main Menu Keys**. Each action accepts a single letter or digit (0–9). Leaving a key blank removes that action from the menu entirely — the option is not shown and is unreachable from the keyboard. The `quit` action always requires a key.

The admin UI shows a center reference column with the built-in default key for each action, so sysops can see at a glance what they are overriding.

The menu layout adapts automatically: when an action has no assigned key its slot is omitted and the remaining items in that section reflow to fill the gap. When every action in an entire section (Messaging, Community/Explore, or Files/Settings) is unassigned the section header itself is suppressed. Sysops who use a custom `mainmenu.ans` are responsible for keeping that art in sync with the configured keys.

No additional admin setup is required. The built-in defaults remain in effect until a custom map is saved through the admin UI.

### Configurable Border Style

The box-drawing characters used for all terminal frames, paged viewers, and content boxes can now be selected per-system. The setting is in **Admin → BBS Settings → Appearance → Terminal Server → Border Style** and is applied immediately — no daemon restart required.

Nine styles are available:

| Style | Description |
|-------|-------------|
| **Classic** *(default)* | Double-line corners and tees, single-line sides |
| **Double** | Full double-line box drawing |
| **Single** | Thin single-line box drawing |
| **Heavy** | Bold thick single-line (UTF-8 terminal required) |
| **Rounded** | Curved corners (UTF-8 terminal required) |
| **Minimal** | Top and bottom rules only, no side walls |
| **Mixed** | Double horizontal, single vertical |
| **Shadow** | Classic borders with a half-block drop shadow (UTF-8 terminal required) |
| **ASCII** | Plus, hyphen, and pipe — safe for any terminal |

Styles that require characters outside the connecting client's character set fall back automatically: Heavy → Classic and Rounded → Single on CP437 terminals; all non-ASCII styles → ASCII on ASCII-only terminals. The Classic style is the default and matches the existing look from earlier releases.

### Terminal Charset Setting Honoured

A user's saved terminal character set preference (ASCII, CP437, or UTF-8, set from **Terminal Settings** in the terminal session) is now correctly applied. Previously, a preference explicitly saved as **ASCII** could be silently promoted to UTF-8 when connecting from a UTF-8-capable terminal, causing box-drawing characters to appear even when the user had opted out. The saved preference now takes precedence over auto-detection in all cases.

Users who were affected do not need to change anything — their existing setting will now be respected on next login.

---

## PacketBBS

### Local Chat {#local-chat-packetbbs-1}

PacketBBS operators can now read and post in local BBS chat rooms directly from their radio node.

**Entering a room**

`CHAT` or `C` with no arguments enters the default room (the first active room, typically Lobby). `CHAT <room>` or `C <room>` enters a room by name. Login is required.

On entry the most recent messages are shown, formatted as `username: message` and paged to fit the node's output profile.

**Auto-post mode**

Once inside a room, every message sent is posted to the room immediately. There is no explicit send command. The following inputs are intercepted as commands and are never posted to the room:

| Input | Action |
|---|---|
| `Q` or `/C` | Exit the room, return to main context |
| `QUIT` | End the PacketBBS session |
| `W` / `WHO` | Show online users |
| `U` / `STATUS` | Show current context |
| `H` / `HELP` / `?` | Show chat help |
| `M` / `MORE` | Page to older history |
| `B` / `P` / `PREV` | Page back toward latest messages |
| `L` / `LOGIN` | Intercepted — not posted |
| `C` / `CHAT` | Refresh or switch rooms |

**Receiving messages from other users**

When a web or terminal user posts to a chat room, any PacketBBS sessions currently in that room receive the message via the outbound queue. The bridge delivers it on its next poll without any bridge-side changes.

No migration is required. The feature uses the existing `chat_rooms` and `chat_messages` tables. No admin configuration is needed beyond having at least one active chat room.

### PacketBBS Node Directory

#### Location Description field

Bridge nodes now include a **Location Description** field stored on `packet_bbs_nodes`. Existing rows with no description continue to display the "No location set" placeholder until you add one.

#### Node description field

Bridge nodes now have a **Description** field (`description TEXT` on `packet_bbs_nodes`). It is optional and accepts free-form text — use it to describe the node's purpose, coverage area, or any other detail useful to users. The description appears in the public node info modal when a user clicks a node on the PacketBBS Nodes page.

Existing rows have a `NULL` description and show no description row in the modal until one is added through **Admin → Packet BBS Nodes**.

#### Admin node list

The **Linked Account** column has been removed from the registered nodes table in **Admin → Packet BBS Nodes**. Account linking was removed from the node edit modal in an earlier build; this removes the now-empty column from the list view.

#### Admin node edit modal

The node edit modal is now wider (`modal-lg`) and uses a two-column layout. The left column holds node identity fields (Node ID, Handle/Callsign, Interface Type, Location Description, Description, Coordinates). The right column holds the Auto-Add Contact Policy section for MeshCore nodes. The **Link to BBS Account** field has been removed from the modal entirely; normal per-user authentication uses the `LOGIN <user> <code>` TOTP flow and does not require an account link.

The Handle/Callsign field placeholder now shows the BBS hostname derived from `SITE_URL` (using the hostname can help with discovery from mesh adverts). A note under the field explains that the value should match the node name set in the MeshCore app, as it is used as the contact display name when the bridge QR-codes itself into a new companion's contact list.

#### Node info modal

The public node info modal (opened by clicking a node on the PacketBBS Nodes page) now shows a **Description** row when a description has been set for the node.

The **QR code** and **Public Key** sections are now only shown for MeshCore nodes. AX.25 TNC (KISS) nodes authenticate by callsign and have no cryptographic public key, so those fields are suppressed for that interface type.

### New Interface Types

Two new interface types are now available in the node registration modal alongside the existing MeshCore option:

**Meshtastic** connects BinktermPHP to a Meshtastic network via TCP or USB serial. The companion bridge adapter is available at [awehttam/binktermphp-meshtasticbridge](https://github.com/awehttam/binktermphp-meshtasticbridge). This adapter is currently experimental and untested.

**AX.25 TNC (KISS)** connects BinktermPHP to AX.25 packet radio via a hardware or software TNC (e.g. Direwolf). Registering a node with this type sets the output profile to 8 lines × 64 columns per page, tuned for packet radio frame sizes. The companion bridge adapter is available at [awehttam/binktermphp-ax25kiss](https://github.com/awehttam/binktermphp-ax25kiss). This adapter is currently experimental.

No BBS migration is required for either type; the interface type string is stored as-is on the existing `packet_bbs_nodes` table.

### Auto-add policy sync change

Previously, saving a MeshCore node always queued a `set_autoadd_config` device command, even when nothing changed. The modal now tracks the policy bitmask at open time and only queues the device command when the value actually changes at save time. The "Saved — waiting for bridge to apply to device" confirmation is shown only when a command was queued.

---

## Web Interface

### Chat Inline Media

Links posted to chat rooms and direct messages are now processed by the inline media player. Recognized content renders automatically below the link in the chat thread — no clicks required, regardless of the user's personal **Media Render Mode** preference in their web settings (that preference governs echomail and netmail only).

Supported content types:

| Category | Formats / Providers |
|---|---|
| Images | `.png` `.jpg` `.jpeg` `.gif` `.webp` `.svg` and known image CDN prefixes |
| Video | `.mp4` `.webm` `.ogv` `.mov` |
| Retro audio | `.sid` `.mod` `.xm` `.it` `.s3m` `.stm` `.amf` `.669` `.mptm` `.midi` |
| Platform embeds | YouTube, Odysee, BitChute, Brighteon, PeerTube, Rumble, SoundCloud, Twitter/X, TikTok, ReverbNation, Bastyon |

No sysop configuration is required. The global **Admin → BBS Settings → Media Player** toggle still controls whether the media player is active at all; if it is disabled site-wide, chat media rendering is also disabled.

### Dark Theme Inline Code Color

Bootstrap 5's default color for inline `code` elements (`#d63384`, a pink/red) is difficult to read against dark backgrounds. The dark, amber, greenterm, and cyberpunk themes now override this with the theme's normal text color.

No sysop action is required.

---

## Developer Tooling

The root `CLAUDE.md` file previously contained all project guidance in a single document. It has been refactored so that sections relevant only to a specific subdirectory now live in a `CLAUDE.md` file within that directory (auto-loaded by Claude Code when working there). Subdirectory files were added for `scripts/`, `telnet/`, `ssh/`, `templates/`, and `public_html/webdoors/`.

Procedural checklists that contributors invoke on demand have been extracted into skill files under `.claude/commands/`:

- `/bump-version` — version bump steps, UPGRADING doc format, and composer dependency notes
- `/new-migration` — database-change workflow guidance, SQL vs PHP choice, no-duplicate-index rule, and setup.php reminder
- `/usercredits-workflow` — five-step checklist for adding new `UserCredit` types
- `/logging-guide` — log file table, per-context code patterns, log levels, and adding a new log file
- `/new-webdoor` — manifest requirement, SDK require path, and API independence rule

A `Doc Maintenance Checklist` section was also added to `docs/DEVELOPER_GUIDE.md` mapping subsystems to their corresponding documentation files, making it easier to identify which docs need updating when a subsystem changes.

`.claude/session-start.php` has been added and is now tracked in git. It prints a welcome message and the list of available project skills at the start of each new Claude Code session. The `CLAUDE.md` instructions were updated to require that any new skill file be registered in both the Skills list in `CLAUDE.md` and in `session-start.php`.

No sysop action is required for any of these changes.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
