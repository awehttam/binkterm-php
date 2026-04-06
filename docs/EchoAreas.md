# Echo Areas

Echo areas are public message forums distributed across FidoNet-compatible (FTN) networks. Unlike netmail (private point-to-point messages), echomail is replicated — a message posted in one system propagates to every other system subscribed to the same area. BinktermPHP stores echo areas in the database, receives echomail from uplinks via the binkp mailer, and lets users read and post through both the web interface and the terminal server.

---

## Concepts

### Tags and Domains

Every echo area is identified by a **tag** (e.g., `GENERAL`, `MICRONET.CHAT`) and a **domain** (e.g., `fidonet`, `lovlynet`). The combination of tag + domain is unique, so the same tag can exist independently in multiple networks. A local area has no domain (or an empty domain) and is never transmitted to any uplink.

### Local vs. Networked Areas

- **Networked areas** — messages are exchanged with uplinks. Inbound messages arrive in packets; outbound messages are bundled into packets at the next poll.
- **Local areas** (`is_local = true`) — messages are stored locally only and never sent to uplinks. Useful for internal discussion, testing, or community areas that are not part of any FTN network.

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
| `is_local` | BOOLEAN | If true, messages are never sent to uplinks. |
| `is_sysop_only` | BOOLEAN | If true, only admin users can access the area. |
| `gemini_public` | BOOLEAN | If true, the area is readable via the Gemini protocol without login. |
| `posting_name_policy` | VARCHAR(20) | Per-area name policy: `real_name`, `username`, or NULL to inherit. |
| `is_default_subscription` | BOOLEAN | If true, new users are automatically subscribed to this area. |
| `message_count` | INTEGER | Running count of messages received. |

The `echomail` table holds individual messages and references `echoareas.id`. The `user_echoarea_subscriptions` table tracks which users are subscribed to which areas.

---

## Admin Configuration

Echo areas are managed at **Admin → Echo Areas** (`/echoareas`).

### Creating an Area

Fill in the form fields:

- **Tag** — Required. Auto-converted to uppercase. Maximum 20 characters. Use alphanumeric characters, dots, underscores, or hyphens (e.g. `MICRONET.CHAT`).
- **Description** — Required. Displayed to users in the area list.
- **Domain** — The FTN network this area belongs to. Leave blank for a local area. Available domains are discovered from the BinkP uplink configuration.
- **Uplink Address** — The node address of the upstream system for this area (e.g. `1:153/757`). Optional.
- **Moderator** — Name of the area moderator. Optional, displayed in area info.
- **Color** — Visual accent color for the web interface. Choose from presets or enter a hex value.
- **Posting Name Policy** — Controls how the user's name appears in outbound messages:
  - *(inherit)* — Use the policy configured for the uplink (default behavior).
  - `real_name` — Use the user's real name field.
  - `username` — Use the user's login username.
- **Active** — Uncheck to disable the area without deleting it.
- **Local Only** — Check to prevent messages from being sent to uplinks.
- **Sysop Access Only** — Check to restrict the area to admin users.
- **Public Gemini Access** — Check to allow read-only access from Gemini protocol clients.

### Editing and Deleting

Click the edit icon next to any area to modify its settings. Areas that contain messages **cannot be deleted** — deactivate them instead by unchecking **Active**. This preserves message history while hiding the area from users.

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

1. **Packet reception** — The binkp server writes received files to `data/inbound/`. Packets are named with `.pkt` (raw packet) or FTN day-of-week bundle extensions (`.su0`, `.mo1`, etc.).
2. **Processing** — `scripts/process_packets.php` (run by cron or triggered by the admin daemon) calls `BinkdProcessor::processInboundPackets()`.
3. **Packet parsing** — Each `.pkt` file is parsed according to FTS-0001. The packet password is validated against the configured uplink password, and packets from insecure sessions are rejected for echomail (only netmail is allowed from insecure sessions).
4. **Echomail detection** — A message is identified as echomail by the presence of an `AREA:` line and `SEEN-BY` kludges.
5. **Area resolution** — The `AREA:` tag and the source uplink's domain are used to look up the area. If no matching area exists, one is created automatically.
6. **Duplicate check** — The `MSGID` kludge is checked against existing messages for that area. Duplicates are silently skipped.
7. **Storage** — The message is stored in the `echomail` table. The `message_count` on the area is incremented. Kludge lines are split into top kludges and bottom kludges (SEEN-BY/PATH) per FTS-4009.
8. **Failure handling** — If processing fails, the original packet is moved to `data/undeliverable/` rather than deleted, so it can be reprocessed manually.

Character encoding is detected via the `CHRS` kludge and converted to UTF-8 for storage.

---

## Outbound Message Flow

1. A user posts a message through the web interface or terminal server.
2. The message is stored in the `echomail` table.
3. If echomail moderation is enabled and the user has not yet earned unmoderated posting rights, the message is stored with `moderation_status = 'pending'` and held for sysop review (see [Echomail Moderation](#echomail-moderation) below). Otherwise it proceeds immediately.
4. At the next binkp poll, `BinkdProcessor` selects pending outbound messages for areas where `is_local = false` and `is_active = true`, and bundles them into a packet destined for the configured uplink.
5. The posting name in the outbound packet is determined by the area's `posting_name_policy` (or the uplink's default policy if the area policy is unset).

---

## Echomail Moderation

BinktermPHP includes an optional hold-for-approval queue for echomail posted by users who have not yet established a posting history. Once a user accumulates enough approved posts they are promoted automatically and never moderated again.

Moderation applies only to **networked** areas (`is_local = false`). Posts to local areas are always stored immediately regardless of the user's moderation status.

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

BinktermPHP supports simultaneous membership in multiple FTN networks. Each uplink is configured with a domain name in the BinkP settings. When a packet arrives from an uplink, its domain is used to scope the echo area lookup, so `GENERAL@fidonet` and `GENERAL@lovlynet` are stored and managed as separate areas even though they share the same tag.

Domain names are discovered automatically from the BinkP uplink configuration and are available in the domain drop-down when creating areas.

---

## Gemini Access

Areas with `gemini_public = true` are accessible via the Gemini protocol without authentication. This allows Gemini browser users to read the area as a read-only capsule. Web and terminal server access still requires a valid login regardless of this setting.

---

## Related Documentation

- [docs/CONFIGURATION.md](CONFIGURATION.md) — BinkP uplink configuration including domain setup and packet passwords.
- [docs/FileAreas.md](FileAreas.md) — File areas, which follow a similar tag/domain model for file distribution.
