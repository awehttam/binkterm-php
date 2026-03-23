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

**Echomail & Netmail**
- The compose form now shows a warning when the message body approaches the 32 KB FTN packet limit, and an error if it exceeds it.
- Sender name popovers in the echomail list now display in plain text style (no underline) for a cleaner appearance.
- The echomail Advanced Search modal now includes a **Message ID** field that searches the `message_id` column using a partial (case-insensitive) match.

**BinkP Configuration**
- The poll schedule input on the uplink configuration screen (both the admin page and the user BinkP page) now has a **schedule builder** toggle button. Clicking it opens an inline panel that parses the current cron expression into its five individual fields (Minute, Hour, Day, Month, Weekday). Editing any field immediately rebuilds the expression in the input and shows a human-readable description of the resulting schedule.
- Packet filenames in the Inbound and Outbound queue lists on the Queues tab are now clickable. Clicking a `.pkt` filename opens the existing packet inspector modal showing packet header details and a message list. A download button is also available. Requires a valid license.

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
