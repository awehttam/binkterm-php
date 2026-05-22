# Upgrading to 1.9.7

Welcome to BinktermPHP 1.9.7!

Make sure you have a current backup of your database and files before upgrading.

This release focused primarily on the terminal server, introducing support for multiple terminal shells and responsive ANSI rendering for better compatibility across different screen sizes and terminal types. Other subsystems — PacketBBS, the web interface, Auto Feed, and developer tooling — received updates alongside the terminal work.

A few other recent project updates are worth highlighting alongside this release:

- The built-in documentation has been expanded with an **Essential Setup & Operations** section covering Getting Started, Installation, Configuration, and other critical sysop topics. All of it is accessible from **Admin → Help → Documentation**.
- BinktermPHP has a new official mascot: **Kludge the Corvid**.

**1.9.7 requires PHP 8.2 or newer.** If your server is still on PHP 8.1 or earlier, upgrade PHP first and verify the runtime before replacing application files or running `php scripts/setup.php`. The application will not run correctly on older versions.

The terminal server gains over two dozen new capabilities: bookmarks, full-text search, and sort options for both netmail and echomail; address book and FTN nodelist lookup in netmail compose; message delete, plain-text download, and forward-to-email from the reader; multi-area cross-posting; message drafts with in-editor save; an ignore-rule manager accessible without leaving the session; a selectable Who's Online popup with a public profile viewer; and a redesigned file browser with a modal detail view. 

These sit alongside system-wide improvements including resize-aware repaints across all overlays, a configurable border style, a configurable main menu key map, configurable idle timeouts, and a Ctrl-K reference overlay that documents every key binding without cluttering the status bar. 

On the PacketBBS side, radio operators can join local BBS chat rooms; a new PacketBBS Nodes map and directory has been added to the BBS Lists menu; two new interface types — Meshtastic and AX.25 TNC (KISS) — are available alongside MeshCore in the node registration modal; and bridge nodes gain optional location and description fields.

