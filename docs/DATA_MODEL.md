# Data Model

This document describes the key database tables and their conceptual relationships. It is a mental model for developers, not a full schema reference — see `database/migrations/` for authoritative column definitions.

---

## Table of Contents

1. [Core Message Tables](#core-message-tables)
2. [User Tables](#user-tables)
3. [FTN Network Tables](#ftn-network-tables)
4. [Real-Time Tables](#real-time-tables)
5. [Supporting Tables](#supporting-tables)
6. [Entity Relationship Overview](#entity-relationship-overview)

---

## Core Message Tables

### `echomail`

The central table. Stores every public FTN message received or posted.

| Column | Notes |
|--------|-------|
| `id` | Primary key |
| `echoarea_id` | FK → `echoareas.id` |
| `from_name` | Sender's display name (FTN real name) |
| `from_address` | Sender's FTN address (zone:net/node.point) |
| `to_name` | Addressee (usually `All` for public posts) |
| `subject` | Message subject line |
| `body` | Full message text |
| `date_written` | Timestamp from the FTN packet header (unreliable — sender's clock) |
| `date_received` | Server-side timestamp set at import (`NOW() AT TIME ZONE 'UTC'`) — always reliable |
| `message_id` | The FTN `@MSGID` kludge value; used for deduplication and threading |
| `reply_to_id` | FK → `echomail.id`; self-referential parent for thread walks |
| `kludge_lines` | Raw kludge lines from the original packet (includes `CHRS`, `TZUTC`, etc.) |
| `message_charset` | Normalized charset for encoding/decoding (e.g. `CP437`, `UTF-8`) |
| `art_format` | Set when the message is ANSI, Sixel, RIPscrip, etc. |
| `qwk_mailbox_id` / `qwk_conference_number` / `qwk_msg_number` | Present on inbound QWK-network messages for deduplication and reply mapping |
| `source_msgid` | Original upstream or gated message identifier used to prevent duplicate mirrored copies |

**Key rule**: prefer `date_received` for display ordering; show `date_written` only as supplementary information (it can be wrong or in the future if the sender's clock is off). Future-dated `date_written` values are suppressed from message list queries until they are no longer in the future.

### `netmail`

Private point-to-point FTN messages. Structure mirrors `echomail` but without an `echoarea_id`. Has `to_address` (the recipient's FTN address) and `is_read`, `is_deleted` per-message state. Attachments are stored as files referenced by `attachment_filename`.

### `echoareas`

One row per echo area (conference/forum).

| Column | Notes |
|--------|-------|
| `id` | Primary key |
| `tag` | Area tag (e.g. `GENERAL`) — case-insensitive in queries |
| `domain` | Network domain (e.g. `fidonet`, `lovlynet`) — allows the same tag in multiple networks |
| `description` | Human-readable area name |
| `is_local` | When true, messages are never forwarded to uplinks |
| `is_active` | Inactive areas are hidden from all queries and API responses |
| `is_sysop_only` | When true, only admin users can see the area |
| `moderator` | FTN address of the area moderator, if any |

The `(tag, domain)` pair is the logical key. Code that looks up areas by tag must also filter by domain when multiple networks are connected.

---

## User Tables

### `users`

| Column | Notes |
|--------|-------|
| `id` | Primary key |
| `username` | Login identifier — unique, case-insensitive |
| `real_name` | FTN display name used in message headers — unique, case-insensitive |
| `password_hash` | bcrypt hash |
| `is_active` | Soft-delete / pending approval flag |
| `is_approved` | Set by admin after registration review |
| `is_admin` | Full sysop access |
| `credit_balance` | Current credit balance (modified only via `user_transactions`) |
| `last_login` | Timestamp of most recent login |
| `referral_code` | For the referral system |

Both `username` and `real_name` are enforced as unique to prevent impersonation. A database trigger fires on insert/update to catch collisions across both columns simultaneously.

### `users_meta`

Key-value store for arbitrary user metadata.

| Column | Notes |
|--------|-------|
| `user_id` | FK → `users.id` |
| `keyname` | String key (e.g. `mcp_serverkey`, `user_settings`) |
| `valname` | String value (may be JSON for structured data) |

Used for: MCP bearer keys, per-user AI and display settings, daily login tracking, and other extensible preferences. Unique index on `(user_id, keyname)`.

### `user_transactions`

Append-only credit ledger. Every credit change goes through here via `UserCredit::transact()`. The `users.credit_balance` column is the running total; `user_transactions` is the audit trail.

| Column | Notes |
|--------|-------|
| `user_id` | FK → `users.id` |
| `amount` | Positive for credit, negative for debit |
| `description` | Human-readable reason (e.g. `Daily login bonus`, `Netmail sent`) |
| `created_at` | Transaction timestamp |

---

## FTN Network Tables

### `echoareas`

See [Core Message Tables](#core-message-tables) above.

### `user_echoarea_subscriptions`

Many-to-many join between users and echo areas. Controls which areas appear in a user's message list and which areas the system exports to each uplink.

### `nodelist` / `nodelist_metadata` / `nodelist_flags`

Imported FTN nodelist data. `nodelist` holds one row per node (zone, net, node, point, name, location, sysop, phone, baud, flags). `nodelist_metadata` tracks the import date and nodelist edition. `nodelist_flags` normalizes the per-node capability flags.

### `binkp_session_log`

One row per completed binkp session (inbound or outbound). Records duration, bytes exchanged, files transferred, and outcome. Used by the admin analytics dashboard.

### `networks`

Logical message networks and their posting capabilities. A QWK-capable network
such as DoveNet can be represented here with `network_type = 2`, but transport
credentials live separately in `qwk_mailboxes`.

### `qwk_mailboxes`

Remote QWK transport accounts. One mailbox can carry conferences from multiple
logical networks.

| Column | Notes |
|--------|-------|
| `id` | Primary key |
| `name` | Friendly admin label |
| `bbs_id` | Remote QWK packet ID |
| `host` / `port` | FTP endpoint used for packet exchange |
| `username` / `password` | Remote login credentials; password is stored encrypted |
| `ftp_remote_path` | Remote directory containing `.QWK` and `.REP` packets |
| `poll_schedule` | Optional scheduler hint / cron-like expression |
| `enabled` | Whether the mailbox should be polled |
| `last_polled_at` / `last_error` | Status from the last poll attempt |

### `echo_area_qwk_subscriptions`

Maps a local echo area to a conference number on a specific QWK mailbox.

| Column | Notes |
|--------|-------|
| `echoarea_id` | FK → `echoareas.id` |
| `mailbox_id` | FK → `qwk_mailboxes.id` |
| `conference_tag` | Remote or admin label for the conference |
| `conference_number` | Remote QWK conference number used in packets |
| `auto_created` | Whether the mapping was auto-created from inbound traffic |

These rows drive both directions: inbound `.QWK` import routing and outbound
`.REP` queue generation.

### `qwk_outbound_messages`

Queue table for local echomail messages that still need to be exported to one
or more QWK mailboxes.

| Column | Notes |
|--------|-------|
| `echomail_id` | FK → `echomail.id` |
| `mailbox_id` | FK → `qwk_mailboxes.id` |
| `queued_at` | When the message was queued for export |
| `sent_at` | Set after a successful `.REP` upload |

### `echo_area_gates`

Defines local cross-area mirroring rules for echoarea message gating across
multiple networks and import paths.

| Column | Notes |
|--------|-------|
| `source_area_id` | FK → `echoareas.id` |
| `target_area_id` | FK → `echoareas.id` |
| `bidirectional` | When true, the same row mirrors traffic both ways |

---

## Real-Time Tables

### `sse_events`

The inter-process event bus. Any code can insert a row; BinkStream delivers it to connected browsers. Defined as `UNLOGGED` so inserts are fast — rows are transient delivery state, not persistent data.

| Column | Notes |
|--------|-------|
| `id` | Monotonic sequence — used as the stream cursor |
| `event_type` | String type name (e.g. `chat_message`, `new_echomail`) |
| `payload` | JSONB — fat payload containing all fields needed to render the event |
| `user_id` | FK → `users.id` — if set, only that user receives the event |
| `admin_only` | When true, only admin users receive the event |
| `created_at` | Pruned after one hour by the admin daemon |

See [BinkStreamChannel.md](BinkStreamChannel.md) for the full architecture.

---

## Supporting Tables

| Table | Purpose |
|-------|---------|
| `message_read_status` | Tracks which echomail/netmail messages each user has read |
| `saved_messages` | User-bookmarked messages |
| `shared_messages` | Webshare links for publicly accessible messages (see key columns below) |
| `drafts` | Saved message drafts |
| `address_book` | Per-user FTN address book entries |
| `chat_messages` | Local shoutbox and MRC chat history |
| `mrc_rooms` / `mrc_messages` / `mrc_users` | MRC multi-relay chat state |
| `dosbox_doors` / `door_sessions` | Door game definitions and active session tracking |
| `webdoor_sessions` | WebDoor session tokens |
| `fileareas` | File area definitions (tag, domain, description, path) |
| `shared_files` | Files shared via the webshare system |
| `freq_log` / `freq_outbound` | File request (FREQ) history and outbound queue |
| `qwk_conference_state` / `qwk_message_index` | Per-user QWK offline mail reader state |
| `qwk_mailboxes` / `echo_area_qwk_subscriptions` / `qwk_outbound_messages` / `echo_area_gates` | QWK network exchange configuration, queueing, and local gating |
| `interests` / `interest_echoareas` / `user_interest_subscriptions` | Topic-based area groupings |
| `ai_requests` | Per-request AI usage accounting |
| `ai_bots` / `ai_bot_activities` | AI bot definitions and activity log |
| `packet_bbs_nodes` / `packet_bbs_sessions` | PacketBBS node registrations and radio sessions; `packet_bbs_sessions.session_state` stores flexible JSON command context such as current area, current message, and guided-flow state |
| `meshcore_contacts` | MeshCore companion contact list; rows are created by the bridge or pre-registered by users; `pub_key_full` is unique when known (partial unique index); `user_id` links to the owning BBS user |
| `meshcore_device_commands` | Queue of pending commands to be executed on a MeshCore radio by the bridge (e.g. `remove_contact`); populated on contact deletion; bridge polls and ACKs each row |
| `bulletins` / `bulletin_reads` | Sysop bulletin board |
| `bbs_directory` | Network BBS directory populated by echomail robots |
| `gateway_tokens` | SSO tokens for external service authentication |
| `password_reset_tokens` | Time-limited password reset tokens |
| `activity_categories` / `activity_types` / `user_activity_log` | User activity analytics |

---

### `shared_messages`

| Column | Type | Description |
|--------|------|-------------|
| `share_key` | `TEXT` | 32-char hex identifier, used in `/shared/{shareKey}` URLs |
| `message_id` / `message_type` | `INTEGER` / `TEXT` | References the shared echomail or netmail row |
| `shared_by_user_id` | `INTEGER` | FK → `users.id` |
| `area_identifier` | `TEXT` | Echoarea tag (used in friendly-URL slugs) |
| `slug` | `TEXT` | Human-readable URL slug (e.g. `hello-world`) |
| `og_image_path` | `TEXT` | Absolute filesystem path to the uploaded OG preview image |
| `og_image_slug` | `TEXT` | Filename with extension (e.g. `abc123….jpg`); used as the URL parameter for `/shared-image/{og_image_slug}` |
| `ai_og_summary` | `TEXT` | AI-generated summary injected into `og:description` |
| `is_active` | `BOOLEAN` | Whether the share link is live |
| `is_public` | `BOOLEAN` | Whether anyone can view without authentication |
| `expires_at` | `TIMESTAMPTZ` | Optional expiry; `NULL` means never expires |
| `access_count` | `INTEGER` | Running count of page views |

The `og_image_slug` is stored as the basename of `og_image_path` and is the canonical URL parameter for `/shared-image/`. Because it includes the file extension, social media crawlers can infer the image format from the URL.

## Entity Relationship Overview

```
users ──────────────────────────────────────────────────────────────┐
  │                                                                  │
  ├── users_meta (key-value preferences and secrets)                 │
  │                                                                  │
  ├── user_transactions (credit ledger)                              │
  │                                                                  │
  ├── user_echoarea_subscriptions ──── echoareas                     │
  │                                        │                         │
  │                                        └── echomail ─────────────┤
  │                                                │                 │
  │                                                └── (reply_to_id) │
  │                                                    (self-ref)    │
  ├── netmail (to/from users or FTN addresses)                       │
  │                                                                  │
  ├── message_read_status (echomail + netmail)                       │
  │                                                                  │
  ├── saved_messages                                                 │
  │                                                                  │
  ├── door_sessions ──── dosbox_doors                                │
  │                                                                  │
  ├── meshcore_contacts (user_id optional; bridge_node_id → packet_bbs_nodes)
  │                                                                  │
  └── ai_requests                                                    │
                                                                     │
sse_events (user_id optional FK → users) ───────────────────────────┘
nodelist (imported FTN network directory, no FK to users)
binkp.json (FTN uplink configuration — stored as JSON, not in the database)
```

The most important relationship for day-to-day development is `users → user_echoarea_subscriptions → echoareas → echomail`. Almost every message-related query joins these four tables.
