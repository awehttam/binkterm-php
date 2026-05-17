# Local Chat

Local Chat is BinktermPHP's built-in messaging system. It provides room-based group chat and direct messages (DMs) between users, delivered through the BinkStream real-time channel for web clients and through polling for terminal clients.

Local Chat is available in both the web UI and the shared terminal server used by Telnet and SSH sessions.

---

## Table of Contents

1. [Overview](#overview)
2. [Chat Rooms](#chat-rooms)
3. [Direct Messages](#direct-messages)
4. [Access Methods](#access-methods)
5. [Real-Time Delivery](#real-time-delivery)
6. [User Commands](#user-commands)
7. [Online Users List](#online-users-list)
8. [Admin Controls](#admin-controls)
9. [Moderation](#moderation)
10. [Matterbridge Integration](#matterbridge-integration)
11. [AI Bot Integration](#ai-bot-integration)
12. [Enabling and Disabling Chat](#enabling-and-disabling-chat)
13. [Database Schema](#database-schema)
14. [API Reference](#api-reference)

---

## Overview

Local Chat is organized around named rooms. Any number of rooms can be created from the admin panel. A default **Lobby** room is created on installation and cannot be deleted.

Messages are stored permanently in the `chat_messages` table. Real-time delivery goes through the `sse_events` transient queue, which is independent of message storage. Losing the `sse_events` queue (e.g., a Postgres crash) does not lose chat history — it only affects in-flight delivery.

---

## Access Methods

### Web UI

The browser chat UI uses BinkStream (WebSocket or SSE) for live delivery with a polling fallback when a real-time stream is not active.

### Terminal Server

The shared terminal server exposes Local Chat from the main menu for both Telnet and SSH users.

- Press `C` at the main menu to open **Local Chat**
- The current wide layout uses a left navigation pane, a larger message pane, and a full-width compose box
- Online users are shown in the left navigation pane rather than in a dedicated sidebar
- Messages are rendered as terminal Markdown, matching the message-body renderer used by echomail and netmail viewers
- Re-entering terminal chat restores the last selected room or DM from user metadata
- The terminal client uses polling rather than SSE/BinkStream for updates while the chat screen is open

See [Terminal Server](TerminalServer.md#local-chat) for terminal controls and layout details.

---

## Chat Rooms

Rooms are created and managed at **Admin → Chat Rooms**. Each room has a name and an optional description. Rooms can be marked inactive to hide them from users without deleting their message history.

Room messages are broadcast to all connected users. The BinkStream delivery trigger inserts each message into `sse_events` with `user_id = NULL`, which BinkStream delivers to every authenticated session.

---

## Direct Messages

DMs are one-to-one messages between two users. They are stored in `chat_messages` with `room_id = NULL` and `to_user_id` set to the recipient. The BinkStream trigger targets the recipient by setting `user_id` to the recipient's ID on the `sse_events` row, so only that user receives the real-time event.

DMs are not bridged to Matterbridge.

---

## Real-Time Delivery

Web chat uses BinkStream as its primary delivery channel. The flow for a room message is:

1. User POSTs to `POST /api/chat/send`.
2. `ChatMessageService::sendMessage()` inserts into `chat_messages` inside a transaction.
3. A PostgreSQL trigger (`trg_chat_message_notify`) fires on the INSERT and writes a fat payload — containing all fields needed to render the message — into `sse_events`.
4. The transaction commits.
5. BinkStream delivers the `sse_events` row to connected browsers over WebSocket or SSE.
6. Browsers that do not have an active BinkStream connection fall back to polling `GET /api/chat/poll` every second.

Browser clients de-duplicate messages by ID so a message received through both BinkStream and the poll fallback is displayed only once.

Terminal clients do not consume the BinkStream channel directly. They load room and DM history through `GET /api/chat/messages`, anchor their poll cursor with `GET /api/chat/cursor`, and then poll `GET /api/chat/poll` for background updates while separately refreshing the active conversation snapshot.

See [BinkStream Back-Channel](BinkStreamChannel.md) for transport details, Apache buffering caveats, and debugging tools.

---

## User Commands

Any user can type these commands in the message input field:

| Command | Effect |
|---|---|
| `/help` | Shows the available command list |
| `/source` | Returns the GitHub repository URL |

Admin users also have access to moderation commands. See [Moderation](#moderation).

---

## Online Users List

`GET /api/chat/online` returns the list of currently active users and any AI bots that are configured. The current user is excluded from the response. Each entry includes `user_id`, `username`, `location`, and `is_bot`.

---

## Admin Controls

### Chat Rooms

Go to **Admin → Chat Rooms** to:

- Create a new room (name and optional description)
- Edit a room's name, description, or active status
- Delete a room (the Lobby room cannot be deleted)
- Configure Matterbridge bridging per room

### Feature Flag

Local Chat can be toggled at **Admin → BBS Settings** via the `chat` feature flag. When disabled, all `/api/chat/*` endpoints return an error and the chat UI is hidden.

---

## Moderation

### In-message commands (admin only, rooms only)

| Command | Effect |
|---|---|
| `/kick <username>` | Temporarily removes the user from the room for 10 minutes |
| `/ban <username>` | Permanently removes the user from the room |

These commands work only in room messages, not in DMs. Admins cannot moderate themselves.

### Moderation API

`POST /api/chat/moderate` accepts `room_id`, `user_id`, and `action` (`kick` or `ban`). This endpoint requires admin authentication.

### How bans are enforced

Bans are stored in `chat_room_bans`. When `ChatMessageService::sendMessage()` inserts a room message, it uses a `WHERE NOT EXISTS` clause to check for an active ban on the sender. If an active ban (permanent or not yet expired) is found, the INSERT returns no row and the send is blocked.

Bans are per-room. A user banned from one room can still post in other rooms.

---

## Matterbridge Integration

Each room can be individually bridged to external chat platforms (Discord, Slack, IRC, and others) via Matterbridge. When bridging is enabled for a room:

- Outbound: messages sent by local users are relayed to the configured Matterbridge gateway by `ChatMessageService`.
- Inbound: messages arriving from the external platform are injected into the room by `scripts/matterbridge_daemon.php`.

DMs are never bridged.

For configuration and architecture details, see [Matterbridge](Matterbridge.md).

---

## AI Bot Integration

AI bots can participate in local chat rooms and respond to DMs. The `LocalChatActivityHandler` in `src/AiBot/LocalChatActivityHandler.php` listens for two activity types:

- `chat_direct` — a DM sent directly to the bot user
- `chat_mention` — a room message containing `@BotUsername`

When triggered, the handler builds a conversation context from recent history, runs the bot middleware pipeline, checks the weekly budget, calls the AI provider, and sends the reply back via `ChatMessageService::sendMessage()`.

Per-bot configuration controls:
- `respond_in_dm` — whether the bot responds to DMs
- `respond_in_rooms` — whether the bot responds to room mentions
- `allowed_room_ids` — optional allowlist of rooms where the bot is active
- `blocked_user_ids` — optional list of users whose messages are ignored

See [AI Bots](AIBots.md) for full bot configuration and middleware documentation.

---

## Enabling and Disabling Chat

Chat is controlled by the `chat` feature flag:

- **Web UI**: Admin → BBS Settings → Features
- When disabled, all `/api/chat/*` endpoints return an error response and the chat UI is not shown to users.

---

## Database Schema

### `chat_rooms`

| Column | Type | Notes |
|---|---|---|
| `id` | `SERIAL` | Primary key |
| `name` | `VARCHAR(64)` | Unique room name |
| `description` | `VARCHAR(255)` | Optional description |
| `is_active` | `BOOLEAN` | Whether the room is visible to users |
| `created_at` | `TIMESTAMP` | Creation time |
| `matterbridge_enabled` | `BOOLEAN` | Whether Matterbridge bridging is active |
| `matterbridge_gateway` | `VARCHAR(100)` | Target Matterbridge gateway name |
| `matterbridge_options` | `JSONB` | Per-room bridge options (`username_template`, `username_suffix`) |

### `chat_messages`

| Column | Type | Notes |
|---|---|---|
| `id` | `SERIAL` | Primary key |
| `room_id` | `INT` | FK to `chat_rooms`; NULL for DMs |
| `from_user_id` | `INT` | FK to `users`; sender |
| `to_user_id` | `INT` | FK to `users`; DM recipient (NULL for room messages) |
| `body` | `TEXT` | Message body |
| `created_at` | `TIMESTAMP` | Send time |

A CHECK constraint ensures that exactly one of `room_id` or `to_user_id` is set on every row.

### `chat_room_bans`

| Column | Type | Notes |
|---|---|---|
| `id` | `SERIAL` | Primary key |
| `room_id` | `INT` | FK to `chat_rooms` |
| `user_id` | `INT` | Banned user |
| `banned_by` | `INT` | Admin who issued the ban |
| `reason` | `TEXT` | Optional reason |
| `created_at` | `TIMESTAMP` | Ban time |
| `expires_at` | `TIMESTAMP` | NULL = permanent; populated = temporary kick |

A UNIQUE constraint on `(room_id, user_id)` prevents duplicate ban rows.

---

## API Reference

All endpoints require authentication.

### `GET /api/chat/rooms`

Returns active chat rooms.

Response: `{ "rooms": [{ "id", "name", "description" }] }`

### `GET /api/chat/online`

Returns online users and active AI bots, excluding the requesting user.

Response: `{ "users": [{ "user_id", "username", "location", "is_bot" }] }`

### `GET /api/chat/messages`

Loads paginated chat history for a room or DM conversation.

Query parameters:

| Parameter | Required | Description |
|---|---|---|
| `room_id` | One of these | Room to load |
| `dm_user_id` | One of these | Other user in a DM conversation |
| `before_id` | No | Load messages before this ID (pagination) |
| `limit` | No | Number of messages (default 50, max 200) |

Response: `{ "messages": [...], "has_more": bool }`

Each message includes `id`, `type`, `room_id`, `room_name`, `from_user_id`, `from_username`, `to_user_id`, `body`, `markup_html`, `created_at`.

### `POST /api/chat/send`

Send a message to a room or user. Exactly one of `room_id` or `to_user_id` must be provided.

Request body:

| Field | Required | Description |
|---|---|---|
| `room_id` | One of these | Target room |
| `to_user_id` | One of these | Target user (DM) |
| `body` | Yes | Message text (1–1000 characters) |

Response: `{ "success", "message_id", "created_at", "local_message": {...} }`

Returns an error if the feature is disabled or the user is banned from the target room.

### `POST /api/chat/moderate`

Admin only. Kick or ban a user from a room.

Request body:

| Field | Description |
|---|---|
| `room_id` | Target room |
| `user_id` | User to moderate |
| `action` | `"kick"` (10 minutes) or `"ban"` (permanent) |

### `GET /api/chat/poll`

Fallback polling endpoint used when BinkStream is unavailable. Returns messages after the given cursor.

Query parameters: `since_id` — only return messages after this ID.

Returns up to 200 messages per request. Excludes messages from the requesting user and messages from inactive rooms.

### `GET /api/chat/cursor`

Returns the highest visible chat message ID for the current authenticated user.

Response: `{ "max_id": number }`

---

## Related Documentation

- [BinkStream Back-Channel](BinkStreamChannel.md) — real-time delivery infrastructure
- [Matterbridge](Matterbridge.md) — bridging rooms to Discord, Slack, IRC, and others
- [AI Bots](AIBots.md) — configuring bots that participate in chat
- [API Reference](API.md) — full HTTP endpoint reference