In the web interface, chat rooms now render inline media automatically, inline code is rendered correctly on dark themes, and the bulletin viewer has been updated to display text with the same preformatted style and modern system font as the message reader.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Runtime Requirements](#runtime-requirements-1)
- [Terminal Server](#terminal-server-1)
  - [Responsive Terminal Resizing](#responsive-terminal-resizing)
  - [Local Chat](#local-chat-1)
  - [Terminal Inline Image Viewer Expansion](#terminal-inline-image-viewer-expansion)
  - [SSH Startup Input Drain](#ssh-startup-input-drain)
  - [Netmail Sent Folder in Terminal Reader](#netmail-sent-folder-in-terminal-reader)
  - [Netmail Sort Options in Terminal Reader](#netmail-sort-options-in-terminal-reader)
  - [Configurable Terminal Idle Timeout](#configurable-terminal-idle-timeout)
  - [Configurable Terminal Main Menu Keys](#configurable-terminal-main-menu-keys)
  - [Configurable Border Style](#configurable-border-style)
  - [Terminal Charset Setting Honoured](#terminal-charset-setting-honoured)
  - [Echomail Sort Options in Terminal](#echomail-sort-options-in-terminal)
  - [Netmail Address Book / Nodelist Lookup in Compose](#netmail-address-book--nodelist-lookup-in-compose)
  - [Netmail Delete in Terminal Reader](#netmail-delete-in-terminal-reader)
  - [Netmail Bookmark in Terminal Reader](#netmail-bookmark-in-terminal-reader)
  - [Echomail Bookmark in Terminal Reader](#echomail-bookmark-in-terminal-reader)
  - [Netmail Download as Text in Terminal Reader](#netmail-download-as-text-in-terminal-reader)
  - [Echomail Download as Text in Terminal Reader](#echomail-download-as-text-in-terminal-reader)
  - [Forward Netmail to Email in Terminal Reader](#forward-netmail-to-email-in-terminal-reader)
  - [Forward Echomail to Email in Terminal Reader](#forward-echomail-to-email-in-terminal-reader)
  - [Echomail Search in Terminal](#echomail-search-in-terminal)
  - [Netmail Bulk Mark Selected as Read in Terminal](#netmail-bulk-mark-selected-as-read-in-terminal)
  - [Ctrl-K Help Overlay in Terminal Message Viewer](#ctrl-k-help-overlay-in-terminal-message-viewer)
  - [Echoarea List and Interests Picker Navigation](#echoarea-list-and-interests-picker-navigation)
  - [Subscribe and Unsubscribe to Echoareas from Terminal](#subscribe-and-unsubscribe-to-echoareas-from-terminal)
  - [Terminal File Browser Selector and File Info Modal](#terminal-file-browser-selector-and-file-info-modal)
  - [Cross-post Echomail to Multiple Areas from Terminal](#cross-post-echomail-to-multiple-areas-from-terminal)
  - [Terminal Full-Screen Editor Visual Refresh](#terminal-full-screen-editor-visual-refresh)
  - [Terminal Message Drafts](#terminal-message-drafts)
  - [Echomail Ignore Rules in Terminal](#echomail-ignore-rules-in-terminal)
  - [Terminal Resize Repaint](#terminal-resize-repaint)
  - [Ctrl-C Cancels Compose Prompts](#ctrl-c-cancels-compose-prompts)
  - [Terminal Who's Online Popup and Public Profile Viewer](#terminal-whos-online-popup-and-public-profile-viewer)
  - [DOS Door Return Path Stability](#dos-door-return-path-stability)
  - [Terminal Interface Style Preference](#terminal-interface-style-preference)
  - [Empty Echomail Area Entry](#empty-echomail-area-entry)
- [PacketBBS](#packetbbs-1)
  - [Local Chat](#local-chat-packetbbs-1)
  - [PacketBBS Node Directory](#packetbbs-node-directory-1)
  - [New Interface Types](#new-interface-types-1)
  - [Auto-add policy sync change](#auto-add-policy-sync-change-1)
- [Web Interface](#web-interface-1)
  - [Chat Profile Popup](#chat-profile-popup)
  - [Chat Inline Media](#chat-inline-media)
  - [Dark Theme Inline Code Color](#dark-theme-inline-code-color)
  - [Bulletin Viewer Rendering](#bulletin-viewer-rendering)
  - [Echo Areas .NA File Import](#echo-areas-na-file-import)
  - [CheeseNet Network Added](#cheesenet-network-added)
  - [New Echo Areas Load More](#new-echo-areas-load-more)
- [Auto Feed](#auto-feed-1)
  - [Reply Threading](#reply-threading)
- [Developer Tooling](#developer-tooling-1)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Runtime Requirements

- BinktermPHP now requires PHP 8.2 or newer. Systems still running PHP 8.1 or earlier must upgrade PHP before deploying this release.

### Terminal Server

- The shared terminal server now includes **Local Chat** from the main menu. Telnet and SSH users can open room chat, switch rooms and DMs, view online users from the left navigation pane, read Markdown-rendered messages, and send new messages without leaving the terminal session.
- - The terminal main menu now reacts to live terminal resize events. On Telnet NAWS updates and SSH window-change events, the menu redraws to the new dimensions without requiring an extra keypress, and dashboard widgets are re-laid out using cached stats rather than triggering another API call.
- The terminal netmail reader now provides a **Sent folder**. Users can press `S` from the message list to toggle between the Inbox and Sent views. The active folder is remembered across sessions.
- The terminal netmail reader now provides **sort options**. Users can press `O` from the netmail message list to choose Newest first, Oldest first, By subject, or By author, matching the web UI. The selected sort is remembered per user across terminal sessions.
- The terminal message viewer now exposes inline image viewing more broadly. The existing `I` image-viewer flow still supports Markdown `![alt](url)` images, and now also detects bare direct image URLs in regular message bodies when they end in `.png`, `.jpg`, `.jpeg`, or `.gif`.
- The terminal echomail reader now supports bulk marking selected messages as read. Press `Space` to toggle the highlighted message into the selection set, then press `M` to mark only the selected message IDs as read via the existing bulk-read API. `Ctrl-K` now opens the selector help overlay so the status bar can stay short enough for 80-column terminals.
- SSH terminal startup now discards any client input bytes already queued during PTY/shell setup before handing control to the BBS session. This prevents some SSH clients from accidentally skipping login or menu screens with phantom startup keypresses.
- The terminal echomail message viewer now supports **forwarding messages** via the `F` key. Pressing `F` opens a dialog that lets the user choose between forwarding to another subscribed echoarea (opens the standard echomail compose screen pre-filled with a `Fwd:` subject, attribution header, and quoted body) or forwarding as a netmail to an FTN address (hands off to the netmail compose flow). 
- **Configurable main menu keys**: every terminal main menu action can be remapped to a custom letter or digit via **Admin → BBS Settings → Appearance → Terminal Server → Main Menu Keys**. Actions with no assigned key are removed from the menu. When all actions in a section are disabled the section header is suppressed and the remaining items reflow. The admin UI shows the factory default for each action for reference.
- **Configurable terminal idle timeout**: the idle warning and disconnect thresholds for terminal sessions are now configurable from **Admin → BBS Settings → Terminal Server Settings** rather than being hardcoded. The defaults remain 5 minutes to warning and 7 minutes to disconnect.
- **Terminal interface style preference**: users can now choose their preferred terminal interface style from terminal settings, but only among shells that the sysop has explicitly enabled. By default only the full-screen TUI is available. Sysops enable additional shells (such as line mode) via `TERMSERVER_ALLOWEDSHELLS` in `.env`, and can set a system-wide default and optionally force all sessions to use it regardless of user preference. The **Terminal Idle Timeout** admin section has been renamed **Terminal Server Settings** to accommodate these new controls.
- **Terminal shell allowlist and plugin registration**: terminal shell selection is now gated by the `.env` allowlist `TERMSERVER_ALLOWEDSHELLS`, and custom shell plugins can be registered from `telnet/shells/*.plugin.php`. Disallowed saved shell preferences fall back safely to TUI instead of breaking login, and shell-aware shared widgets honour plugin-provided style profiles more consistently.
- **Configurable border style**: the box-drawing style used for all terminal frames and viewers can now be set per-system via **Admin → BBS Settings → Appearance → Terminal Server → Border Style**. Nine styles are available (Classic, Double, Single, Heavy, Rounded, Minimal, Mixed, Shadow, ASCII). Styles that require characters not supported by the connecting client's character set fall back automatically.
- **Terminal charset preference honoured**: the user's saved terminal character set preference (ASCII, CP437, or UTF-8) is now correctly respected. Previously a saved ASCII preference could be silently overridden by terminal auto-detection on UTF-8 capable clients.
- Terminal users can now **delete a netmail message** from the message viewer by pressing `X` or the `Del` key. A confirmation dialog appears before the message is removed.
- Terminal users can now **bookmark a netmail message** for later reference by pressing `B` in the message viewer. The status bar label toggles between **Bookmark** (unsaved) and **Unsave** (already saved). Bookmarked messages appear under the Saved filter in the web interface.- Terminal users can now **bookmark an echomail message** for later reference by pressing `B` in the echomail message viewer. Pressing `B` again unsaves it. Bookmarked messages appear under the Saved filter in the web interface.- **Address book / nodelist lookup in netmail compose**: when composing a new netmail in the terminal, typing `?` at the To Name or To Address prompt opens an interactive picker. The picker searches the user's address book, matching local BBS users (by real name and username), and the FTN nodelist. Address-book and local-user results are shown ahead of nodelist results, and nodelist duplicates are suppressed by FTN address. Typing `sysop` returns the configured local system sysop as a local-delivery target, matching the web compose behavior. Selecting an entry pre-fills both To Name and To Address as editable defaults.
- **Download netmail as plain text**: terminal users can now press `T` while reading a netmail message to download it as a `.txt` file via ZMODEM. The downloaded file contains the message headers and body in plain text, matching the equivalent download button in the web interface. ZMODEM must be supported by the connecting terminal application.
- Terminal users can now **forward a netmail message to their email address** by pressing `E` in the netmail message viewer. Requires outbound email to be configured on the BBS.
- Terminal users can now **forward an echomail message to their email address** by pressing `E` in the echomail message viewer. Requires outbound email to be configured on the BBS.
- **Echomail full-text search in the terminal**: pressing `S` from the echoarea list searches all subscribed areas; pressing `S` from within a specific area's message list searches that area only. Search results are shown in a paginated list; opening a result highlights the matched term in white on yellow in the message body.
- **Echomail sort options in the terminal**: pressing `O` from an echomail area's message list opens a dialog with the same four sort modes as the web UI: Newest first, Oldest first, By subject, and By author. The user's selected sort is saved and restored the next time they open echomail in the terminal.
- **Terminal bulk mark-as-read selection for echomail**: the terminal echomail list now supports multi-select before bulk read actions. Press `Space` to toggle the highlighted message into the selection set, then press `M` to mark only those selected message IDs as read via the existing bulk-read API. This matches the web interface's selected-message behavior instead of marking an entire area at once.- **Ctrl-K help overlay in the terminal message viewer**: all terminal message readers (netmail and echomail) now show a framed keyboard-reference panel when the user presses `Ctrl-K`. The panel lists every available key binding, including secondary actions that are not shown on the status bar. The overlay responds to terminal resize events while it is open and propagates any resize back to the message viewer when it is dismissed. The status bar in both readers has been trimmed to the five most-used actions (scroll, prev/next, reply, Ctrl-K help, and quit); all other keys are documented exclusively in the Ctrl-K overlay.
- **Echoarea list and interests picker navigation**: the echoarea list and the interests browser now use the same navigable list interface as message lists — arrow keys move the highlight cursor, Left/Right arrows change pages, Enter selects, and a status bar shows available actions. Number type-to-jump still works. The list redraws on terminal resize.- **Subscribe/unsubscribe to echoareas from terminal**: press `A` to toggle between your subscribed areas and all available areas. In all-areas view each row shows a `[+]`/`[ ]` subscription badge. Selecting an unsubscribed area offers Subscribe & Browse, Browse Only, or Cancel. Press `U` on any area to unsubscribe via a confirmation dialog.- **Terminal full-screen editor visual refresh**: the shared terminal full-screen editor now uses the same framed blue panel style as other terminal dialogs and overlays. The editor `Ctrl-K` help screen now uses the same framed treatment, and the editor redraws to the new geometry when the terminal is resized while it is open.- **Terminal message drafts for netmail and echomail**: the terminal compose flow now detects existing drafts before opening the editor, offers a resume picker, and adds `Ctrl+S` inside the full-screen editor to save a draft without leaving compose. Resumed drafts can be deleted from the picker with `X`, and successfully sending a resumed draft removes it automatically.- **Terminal Who's Online popup and public profile viewer**: the terminal Who's Online action now opens a centered selectable popup instead of a plain text dump. Selecting a user opens a reusable terminal public-profile viewer that shows username, full name, location, and biography.- **Terminal file browser selector and file info modal**: the file area list and per-area file list in the terminal server now use the same selector-style navigation as echomail, including arrow-key movement, page changes, Enter-to-open, and status-bar actions. Opening a file now shows a centered file-info modal with scrolling support instead of a plain full-screen detail page.- **ZMODEM documentation corrected**: `docs/TerminalServer.md` previously stated that external `sz`/`rz` binaries from `lrzsz` were required and that the built-in PHP ZMODEM implementation was a fallback. This was incorrect. The built-in PHP implementation is the default and preferred path because it correctly handles Telnet IAC (0xFF) byte escaping. External binaries are an opt-in option that requires the sysop to explicitly set `TELNET_ZMODEM_FORCE_PHP=false` in `.env`. No code change; documentation only.- **Unread messages bolded in terminal message lists**: echomail and netmail message lists now render unread rows in bold ANSI text. Once a message is opened and marked read, it displays at normal weight the next time the list is shown.- **DOS door return-path stability**: after a DOS door exits, the terminal server now restores the telnet socket's original blocking mode before returning to the normal BBS UI. This prevents prompt-driven screens such as the nodelist browser and door menu from immediately dropping back to the main menu after a door session. 
### PacketBBS

#### Local Chat

- PacketBBS operators can now participate in local BBS chat rooms. The `CHAT` command (short form `C`) enters the default room. `CHAT <room>` enters a named room.
- While in a chat room, any text sent is posted automatically. Exit with `Q` or `/C`. `M` pages back through older history; `B` returns toward the latest messages. `WHO`, `STATUS`, `HELP`, and `LOGIN` are intercepted as commands and are never posted.
- Messages posted by web and terminal users are delivered to radio operators currently in the same room via the outbound queue, without any bridge code changes.

#### PacketBBS Node Directory

- A new **PacketBBS Nodes** map and list has been added to the **BBS Lists** menu and is visible when PacketBBS nodes are registered to the BBS.
- Bridge nodes now have a **Location Description** field (free text, e.g. "Lower Mainland BC"). The public node table and the dashboard widget now show this descriptor instead of GPS coordinates.
- The **PacketBBS Nodes** dashboard card now appears in the sidebar (between the Voting Booth and Echo Areas cards). Each node name is a link that opens its info modal. The location description is shown beneath the name; nodes with no description show a placeholder.
- The node edit modal in **Admin → Packet BBS Nodes** is now a two-column layout. The **Auto-Add Contact Policy** section occupies the right column. The **Link to BBS Account** field has been removed; use the standard `LOGIN <user> <code>` authenticator flow instead.
#### New Interface Types

- The Interface Type dropdown in the node registration modal now includes **Meshtastic** (experimental, untested) and **AX.25 TNC (KISS)** (experimental, limited testing) alongside MeshCore. Companion bridge adapters are available as separate packages: [awehttam/binktermphp-meshtasticbridge](https://github.com/awehttam/binktermphp-meshtasticbridge) for Meshtastic (TCP or USB serial) and [awehttam/binktermphp-ax25kiss](https://github.com/awehttam/binktermphp-ax25kiss) for AX.25 packet radio.

#### Auto-add policy sync change

- The auto-add contact policy is no longer pushed to the device on every save — a `set_autoadd_config` device command is queued only when the bitmask actually changes.

### Web Interface

- Clicking a username in the web local chat thread now opens a context menu for all users. The first action opens that user's public profile page. Admins still see Kick and Ban in room chat from the same menu.
- Links posted to chat rooms and direct messages are now processed by the inline media player. Images, video files, retro audio files, and platform embeds (YouTube, etc.) render automatically below the link in the chat thread.
- Inline code (`code`) in Markdown-rendered content now renders in the theme's normal text color on all dark themes (dark, amber, greenterm, cyberpunk). Bootstrap's default pink/red code color was difficult to read against dark backgrounds.
- The echomail/netmail compose form now uses the same advanced **Insert Image** picker in Plain text, StyleCodes, and Markdown modes. Uploading, picking a previously uploaded image, pasting from the clipboard, and inserting by URL are now available consistently across all compose modes.
- The **Import Echo Areas** admin page now accepts `.NA` files in addition to CSV. A domain selector lets the sysop choose which configured network applies to all areas in the file, with a Local option for areas with no network domain.
- **CheeseNet** (`cheese`) has been added to the built-in FTN network list. It becomes available in **Admin → Networks** after running `php scripts/setup.php`. No action is required if you are not connecting to CheeseNet.
- The **New Echo Areas** section of the dashboard **Mail & Areas** card now has a **Load More** button. When more than eight areas were created in the past 30 days the button fetches and appends additional pages; it hides itself automatically once all areas are shown.
### Auto Feed

- The Auto Feed script now supports optional reply threading per feed. When **Thread Replies** is enabled for a feed, articles with `RE:`/`Fwd:` subject prefixes are posted as replies to their matching parent message in the echoarea. The lookup depth is configurable. Run `php scripts/setup.php` to apply the required migration.

### Developer Tooling

- The root `CLAUDE.md` contributor guide has been split into subdirectory-scoped files and on-demand skill scripts, reducing context load when working in specific parts of the codebase. No action required for sysops.
- A `session-start.php` script has been added to `.claude/` to print available project skills at the start of each Claude Code session.
- **OpenRouter AI provider**: [OpenRouter](https://openrouter.ai) is now available as a fourth AI service provider. Set `OPENROUTER_API_KEY` in `.env` to enable it. No upgrade action is required for systems not using OpenRouter.
- **OpenAI (Codex) compatibility shims**: `AGENTS.md` has been updated with compatibility shims for OpenAI Codex and other OpenAI-compatible AI coding agents, directing them to follow the same project conventions defined in `CLAUDE.md`.

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

Terminal Local Chat no longer sends terminal bell characters when new messages arrive. Unread rooms and direct-message conversations that are not currently open are instead highlighted in green in the left navigation pane, while the existing unread count badge remains visible.

No post-upgrade admin action is required for this feature. If Local Chat is already enabled in **Admin -> BBS Settings**, it becomes available automatically to terminal users after the upgraded daemons are restarted.

### Terminal Inline Image Viewer Expansion

The terminal message viewer's existing `I` image-viewer flow now covers more than just Markdown image syntax. Markdown messages still support `![alt](url)` inline images as before, and the viewer now also scans regular message bodies for bare direct image URLs ending in `.png`, `.jpg`, `.jpeg`, or `.gif`.

When any supported inline images are found, the message body shows numbered `[Image N: …]` placeholders and pressing `I` opens the same Sixel image viewer used previously. This applies across the shared terminal readers rather than only to Markdown-rendered messages.

For safety and predictable `img2sixel` behavior, the terminal-side URL scan is limited to raster formats known to work in this environment. SVG, WebP, and other extensions are not included in the terminal scan. Hosts that serve valid image files as `application/octet-stream` are now accepted when the URL path ends in one of the supported raster extensions, and the downloaded file is validated before conversion.

If `img2sixel` is already installed, the expanded image-viewer behavior becomes available after the upgraded daemons are restarted.

### SSH Startup Input Drain

Some SSH clients send buffered input bytes immediately during PTY or shell startup. Previously those bytes could reach the shared BBS session as if the user had already pressed keys, causing the terminal UI to skip past login screens, menu screens, or even enter a message viewer unexpectedly.

The SSH daemon now discards any channel-data bytes that are already queued at the moment the shell channel is established, before handing the connection to the shared `BbsSession`. Normal interactive input after startup is unaffected.

No configuration change is required. The fix takes effect when the upgraded SSH daemon is restarted.

### Netmail Sent Folder in Terminal Reader

The terminal netmail message list now includes a Sent folder. From the message list, pressing `S` switches between the Inbox view (all received messages) and the Sent view (messages sent from this system). Pressing `S` again returns to the Inbox. The last-used folder is saved per user and restored the next time they open netmail from the terminal.

When reading a message in the Sent view, the header shows the recipient (`To:`) rather than the sender, since the sender is always the logged-in user. Pressing `R` to reply from the Sent view pre-fills the recipient fields with the original message's addressee rather than the sender.

The daemon restart that follows a normal upgrade is sufficient.

### Netmail Sort Options in Terminal Reader

The terminal netmail message list now supports the same four sort modes as the web netmail UI. Press `O` from the netmail list to choose **Newest first**, **Oldest first**, **By subject**, or **By author**.

The selected sort is stored per user and restored the next time that user opens netmail from the terminal. The same saved sort applies to both Inbox and Sent views.

The change takes effect when the upgraded daemons are restarted.

### Configurable Terminal Idle Timeout

The idle warning and disconnect timeouts for terminal sessions (Telnet and SSH) are now configurable from **Admin → BBS Settings → Terminal Server Settings**. Previously these were hardcoded at 5 minutes to warning and 7 minutes to disconnect; those values are now the defaults and can be changed without editing code or config files. The disconnect timeout must always be set greater than the warning timeout.

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

### Echomail Sort Options in Terminal

The terminal echomail message list now supports the same four list-order choices as the web interface. While viewing messages in an echo area, press `O` to open a centered sort dialog and choose **Newest first**, **Oldest first**, **By subject**, or **By author**.

The selected sort is saved in the user's terminal mail state and restored automatically the next time that user opens an echomail area from the terminal. 
---

### Netmail Address Book / Nodelist Lookup in Compose

When composing a new netmail from the terminal, users can now look up recipients without typing an FTN address from memory. Typing `?` at either the **To Name** or **To Address** prompt opens an interactive address picker.

The picker prompts for a search term, then queries the user's personal address book, matching local BBS users, and the FTN nodelist. Results are merged into a single list — address-book entries and local-user matches appear before nodelist matches, and duplicates from the nodelist are suppressed by FTN address. The list is navigable with the Up/Down arrow keys or by typing a row number; pressing Enter confirms the selection and pressing `Q` returns to the search prompt for a new query.

Selecting an entry pre-fills both **To Name** and **To Address** as editable defaults. The To Name field is populated with the sysop name from the nodelist (not the BBS system name), the stored contact target from the address book, or the matched local username for local BBS user results. Entering `sysop` in the picker returns the configured local system sysop name as a local-delivery target, matching the web compose flow. The user can accept the pre-filled values by pressing Enter or type to override them before proceeding.

The picker is implemented as a reusable `TelnetUtils::runAddressPicker()` widget and responds correctly to terminal resize events.


---

### Netmail Delete in Terminal Reader

Terminal users can now delete a netmail message directly from the message viewer. Pressing `X` or the `Del` key while reading a message opens a centered confirmation dialog. Pressing `Y` deletes the message and returns to the message list; any other key cancels and returns to the message.

Both the sender and the recipient may delete their copy of a message. The same ownership rules that govern deletion in the web interface apply here.

A pre-existing bug in the terminal key decoder has also been fixed as part of this change: the `Del` key sequence (`\033[3~`) was not recognized by the interactive session key reader, causing it to be silently ignored in all full-screen terminal views. The key now works reliably across the entire terminal interface.

The change takes effect when the upgraded daemons are restarted.

---

### Netmail Bookmark in Terminal Reader

Terminal users can now bookmark a netmail message for later reference by pressing `B` while reading it in the terminal netmail viewer. Pressing `B` on an already-saved message removes the bookmark. The status bar label toggles between **Bookmark** (unsaved) and **Unsave** (already saved).

Bookmarked netmail messages appear under the **Saved** filter in the web interface. The feature uses the same bookmark API endpoints as the web UI.

The `B` key is listed in the Ctrl-K help overlay for the netmail viewer. It is not shown on the status bar (which is reserved for primary navigation keys).

The change takes effect when the upgraded daemons are restarted.

---

### Echomail Bookmark in Terminal Reader

Terminal users can now bookmark an echomail message for later reference by pressing `B` while reading it in the terminal message viewer. Pressing `B` on an already-saved message removes the bookmark. A brief confirmation line ("Saved." or "Unsaved.") is displayed after each action.

Bookmarked echomail messages appear under the **Saved** filter in the web interface, alongside saved netmail and other message types. The feature uses the same `POST /api/messages/echomail/{id}/save` and `DELETE /api/messages/echomail/{id}/save` endpoints as the web UI.

The `B` key is listed in the Ctrl-K help overlay for the echomail viewer. It is not shown on the status bar (which is reserved for primary navigation keys).

The change takes effect when the upgraded daemons are restarted.

---

### Netmail Download as Text in Terminal Reader

Terminal users can now download the message they are currently reading as a plain `.txt` file by pressing `T`. The download uses BinktermPHP's built-in ZMODEM implementation and requires a ZMODEM-capable terminal application (e.g. SyncTERM, NetRunner, most terminal emulators with ZMODEM receive support).

The downloaded file contains the message headers (From, To, Date, Subject) and the full message body in plain text, equivalent to the **Download .txt** button available in the web interface.


---

### Echomail Download as Text in Terminal Reader

Terminal users can now download an echomail message as a plain `.txt` file by pressing `T` while reading it in the terminal echomail viewer. The key appears in the Ctrl-K help overlay. The download uses ZMODEM and requires a ZMODEM-capable terminal application. The filename is derived from the message subject.

This matches the equivalent **Download .txt** button in the web interface and mirrors the same feature already available in the netmail viewer.


---

### Forward Netmail to Email in Terminal Reader

Terminal users can now forward a netmail message to their account email address by pressing `E` while reading it in the terminal netmail viewer. The key appears in the Ctrl-K help overlay but not on the status bar. A "Forwarding..." indicator appears immediately while the request is in flight; on completion a colour-coded dialog confirms success (blue) or reports the error (red) and is dismissed with Enter.

This matches the equivalent **Forward to email** option in the web interface. The feature requires outbound email to be configured on the BBS — the same requirement as the web equivalent. No additional sysop configuration is needed beyond that.

---

### Forward Echomail to Email in Terminal Reader

Terminal users can now forward an echomail message to their account email address by pressing `E` while reading it in the terminal echomail viewer. The key appears in the Ctrl-K help overlay but not on the status bar. A "Forwarding..." indicator appears immediately while the request is in flight; on completion a colour-coded dialog confirms success (blue) or reports the error (red) and is dismissed with Enter.

This matches the equivalent **Forward to email** option in the web interface and mirrors the same feature already available in the netmail viewer. The feature requires outbound email to be configured on the BBS. No additional sysop configuration is needed beyond that.

---

### Echomail Search in Terminal

Terminal users can now search echomail messages by keyword from two places.

**Global search** — press `S` from the echoarea list (the screen that shows all your subscribed areas). You are prompted for a search term (minimum two characters). Results from all subscribed areas are shown together in a paginated list; the area tag appears prepended to the From column so you can tell at a glance which area each message came from. Navigate the list with the arrow keys and press Enter to open a message.

**Area-specific search** — press `S` from within a specific area's message list. The search is scoped to that area only and results are shown in the same format as the regular message list for that area, including the Compose key.

When reading a message opened from search results, any occurrence of the search term in the message body is highlighted in white text on a yellow background, consistent with the search result highlighting in the web interface. Navigating to the previous or next message within the result set preserves the highlight.

The change takes effect when the upgraded daemons are restarted.

---

### Netmail Bulk Mark Selected as Read in Terminal

Terminal users can now mark multiple netmail inbox messages as read in one action without opening each one individually.

**How it works:**

- In the netmail inbox list, press `Space` to toggle the highlighted message in or out of the selection set. Selected messages show a green `*` marker to the left of the row.
- Press `M` to mark all selected messages as read. A confirmation prompt appears; pressing `Y` submits the message IDs to the new `POST /api/messages/netmail/read` endpoint. On success the selection is cleared and the list redraws with updated unread indicators.
- Multi-select and the `M` key are available in the **inbox only**. The Sent folder does not expose them.
- `Ctrl-K` opens the key-binding help overlay, which lists both `Space` (Toggle selection) and `M` (Mark selected messages as read).

The change takes effect when the upgraded daemons are restarted.

---

### Ctrl-K Help Overlay in Terminal Message Viewer

The terminal netmail and echomail message viewers now provide a universal keyboard-reference panel accessible with `Ctrl-K`. The overlay draws a framed panel listing every available key binding for the current viewer context — including secondary actions such as header viewer (`H`), delete (`X`), bookmark (`B`), and text download (`T`) that are not shown on the status bar.

The overlay responds to terminal resize events while it is open, and any resize that occurs while the overlay is visible is propagated back to the message viewer when the overlay is dismissed, so the reader always displays at the correct dimensions.

The bottom status bar in both the netmail and echomail readers has been trimmed to the five primary actions (scroll, previous/next, reply, Ctrl-K help, quit). All remaining key bindings are documented exclusively in the Ctrl-K overlay, keeping the status bar readable on narrow terminals.


---

### Echoarea List and Interests Picker Navigation

The echoarea list (the screen showing your subscribed echo areas) and the interests browser (opened with `I` when Interests is enabled) now use the same `runSelectableList` widget used by message lists throughout the terminal server.

**What changed for the echoarea list:**

- **Arrow Up/Down** moves the highlight cursor through the list — no longer need to type a number and press Enter just to move focus
- **Arrow Left/Right** (or `n`/`p`) change pages
- **Enter** selects the highlighted area
- Typing a number jumps the cursor to that row; pressing Enter confirms — the existing number-entry workflow is unchanged
- A **status bar** at the bottom replaces the plain-text navigation hint, matching the style used by message lists
- The list **redraws immediately on terminal resize** without requiring a keypress

The `/` filter, `S` cross-area search, `C` clear-filter, and `I` interests keys continue to work exactly as before; the filter key is now shown on the status bar.

**What changed for the interests browser:**

The interests picker uses the same widget: arrow keys or number+Enter to select an interest, Q to return.

The change takes effect when the upgraded daemons are restarted.

---

### Subscribe and Unsubscribe to Echoareas from Terminal

Terminal users can now subscribe and unsubscribe to echo areas without leaving the terminal, matching the controls available in the web interface.

**Browsing all areas** — press `A` from the echoarea list to switch from your subscribed areas to the full list of available areas on this BBS. Press `A` again to return to your subscribed list. When you switch to all-areas view, each row shows a `[+]` badge if you are subscribed or a `[ ]` badge if you are not.

**Subscribing** — while in all-areas view, navigate to an area you are not subscribed to and press Enter. A dialog appears offering three choices:

- **S) Subscribe & Browse** — subscribes you to the area and then opens its message list
- **B) Browse Only** — opens the message list without subscribing
- **Q) Cancel** — returns to the area list

**Unsubscribing** — press `U` on any area (in either subscribed or all-areas view) to open a confirmation dialog. Confirm with `Y` to unsubscribe; any other key cancels. After a successful unsubscribe the list refreshes automatically.

**Empty subscribed list** — if you have no subscribed areas (for example on a new account), the terminal shows a hint and waits for you to press `A` (browse all) or `Q` (quit) rather than returning immediately. This allows you to discover and subscribe to areas in a single flow.

`GET /api/echoareas` now includes a `subscribed` boolean field on each area object, indicating whether the authenticated user is currently subscribed. Clients that do not use this field are unaffected.

The change takes effect when the upgraded daemons are restarted.

---

### Terminal File Browser Selector and File Info Modal

The terminal file browser now matches the selector-style interaction already used by echomail. The file area list and the per-area file list both support arrow-key movement, Left/Right page changes, Enter to open the highlighted row, and status-bar shortcuts for download, upload, and moving up a folder when applicable.

Opening a file from the terminal browser now shows a centered file-info modal instead of a plain full-screen text page. The modal redraws cleanly on terminal resize, keeps long descriptions scrollable with Up/Down or PgUp/PgDn, and still allows direct `D` download from the detail view.

The change takes effect when the upgraded daemons are restarted.

---

### Cross-post Echomail to Multiple Areas from Terminal

Terminal users can now cross-post a new echomail message to multiple subscribed areas in a single operation, matching the capability already available in the web interface.

When composing a **new** message (not a reply), the terminal compose flow asks "Cross-post to other areas? [y/N]:" immediately after displaying the destination area. Answering `y` opens a checkbox picker showing all other subscribed areas. Up/Down arrows navigate the list, Space toggles selection, Enter confirms the selection, and Q skips cross-posting entirely. Scroll indicators (▲/▼) appear when the list overflows the dialog height. The number of additional areas is limited by the **Max cross-post areas** BBS setting (configurable in Admin → BBS Settings; default 5).

If the user resizes the terminal while the cross-post picker is open, the compose-flow background is now cleared and redrawn before the dialog is re-centered. This prevents stale dialog content or underlying area-list output from remaining visible after a resize.

No migration or configuration change is required. The cross-post limit is already stored in `bbs.json` as `max_cross_post_areas` and defaults to 5 if absent.

---

### Terminal Full-Screen Editor Visual Refresh

The shared terminal full-screen editor now matches the visual language used by the other terminal overlays and dialogs. Instead of the older separator-line layout, it renders inside a framed blue panel with a titled top border, bordered compose area, and footer shortcut row.

The editor `Ctrl-K` help view now uses the same framed dialog treatment instead of dropping to an unframed text page. This keeps compose, help, and the rest of the terminal UI visually consistent.

The editor also redraws against the current terminal size when the user resizes the window while composing, so the frame and text area stay aligned with the new dimensions.

The change takes effect when the upgraded Telnet and SSH daemons are restarted.

---

### Terminal Message Drafts

Terminal netmail and echomail compose now share the existing draft backend used by the web interface.

When a user starts a new compose flow in the terminal and saved drafts already exist for that message type, the terminal first prompts to **Resume Draft**, **New Message**, or **Cancel**. Choosing resume opens a selector-style drafts picker with the same keyboard navigation as the other terminal lists. Pressing `X` in that picker deletes the highlighted draft after confirmation.

Inside the full-screen editor, `Ctrl+S` now saves the current message as a draft and keeps the user in the editor so they can continue writing. `Ctrl+Z` remains the send key. If the user resumed an existing draft and then sends successfully, that draft is deleted automatically so stale copies do not accumulate.

The feature becomes available after the upgraded Telnet and SSH daemons are restarted.

---

### Echomail Ignore Rules in Terminal

Two new terminal actions let users manage echomail ignore rules without leaving the BBS session.

**G in the echomail message viewer** — while reading a message, pressing `G` opens an overlay with options to ignore the sender by name only, by name and FTN address, or by name, address, and a subject keyword. The subject keyword field is pre-filled with the current message subject. Any combination of the three fields is submitted to the existing `/api/messages/echomail/ignore-rules` endpoint, matching the behaviour of the web ignore-rule modal.

**G on the echoarea list** — pressing `G` on the echoarea list opens a paginated ignore-rules management screen showing all of the user's current ignore rules. Selecting a rule and pressing `Enter` or `D` prompts for confirmation before deleting it via `DELETE /api/user/echomail-ignore-rules/{id}`.

Both actions become available after the upgraded daemons are restarted.

---

### Terminal Resize Repaint

Full-screen terminal surfaces now register a repaint closure in `$state['repaint_fn']` while they are active. Overlays (confirm dialogs, alert dialogs, input dialogs) call this closure on terminal resize before redrawing themselves, so the background surface is repainted cleanly at the new dimensions rather than leaving stale content behind. The pattern applies to the echomail and netmail message viewers, the selectable list widget used for the echoarea list and ignore-rules screen, and all overlay dialog types.

Sysop action: none. The change is internal to the terminal rendering stack.

---

### Ctrl-C Cancels Compose Prompts

Pressing `Ctrl-C` at any field prompt during netmail or echomail compose now immediately aborts the compose session and returns to the previous screen. Previously `Ctrl-C` was passed through as a literal character. The line reader now treats byte `0x03` as a cancellation signal, echoing `^C` and returning `null` to the caller, which the compose handlers already handle as an abort.

---

### Terminal Who's Online Popup and Public Profile Viewer

The terminal **Who's Online** main-menu action now opens a centered selectable popup instead of rendering a plain scrolling text list. The popup shows currently active users from the existing `/api/whosonline` endpoint, and pressing Enter on a highlighted user opens a read-only terminal profile viewer.

The new terminal public-profile viewer displays only public fields: **username**, **full name**, **location**, and **biography**. It is implemented as a reusable terminal viewer rather than a one-off Who's Online screen so other terminal features can link to public user profiles later without duplicating UI code.

To support this, the API now also exposes `GET /api/user/public-profile/{id}` for authenticated callers. The endpoint returns only the public profile fields needed by the terminal viewer and does not include private account data.

The feature becomes available after the upgraded Telnet and SSH daemons are restarted.

---

### DOS Door Return Path Stability

The DOS door relay now restores the telnet client socket to its pre-door blocking mode before control returns to the regular terminal session. This keeps prompt-driven terminal flows operating normally after a door exits, including reopening the door menu, entering the nodelist browser, and using other line-input screens from the main menu.

The change takes effect when the upgraded Telnet daemon is restarted.

---

### Terminal Interface Style Preference

Users can now choose their terminal interface style from terminal settings. The setting appears on the **Terminal** tab under **Interface Style** and offers three options:

| Option | Behaviour |
|--------|-----------|
| **Full-screen TUI (default)** | Uses the full-screen TUI unless the sysop's system default is set to line mode |
| **Full-screen TUI (always)** | Always uses the full-screen TUI regardless of the system default |
| **Line mode** | Always uses the line-mode shell |

**Sysop controls** — a new **Terminal Server Settings** section in **Admin → BBS Settings** (previously labelled **Terminal Idle Timeout**, which remains in the same section) adds two options:

- **Default interface style** — sets the system-wide default shell (Full-screen TUI or Line mode) used when a user's preference is **Auto** (the default for new users and existing users who have not changed the setting).
- **Force shell** — when checked, every terminal session uses the system default regardless of the user's own preference. Individual user settings are ignored while this is enabled.

The priority order is: Force shell (sysop) → explicit user preference (TUI or Line) → system default (Auto).

Line mode now has its own shell-native settings container instead of reusing the ANSI tab widget directly. Users who choose **Line mode** from terminal settings will see a section picker and per-section field lists that follow the line shell's normal prompt-driven interaction model, while TUI users keep the full tabbed screen. The terminal detection wizard intentionally remains a plain prompt flow across all shells because it must still work before shell-specific rendering can be trusted. Separately, turning ANSI color off no longer forces ASCII-only main-menu borders on UTF-8 or CP437 terminals; those sessions now keep their normal monochrome line-drawing characters.

No migration is required. The idle timeout fields remain in the same form as before under the renamed heading. The daemon restart that follows a normal upgrade is sufficient.

---

### Terminal Shell Allowlist and Plugin Registration

Terminal shell availability is now controlled by the `.env` variable `TERMSERVER_ALLOWEDSHELLS`. The value is a space-separated allowlist of registered shell IDs such as `tui`, `tui line`, or `tui retroglass`.

If the variable is unset or empty, BinktermPHP defaults to `tui` only. The terminal settings screen and the sysop-facing default shell selector both hide any shell mode that is not currently allowed. If an older saved user preference refers to a shell that is no longer allowed, the session now falls back safely to the TUI shell instead of failing to honor the preference in unpredictable ways.

Custom terminal shells can now be added without editing the built-in factory directly. Plugin definition files placed in `telnet/shells/*.plugin.php` are discovered automatically and may register additional shell IDs and classes. This allows local deployments to add custom terminal shells while still using the same central allowlist and validation path.

The shared terminal shell style profile is also now threaded through more of the common terminal UI, including the main menu, tabbed settings screens, inline text-entry prompts, and editor overlays. Shell plugins that override the style profile should now render more consistently across the shared interface instead of falling back to the default blue palette in those screens.

**Upgrade action:** if you want to expose more than the built-in TUI shell after upgrading, set `TERMSERVER_ALLOWEDSHELLS` in your `.env` file and restart the Telnet and SSH daemons. Custom shells must be registered through `telnet/shells/*.plugin.php` before they can be added to the allowlist.

---

### Empty Echomail Area Entry

Entering an echomail area that contains no messages now opens the message list interface rather than immediately returning the user to the echoarea list. The area title and status bar are displayed so the user can press `C` to compose a new message or `Q` to return.

No migration or configuration change is required.

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

**AX.25 TNC (KISS)** connects BinktermPHP to AX.25 packet radio via a hardware or software TNC (e.g. Direwolf). Registering a node with this type sets the output profile to 8 lines × 64 columns per page, tuned for packet radio frame sizes. The companion bridge adapter is available at [awehttam/binktermphp-ax25kiss](https://github.com/awehttam/binktermphp-ax25kiss). This adapter is experimental with limited testing.

No BBS migration is required for either type; the interface type string is stored as-is on the existing `packet_bbs_nodes` table.

### Auto-add policy sync change

Previously, saving a MeshCore node always queued a `set_autoadd_config` device command, even when nothing changed. The modal now tracks the policy bitmask at open time and only queues the device command when the value actually changes at save time. The "Saved — waiting for bridge to apply to device" confirmation is shown only when a command was queued.

---

## Web Interface

### Chat Profile Popup

Clicking a username in the web local chat thread now opens a small context menu for every user, not just admins.

The first menu item is **View Profile**, which opens the clicked poster's public profile page. This applies to both room messages and direct-message threads.

For admins in room chat, the same menu still includes **Kick** and **Ban**. For regular users, only the profile action is shown.

The change takes effect as soon as the updated JavaScript and service-worker cache are deployed.

### Chat Inline Media

Links posted to chat rooms and direct messages are now processed by the inline media player. Recognized content renders automatically below the link in the chat thread — no clicks required, regardless of the user's personal **Media Render Mode** preference in their web settings (that preference governs echomail and netmail only).

Supported content types:

| Category | Formats / Providers |
|---|---|
| Images | `.png` `.jpg` `.jpeg` `.gif` `.webp` `.svg` and known image CDN prefixes |
| Video | `.mp4` `.webm` `.ogv` `.mov` |
| Retro audio | `.sid` `.mod` `.xm` `.it` `.s3m` `.stm` `.amf` `.669` `.mptm` `.midi` |
| Platform embeds | YouTube, Odysee, BitChute, Brighteon, PeerTube, Rumble, SoundCloud, Twitter/X, TikTok, ReverbNation, Bastyon |

The global **Admin → BBS Settings → Media Player** toggle still controls whether the media player is active at all; if it is disabled site-wide, chat media rendering is also disabled.

### Dark Theme Inline Code Color

Bootstrap 5's default color for inline `code` elements (`#d63384`, a pink/red) is difficult to read against dark backgrounds. The dark, amber, greenterm, and cyberpunk themes now override this with the theme's normal text color.


### Bulletin Viewer Rendering

The bulletin viewer now renders plain-text bulletin bodies using the same preformatted style as the message reader. Text wraps to the container width rather than overflowing into a horizontal scrollbar, and the font has been updated to the system UI monospace stack (Cascadia Code on Windows 11, SF Mono on macOS, Consolas on older Windows) instead of Courier New.

### Ad Content Manager Ruler Fix

The column ruler above the ANSI editor textarea in **Admin → Ads and Bulletins → Content Manager** was disappearing after saving an advertisement. The ruler is rendered by a dynamically inserted `<textarea>`, and `form.reset()` — called as part of closing the editor on save — was resetting its content to an empty string because no default value had been set. After this fix the ruler persists correctly across saves without requiring a page reload.

No upgrade action is required.

---

### Echo Areas .NA File Import

The **Import Echo Areas** page in the admin interface now accepts `.NA` files in addition to CSV. A `.NA` file lists echo areas one per line with the tag and description separated by whitespace — the format distributed by many FTN networks alongside their nodelist.

The import page now has two tabs: **CSV File** (unchanged) and **.NA File**. When importing a `.NA` file, a domain selector lets the sysop choose which configured network applies to all areas in the file. The dropdown lists every network domain already set up in **Admin → Networks**, plus a **Local (no domain)** option for areas with no network assignment.

Import semantics are identical to the CSV importer: existing areas with matching tag and domain have their description updated and are reactivated; new areas are created active with default settings; lines beginning with `%` or `;` are treated as comments and skipped.

No migration is required. The feature becomes available as soon as the updated files are deployed.

---

### CheeseNet Network Added

CheeseNet (`cheese`) has been added to the built-in FTN network list. It is a general-community FTN network open to both human and AI participants, with echo areas covering BBS meta, creative pursuits, current events, debate, and more. The network's home page is at [https://futureland.today/cheesenet](https://futureland.today/cheesenet).

Running `php scripts/setup.php` adds a `cheese` row to the `networks` table. CheeseNet then appears in **Admin → Networks** and is available as a domain target when importing echo areas or configuring an uplink. No action is required if you are not connecting to CheeseNet.

---

### New Echo Areas Load More

The **New Echo Areas** section at the bottom of the dashboard **Mail & Areas** card now includes a **Load More** button. The button sits on the left side of the same row as the **Manage Subscriptions** and **Manage Interests** navigation links.

When more than eight echo areas have been created in the past 30 days, the button is visible and fetches the next page of results, appending them to the list. It hides itself automatically once all areas have been loaded.

The feature becomes available as soon as the updated files are deployed.

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

`AGENTS.md` has been updated with compatibility shims for OpenAI Codex and other OpenAI-compatible AI coding agents, directing them to the same project conventions and workflows defined in `CLAUDE.md` so contributors using either toolchain follow a similar development process. Note that Codex does not load Claude Code skill files directly — the equivalent of a slash command is invoked through natural language instead (e.g. Claude Code: `/tackleissue 42`; Codex: *"let's tackle issue 42"*).


---

## Auto Feed

### Reply Threading

The Auto Feed script (`scripts/rss_poster.php`) can now thread reply messages under their parent posts when a feed comes from a source — such as groups.io — that uses `RE:` subject prefixes to indicate replies.

Threading is **opt-in per feed**. To enable it, open the feed in **Admin → Auto Feed**, check **Thread Replies**, and set a **Thread Lookup Limit** (default 1000). The lookup limit controls how many recent echomail messages in the target area are scanned when searching for a parent; raise it for high-volume areas, lower it to reduce scan overhead.

**How it works:** when the script posts an article whose subject begins with a reply prefix (`Re:`, `RE:`, `Fwd:`, `FW:`, or similar — multiple stacked prefixes are handled), it strips the prefix(es), looks up the most recent message in the echoarea with a matching base subject, and sets that message as the `reply_to_id`. If no parent is found the article is posted as a top-level message instead.

Within a single run, articles are posted oldest-first so that a parent message lands in the database before any replies from the same batch, enabling same-run threading for burst imports.

**Migration:** run `php scripts/setup.php` to apply the migration that adds `thread_replies` and `thread_lookup_limit` columns to `auto_feed_sources`. Existing feeds default to threading disabled and require no other change.

---

### OpenRouter AI Provider

[OpenRouter](https://openrouter.ai) is now a supported AI service provider alongside OpenAI, Anthropic, and Ollama. It acts as a gateway to many upstream models through a single API key, and includes an `openrouter/auto` routing mode that selects the best available model automatically.

To enable it, add to `.env`:

```ini
OPENROUTER_API_KEY=sk-or-...
OPENROUTER_DEFAULT_MODEL=openrouter/auto
OPENROUTER_SUPPORTS_TOOLS=true
AI_DEFAULT_PROVIDER=openrouter
```

OpenRouter returns the actual USD cost of each request in the response body, so cost tracking works automatically for all models including `openrouter/auto` — no pricing env vars are needed. See `docs/AIProviders.md` for full configuration details.

No upgrade action is required for systems not using OpenRouter.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
