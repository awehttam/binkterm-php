# Upgrading to 1.8.9

⚠️ Make sure you've made a backup of your database and files before upgrading.

## Table of Contents

- [Introduction](#introduction)
- [Summary of Changes](#summary-of-changes)
- [Interests](#interests)
  - [Overview](#overview)
  - [Admin Management](#admin-management)
  - [User Interest Picker](#user-interest-picker)
  - [First-Time Onboarding](#first-time-onboarding)
  - [Echo Area List Integration](#echo-area-list-integration)
  - [Subscription Mechanics](#subscription-mechanics)
  - [Multi-Interest Source Tracking](#multi-interest-source-tracking)
  - [AI-Assisted Interest Generation](#ai-assisted-interest-generation)
  - [Activity Statistics: Popular Interests Tab](#activity-statistics-popular-interests-tab)
  - [Feature Flag](#feature-flag)
- [Echomail & Netmail](#echomail--netmail)
  - [Compose Message Size Warning](#compose-message-size-warning)
  - [Sender Name Popover Style](#sender-name-popover-style)
  - [Advanced Search: Message ID Field](#advanced-search-message-id-field)
  - [Show Entire Conversation](#show-entire-conversation)
  - [Message List Context Menu](#message-list-context-menu)
  - [Ignored Echomail Messages](#ignored-echomail-messages)
  - [Ignored Echomail: Multi-Address Rule Fix](#ignored-echomail-multi-address-rule-fix)
  - [Markdown Image Support](#markdown-image-support)
  - [Message Viewer: Raw Source Mode](#message-viewer-raw-source-mode)
  - [Pipe Code False Positive Fix](#pipe-code-false-positive-fix)
- [QWK Offline Mail](#qwk-offline-mail)
  - [HTTP Basic Auth Endpoints](#http-basic-auth-endpoints)
  - [FTP Access](#ftp-access)
  - [Conference Area Selection](#conference-area-selection)
- [BinkP Configuration](#binkp-configuration)
  - [Poll Schedule Builder](#poll-schedule-builder)
  - [Status Page Uplink Checks](#status-page-uplink-checks)
  - [Queue Packet Viewer](#queue-packet-viewer)
  - [Scheduler](#scheduler)
  - [Insecure Session Enhancements](#insecure-session-enhancements)
  - [BinkP Session Log Coverage](#binkp-session-log-coverage)
  - [BinkP Session Log Details and Log Viewer](#binkp-session-log-details-and-log-viewer)
  - [BinkP Session Log Retention](#binkp-session-log-retention)
- [Echomail MCP Server](#echomail-mcp-server)
  - [Per-User Bearer Keys](#per-user-bearer-keys)
  - [Daemon Mode and Reverse Proxy Support](#daemon-mode-and-reverse-proxy-support)
  - [Encoding Fix](#encoding-fix)
- [Dashboard](#dashboard)
  - [Dynamic Advertisement Content](#dynamic-advertisement-content)
  - [Today's Callers Table](#todays-callers-table)
  - [Active BinkP Sessions Card](#active-binkp-sessions-card)
- [Admin Help](#admin-help)
  - [In-App FAQ and README Viewer](#in-app-faq-and-readme-viewer)
- [File Areas](#file-areas)
  - [Upload Approval Queue](#upload-approval-queue)
  - [Activity Statistics Exclude Private File Areas](#activity-statistics-exclude-private-file-areas)
  - [ISO Mount Point Restriction](#iso-mount-point-restriction)
- [Broadcast Manager](#broadcast-manager)
  - [Clone Campaign](#clone-campaign)
- [Registration Page](#registration-page)
- [User Settings](#user-settings)
  - [Tabbed Layout](#tabbed-layout)
  - [Notification Sound Preview](#notification-sound-preview)
  - [Ignored Echomail Management](#ignored-echomail-management)
  - [Markdown Image Load Preference](#markdown-image-load-preference)
- [Appearance](#appearance)
  - [Terminal Server Screens](#terminal-server-screens)
  - [Shared ANSI Editor](#shared-ansi-editor)
- [Real-time Events (BinkStream)](#real-time-events-binkstream)
  - [SharedWorker Architecture](#sharedworker-architecture)
  - [Chat Integration](#chat-integration)
  - [Long-lived Connections](#long-lived-connections)
  - [User and Admin Targeting](#user-and-admin-targeting)
  - [php-fpm Worker Capacity](#php-fpm-worker-capacity)
  - [BinkStream Test Tools](#binkstream-test-tools)
  - [Real Time Server - Recommended Daemon](#real-time-server---recommended-daemon)
  - [Dashboard Stats Push](#dashboard-stats-push)
  - [Cross-Tab Read Sync](#cross-tab-read-sync)
  - [File Approval Queue Notifications](#file-approval-queue-notifications)
  - [Admin File Menu Dropdown](#admin-file-menu-dropdown)
- [AreaFix / FileFix Manager](#areafix--filefix-manager)
  - [Overview](#areafix-overview)
  - [Quick Actions](#quick-actions)
  - [Subscribe and Unsubscribe](#subscribe-and-unsubscribe)
  - [Latest Reply Panel](#latest-reply-panel)
  - [Message History](#message-history)
  - [Subject Masking](#subject-masking)
  - [Uplink Password Fields](#uplink-password-fields)
  - [History Table Improvements](#history-table-improvements)
- [Advertising Improvements](#advertising-improvements)
  - [Content Command Whitelist and Dropdown](#content-command-whitelist-and-dropdown)
  - [Content Command Parameters](#content-command-parameters)
  - [Click-through URLs and Impression Tracking](#click-through-urls-and-impression-tracking)
  - [Ad Analytics Admin Page](#ad-analytics-admin-page)
  - [Ad Title File-type Prefix](#ad-title-file-type-prefix)
  - [ANSI Editing Workflow](#ansi-editing-workflow)
- [AI Provider Layer](#ai-provider-layer)
- [MRC Chat](#mrc-chat)
  - [Join Command](#join-command)
- [Docs Viewer: HTML Pass-through](#docs-viewer-html-pass-through)
- [Telnet / SSH BBS Server](#telnet--ssh-bbs-server)
  - [System News and Recent Shoutbox Flow](#system-news-and-recent-shoutbox-flow)
  - [Interests Menu](#interests-menu)
  - [QWK Offline Mail via ZMODEM](#qwk-offline-mail-via-zmodem)
  - [Echomail Interest-Based Browsing](#echomail-interest-based-browsing)
- [CLI Tools](#cli-tools)
  - [fix_date_received.php](#fix_date_receivedphp)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

---

## Introduction

Version 1.8.9 is a broad feature release touching nearly every part of the system.

The headline addition is the **Interests** system — a way for admins to bundle related echo areas and file areas into named topic groups that users can subscribe to in one step. A card-based interest picker guides new users through onboarding, and the echomail reader gains an Interests tab and area list filter so readers can stay focused on what they care about.

**Real-time delivery (BinkStream)** is introduced in this release. A SharedWorker owns one persistent transport connection per logged-in user, shared across all open browser tabs, using WebSocket where available and SSE as a fallback. Unread badge counts for echomail, netmail, files, and the file approval queue are pushed from the server the moment something changes — driven by PostgreSQL triggers — eliminating client-side polling for these counts entirely. Message-list views refresh silently in the background without showing a loading spinner, and marking a message as read in one browser tab is immediately reflected in every other open tab. The realtime server is now a core daemon listed alongside the BinkP server in startup documentation and the admin dashboard.

**File areas** gain a proper upload approval workflow. Non-admin uploads land in a pending queue that sysops can review, scan, approve, or reject from a dedicated admin page. Admins see a live notification badge on the Files menu the moment a new upload is waiting, and the badge persists its seen state across page loads. The Files nav item for admins becomes a dropdown that surfaces the File Approvals queue alongside the regular file browser.

**Messaging** improvements include a right-click context menu on echomail and netmail list rows (with long-press support on touch devices), a Show Entire Conversation mode, a Raw Source viewer mode for inspecting wire content, and a compose-time warning when a message approaches the FidoNet 16 KB limit. QWK offline mail adds HTTP Basic Auth endpoints for scripted access, and users can now choose exactly which echo areas go into their QWK packets.

**BinkP configuration** gains several operational improvements. A visual schedule builder simplifies writing cron expressions for poll schedules. Each uplink on the BinkP page has an on-demand connectivity check button that runs a lightweight auth-only probe. Packet filenames in the inbound and outbound queues are now clickable, opening the same packet inspector available on the Kept Packets tab. The scheduler now respects each uplink's configured poll schedule when deciding whether to poll on outbound activity, preventing unnecessary connections to the upstream hub. Session logging is expanded to cover normal inbound and outbound sessions in addition to crash mail, making the BinkP Sessions page and dashboard widgets useful for day-to-day monitoring.

The **Telnet/SSH BBS server** gains several features tied to this release's major additions. When Interests are enabled, a new Interests menu lets BBS users browse and subscribe from the terminal. The QWK Offline Mail menu now supports full ZMODEM file transfer for both download and upload alongside the existing HTTP download URL. The echomail area picker gains an option to filter areas by Interest.

The **AreaFix / FileFix Manager** is a new admin tool for managing echo area subscriptions with the upstream hub's robots — sending commands and reviewing replies — all without touching a mail client.

For AI-curious sysops, an optional **MCP Server** exposes echomail to AI assistants such as Claude via the Model Context Protocol, with per-user bearer keys and the same access controls as the web interface. A new abstracted **AI Provider Layer** backs the Interests suggestion wizard and area classifier, supporting both Anthropic and OpenAI.

Rounding out the release: a tabbed User Settings layout, notification sound previews, admin dashboard improvements including a live Active BinkP Sessions card and a redesigned Today's Callers table, ad click-through tracking and analytics, a campaign clone action in the Broadcast Manager, and miscellaneous bug fixes and improvements including a pipe code false-positive that was rendering English words with green backgrounds.

---

## Summary of Changes

**Interests**
- Admins define named topic groups (Interests) by bundling echo areas and file areas together. Users subscribe to an interest with a single click and are automatically enrolled in all its member areas.
- Admin management at `/admin/interests`: create, edit, reorder, and activate/deactivate interests; assign areas; configure icon and color; and use the **Generate Suggestions** wizard for keyword-based or AI-assisted grouping.
- User interest picker at `/interests`: card-based subscribe flow with optional per-area selection.
- The echomail reader includes an **Interests** tab and area list filter for a focused reading experience.
- New users are guided through interest selection during onboarding. Activity Statistics includes a **Popular Interests** tab.
- Controlled by `ENABLE_INTERESTS` in `.env`.

**Echomail & Netmail**
- Message lists now support a right-click context menu (long-press on mobile) with actions including **View Conversation**, **Save for later**, **Download Message**, **Forward by EMail**, and **Share**.
- Echomail message lists now include an **Ignore message** action. It creates per-user ignore rules that match the exact sender name, the exact sender node address, and optionally a substring in the subject line. Leaving the subject blank blocks that sender entirely.
- Each distinct combination of sender name, node address, and subject is stored as its own independent ignore rule. A sender posting from multiple node addresses under the same name can be blocked per address or all at once.
- Markdown messages now render `![alt](url)` image syntax as a click-to-load placeholder instead of displaying a raw exclamation mark and hyperlink. Clicking the placeholder fetches and displays the image inline. The Markdown toolbar in compose gains an **Insert Image** button that opens a dialog for entering an image URL, uploading an image file, or selecting a previously uploaded image. Uploaded images are stored privately under the user's file area and served via a stable URL on this BBS (`/echomail-images/{hash}`). Those URLs always use the configured `SITE_URL` so they resolve correctly when messages are read on other systems.
- A **Show Entire Conversation** mode loads the full thread when clicking the reply icon, not just the messages on the current page.
- The **A** key cycle now includes a **Raw Source** mode showing message bytes verbatim — useful for inspecting wire content.
- Messages explicitly marked as **Plain Text** now bypass ANSI and pipe-code rendering completely.
- The compose form warns when approaching the 16 KB FidoNet message body limit.
- Fixed a pipe code false-positive that rendered English words like `|Advertise` with a green background. Detection now requires uppercase letters, matching real BBS software.

**QWK Offline Mail**
- QWK download and REP upload are now available via HTTP Basic Auth at `/qwk/download` and `/qwk/upload` for use with external offline-mail tools.
- A standalone FTP daemon (`scripts/ftp_daemon.php`) is available for scripted QWK access and file transfers. It is disabled by default and must be enabled explicitly with `FTPD_ENABLED=true`.
- Users can choose exactly which echo areas appear in their QWK packets via a **Conference Areas** picker.

**User Settings**
- The settings page is reorganized into a tabbed layout: **Display**, **Messaging**, **Notifications**, and **Account**.
- Notification sound select boxes now have a **▶** button to preview sounds without leaving the page.
- The **Messaging** tab now ends with an **Ignored Echomail** section where users can review and remove saved echomail ignore rules.
- The **Messaging** tab includes an **Image Loading** preference: images in Markdown messages can be set to load automatically or only on tap (default: tap to load).

**Appearance**
- The BBS menu shell's `ansi` variant now supports size presets: `80x25`, `132x24`, `132x43`, `132x50`, and `Full Screen`.
- `80x25` is the authentic baseline terminal presentation. `Full Screen` instead scales the rendered ANSI art to the available browser viewport below the shell header.
- A new **Term Server** tab in **Admin -> Appearance** lets sysops edit or upload the supported custom ANSI screens used by the telnet and SSH terminal servers.
- ANSI-capable admin editors now share a reusable editor widget with modal preview, an ANSI cheatsheet, and a 132-column ruler for alignment work.

**Echomail MCP Server**
- An optional [Model Context Protocol](https://modelcontextprotocol.io/) server (`mcp-server/`) gives AI assistants read-only access to your echomail. Each user generates a personal bearer key from **Settings → AI**. See `docs/MCPServer.md` for setup.

**File Areas**
- Non-admin uploads are placed in a pending approval queue. Sysops review, scan, approve, or reject uploads from **Admin → File Approvals**.
- Users have a **My Uploads** view in `/files` showing pending, approved, and rejected uploads with status badges.
- Admins see a live notification badge on the Files menu when uploads are awaiting approval. The badge clears on visiting the approvals page and persists its seen state across page loads.
- The Files nav entry for admins is now a dropdown containing **Files** and **File Approvals**.
- The **Activity Statistics** page now excludes private file areas from its file-activity reports so public/admin-facing download and browse totals do not reveal private-area usage.
- ISO file-area mount points entered through the web interface or API must now stay under `data/iso_mounts`. If you need a custom external mount path, set it directly in the database after upgrade.

**Real-time Events (BinkStream)**
- Unread badge counts for echomail, netmail, files, and the file approval queue are now pushed from the server the moment something changes — no more client-side polling.
- Message lists update silently in the background without a loading spinner.
- Marking a message as read in one tab immediately reflects in all other open tabs.
- `realtime_server.php` is now a core daemon (alongside `binkp_server.php`) and appears in the Admin dashboard service status panel. **`pm.max_children` must be sized for your expected concurrent user count** — see [CONFIGURATION.md — Server Sizing & Tuning](CONFIGURATION.md#server-sizing--tuning).

**AreaFix / FileFix Manager**
- New admin tool at `/admin/areafix` for managing echo area subscriptions with the upstream hub's robots. Quick-action buttons send common commands with one click; incoming replies are displayed in a Latest Reply panel.
- AreaFix/FileFix message subjects are masked to `••••••••` in all views to protect the password.
- `areafix_password` and `filefix_password` fields added to the BinkP uplink editor.

**Advertising**
- Content commands are now restricted to an approved whitelist; the admin editor uses a dropdown instead of free text.
- Ads now support a **Click-through URL**. Impressions and clicks are tracked and displayed on a new **Ad Analytics** page at `/admin/ad-analytics`.
- Uploaded ad files are auto-prefixed with `[ANSI]`, `[RIP]`, or `[SIXEL]` based on file type.
- The ad content editor now uses the shared ANSI editor component, giving it a richer sequence helper, modal preview, cheatsheet, and column ruler.

**AI Provider Layer**
- A new AI provider layer supports both OpenAI and Anthropic as backends for AI-assisted features. An admin usage report is available at `/admin/ai-usage`. See `docs/AIProviders.md`.

**BinkP Configuration**
- Poll schedule inputs now have a **schedule builder** that breaks a cron expression into editable fields with a human-readable description.
- The BinkP status page includes options to check uplinks for connectivity status.
- Packet filenames in the queue lists are now clickable and open the packet inspector modal.
- Uplinks have a new **Allow insecure echomail delivery** option for legacy nodes that cannot authenticate. ⚠️ Not recommended — use only as a last resort.
- The **BinkP Session Log** now records normal outbound poll sessions and inbound server sessions, not just crash mail activity.
- BinkP session records now include the handling process ID and log filename basename, and the admin sessions table can open the matching log lines for a session in a modal.
- `scripts/database_maintenance.php` now purges old `binkp_session_log` rows after 30 days by default. Retention is configurable with `BINKP_SESSION_LOG_RETENTION_DAYS`.

**Telnet / SSH BBS Server**
- After login, telnet users now see **SYSTEM NEWS** from `data/systemnews.md` rendered in a framed terminal screen before the recent shoutbox. The read-only recent shoutbox screen now also offers a quick `S` shortcut to post a shout before continuing.
- Interests menu (`I`) added for browsing and subscribing to topic groups.
- QWK Offline Mail (`K`) now supports ZMODEM file transfer for download (`D`) and upload (`U`) using a built-in PHP ZMODEM implementation — no external tools required.

**Dashboard**
- Content-command-backed advertisements now render correctly in all views.
- **Today's Callers** is now a table with User, Time, and Online columns.
- Admin dashboards now include an **Active BinkP Sessions** card that refreshes automatically as session progress changes.

**Broadcast Manager**
- Campaign editor now has a **Clone** action to duplicate a campaign as a new disabled copy.

**Registration**
- Registration page now advises applicants to expect an approval email and check their spam folder.

**MRC Chat**
- `/join <room>` slash command added to the MRC chat input.

**CLI Tools**
- New `scripts/fix_date_received.php` resets `date_received` to `date_written` for echomail rows in specified areas. Useful after a `%RESCAN` import.

---

## Interests

### Overview

Interests are admin-defined topic groups that bundle echo areas (and optionally file areas) under a named theme. Users subscribe to an interest with a single click and are automatically subscribed to all its member echo areas, removing the need to hunt through hundreds of individual areas.

Two database migrations are included: `v1.11.0.49_interests.sql` (core schema) and `v1.11.0.50_interest_echo_sources.sql` (source-tracking table). Both run automatically via `php scripts/setup.php`.

### Admin Management

Admins manage interests at `/admin/interests`. Each interest has:

- **Name** and **slug** (URL-friendly identifier, auto-generated from the name)
- **Description** shown to users on the interest card
- **Icon** — any FontAwesome class (e.g. `fa-gamepad`)
- **Color** — hex accent color shown on the card and in the area list
- **Sort order** — controls display order on the picker page
- **Active flag** — inactive interests are hidden from users

Echo areas and file areas are assigned via a searchable list. Saving a new echo area list automatically propagates new areas to all existing interest subscribers.

### User Interest Picker

Active interests are shown at `/interests` as a card grid. Each card shows the icon, color accent, name, description, and echo area count. A Subscribe button on each card subscribes the user to all member echo areas at once. A details view lets the user pick individual areas before subscribing.

The **Interests** link appears in the user dropdown menu only when the feature is enabled and at least one active interest exists.

### First-Time Onboarding

When a user visits `/echomail` for the first time after Interests is enabled and they have no interest subscriptions, they are redirected to `/echo-onboarding` first. That guide introduces echomail, explains how the network works, and then sends the user on to `/interests`. The onboarding redirect happens only once unless the user later resets the onboarding flag from Settings.

A **Go to Echo Areas** button at the bottom of the `/interests` page takes users directly back to `/echomail` after they have finished subscribing.

### Echo Area List Integration

The echomail reader (`/echomail`) now shows an **Interests** tab in both the desktop sidebar card and the mobile "Viewing" accordion. The Interests tab appears first and is active by default when interests exist.

Selecting an interest from the tab loads a unified, paginated message feed from all its echo areas — the same view used for individual echo areas, with the same filter and sort controls.

The **Manage Subscriptions** button (mobile) and the wrench icon link (desktop) swap to **Manage Interests** and link to `/interests` while the Interests tab is active, and revert to **Manage Subscriptions** → `/subscriptions` when the Area List tab is active.

The reader remembers the user's last selected **Area List** or **Interests** tab using the `p_listorinterest` cookie. When an interest is selected from the Interests tab, the Area List tab's interest dropdown is also updated to match so switching back to the Area List view preserves the same interest context.

### Subscription Mechanics

When a user subscribes to an interest:

1. A row is inserted into `user_interest_subscriptions`.
2. For each echo area in the interest, the system checks `user_echoarea_subscriptions`:
   - If the user has previously explicitly unsubscribed from that area (`is_active = false`), it is skipped — explicit unsubscriptions are always respected.
   - If the user already has an active subscription, only a source-tracking row is added.
   - If no existing subscription exists, a new one is created with `subscription_type = 'interest'`.

When a user unsubscribes from an interest, only echo area subscriptions that were sourced by that interest (and not covered by another interest the user remains subscribed to) are removed.

### Multi-Interest Source Tracking

The `user_echoarea_interest_sources` table tracks which interest(s) led to each of a user's echo area subscriptions. If two interests share an echo area and the user unsubscribes from one, the area is only removed if no other interest still covers it for that user.

This table is populated automatically and requires no manual configuration.

### AI-Assisted Interest Generation

The admin interests page includes a **Generate Suggestions** wizard that analyzes the echo area catalog and proposes interest groupings with suggested names, descriptions, and area assignments. Suggestions are presented for review before any changes are saved.

The wizard uses keyword matching by default. If an AI provider is configured in `.env` (`OPENAI_API_KEY` or `ANTHROPIC_API_KEY`), it offers an additional AI-assisted mode that produces higher-quality groupings for areas with ambiguous or abbreviated tags.

### Activity Statistics: Popular Interests Tab

The Activity Statistics admin page (`/admin/activity-stats`) includes a new **Popular Interests** tab (visible when the feature is enabled). It shows all active interests ranked by total subscriber count, with a progress bar for visual comparison. The tab appears next to the Popular Areas tab.

### Feature Flag

```
ENABLE_INTERESTS=true
```

The feature is enabled by default. Set to `false` in `.env` to disable all Interests routes and hide all related UI. No data is deleted when the feature is disabled.

---

## Echomail & Netmail

### Compose Message Size Warning

The echomail and netmail compose forms now show a warning indicator when the message body exceeds 12 KB (75% of the 16 KB FidoNet message body limit) and an error if it exceeds 16 KB. This matches the enforced compose limit in the application and helps users avoid silent truncation or rejected posts at the network level.

### Sender Name Popover Style

The sender name in the echomail message list is no longer underlined. The popover (showing BBS name, FTN address, and quick-action buttons) is still triggered by clicking the name; only the visual style has changed.

### Advanced Search: Message ID Field

The echomail **Advanced Search** modal now includes a **Message ID** field. Entering a value performs a case-insensitive partial match (`ILIKE`) against the `message_id` column of the `echomail` table, allowing you to search by a fragment of a FidoNet message ID without knowing the full value. The field follows the same two-character minimum rule as the other Advanced Search text fields and is combined with them using AND logic.

### Show Entire Conversation

In threaded message lists, the reply icon can now be used as a dedicated conversation-view action.

Clicking it switches the list into **Show Entire Conversation** mode, which loads the full visible conversation for the selected message and displays it as one threaded list without page-based thread splitting.

This applies to both:

- echomail
- netmail

While conversation mode is active, pagination is hidden and a **Back to Message List** button restores the normal paged view.

### Message List Context Menu

The echomail and netmail message lists now support a custom message action menu on each message row.

On desktop, it opens with right-click. On touch devices, the same menu opens with a long press on the row.

This menu gives users quick access to common actions without opening the message first.

Common actions include:

- **View Conversation** - loads the full visible conversation for the selected message
- **Download Message** - uses the existing message download endpoint
- **Forward to me by EMail** - shown only for users who have an email address configured

Additional echomail-only actions:

- **Save for later** / **Remove from saved**
- **Share** - opens the existing message-sharing dialog from the list view

### Ignored Echomail Messages

Echomail now supports per-user ignore rules for hiding messages from the reader and API responses.

The echomail message list context menu includes **Ignore message**. Choosing it opens a dialog pre-filled from the selected message:

- **Sender** - the exact sender name plus the sender's FTN node address
- **Subject contains** - words from the selected subject line

Ignore matching is done on the backend. A message is hidden only when all populated parts of the rule match:

- `from_name` matches exactly
- `from_address` matches exactly
- `subject` contains the saved text

If the **Subject contains** field is left blank, the rule hides all echomail from that sender identity regardless of subject.

Two migrations support this feature:

- `v1.11.0.61_echomail_ignore_rules.sql` - creates the ignore-rule storage table
- `v1.11.0.62_echomail_ignore_rule_sender_address.sql` - extends matching to include sender node address and installs the final uniqueness rule

### Ignored Echomail: Multiple Rules per Sender

Each ignore rule is uniquely identified by the combination of sender name, sender node address, and subject substring. A sender who posts from multiple node addresses under the same display name can be silenced per address — or across all addresses if the subject field is left blank — and each rule is stored and managed independently.

No database migration is required. No configuration change is needed.

### Markdown Image Support

Echomail and netmail messages written in Markdown can now include inline images using standard Markdown syntax: `![alt text](url)`.

**Image rendering**

`![alt](url)` syntax is rendered as a click-to-load placeholder showing an image icon and the alt text as a link. Clicking it fetches the image and displays it inline, replacing the placeholder. A user setting in **Settings → Messaging** controls whether images load automatically instead of requiring a tap (default: tap to load).

**Insert Image toolbar button**

The Markdown editor toolbar gains an **Insert Image** button beside the existing Insert Link button. Clicking it opens a dialog with three tabs:

- **Image URL** — paste an external URL and optional alt text; the syntax is inserted at the cursor.
- **Upload** — select a JPEG, PNG, GIF, or WebP file (up to the configured size limit). The file is stored privately under the user's file area in a dedicated subfolder, deduplicated by SHA-256 hash, and served through this BBS at `/echomail-images/{hash}`. The full absolute URL is inserted into the message.
- **My Images** — a dropdown list of images previously uploaded by this user; selecting one fills in the URL automatically.

**Image URL construction**

Hosted image URLs are built from the `SITE_URL` environment variable so they resolve correctly when a message is read on another system or client. Ensure `SITE_URL` is set correctly in `.env` for hosted image links to work in distributed messages.

Uploaded images are stored in the existing `files` table under the user's private file area. No new database migration is required for this feature.

### Message Viewer: Raw Source Mode

The **A** key now cycles through one additional viewer mode: **Raw Source**.

Full cycle order: Auto → RIPscrip → ANSI → Amiga ANSI → Plain Text → Raw Source → Auto

| Mode | Behaviour |
|---|---|
| **Plain Text** | Strips ANSI escape sequences and pipe color codes; linkifies URLs |
| **Raw Source** | Displays the message bytes exactly as received — no stripping, no rendering, no linkification |

Raw Source is useful for inspecting the wire content of a message without any client-side transformation applied.

Pressing **A** now also shows a brief toast notification each time the mode changes, making the active mode clearly visible even for modes that look visually similar to Auto.

### Pipe Code False Positive Fix

The pipe code detector previously matched any `|` followed by two characters that happened to be valid hexadecimal — including letters in ordinary English words. For example, `|Advertise` was being treated as a Mystic-style hex color code (`|AD` = bright green background), causing all text after it to render on a green background.

Detection now requires uppercase letters for both hex color codes and two-letter special codes such as `|CL` and `|PA`. All real BBS software produces uppercase pipe codes. The same fix was applied to `convertPipeCodesToAnsi` and `parsePipeCodes` for consistency.

The detector and parser now also treat doubled pipes (`||`) as literal pipe characters instead of the start of a pipe code, and they no longer match partial two-character codes inside longer numeric strings. This prevents ordinary text such as FTP `229 Entering Extended Passive Mode (||22|)` and `229 Entering Extended Passive Mode (|||2122|)` responses from being mis-rendered.

Messages explicitly marked as `plain` / **Plain Text** now bypass ANSI and pipe-code rendering entirely in the web viewer instead of being auto-rendered based on message contents.

---

## QWK Offline Mail

### HTTP Basic Auth Endpoints

QWK packet download and REP upload are now available through two HTTP Basic Auth endpoints:

- `GET /qwk/download`
- `POST /qwk/upload`

These endpoints authenticate with the user's regular BinktermPHP username and password and are intended for external offline-mail clients, scripts, or automation that cannot use the browser session-based `/api/qwk/*` routes.

They reuse the same QWK packet builder and REP processor as the in-app QWK page, so packet contents and import behavior remain consistent across both access methods.

The `/qwk` page now includes a help section for this automation workflow, including example `curl` commands for both endpoints.

It also now documents the JSON response returned by `/qwk/upload`, including the `success`, `imported`, `skipped`, and `errors` fields.

### FTP Access

The standalone FTP daemon (`scripts/ftp_daemon.php`) provides a passive FTP service for moving QWK packets and file-area content without going through the browser.

The FTP virtual filesystem exposes:

- `/qwk/download/<BBSID>.QWK` to generate and download the authenticated user's QWK packet
- `/qwk/upload/*.REP` or `/qwk/upload/*.ZIP` to upload REP reply packets for import
- `/incoming/<AREA>/...` to upload files into writable file areas using the same pending-approval path as the web interface
- `/fileareas/...` to browse and download approved files from accessible file areas

Anonymous FTP login is supported for public downloads. Anonymous users are restricted to `/fileareas/...` and only see file areas marked public; they cannot access QWK paths or upload files.

This FTP service is **disabled by default**. To enable it, set `FTPD_ENABLED=true` and configure the control port plus passive port range in `.env`. If the daemon is behind NAT or bound to `0.0.0.0`, also set `FTPD_PUBLIC_HOST` so passive-mode clients receive a usable address in `PASV` replies.

By default the FTP daemon listens on port `2121`, not privileged port `21`. If you want users to connect on the standard FTP port, set up a NAT or port-forward rule that redirects external port `21` to internal port `2121`, and forward the configured passive port range as well.

### Conference Area Selection

Users can now control exactly which echo areas are included in their QWK packets without changing their echomail subscriptions.

A sliders button on the **Conferences** panel opens a **QWK Conference Areas** modal. The modal shows all subscribed areas as checkboxes (all ticked by default) and a search box for adding areas that are not in the user's subscription list.

Behaviour:

- **Default** — when no custom selection has been saved, all subscribed echo areas are included (unchanged from previous behaviour).
- **Custom selection** — once saved, only the ticked areas appear in the packet. The Conferences panel displays a **custom** badge when a custom selection is active.
- **Personal mail only** — saving with zero areas ticked produces a packet that contains only conference 0 (Personal Mail / netmail) and no echo areas.
- **Reset** — the Reset button in the modal clears the custom selection and reverts to the all-subscribed default.

The selection is stored in the new `qwk_area_selections` table. An activation flag (`qwk_custom_areas_active`) in `users_meta` distinguishes "not configured" from "explicitly empty," making personal-mail-only packets possible.

Three new API endpoints support the feature:

| Endpoint | Purpose |
|---|---|
| `GET /api/qwk/area-selections` | Returns current selection and subscribed area list |
| `POST /api/qwk/area-selections` | Saves selection; `{"reset": true}` reverts to default |
| `GET /api/qwk/area-search?q=` | Searches active areas by tag or description |

Migration `v1.11.0.56_qwk_area_selections.sql` creates the `qwk_area_selections` table and its index.

---

## BinkP Configuration

### Poll Schedule Builder

The poll schedule input on the uplink configuration screen has a new **schedule builder** toggle button (sliders icon) appended to the right of the field. It is available on both the admin BinkP configuration page (`/admin/binkp-config`) and the user BinkP page (`/binkp`).

Clicking the button opens an inline panel that:

- Parses the current cron expression into its five labelled fields: **Minute**, **Hour**, **Day**, **Month**, and **Weekday**, each with a range hint.
- Rebuilds the cron expression in the main input live as you edit any field.
- Shows a human-readable description of the resulting schedule (e.g. "Runs every 4 hours at minute 0", "Runs daily at 06:00").
- Re-parses and repopulates the fields if you edit the main input directly while the panel is open.

Clicking the button again collapses the panel. No changes to the data model or API are involved.

### Status Page Uplink Checks

Each uplink on the BinkP status page (`/binkp`) now has a button to trigger an on-demand connectivity check. The check is an authentication-only probe — it stops after the BinkP handshake succeeds and does not proceed into file transfer or packet download processing.

### Queue Packet Viewer

Packet filenames in the **Inbound Queue** and **Outbound Queue** lists on the BinkP Queues tab are now clickable. Clicking any `.pkt` filename opens the packet inspector modal — the same modal used on the Kept Packets tab — showing:

- Packet header: originating address, destination address, creation date, size, packet version, product code, and password status.
- Message list: message number, from, to, subject, date, and attribute flags for every message in the packet.
- A **Download** button to save a copy of the raw `.pkt` file.

Non-`.pkt` files in the queue (e.g. file attachments) remain plain text.

This feature requires a valid registered license, consistent with the existing kept-packets inspector.
### Scheduler

- **Outbound poll scheduling**: The scheduler's outbound poll check (`pollIfOutbound`) now respects each uplink's configured `poll_schedule` cron expression. Previously, outbound packets could trigger a poll as frequently as once per minute; they now only poll when the schedule allows it. This prevents flooding uplinks with connections. The outbound and scheduled poll timers are tracked independently so an outbound poll does not delay the next scheduled inbound poll.
- **No duplicate outbound poll after scheduled poll**: When a scheduled inbound poll runs, its bidirectional binkp session already exchanges any outbound packets. The scheduler now tracks which uplinks were polled in the current loop iteration and skips same-iteration outbound polls for those uplinks, while still allowing independent outbound polls in subsequent iterations.

### Insecure Session Enhancements

#### Allow Insecure Echomail Delivery (per uplink)

Each uplink now has an **Allow insecure echomail delivery** checkbox in the uplink modal. When enabled, the processor checks the FTN node address recorded in the `.meta` file written during the inbound session. If the address matches the uplink's configured address, echomail from that packet is accepted even though the session was unauthenticated. All other unauthenticated sessions continue to reject echomail as before.

> ⚠️ **This option is not recommended and should only be used as a last resort.** An unauthenticated session provides no cryptographic proof of the caller's identity — any connecting node can claim any FTN address. The correct solution is to configure a shared BinkP session password on both ends. Only enable this if the remote system genuinely cannot support passwords and the operator is aware of and accepts the risk.

#### Insecure Receive Only (enforced)

The **Insecure Receive Only** checkbox in the Security settings panel is now enforced. When enabled, unauthenticated inbound sessions will not receive any outbound files, hold-directory files, FREQ file serves, or have FREQ requests honoured. Previously the setting was read from configuration but had no effect.

#### CRAM-MD5 Handshake Ordering

When acting as the answering side of a BinkP session, BinktermPHP now sends `OPT CRAM-MD5-<challenge>` as the first `M_NUL` frame whenever CRAM-MD5 is available. Previously the challenge was sent later in the initial `M_NUL` sequence after `SYS`, `ZYZ`, `LOC`, `VER`, and `TIME`.

This change does not alter password timing (`M_PWD` is still sent after `ADR`) or plaintext password fallback behavior. It is an interoperability fix for peers that expect the CRAM challenge to be advertised first.

### Admin — BinkP Config

- **Uplinks table cleanup**: The uplinks table columns have been consolidated — Hostname and Port are now shown as a single Host column, and Enabled/Default are combined into a Status badge column. Markdown, Posting Name, and ADR @Domain are no longer shown in the table (they remain editable in the uplink modal).
- **Uplinks table responsive**: The uplinks table is now responsive. On smaller screens, less critical columns are hidden progressively: Me and Domain are hidden below `sm`, Host below `md`, and Schedule below `lg`. Uplink, Status, and Actions are always visible.

---

### BinkP Session Log Coverage

The `binkp_session_log` table now reflects actual BinkP traffic more completely. In addition to crash mail, the application now records:

- normal outbound `binkp_poll` sessions
- normal inbound `binkp_server` sessions
- remote IP addresses for connected peers when available

This makes the **Admin -> BinkP Sessions** page and related dashboard widgets much more useful for operational monitoring. Sysops upgrading from earlier 1.8.9 builds should expect the session log to fill more quickly because ordinary BinkP traffic is now included instead of only crash mail activity.

### BinkP Session Log Details and Log Viewer

New BinkP session rows now also store:

- `process_id` — the PID of the process that handled the session
- `log_file` — the basename of the log file being written, such as `binkp_poll.log`, `binkp_server.log`, or `crashmail.log`

This allows the **Admin -> BinkP Sessions** table to offer a session log viewer. Clicking a recent session row opens a modal that searches the relevant BinkP log for lines matching that session's PID.

The search uses the recorded log filename basename and checks rotated variants under `data/logs/` as well, so a session can still be inspected even if the active log rolled over after the session completed.

### BinkP Session Log Retention

Because `binkp_session_log` now captures all normal BinkP sessions, the database maintenance job now prunes older records automatically.

By default, `scripts/database_maintenance.php` deletes `binkp_session_log` rows older than 30 days.

To change that retention window, set this in `.env`:

```env
BINKP_SESSION_LOG_RETENTION_DAYS=30
```

Set a larger value if you want a longer operational history, or a smaller value if you prefer to keep the session log compact.

## Echomail MCP Server

An optional [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server is included in `mcp-server/`. It allows AI assistants that support MCP — such as Claude — to query your echomail database in read-only mode over HTTP. The server requires a valid registered license.

### Per-User Bearer Keys

Authentication is per-user. Each user generates a personal bearer key from **Settings → AI → MCP Server Bearer Key**. Keys are stored in the `users_meta` table and enforce the same access rules as the web interface — inactive areas are always hidden, and sysop-only areas are hidden for non-admin users.

- A key can be regenerated at any time; the old key is immediately invalidated.
- The full key is shown only once at generation time.

To connect Claude, add to `.mcp.json` in your project root:

```json
{
  "mcpServers": {
    "binkterm": {
      "type": "http",
      "url": "http://your-bbs-hostname:3740/mcp",
      "headers": {
        "Authorization": "Bearer <your-key-from-settings>"
      }
    }
  }
}
```

`.mcp.json` is listed in `.gitignore` — do not commit it.

### Daemon Mode and Reverse Proxy Support

The server supports `--bind=<host>` to restrict it to a specific interface (recommended when running behind a reverse proxy), `--daemon` for background/boot startup, and `--help` for usage. The `MCP_BIND_HOST` `.env` variable sets the default bind address.

For reverse proxy configuration (nginx, Caddy), systemd unit file, and cron `@reboot` examples, see `docs/MCPServer.md`.

---

## Dashboard

### Dynamic Advertisement Content

Advertisements that use **Content Command** now have their command output resolved when the ad is fetched for display.

This affects:

- the dashboard advertisement widget on `/`
- the full ad page at `/ads/{name}` and `/ads/random`
- the admin advertisement preview modal

If the command succeeds, its stdout becomes the rendered ad body. If it fails, the system now falls back to any stored static `content` and also exposes the command error in the ad payload for troubleshooting.

### Today's Callers Table

The **Today's Callers** list in the System Information box (visible to admins on the dashboard) is now rendered as a table with three columns: **User**, **Time**, and **Online**. Users who have been active within the last 15 minutes are marked with a green badge in the Online column.

### Active BinkP Sessions Card

The admin dashboard now includes an **Active BinkP Sessions** card. It lists currently active BinkP sessions and updates automatically as sessions start, transfer data, and finish.

The feed is admin-only and uses the existing BinkStream realtime channel. This replaces the need to manually refresh the dashboard to watch poll progress.

---

## Admin Help

### In-App FAQ and README Viewer

The Admin **Help** menu entries for **FAQ** and **README** now open inside the built-in admin docs viewer instead of linking out to GitHub.

The docs controller now treats `FAQ.md` and `README.md` in the project root as special in-app documents. Their markdown links are rewritten so related documentation continues to open inside `/admin/docs/view/...`.

This keeps admins inside the application while browsing operational documentation and avoids broken links when those files are not stored under `docs/`.

---

## File Areas

### Upload Approval Queue

User-uploaded files now support a moderation workflow.

- Uploads from administrators continue to publish immediately.
- Uploads from non-admin users are stored with `status = pending` and do not appear in public file listings, searches, recent uploads, downloads, or TIC distribution until approved.
- Sysops can review the queue at `/admin/file-approvals`, inspect the uploaded file, optionally trigger an on-demand virus scan when scanning is enabled, and then choose **Approve** or **Reject**.
- Approval promotes the file into the normal area storage path, updates file area statistics, and performs post-approval processing such as TIC generation.
- Rejection keeps the upload hidden and records rejection metadata. When credits are enabled, the upload charge is refunded on rejection.
- Uploaders can review the status of their own submissions from the new **My Uploads** entry on the `/files` page.

This change adds the migration `v1.11.0.52_file_upload_approval.sql`, which records approval and rejection metadata on the `files` table and adds an index for the pending queue.

### Activity Statistics Exclude Private File Areas

The **Admin → Activity Statistics** page now omits private file areas from the file-activity tab.

This affects:

- **Top Downloaded Files**
- **Most Browsed File Areas**

The change keeps private-area usage out of aggregate admin activity reports while leaving the underlying activity log table unchanged.

### ISO Mount Point Restriction

When an ISO-backed file area is created or edited through the web interface or API, its mount point must now live under `data/iso_mounts`.

This restriction applies only to values entered through the application UI and API. It is intended to keep browser-configured ISO mount paths inside the application's managed mount tree and prevent accidental references to arbitrary filesystem locations.

If you intentionally use a custom external mount path for an ISO area, set that value directly in the database after the upgrade.

---

## Broadcast Manager

### Clone Campaign

The Broadcast Manager campaign list now includes a **Clone** action in the row actions menu.

Cloning opens the campaign editor pre-filled from the selected campaign, but as a new record:

- the campaign ID is cleared
- the campaign name is suffixed with ` (Copy)`
- the cloned campaign is disabled by default

This makes it easier to reuse an existing campaign as a starting point without modifying the original or accidentally activating the copy immediately.

---

## Registration Page

The approval notice on the registration page now includes an additional note informing applicants that:

- An email will be sent when their account is approved.
- Reminder emails may also be sent.
- They should check their junk/spam folder if they don't see the email.

---

## User Settings

### Tabbed Layout

The user settings page (`/settings`) has been reorganized from a single scrolling card into a tabbed layout. A vertical navigation panel on the left lists four tabs:

- **Display** — messages per page, timezone, language, date format, theme, interface style, default echo list, message font and font size.
- **Messaging** — signature, default tagline, threading preferences, page position memory, quote display, forward netmail to email, echomail digest.
- **Notifications** — notification sounds for chat, echomail, netmail, and file events.
- **Account** — active sessions, logout all sessions, onboarding reset.

The Save button is hidden on the Account tab (which uses its own action buttons) and shown on all others. The active tab is persisted in `localStorage` so the page reopens on the last-used tab.

### Notification Sound Preview

Each notification sound select box on the Notifications tab now has an adjacent **▶** button. Clicking it plays the currently selected sound so you can audition each option without saving.

### Ignored Echomail Management

The **Messaging** tab in `/settings` now includes an **Ignored Echomail** section at the bottom of the page, separated from the forward-to-email and digest settings.

This section lists the user's saved echomail ignore rules and provides a remove action for each one, giving users a way to unhide messages by deleting the matching rule.

### Markdown Image Load Preference

The **Messaging** tab includes a new **Image Loading** preference controlling how inline images in Markdown messages are handled:

- **Tap to load** (default) — images render as a placeholder; clicking fetches and displays the image inline.
- **Automatically load** — images are fetched and displayed as soon as the message is rendered.

This preference is stored per user and requires no database migration.

---

## Appearance

### Terminal Server Screens

The **Appearance** page now includes a **Term Server** tab for managing the built-in ANSI screens used by the telnet and SSH terminal servers.

Supported screen slots are:

- `welcome` -> `telnet/screens/login.ans`
- `main_menu` -> `telnet/screens/mainmenu.ans`
- `goodbye` -> `telnet/screens/bye.ans`

From this tab, a sysop can:

- edit the screen contents directly in a textarea
- upload a replacement `.ans`, `.asc`, or `.txt` file
- reset a custom screen so the default terminal behavior is used again

The editor works directly with the same screen files already used by the terminal daemons, so saving a custom screen takes effect without any additional appearance configuration.

### Shared ANSI Editor

ANSI editing in the admin interface is now handled through one shared editor component instead of separate page-specific controls.

Current behavior:

- the **Content Library** ad editor and **Admin -> Appearance -> Term Server** editor use the same ANSI editing widget
- preview opens in a modal and renders directly from the current textarea content
- an **ANSI Cheatsheet** button opens a reference table of common escape sequences with rendered examples where that makes sense
- a fixed ruler above the textarea marks columns from `1` through `132`, with stronger indicators every `10` columns, so ANSI art and terminal layouts can be lined up more reliably
- soft wrapping is disabled in these editors so visible columns match actual character positions

This change does not add any migration or configuration requirement. It is an editing workflow improvement for sysops who maintain terminal server screens or ANSI advertisements.

The service worker asset update path for these JavaScript changes was also tightened so fresh editor code is fetched and precached more reliably during upgrades. If a browser still shows the old editor behavior immediately after deployment, reload once after the new service worker activates.

---

## Real-time Events (BinkStream)

### SharedWorker Architecture

BinktermPHP now delivers real-time browser events through a SharedWorker that owns one active BinkStream transport per logged-in user, shared across all browser tabs. Depending on configuration and runtime availability, that transport is either WebSocket or SSE.

Events are written to an UNLOGGED table `sse_events` with a `BIGSERIAL` id used as the stream cursor. `GET /api/stream` delivers outbound events over SSE. `POST /api/stream` accepts inbound commands when SSE is the active transport. The standalone PHP WebSocket daemon uses the same command and event services for bidirectional realtime delivery.

Two database migrations run automatically via `php scripts/setup.php`:
- `v1.11.0.54_chat_notify_trigger.php` — installs a Postgres trigger on `chat_messages` that inserts into `sse_events`
- `v1.11.0.55_sse_events_table.php` — creates the `sse_events` UNLOGGED table and its index

### Chat Integration

The first event type delivered over BinkStream is incoming chat messages. When a chat message is saved, the insert trigger queues a BinkStream event immediately, while the sender's own message is returned in the `/api/chat/send` response and rendered locally without waiting for realtime delivery.

### Long-lived Connections

When SSE is the active transport, each connection holds open for `SSE_WINDOW_SECONDS` (default `60`) and sends a `: keepalive` comment every 15 seconds to prevent proxy and load-balancer timeouts. At the end of the window the server sends a `reconnect` event and the SharedWorker reconnects automatically.

```
SSE_WINDOW_SECONDS=60
```

On the built-in PHP development server the window is forced to `0` (immediate reconnect) because that server is single-threaded and cannot handle concurrent requests.

Testing has shown that some Apache + PHP-FPM (`mod_proxy_fcgi`) deployments buffer SSE responses instead of flushing events in real time. In those environments, sysops will need to use a lower `SSE_WINDOW_SECONDS` value to reduce how much data Apache can hold before releasing the response. As an interim mitigation, when `BINKSTREAM_TRANSPORT_MODE=auto` and Apache is detected, BinktermPHP automatically uses a default `SSE_WINDOW_SECONDS` of `2` unless the sysop explicitly sets `SSE_WINDOW_SECONDS` in `.env`.

`BINKSTREAM_TRANSPORT_MODE` currently supports:

- `auto` — prefer WebSocket when the daemon is available; otherwise use SSE
- `sse` — force SSE
- `ws` — force the standalone PHP WebSocket daemon

### php-fpm Worker Capacity

When SSE is in use, each active browser session holds one php-fpm worker open for the full `SSE_WINDOW_SECONDS` duration (default: 60 s). This enables low-latency real-time event delivery, but worker count must be planned for your expected concurrent user load.

To minimize SSE requests hitting php-fpm, we recommend running the standalone real-time WebSocket server where possible. With `BINKSTREAM_TRANSPORT_MODE=auto` or `ws` and the realtime daemon available, browser sessions can use WebSocket transport instead of relying on repeated SSE connections through php-fpm.

**Rule of thumb:** `pm.max_children` ≥ (concurrent users × 1.1) + 5

If all workers are occupied by SSE connections, regular page loads and API calls will queue or fail. For a full sizing table, php-fpm and Apache configuration snippets, and PostgreSQL tuning guidance, see **[docs/CONFIGURATION.md — Server Sizing & Tuning](CONFIGURATION.md#server-sizing--tuning)**.

**Low-RAM options** — if you cannot provision enough workers for the default window:

- **Reduce `SSE_WINDOW_SECONDS`** (e.g. `15` or `30`). Shorter windows free workers more often; reconnect overhead stays low because the SharedWorker reconnects after a short pause on window expiry. Event latency impact is usually modest.
- **Set `SSE_WINDOW_SECONDS=0`** to disable long-polling entirely. The SharedWorker reconnects after a short pause on close, reducing hammering at the cost of more frequent HTTP round-trips per user.
- Because all tabs for the same user on the same browser share one SharedWorker and therefore one SSE connection, multiple open tabs do not multiply worker usage.

### BinkStream Test Tools

Two diagnostic tools are in the Admin **Help → Developer** submenu:

- **BinkStream Test** — sends a test event through the database trigger and displays it in real time, confirming the full BinkStream pipeline is working.
- **Proxy Buffer Test** — flushes progressively larger chunks of data and reports whether each chunk arrives immediately or is held by an intervening proxy or web server buffer.

### Real Time Server - Recommended Daemon

`realtime_server` is recommended to be started on system boot up to support web socket based BinkStream streaming. It appears in the Admin dashboard service status panel with a live indicator showing your current active transport mode (WebSocket or SSE). The README startup sequence and cron/systemd examples include it in the required services section. See `docs/BinkStreamChannel.md` for reverse proxy setup and diagnostics.

### Dashboard Stats Push

Echomail, netmail, and files unread badge counts are now delivered via BinkStream instead of a 30-second client-side poll. A new shared Postgres trigger function (`notify_dashboard_stats`) fires on INSERT to the `echomail`, `netmail`, and `files` tables. It inserts a signal-only `dashboard_stats` broadcast event into `sse_events` and calls `pg_notify`. The trigger is debounced to one event per 5-second window to avoid flooding clients during a batch mail import. When the event arrives the browser calls `/api/dashboard/stats` for a fresh count.

Migration `v1.11.0.58_dashboard_stats_triggers.php` installs this trigger function and wires it to all three tables.

The echomail and netmail message lists also listen for `dashboard_stats` and update silently (no loading spinner) when the event fires, replacing the previous 5-minute and 2-minute polling intervals. A 2-second client-side debounce is applied on top of the DB-level debounce to handle concurrent imports where multiple transactions may each emit an event before the other has committed.

### Cross-Tab Read Sync

When a user marks a message as read (single or bulk), the server inserts a user-targeted `message_read` event into `sse_events` in addition to the normal database write. The event payload contains the array of message IDs and the message type (`echomail` or `netmail`). Other browser tabs open to the same message list receive the event via BinkStream and immediately apply the read styling (open envelope icon, faded row) without reloading the list.

### File Approval Queue Notifications

When a non-admin uploads a file it is inserted with `status = 'pending'`. The existing `files` INSERT trigger fires a `dashboard_stats` event, which causes all connected clients to re-fetch `/api/dashboard/stats`. For admin users the response now includes `pending_file_approvals` (new pending files since last seen) and `pending_files_max_id` (the highest ID seen).

The unread indicator logic follows the same pattern used for the Files and Chat badges:

- The Files nav icon and link gain the `unread` class when either `new_files > 0` or `pending_file_approvals > 0`.
- The File Approvals sub-item gains the `unread` class when `pending_file_approvals > 0`.
- On page load, if the user is already on `/admin/file-approvals`, `markSeen('file-approvals', pending_files_max_id)` is called immediately so the badge clears and the seen position is persisted to `users_meta` (`last_pending_files_max_id`).
- `DashboardStatsService` uses the stored `last_pending_files_max_id` to count only files added since the admin last visited the queue. On first visit the current max ID is recorded and the badge starts at zero.

### Admin File Menu Dropdown

For administrators the Files entry in the top navigation has been converted to a Bootstrap dropdown. The dropdown contains two items:

- **Files** — links to `/files` as before.
- **File Approvals** — links to `/admin/file-approvals`; gains the `unread` badge independently when new pending uploads are waiting.

Non-admin users continue to see a plain Files nav link with no dropdown.

The `ui.base.file_approvals` i18n key has been added to all locale catalogs (`en`, `fr`, `es`).

---

## AreaFix / FileFix Manager

### AreaFix Overview

AreaFix and FileFix are Fidonet robots that manage echomail and file-area subscriptions by exchanging netmail with the upstream hub. The new admin tool at `/admin/areafix` provides a UI for sending commands to those robots and managing the results without using a separate mail client.

The tool is accessible from the Admin **BinkP** menu when at least one uplink has a configured `areafix_password` or `filefix_password`.

### Quick Actions

Buttons for the most common AreaFix/FileFix commands are shown at the top of each robot tab: `%QUERY` (list subscribed areas), `%LIST` (list all available areas), `%UNLINKED` (list areas available but not subscribed), `%HELP`, `%PAUSE`, and `%RESUME`. Clicking any button sends the command immediately to the selected uplink.

### Subscribe and Unsubscribe

A text field accepts a single area tag. The **Subscribe** and **Unsubscribe** buttons send `+TAG` and `-TAG` commands respectively. A freeform textarea below accepts multi-line command sets for bulk operations.

After every sent command the history table and latest reply panel refresh automatically. The latest reply panel also polls for new replies from the hub every 30 seconds.

### Latest Reply Panel

The most recent incoming reply from the hub is displayed in the **Latest Reply** panel as raw pre-formatted text, showing the sender name, date, and full message body.

### Message History

The message history table shows the last 50 netmail messages to or from AreaFix/FileFix for the selected uplink. Each row is expandable to show the full message body. The table is filtered client-side to the active robot tab (AreaFix or FileFix).

### Subject Masking

AreaFix uses the netmail subject line as the robot password. To prevent that password from appearing on screen, any netmail message whose `to_name` or `from_name` contains `areafix` or `filefix` (case-insensitive) has its subject replaced with `••••••••` in all display contexts, including message lists, message detail views, and the history table on this page.

### Uplink Password Fields

The BinkP uplink editor modal now includes **AreaFix Password** and **FileFix Password** fields. These are saved as `areafix_password` and `filefix_password` in `config/binkp.json` via the admin daemon and are used when sending commands to the respective robot. Both fields use a masked password input with a show/hide toggle.

### History Table Improvements

The AreaFix/FileFix message history table now includes two additional columns:

- **Node Address** — the FTN address of the other party: the hub's address for outgoing messages, the hub's address for incoming replies.
- **Network** — the domain of the selected uplink (e.g. `fidonet`, `araknet`).

Dates in the history table are now formatted as human-readable local timestamps (e.g. `Mar 25, 2026, 05:51 AM`) instead of raw PostgreSQL timestamp strings.


---

## Advertising Improvements

### Content Command Whitelist and Dropdown

Ad content commands are now restricted to a server-side whitelist. Only the following are permitted:
- `scripts/weather_report.php`
- `scripts/report_newfiles.php`
- `scripts/generate_ad.php`
- Any file inside the `content_commands/` directory in the project root

The admin ad editor's Content Command field is now a dropdown populated by scanning those locations, replacing the previous free-text input. This prevents arbitrary command injection through the ad configuration UI.

Custom ad scripts should be placed in `content_commands/` with execute permissions.

### Content Command Parameters

Content commands may now include space-separated arguments after the script path (e.g. `scripts/report_newfiles.php 7` to report the last 7 days of new files). Arguments are passed to the script as `$argv` entries. The entire command string including arguments is validated against the whitelist before execution.

### Click-through URLs and Impression Tracking

Each advertisement can now have an optional **Click-through URL**. When set, clicking the ad in the web interface records a click and redirects the user to the target URL.

Every ad display now records an impression. The migration `v1.11.0.53_ad_tracking.sql` adds:
- `click_url` column on the `advertisements` table
- `advertisement_impressions` table (ad id, user id, displayed_at)
- `advertisement_clicks` table (ad id, user id, clicked_at)

### Ad Analytics Admin Page

A new admin page at `/admin/ad-analytics` shows per-campaign analytics:
- Total impressions and clicks
- Click-through rate
- A breakdown of performance by campaign

### Ad Title File-type Prefix

When an ad file is uploaded, the title is now automatically prefixed based on the file extension:
- `.ans` → `[ANSI]`
- `.rip` → `[RIP]`
- `.six` / `.sixel` → `[SIXEL]`

The prefix is applied to both auto-generated titles and any title provided by the uploader, making it easier to identify the ad format at a glance in the admin list.

### ANSI Editing Workflow

The advertisement editor now uses the same shared ANSI editor component as the terminal-screen editor.

In practice, this means:

- the old ad-specific inline sequence helper has been replaced by a shared toolbar with a larger preset list
- preview opens in a modal instead of taking space below the editor
- an **ANSI Cheatsheet** button is available beside the preview button
- a `1` to `132` column ruler is shown above the textarea so ANSI art can be aligned to traditional terminal widths while editing

No stored ad format changed in the `advertisements` table. Existing ANSI content continues to render as before; this is a usability improvement for editing and previewing content.

---

## AI Provider Layer

A new abstracted AI provider layer (`src/AI/`) is used by all AI-assisted features in BinktermPHP. Supported backends:

| Provider | `.env` key |
|---|---|
| Anthropic (Claude) | `ANTHROPIC_API_KEY` |
| OpenAI | `OPENAI_API_KEY` |

The active provider is selected automatically based on which key is set. If both are set, Anthropic is preferred.

AI request usage is tracked in the `ai_request_accounting` table (migration `v1.11.0.51_ai_request_accounting.sql`). An admin usage summary is available at `/admin/ai-usage`.

See `docs/AIProviders.md` for supported models, configuration, and cost management guidance.

---

## MRC Chat

### Join Command

The MRC chat input now accepts `/join <room>` as a slash command. Typing `/join myroom` in the message input is equivalent to clicking the room in the room list — it calls `joinRoom()` directly. The command is recognized before the user has joined any room and is included in the tab-completion command list.

---

## Docs Viewer: HTML Pass-through

The admin docs viewer (`/admin/docs/view/README`) now renders raw HTML blocks and inline tags in `README.md` unescaped. This allows embedded HTML tables, badges, and image tags in the README to display correctly inside the application rather than appearing as escaped markup.

HTML pass-through is enabled only for `README.md`. All other documents continue to escape HTML for safety.

---

## Telnet / SSH BBS Server

### System News and Recent Shoutbox Flow

After a successful telnet login, the BBS now loads `data/systemnews.md`, renders its Markdown for terminal display, clears the screen, and shows it in a framed **SYSTEM NEWS** screen before the recent shoutbox.

The terminal Markdown renderer now formats headings and inline Markdown links into terminal-friendly output so system news can be maintained as ordinary Markdown without exposing raw `#` or `[label](url)` syntax to callers.

After the system news screen is dismissed, the read-only recent shoutbox screen appears. Users can:

- press `S` to enter and post a new shout immediately
- press any other key to continue into the normal BBS session

### Interests Menu

When `ENABLE_INTERESTS=true`, a new **I — Interests** option appears in the telnet and SSH BBS main menu. Pressing `I` opens the Interests browser:

- Active interests are listed with a `[+]` (subscribed) or `[ ]` (not subscribed) badge and an echo area count.
- Selecting a number opens a detail screen showing the interest name, description, current subscription status, and the full list of member echo areas (tag and description) displayed inline.
- From the detail screen, `S` subscribes and `U` unsubscribes. The subscription status refreshes on every redisplay so it always reflects the current state.
- After a first login when the user has no interest subscriptions, a one-time onboarding hint is shown suggesting they visit the Interests menu.

### QWK Offline Mail via ZMODEM

The telnet and SSH BBS **K — QWK Offline Mail** menu (controlled by `BbsConfig::isFeatureEnabled('qwk')`) now supports full ZMODEM file transfer in addition to showing the HTTP download URL.

**Download (`D`)**

- Builds a QWK packet on the fly using `QwkBuilder::buildPacket()` for the logged-in user.
- Transfers the packet to the terminal using the built-in PHP ZMODEM implementation. On telnet connections, IAC bytes are escaped during transfer.
- Deletes the temporary packet file after transfer regardless of success or failure.

**Upload (`U`)**

- Initiates a ZMODEM receive and waits for the terminal to send a REP packet.
- Processes the received file with `RepProcessor::processRepPacket()` and reports how many messages were imported, skipped, and errored.

The HTTP download URL (for clients that prefer it) is shown on the status screen as a supplemental tip below the action menu.

### Echomail Interest-Based Browsing

The echomail area picker (accessed when browsing echomail from the BBS menu) now includes an **I — Browse by Interest** key when `ENABLE_INTERESTS=true`.

Pressing `I` shows a numbered list of active interests with subscribe/unsubscribe badges. Selecting one filters the area picker to only that interest's echo areas. The user can then select an area normally. Pressing `Q` from the interest list returns to the unfiltered area picker.

The paginated area picker itself retains an `I` hint in its navigation line so users are reminded the option is available while browsing a long area list. Selecting an area from an interest-filtered list opens the echomail reader for that area as usual.

---

## CLI Tools

### fix_date_received.php

`scripts/fix_date_received.php` resets `date_received` to `date_written` for echomail rows in one or more echo areas. This is useful after a `%RESCAN` import, where all imported messages land with the same `date_received` (the import time) instead of their original send date, causing them to appear sorted as a block at the top of the message list.

Only rows where `date_written` is non-NULL and not in the future are updated. Rows where `date_written` already matches `date_received` are skipped.

**Usage:**

```bash
# By area ID
php scripts/fix_date_received.php <echoarea_id> [echoarea_id ...]

# By area tag
php scripts/fix_date_received.php --tag <tag> [--tag <tag> ...]

# By domain
php scripts/fix_date_received.php --domain <domain>

# Combine tag and domain filters (both must match)
php scripts/fix_date_received.php --tag <tag> --domain <domain>

# All echo areas
php scripts/fix_date_received.php --all

# Preview without making changes
php scripts/fix_date_received.php --all --dry-run
```

### binktop.php

`scripts/binktop.php` now provides a denser live dashboard for operational monitoring.

Current behavior:

- compact three-line header with uptime, load, RAM, disk, queue totals, PostgreSQL connection totals, and refresh interval
- `sess:` in the header now means `logged-in users / guest users`, rather than raw session totals
- current users list with compact timestamps and truncated activity text for narrow terminals
- daemon list rendered in a tighter two-column layout when terminal width allows
- daemon state indicated by color instead of a separate `state` column
- per-daemon RSS plus a total daemon RSS line
- extra host-process rows for `postgres`, `httpd`, `apache2`, `php-fpm`, and `php-fpm:*` when detected
- active door sessions shown at the bottom

This makes `binktop.php` more practical as a persistent sysop console, especially on 80-column terminals and mixed-service hosts.

---

## Upgrade Instructions

### From Git

```bash
git pull origin main
composer install
php scripts/setup.php
```

`setup.php` runs all pending migrations automatically, including:

| Migration | Purpose |
|---|---|
| `v1.11.0.49_interests.sql` | Interests core schema |
| `v1.11.0.50_interest_echo_sources.sql` | Interest subscription source tracking |
| `v1.11.0.51_ai_request_accounting.sql` | AI request usage accounting |
| `v1.11.0.52_file_upload_approval.sql` | File upload approval queue |
| `v1.11.0.53_ad_tracking.sql` | Ad impression and click tracking |
| `v1.11.0.54_chat_notify_trigger.php` | Postgres trigger for SSE chat events |
| `v1.11.0.55_sse_events_table.php` | SSE events UNLOGGED table |
| `v1.11.0.56_qwk_area_selections.sql` | QWK per-user conference area selection |
| `v1.11.0.57_sse_events_user_targeting.php` | SSE event targeting: `user_id` and `admin_only` columns; fat-payload chat trigger |
| `v1.11.0.58_dashboard_stats_triggers.php` | DB triggers on `echomail`, `netmail`, `files` for `dashboard_stats` BinkStream push |
| `v1.11.0.60_binkp_session_pid_log.sql` | Adds `process_id` and `log_file` to `binkp_session_log` for session-to-log correlation |
| `v1.11.0.61_echomail_ignore_rules.sql` | Creates per-user echomail ignore-rule storage |
| `v1.11.0.62_echomail_ignore_rule_sender_address.sql` | Extends ignore rules to match sender node address and installs the final uniqueness rule |

**Optional `.env` additions:**

```env
# Opt out of Interests feature
ENABLE_INTERESTS=false

# BinkStream transport strategy (currently supported: auto, sse, ws)
BINKSTREAM_TRANSPORT_MODE=auto

# Standalone BinkStream WebSocket daemon
BINKSTREAM_WS_BIND_HOST=127.0.0.1
BINKSTREAM_WS_PORT=6010
BINKSTREAM_WS_PUBLIC_URL=/ws

# Optional standalone FTP daemon
FTPD_ENABLED=false
FTPD_BIND_HOST=0.0.0.0
FTPD_PORT=2121
FTPD_PASSIVE_PORT_START=2122
FTPD_PASSIVE_PORT_END=2149

# SSE connection window duration in seconds (default 60)
SSE_WINDOW_SECONDS=60

# Purge BinkP session log entries older than this many days (default 30)
BINKP_SESSION_LOG_RETENTION_DAYS=30
```

### Using the Installer

Re-run the BinktermPHP installer to upgrade the application files. When prompted to run `php scripts/setup.php`, allow it to complete — this applies all pending database migrations.
