# Echo Areas

Echo areas are public message forums distributed across FidoNet-compatible (FTN) networks. Unlike netmail (private point-to-point messages), echomail is replicated — a message posted in one system propagates to every other system subscribed to the same area. BinktermPHP stores echo areas in the database, receives echomail from uplinks via the binkp mailer, and lets users read and post through both the web interface and the terminal server.

---

## Table of Contents

- [Concepts](#concepts)
- [Database Schema](#database-schema)
- [Admin Configuration](#admin-configuration)
  - [Screen Overview](#screen-overview)
  - [Creating an Area](#creating-an-area)
  - [QWK Subscriptions](#qwk-subscriptions)
  - [Gates](#gates)
  - [Editing and Deleting](#editing-and-deleting)
  - [Statistics](#statistics)
  - [Bulk CSV Import](#bulk-csv-import)
- [Inbound Message Flow](#inbound-message-flow)
- [Outbound Message Flow](#outbound-message-flow)
- [Echomail Moderation](#echomail-moderation)
- [User Subscriptions](#user-subscriptions)
- [Multi-Network Support](#multi-network-support)
- [Gemini Access](#gemini-access)
- [Related Documentation](#related-documentation)

---

## Concepts

### Tags and Domains

Every echo area is identified by a **tag** (e.g., `GENERAL`, `MIN_CHAT`) and a **domain** (e.g., `fidonet`, `lovlynet`). The combination of tag + domain is unique, so the same tag can exist independently in multiple networks. A local area has no domain (or an empty domain) and is never transmitted to any uplink.

### Local vs. Networked Areas

- **Networked areas** — messages may be distributed externally. Depending on the area's configuration, this can include FTN uplinks, QWK mailbox fanout, or both.
- **Local areas** (`is_local = true`) — messages are stored locally only and are never propagated to any external network transport. That means:
  - no FTN uplink spooling
  - no inter-BBS QWK mailbox distribution
  - no other external redistribution path

Local areas can still appear in the logged-in user's own offline QWK reader packet on this BBS, and replies uploaded by that same user can still be imported back into the local area. That user-facing offline-reader workflow is not considered external network propagation.

### Auto-Creation

When a packet arrives from an uplink containing a message for an area that does not yet exist in the database, BinktermPHP creates the area automatically. The description is set to `"Auto-created: TAGNAME@domain"`. Sysops can edit the description and other settings afterward.

---

## Database Schema

Echo areas are stored in the `echoareas` table. Key columns:

| Column | Type | Description |
|--------|------|-------------|
| `tag` | VARCHAR(50) | Area tag (uppercase). Unique per domain. |
| `description` | TEXT | Human-readable area description. |
| `domain` | VARCHAR(50) | Network domain (e.g. `fidonet`). Empty for local areas. |
| `uplink_address` | VARCHAR(20) | Uplink node address (e.g. `1:153/757`). Optional. |
| `moderator` | VARCHAR(100) | Moderator name. Optional. |
| `color` | VARCHAR(7) | Hex color for the web UI (default `#28a745`). |
| `is_active` | BOOLEAN | Whether the area is visible and accepting messages. |
| `is_local` | BOOLEAN | If true, the area is local-only: no FTN spooling, no inter-BBS QWK fanout, and no other external propagation. |
| `is_sysop_only` | BOOLEAN | If true, only admin users can access the area. |
| `gemini_public` | BOOLEAN | If true, the area is readable via the Gemini protocol without login. |
| `posting_name_policy` | VARCHAR(20) | Per-area name policy: `real_name`, `username`, or NULL to inherit. |
| `is_default_subscription` | BOOLEAN | If true, new users are automatically subscribed to this area. |
| `message_count` | INTEGER | Running count of messages received. |

The `echomail` table holds individual messages and references `echoareas.id`. The `user_echoarea_subscriptions` table tracks which users are subscribed to which areas.

---

## Admin Configuration

Echo areas are managed at **Admin → Echo Areas** (`/echoareas`).

### Screen Overview

The Echo Areas screen includes:

- a searchable area list with active/all/inactive filters
- domain filter buttons so you can narrow the list to one network or local areas
- summary statistics for active areas and message volume
- an **Import** button for bulk CSV or `.NA` imports
- a **Manage QWK Mailboxes** button for configuring QWK network peers used by area subscriptions

Default subscriptions are not managed on this screen. Those are configured separately at **Admin → Subscriptions**, where you choose which areas new users are auto-subscribed to.

### Creating an Area

Fill in the form fields:

- **Tag** — Required. Auto-converted to uppercase. Maximum 20 characters. Use alphanumeric characters, dots, underscores, or hyphens (e.g. `MICRONET.CHAT`).
- **Description** — Required. Displayed to users in the area list.
- **Network** — The FTN network this area belongs to. Choose **Local** for a local area; local areas have no domain (or an empty domain) and are never transmitted to any uplink. Available network options come from the configured networks list.
- **Uplink Address** — The node address of the upstream system for this area (e.g. `1:153/757`). Optional.
- **Moderator** — Name of the area moderator. Optional, displayed in area info.
- **Color** — Visual accent color for the web interface. Choose from presets or enter a hex value.
- **Posting Name Policy** — Controls how the user's name appears in outbound messages:
  - *(inherit)* — Use the policy configured for the network (default behavior).
  - `real_name` — Use the user's real name field.
  - `username` — Use the user's login username.
- **Art Format Hint** — Optional hint for terminal art rendering in this area.
  - *(inherit)* — Use the system or network default behavior.
  - `ansi` — Prefer IBM/PC ANSI assumptions.
  - `amiga_ansi` — Prefer Amiga-style ANSI assumptions.
- **Active** — Uncheck to disable the area without deleting it.
- **Local Only** — Check to make the area local-only. Local-only areas are never propagated to FTN uplinks, QWK mailbox peers, or any other external transport. They can still be included in a user's own offline QWK packet on this BBS.
- **Sysop Access Only** — Check to restrict the area to admin users.
- **Public Gemini Access** — Check to allow read-only access from Gemini protocol clients.
- **Allow Media** — Per-area override for inline media handling in messages:
  - *(inherit)* — Use the network-level media policy.
  - `allow` — Explicitly allow media in this area.
  - `deny` — Explicitly block media in this area.

If the area belongs to LovlyNet and the local description or recommended settings differ from the network metadata, the edit dialog can also show a **Sync** button to pull the LovlyNet description back into the local record.

### QWK Subscriptions

The lower part of the edit dialog includes a **QWK Subscriptions** panel. This is where you map the area to one or more conferences on configured QWK mailboxes.

Each subscription row contains:

- **Mailbox** — which configured QWK peer to use
- **Conference Tag** — the remote conference name or tag
- **Conference #** — the numeric conference mapping used in packets

This is area-level transport mapping. QWK mailbox hostnames, credentials, schedules, and FTP settings are managed from the separate **Manage QWK Mailboxes** dialog. For inter-BBS QWK transport details, see [docs/QWKNetworking.md](QWKNetworking.md).

### Imported Message Relay

The same edit dialog also includes an **Imported Message Relay** setting. This
controls only what happens after a message is imported from an external
transport such as FTN or inter-BBS QWK.

The available modes are:

- **No relay** — imported messages are stored in this area only
- **Auto relay** — imported messages are forwarded to all other connected transports for this area, skipping the same origin transport
- **Manual relay rules** — imported messages are forwarded only for explicitly allowed origin → target transport pairs

This relay policy does not change how local users posting directly into the
area behave. Local posts still use the area's normal outbound transport
subscriptions.

### Gates

The same edit dialog also includes a **Gates** panel. Gates are not QWK-specific even though they currently share the same configuration section.

A gate links two distinct echo areas so that newly imported or newly posted messages in one area are copied into the other area. This is used when the two sides must remain separate area records, such as:

- the same topic carried under different tags on two networks
- a local mirror area that relays into a networked area
- a QWK-backed area mirrored into an FTN-backed area

Each gate row contains:

- **Target Area** — the other local echo area record to mirror into
- **Bidirectional** — when enabled, traffic flows both ways; when disabled, it flows only from the current area to the target area

Important gate behavior:

- Gates apply to new messages only. They do not retroactively copy historical messages.
- The copied message is stored as a separate local `echomail` row in the target area.
- After the copy is created, the target area's own delivery rules apply. If the target area is networked, it may then spool to FTN, queue for QWK, or both.
- Loop protection uses the original/source message ID so a gated copy returning from another network is not imported again endlessly.
- Self-gates are not allowed.

### Editing and Deleting

Click the edit icon next to any area to modify its settings. When deleting an area, the confirmation dialog now asks what to do with any remaining messages:

- **Delete them** — removes the area and permanently deletes the messages in it.
- **Move them to another area** — reassigns the messages to a different echo area before deleting the original area. This is a local move only; it does not re-gate, re-spool, or republish those historical messages.

If you want to keep the area and its current message history intact, uncheck **Active** instead.

### Statistics

The admin panel shows a summary: number of active areas, total messages across all areas, and messages received today.

### Bulk CSV Import

Use **Import** to create or update multiple areas at once from a CSV file:

```
ECHOTAG,DESCRIPTION,DOMAIN
GENERAL,General Discussion,fidonet
MICRONET.CHAT,MicroNet Chat,micronet
LOCALNEWS,Local News and Announcements,
```

- The header row is required.
- A blank DOMAIN creates a local area.
- If an area with the same tag and domain already exists it is updated, not duplicated.
- The import is transactional — if any row fails validation, the entire import is rolled back.

---

## Inbound Message Flow

Inbound echomail can arrive through two external transports: FTN packets and inter-BBS QWK mailbox exchange.

### FTN Inbound

1. **Packet reception** — The binkp server writes received files to `data/inbound/`. Packets are named with `.pkt` (raw packet) or FTN day-of-week bundle extensions (`.su0`, `.mo1`, etc.).
2. **Processing** — `scripts/process_packets.php` (run by cron or triggered by the admin daemon) calls `BinkdProcessor::processInboundPackets()`.
3. **Packet parsing** — Each `.pkt` file is parsed according to FTS-0001. The packet password is validated against the configured uplink password, and packets from insecure sessions are rejected for echomail (only netmail is allowed from insecure sessions).
4. **Echomail detection** — A message is identified as echomail by the presence of an `AREA:` line and `SEEN-BY` kludges.
5. **Area resolution** — The `AREA:` tag and the source uplink's domain are used to look up the area. If no matching area exists, one is created automatically.
6. **Duplicate check** — The `MSGID` kludge is checked against existing messages for that area. Duplicates are silently skipped.
7. **Storage** — The message is stored in the `echomail` table. The `message_count` on the area is incremented. Kludge lines are split into top kludges and bottom kludges (SEEN-BY/PATH) per FTS-4009.
8. **Failure handling** — If processing fails, the original packet is moved to `data/undeliverable/` rather than deleted, so it can be reprocessed manually.

### Inter-BBS QWK Inbound

1. **Mailbox poll** — `scripts/qwk_poll.php` polls an enabled QWK mailbox, usually on a schedule or via the admin daemon.
2. **Packet download** — If the remote BBS has a `.QWK` packet waiting, BinktermPHP downloads it from the configured mailbox.
3. **Conference mapping** — Each inbound QWK message is matched to a local echo area using the mailbox ID plus the configured remote conference number.
4. **Auto-creation when needed** — If a conference mapping does not yet exist, BinktermPHP can auto-create a placeholder area and subscription for that remote conference.
5. **Duplicate check** — QWK duplicates are detected using the mailbox ID, conference number, and remote message number.
6. **Storage and linkage** — The message is stored in `echomail` with QWK source metadata so replies can preserve QWK threading and so the same message is not echoed straight back to the mailbox it came from.
7. **Post-import fanout** — Once stored, the message can still be copied through gates or delivered to other configured external transports according to the area's imported-message relay mode. Inter-BBS QWK imports are never sent back to the same originating mailbox.

Character encoding is detected via the `CHRS` kludge and converted to UTF-8 for storage.

---

## Outbound Message Flow

1. A user posts a message through the web interface or terminal server, or a remote message is imported into an area from FTN or QWK.
2. The message is stored in the `echomail` table.
3. If echomail moderation is enabled and the user has not yet earned unmoderated posting rights, the message is stored with `moderation_status = 'pending'` and held for sysop review (see [Echomail Moderation](#echomail-moderation) below). Otherwise it proceeds immediately.
4. If the message is approved for delivery, BinktermPHP evaluates the area's configured outbound paths:
   - **FTN outbound** — when the area belongs to an FTN-routable network, the message is spooled into outbound FTN packet flow for that uplink path
   - **QWK outbound** — when the area has QWK conference subscriptions, the message is queued for one or more mailbox-specific `.REP` packets
   - **Gates** — when the area has gate rules, the message is copied into the target local area or areas, and each target area then evaluates its own FTN/QWK outbound rules
5. QWK outbound delivery is mailbox-aware. If a message originally arrived from mailbox `A`, it is not queued straight back to mailbox `A`; only other configured destinations are considered.
6. During the next QWK poll cycle, pending messages for each mailbox are assembled into a `.REP` packet and uploaded to that remote BBS.
7. Local-only areas (`is_local = true`) never enter any external propagation queue. They remain on this BBS only, aside from the logged-in user's own offline-reader packet workflow.
8. The posting name in any FTN outbound packet is determined by the area's `posting_name_policy` (or the network's default policy if the area policy is unset).

For the mailbox-side details of QWK polling, downloads, uploads, and conference mapping, see [docs/QWKNetworking.md](QWKNetworking.md).

---

## Echomail Moderation

BinktermPHP includes an optional hold-for-approval queue for echomail posted by users who have not yet established a posting history. Once a user accumulates enough approved posts they are promoted automatically and never moderated again.

Moderation applies only to **networked** areas (`is_local = false`). Posts to local areas are always stored immediately and never enter any external propagation queue regardless of the user's moderation status.

### Enabling Moderation

Moderation is controlled by a single threshold in **Admin → BBS Settings**:

- **Echomail Moderation Threshold** (`echomail_moderation_threshold` in `bbs.json`) — the number of approved networked echomail posts a user must accumulate before they can post without moderation. The default is **0**, which disables the feature entirely. Set this to a positive integer (e.g. `5` or `10`) to enable the queue.

### Who Is Affected

| User type | Behaviour |
|-----------|-----------|
| Admin users (`is_admin = true`) | Always bypass moderation |
| Users with `can_post_netecho_unmoderated = true` | Post immediately |
| New users (flag is `false`) | Held for review when threshold > 0 |

All users who had at least one active session at the time the migration ran are grandfathered in with `can_post_netecho_unmoderated = true`, so existing accounts are never disrupted when the feature is first enabled. Only accounts created after the migration are subject to moderation.

### The Pending Queue

When a post is held, the message is stored in the `echomail` table with `moderation_status = 'pending'`. No outbound packet is written and no poll is triggered.

The author can see their own pending message in the message list with a **Pending Approval** indicator, so they know it was received. Other users cannot see it until it is approved.

### Sysop Moderation Page

The admin moderation queue is at **Admin → Area Management → Echomail Moderation** (`/admin/echomail-moderation`). It lists all pending messages with the area tag, author, subject, and submission date. Clicking a subject line opens a preview of the full message body.

Per-message actions:

- **Approve** — marks the message `approved`, spools it into an outbound packet, and triggers an immediate poll. Also checks whether the author should be auto-promoted (see below).
- **Reject** — marks the message `rejected`. The message is permanently suppressed; it is never transmitted and is no longer visible to the author.

### Automatic Promotion

Each time a message is approved, BinktermPHP counts that user's total approved networked echomail posts. If the count reaches or exceeds `echomail_moderation_threshold`, the user's `can_post_netecho_unmoderated` flag is set to `true` and they are never moderated again. No scheduled job is needed — the check runs at approval time.

### Database Columns

The following columns support this feature:

| Table | Column | Description |
|-------|--------|-------------|
| `echomail` | `user_id` | The local user account that submitted the message (nullable; NULL for messages received from remote systems). |
| `echomail` | `moderation_status` | `approved` (default), `pending`, or `rejected`. |
| `users` | `can_post_netecho_unmoderated` | When `true` the user bypasses the moderation gate. |

---

## User Subscriptions

Users subscribe to echo areas to receive them in their message feed. Subscription state is stored in `user_echoarea_subscriptions`.

- **Default subscriptions** — Areas marked `is_default_subscription = true` are automatically subscribed for every new user at registration.
- **Manual subscription** — Users can subscribe or unsubscribe from any area they have access to via **My Subscriptions** in the web interface.
- **Sysop-only areas** — Non-admin users cannot subscribe to or view areas marked `is_sysop_only`.
- Unsubscribing sets `is_active = false` on the subscription record; the history is retained.

---

## Multi-Network Support

BinktermPHP supports simultaneous membership in multiple FTN networks at the
system level. Each uplink selects a configured network domain in the BinkP
settings. When a packet arrives from an uplink, its domain is used to scope the
echo area lookup, so `GENERAL@fidonet` and `GENERAL@lovlynet` are stored and
managed as separate areas even though they share the same tag.

Important limitation:

- One echo area can belong to one FTN network domain at a time.
- An echo area cannot belong to more than one FTN network simultaneously.
- If you need the same tag on multiple FTN networks, create separate
  tag+domain areas such as `GENERAL@fidonet` and `GENERAL@lovlynet`.
- A local area is the non-networked case: it has no domain and belongs to no
  FTN network.

Domain names are managed in **Admin → Networks** and are available in the domain drop-down when creating areas.

---

## Gemini Access

Areas with `gemini_public = true` are accessible via the Gemini protocol without authentication. This allows Gemini browser users to read the area as a read-only capsule. Web and terminal server access still requires a valid login regardless of this setting.

---

## Related Documentation

- [docs/CONFIGURATION.md](CONFIGURATION.md) — BinkP uplink configuration including domain setup and packet passwords.
- [docs/FileAreas.md](FileAreas.md) — File areas, which follow a similar tag/domain model for file distribution.
