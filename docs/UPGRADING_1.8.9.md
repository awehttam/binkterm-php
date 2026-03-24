# Upgrading to 1.8.9

⚠️ Make sure you've made a backup of your database and files before upgrading.

## Table of Contents

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
- [BinkP Configuration](#binkp-configuration)
  - [Poll Schedule Builder](#poll-schedule-builder)
  - [Queue Packet Viewer](#queue-packet-viewer)
  - [Insecure Session Enhancements](#insecure-session-enhancements)
- [Echomail MCP Server](#echomail-mcp-server)
- [User Settings](#user-settings)
  - [Tabbed Layout](#tabbed-layout)
  - [Notification Sound Preview](#notification-sound-preview)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

---

## Summary of Changes

**Interests**
- New Interests system: admins define named topic groups (e.g. "Retro Gaming", "Amateur Radio") by bundling echo areas and file areas together. Users subscribe to an interest and are automatically subscribed to all its member echo areas.
- Admin management page at `/admin/interests`: create, edit, and delete interests; assign echo areas and file areas; configure icon, color, and display order; toggle active/inactive.
- User interest picker at `/interests`: card grid showing all active interests with subscribe/unsubscribe toggle. Users can subscribe to all echo areas at once or pick individual areas from a list.
- Interests tab added to the echomail reader sidebar (desktop) and mobile accordion alongside the Area List tab. Selecting an interest loads a unified message feed from all its echo areas.
- Selecting individual areas to subscribe/unsubscribe is supported from both the interest picker and the echo area list.
- When an admin adds a new echo area to an existing interest, all current interest subscribers are automatically subscribed to the new area (unless they previously explicitly unsubscribed from it).
- Multi-interest source tracking: unsubscribing from one interest does not remove echo areas that are also covered by another interest the user remains subscribed to.
- A **Generate Suggestions** wizard on the admin interests page analyzes the echo area catalog and proposes interest groupings using keyword matching. If `ANTHROPIC_API_KEY` is configured, it additionally offers AI-assisted classification for higher-quality results.
- First-time users visiting `/echomail` with no interest subscriptions are automatically redirected to `/interests` to complete onboarding. This happens only once per user.
- The `/interests` page includes a **Go to Echo Areas** button at the bottom to return to the echomail reader after subscribing.
- The Activity Statistics admin page (`/admin/activity-stats`) includes a new **Popular Interests** tab showing active interests ranked by subscriber count.
- Controlled by `ENABLE_INTERESTS` in `.env`; defaults to `true`.
- New documentation: `docs/Interests.md`.
- First-time users visiting `/echomail` with no interest subscriptions are now redirected to `/echo-onboarding` (a new onboarding guide page) instead of directly to `/interests`. The guide explains what echomail is, how the network works, and how to get started, then offers a "Next: Select Interests" button and a skip link.
- Users can reset the onboarding flag from the Settings page to revisit the guide.
- The Area List tab in the echomail reader sidebar (desktop) and mobile accordion now includes an interest filter dropdown above the search box. Selecting an interest narrows the area list to only the echo areas belonging to that interest. The first option, "All Subscribed Areas", restores the full unfiltered list. The dropdown is only shown when interests are enabled and at least one active interest exists.

**Echomail & Netmail**
- The compose form now shows a warning when the message body approaches the 32 KB FTN packet limit, and an error if it exceeds it.
- Sender name popovers in the echomail list now display in plain text style (no underline) for a cleaner appearance.
- The echomail Advanced Search modal now includes a **Message ID** field that searches the `message_id` column using a partial (case-insensitive) match.

**User Settings**
- The settings page has been reorganized from a single long card into a tabbed layout with a left-side navigation panel. Tabs: **Display**, **Messaging**, **Notifications**, and **Account**. The active tab is remembered across page visits via `localStorage`.
- Notification sound select boxes now have a play button (▶) to preview the selected sound without leaving the settings page.

**Echomail MCP Server**
- A new optional Model Context Protocol (MCP) server is included in `mcp-server/`. It provides AI assistants (Claude Code, etc.) with read-only access to the echomail and echoareas tables via six tools: `list_echoareas`, `get_echoarea`, `get_echomail_messages`, `get_echomail_message`, `search_echomail`, and `get_echomail_thread`.
- Authentication uses a personal bearer key generated by each user from **Settings → AI**. Keys are stored in the `users_meta` table and enforce the same echoarea access rules as the web interface.
- The server reads the main BinktermPHP `.env` file directly (using `DB_PASS`).
- This component is entirely optional and is not started by the main application or daemon scripts.

**BinkP Configuration**
- The poll schedule input on the uplink configuration screen (both the admin page and the user BinkP page) now has a **schedule builder** toggle button. Clicking it opens an inline panel that parses the current cron expression into its five individual fields (Minute, Hour, Day, Month, Weekday). Editing any field immediately rebuilds the expression in the input and shows a human-readable description of the resulting schedule. The builder now handles a wider range of patterns including `*/N` steps in any field, comma lists and ranges in the Weekday field (e.g. `1,2` → Monday, Tuesday; `1-5` → weekdays; `0,6` → weekends), combined step expressions (e.g. `*/5 */4 * * *`), and a compositional fallback for complex combinations.
- Packet filenames in the Inbound and Outbound queue lists on the Queues tab are now clickable. Clicking a `.pkt` filename opens the existing packet inspector modal showing packet header details and a message list. A download button is also available. Requires a valid license.
- Uplinks now have an **Allow insecure echomail delivery** checkbox. When ticked, echomail is accepted from this node even when it connects without authentication, provided the node address claimed during the session matches the uplink's configured address. The **Insecure Receive Only** security setting is now enforced: when enabled, unauthenticated sessions cannot receive outbound files, hold-directory files, or serve FREQ requests.

  > ⚠️ **Insecure echomail delivery is not recommended.** Because unauthenticated sessions cannot verify the caller's identity, a malicious node could claim any FTN address. This option exists only as a last resort for legacy systems that cannot support BinkP passwords. The strongly preferred approach is to configure a shared session password on both ends.

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

When a user visits `/echomail` for the first time after Interests is enabled and they have no interest subscriptions, they are automatically redirected to `/interests` to choose their interests before reading mail. The redirect happens only once — subsequent visits go straight to the echomail reader regardless of subscription state.

A **Go to Echo Areas** button at the bottom of the `/interests` page takes users directly back to `/echomail` after they have finished subscribing.

### Echo Area List Integration

The echomail reader (`/echomail`) now shows an **Interests** tab in both the desktop sidebar card and the mobile "Viewing" accordion. The Interests tab appears first and is active by default when interests exist.

Selecting an interest from the tab loads a unified, paginated message feed from all its echo areas — the same view used for individual echo areas, with the same filter and sort controls.

The **Manage Subscriptions** button (mobile) and the wrench icon link (desktop) swap to **Manage Interests** and link to `/interests` while the Interests tab is active, and revert to **Manage Subscriptions** → `/subscriptions` when the Area List tab is active.

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

The wizard uses keyword matching by default. If `ANTHROPIC_API_KEY` is set in `.env`, it offers an additional AI-assisted mode that produces higher-quality groupings for areas with ambiguous or abbreviated tags.

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

The echomail and netmail compose forms now show a warning indicator when the message body exceeds 24 KB (75% of the 32 KB FTN packet limit) and an error if it exceeds 32 KB. FTN packets cannot carry messages larger than 32 KB; this warning helps users avoid silent truncation at the network level.

### Sender Name Popover Style

The sender name in the echomail message list is no longer underlined. The popover (showing BBS name, FTN address, and quick-action buttons) is still triggered by clicking the name; only the visual style has changed.

### Advanced Search: Message ID Field

The echomail **Advanced Search** modal now includes a **Message ID** field. Entering a value performs a case-insensitive partial match (`ILIKE`) against the `message_id` column of the `echomail` table, allowing you to search by a fragment of a FidoNet message ID without knowing the full value. The field follows the same two-character minimum rule as the other Advanced Search text fields and is combined with them using AND logic.

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

### Admin — BinkP Config

- **Uplinks table cleanup**: The uplinks table columns have been consolidated — Hostname and Port are now shown as a single Host column, and Enabled/Default are combined into a Status badge column. Markdown, Posting Name, and ADR @Domain are no longer shown in the table (they remain editable in the uplink modal).
- **Uplinks table responsive**: The uplinks table is now responsive. On smaller screens, less critical columns are hidden progressively: Me and Domain are hidden below `sm`, Host below `md`, and Schedule below `lg`. Uplink, Status, and Actions are always visible.

---

## Echomail MCP Server

An optional Model Context Protocol server lives in `mcp-echomail/`. It allows AI assistants that support MCP (such as Claude Code) to query the echomail and echoareas tables in read-only mode over HTTP.

### Setup

```bash
cd mcp-echomail
cp .env.example .env   # fill in keys and DB credentials
npm install
node server.js
```

### Configuration

`mcp-echomail/.env`:

```
MCP_PORT=3740
MCP_API_KEYS=your-secret-key-here
DB_HOST=localhost
DB_PORT=5432
DB_NAME=binktest
DB_USER=binkterm
DB_PASSWORD=yourpassword
```

Generate a key with:

```bash
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
```

Multiple keys can be listed comma-separated in `MCP_API_KEYS` (useful for rotating keys or multiple clients).

### Available Tools

| Tool | Description |
|------|-------------|
| `list_echoareas` | List active echo areas, optionally filtered by domain |
| `get_echoarea` | Details for a specific area by tag |
| `get_echomail_messages` | Paginated messages from an area with sender/subject/date filters |
| `get_echomail_message` | Full text of a single message by ID |
| `search_echomail` | Cross-area search against subject and message body |
| `get_echomail_thread` | Complete conversation thread from any message in it |

### Wiring into Claude Code

Add to `.claude/settings.json` (or the global Claude Code settings):

```json
{
  "mcpServers": {
    "echomail": {
      "type": "http",
      "url": "http://your-server:3740/mcp",
      "headers": {
        "Authorization": "Bearer your-secret-key-here"
      }
    }
  }
}
```

The server is entirely optional and is not started by BinktermPHP or its daemon scripts.

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

---

## Upgrade Instructions

### From Git

```bash
git pull origin main
composer install
php scripts/setup.php
```

`setup.php` runs migrations `v1.11.0.49_interests.sql` and `v1.11.0.50_interest_echo_sources.sql` automatically.

No `.env` changes are required. To opt out of the Interests feature, add:

```
ENABLE_INTERESTS=false
```

### Using the Installer

Run the installer as normal. When prompted to run `php scripts/setup.php`, allow it to complete — this applies the two new database migrations.
php scripts/setup.php
```

### Using the Installer

Re-run the BinktermPHP installer to upgrade the application files, then restart
the daemons if your deployment manages them separately.
