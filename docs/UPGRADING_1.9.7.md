# Upgrading to 1.9.7

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
  - [Runtime Requirements](#runtime-requirements)
  - [Terminal Server](#terminal-server)
  - [PacketBBS Node Directory](#packetbbs-node-directory)
  - [Developer Tooling](#developer-tooling)
- [Runtime Requirements](#runtime-requirements-1)
- [Terminal Server](#terminal-server-1)
- [PacketBBS Node Directory](#packetbbs-node-directory-1)
- [Developer Tooling](#developer-tooling-1)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Runtime Requirements

- BinktermPHP now requires PHP 8.2 or newer. Systems still running PHP 8.1 or earlier must upgrade PHP before deploying this release.

### Terminal Server

- The shared terminal server now includes **Local Chat** from the main menu. Telnet and SSH users can open room chat, switch rooms and DMs, view online users from the left navigation pane, read Markdown-rendered messages, and send new messages without leaving the terminal session.
- The terminal chat client uses the existing local chat API with polling, so no additional daemon or sysop configuration is required when upgrading.
- The terminal main menu now reacts to live terminal resize events. On Telnet NAWS updates and SSH window-change events, the menu redraws to the new dimensions without requiring an extra keypress, and dashboard widgets are re-laid out using cached stats rather than triggering another API call.
- The terminal netmail reader now provides a **Sent folder**. Users can press `S` from the message list to toggle between the Inbox and Sent views. The active folder is remembered across sessions.
- The terminal message viewer now exposes inline image viewing more broadly. The existing `I` image-viewer flow still supports Markdown `![alt](url)` images, and now also detects bare direct image URLs in regular message bodies when they end in `.png`, `.jpg`, `.jpeg`, or `.gif`.
- SSH terminal startup now discards any client input bytes already queued during PTY/shell setup before handing control to the BBS session. This prevents some SSH clients from accidentally skipping login or menu screens with phantom startup keypresses.
- **Configurable main menu keys**: every terminal main menu action can be remapped to a custom letter or digit via **Admin → BBS Settings → Appearance → Terminal Server → Main Menu Keys**. Actions with no assigned key are removed from the menu. When all actions in a section are disabled the section header is suppressed and the remaining items reflow. The admin UI shows the factory default for each action for reference.
- **Configurable terminal idle timeout**: the idle warning and disconnect thresholds for terminal sessions are now configurable from **Admin → BBS Settings → Terminal Idle Timeout** rather than being hardcoded. The defaults remain 5 minutes to warning and 7 minutes to disconnect.

### PacketBBS Node Directory

- Bridge nodes now have a **Location Description** field (free text, e.g. "Lower Mainland BC") stored in the new `location` column on `packet_bbs_nodes`. The public node table and the dashboard widget now show this descriptor instead of GPS coordinates. Run `php scripts/setup.php` to apply migration `v20260517215052` that adds the column.
- The public node directory URL has changed from `/meshcore-nodes` to `/packetbbs-nodes`. The **BBS Lists** menu entry has been renamed to **PacketBBS Nodes**. Any bookmarks or links to the old URL need to be updated.
- The public `/packetbbs-nodes` page now opens a node info modal when the URL hash is `#node-{id}`, making deep links from the dashboard widget work correctly.
- The **PacketBBS Nodes** dashboard card now appears in the sidebar (between the Voting Booth and Echo Areas cards). Each node name is a link that opens its info modal. The location description is shown beneath the name; nodes with no description show a placeholder.
- The node edit modal in **Admin → Packet BBS Nodes** is now a two-column layout. The **Auto-Add Contact Policy** section occupies the right column. The **Link to BBS Account** field has been removed; use the standard `LOGIN <user> <code>` authenticator flow instead. The Handle/Callsign field now shows the BBS hostname as placeholder text and includes a note that the value should match the MeshCore node name.
- The auto-add contact policy is no longer pushed to the device on every save — a `set_autoadd_config` device command is queued only when the bitmask actually changes.

### Developer Tooling

- The root `CLAUDE.md` contributor guide has been split into subdirectory-scoped files and on-demand skill scripts, reducing context load when working in specific parts of the codebase. No action required for sysops.
- A `session-start.php` script has been added to `.claude/` to print available project skills at the start of each Claude Code session.

---

## Runtime Requirements

This release raises the minimum supported PHP version to 8.2. The project metadata, build image, and operator-facing guidance now all assume PHP 8.2 or newer.

If your server is still on PHP 8.1, upgrade PHP first and verify the runtime before replacing the application files or running `php scripts/setup.php`. No database migration is tied to this requirement, but the application will not run correctly on older PHP versions.

---

## Terminal Server

Terminal users can now access Local Chat directly from the shared BBS main menu by pressing `C`.

The shared terminal main menu also now responds to terminal window resizing while waiting for input. If the user resizes the terminal, the menu redraws immediately for the new width and height, and the dashboard widgets switch between the wide sidebar and narrow bottom-bar layouts as needed without making another `/api/dashboard/stats` request.

The terminal client currently provides:

- room and DM selection from the left navigation pane
- online-user summary in that same pane
- a larger message pane with Markdown rendering
- a bottom compose box with `Enter` to send and `Ctrl+E` for multiline compose
- API-backed polling for live updates while the chat screen is open

No migration or post-upgrade admin action is required for this feature. If Local Chat is already enabled in **Admin -> BBS Settings**, it becomes available automatically to terminal users after the upgraded daemons are restarted.

### Terminal Inline Image Viewer Expansion

The terminal message viewer's existing `I` image-viewer flow now covers more than just Markdown image syntax. Markdown messages still support `![alt](url)` inline images as before, and the viewer now also scans regular message bodies for bare direct image URLs ending in `.png`, `.jpg`, `.jpeg`, or `.gif`.

When any supported inline images are found, the message body shows numbered `[Image N: …]` placeholders and pressing `I` opens the same Sixel image viewer used previously. This applies across the shared terminal readers rather than only to Markdown-rendered messages.

For safety and predictable `img2sixel` behavior, the terminal-side URL scan is limited to raster formats known to work in this environment. SVG, WebP, and other extensions are not included in the terminal scan. Hosts that serve valid image files as `application/octet-stream` are now accepted when the URL path ends in one of the supported raster extensions, and the downloaded file is validated before conversion.

No migration or sysop configuration is required. If `img2sixel` is already installed, the expanded image-viewer behavior becomes available after the upgraded daemons are restarted.

### SSH Startup Input Drain

Some SSH clients send buffered input bytes immediately during PTY or shell startup. Previously those bytes could reach the shared BBS session as if the user had already pressed keys, causing the terminal UI to skip past login screens, menu screens, or even enter a message viewer unexpectedly.

The SSH daemon now discards any channel-data bytes that are already queued at the moment the shell channel is established, before handing the connection to the shared `BbsSession`. Normal interactive input after startup is unaffected.

No migration or configuration change is required. The fix takes effect when the upgraded SSH daemon is restarted.

### Netmail Sent Folder in Terminal Reader

The terminal netmail message list now includes a Sent folder. From the message list, pressing `S` switches between the Inbox view (all received messages) and the Sent view (messages sent from this system). Pressing `S` again returns to the Inbox. The last-used folder is saved per user and restored the next time they open netmail from the terminal.

When reading a message in the Sent view, the header shows the recipient (`To:`) rather than the sender, since the sender is always the logged-in user. Pressing `R` to reply from the Sent view pre-fills the recipient fields with the original message's addressee rather than the sender.

No migration or sysop configuration is required. The daemon restart that follows a normal upgrade is sufficient.

### Configurable Terminal Idle Timeout

The idle warning and disconnect timeouts for terminal sessions (Telnet and SSH) are now configurable from **Admin → BBS Settings → Terminal Idle Timeout**. Previously these were hardcoded at 5 minutes to warning and 7 minutes to disconnect; those values are now the defaults and can be changed without editing code or config files. The disconnect timeout must always be set greater than the warning timeout.

No migration is required. The daemon restart that follows a normal upgrade is sufficient.

### Configurable Terminal Main Menu Keys

Every action in the terminal main menu can now be remapped to a custom key via **Admin → BBS Settings → Appearance → Terminal Server → Main Menu Keys**. Each action accepts a single letter or digit (0–9). Leaving a key blank removes that action from the menu entirely — the option is not shown and is unreachable from the keyboard. The `quit` action always requires a key.

The admin UI shows a center reference column with the built-in default key for each action, so sysops can see at a glance what they are overriding.

The menu layout adapts automatically: when an action has no assigned key its slot is omitted and the remaining items in that section reflow to fill the gap. When every action in an entire section (Messaging, Community/Explore, or Files/Settings) is unassigned the section header itself is suppressed. Sysops who use a custom `mainmenu.ans` are responsible for keeping that art in sync with the configured keys.

No migration is required. The built-in defaults remain in effect until a custom map is saved through the admin UI.

---

## PacketBBS Node Directory

### Migration: location column

A new `location VARCHAR(255)` column has been added to `packet_bbs_nodes`. Run `php scripts/setup.php` after upgrading to apply migration `v20260517215052_add_location_to_packet_bbs_nodes.sql`. No data migration is required; existing rows default to `NULL` (displayed as "No location set").

### Public node directory URL change

The public node directory has moved from `/meshcore-nodes` to `/packetbbs-nodes`. The navigation menu entry is now **PacketBBS Nodes**. The old URL is not redirected; update any external links or bookmarks.

### Admin node edit modal

The node edit modal is now wider (`modal-lg`) and uses a two-column layout. The left column holds node identity fields (Node ID, Handle/Callsign, Interface Type, Location Description, Coordinates). The right column holds the Auto-Add Contact Policy section for MeshCore nodes. The **Link to BBS Account** field has been removed from the modal entirely; normal per-user authentication uses the `LOGIN <user> <code>` TOTP flow and does not require an account link.

The Handle/Callsign field placeholder now shows the BBS hostname derived from `SITE_URL`. A note under the field explains that the value should match the node name set in the MeshCore app, as it is used as the contact display name when the bridge QR-codes itself into a new companion's contact list.

### Auto-add policy sync change

Previously, saving a MeshCore node always queued a `set_autoadd_config` device command, even when nothing changed. The modal now tracks the policy bitmask at open time and only queues the device command when the value actually changes at save time. The "Saved — waiting for bridge to apply to device" confirmation is shown only when a command was queued.

---

## Developer Tooling

The root `CLAUDE.md` file previously contained all project guidance in a single document. It has been refactored so that sections relevant only to a specific subdirectory now live in a `CLAUDE.md` file within that directory (auto-loaded by Claude Code when working there). Subdirectory files were added for `scripts/`, `telnet/`, `ssh/`, `templates/`, and `public_html/webdoors/`.

Procedural checklists that contributors invoke on demand have been extracted into skill files under `.claude/commands/`:

- `/bump-version` — version bump steps, UPGRADING doc format, and composer dependency notes
- `/new-migration` — migration ID format, SQL vs PHP choice, no-duplicate-index rule, and setup.php reminder
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
