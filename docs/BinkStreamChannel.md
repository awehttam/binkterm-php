# BinkStream Back-Channel

BinktermPHP uses BinkStream as its browser-facing real-time channel. BinkStream is a shared command and event interface with multiple transports behind it:

- WebSocket when available
- SSE when WebSocket is unavailable or disabled
- short-window SSE as the degraded Apache-friendly fallback

The business logic is shared. `GET /api/stream`, `POST /api/stream`, and the standalone WebSocket server all use the same realtime core.

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Current Transport Model](#current-transport-model)
3. [Key Files](#key-files)
4. [The `sse_events` Table](#the-sse_events-table)
5. [Event Targeting](#event-targeting)
6. [Admin Daemon Role](#admin-daemon-role)
7. [The `/api/stream` Endpoint](#the-apistream-endpoint)
8. [Command Path](#command-path)
9. [SharedWorker (`binkstream-worker-v2.js`)](#sharedworker-binkstream-worker-v2js)
10. [Client Library (`binkstream-client.js`)](#client-library-binkstream-clientjs)
11. [Subscribing to Events in JavaScript](#subscribing-to-events-in-javascript)
12. [Adding a New Event Type](#adding-a-new-event-type)
13. [Connection Lifecycle](#connection-lifecycle)
14. [Debugging](#debugging)

---

## Architecture Overview

```text
PostgreSQL triggers / PHP app code
      |
      | INSERT INTO sse_events (event_type, payload, user_id, admin_only)
      v
src/Realtime/StreamService.php
      |
      +--> GET /api/stream      -> SSE event delivery
      |
      +--> WebSocket server     -> WS event delivery
      |
      v
src/Realtime/CommandDispatcher.php
      |
      +--> POST /api/stream     -> UI -> BBS commands in SSE mode
      |
      +--> WebSocket server     -> UI -> BBS commands in WS mode
      v
SharedWorker (public_html/js/binkstream-worker-v2.js)
      |
      v
Client library (public_html/js/binkstream-client.js)
      |
      v
window.BinkStream.on(...) / off(...) / send(...)
```

The important design rule is that WebSocket is not a separate realtime subsystem. It is another transport over the same command/event core used by `/api/stream`.

---

## Current Transport Model

The effective transport is controlled by `BINKSTREAM_TRANSPORT_MODE`.

Supported values today:

- `auto`
- `sse`
- `ws`

In `auto` mode:

- the server prefers WebSocket when the BinkStream daemon PID file exists
- the browser still confirms availability by successfully opening the socket and receiving the `connected` message
- if that handshake fails, the worker falls back to SSE
- while running on SSE fallback, the worker re-probes WebSocket every 30 seconds and switches back automatically if it becomes available

In `sse` mode:

- the worker skips WebSocket and uses `/api/stream` directly

In `ws` mode:

- the worker requires WebSocket and retries it with backoff if disconnected

### Apache note

Testing has shown that Apache + PHP-FPM can buffer SSE responses, including short-window SSE. Short windows reduce the delay but do not guarantee event-by-event delivery. This makes short-window SSE acceptable for notifications and degraded chat behavior, but it is not equivalent to real streaming.

For that reason:

- Apache deployments should treat SSE as a degraded compatibility path
- Caddy and direct PHP-FPM testing have shown correct realtime streaming behavior

---

## Key Files

| File | Purpose |
|---|---|
| `database/migrations/v1.11.0.55_sse_events_table.php` | Creates `sse_events` table and installs the initial DB trigger |
| `database/migrations/v1.11.0.57_sse_events_user_targeting.php` | Adds `user_id` / `admin_only` targeting columns and chat fat payload delivery |
| `src/Admin/AdminDaemonServer.php` | Periodic `sse_events` pruning |
| `src/Realtime/StreamService.php` | Shared event fetch, cursor anchor, SSE window resolution |
| `src/Realtime/CommandDispatcher.php` | Shared realtime command execution for HTTP and WebSocket |
| `src/Realtime/WebSocketServer.php` | Standalone WebSocket server |
| `scripts/realtime_server.php` | CLI daemon entrypoint for the WebSocket server |
| `routes/api-routes.php` | `GET /api/stream` for events and `POST /api/stream` for commands |
| `public_html/js/binkstream-worker-v2.js` | SharedWorker transport layer; chooses WS or SSE |
| `public_html/js/binkstream-client.js` | Per-tab client; exposes `window.BinkStream` |

---

## The `sse_events` Table

```sql
CREATE UNLOGGED TABLE sse_events (
    id          BIGSERIAL    PRIMARY KEY,
    event_type  VARCHAR(64)  NOT NULL,
    payload     JSONB        NOT NULL DEFAULT '{}',
    user_id     INTEGER      NULL REFERENCES users(id) ON DELETE CASCADE,
    admin_only  BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);
```

**UNLOGGED** means no WAL writes, so inserts are fast. The table is automatically truncated on a Postgres crash, which is acceptable because it is a transient delivery queue. Actual data lives in domain tables permanently.

**Why a separate table instead of using domain IDs?**

Using `chat_messages.id` as the stream cursor would break the moment a second event type is added. `sse_events.id` is a single monotonic sequence across all event types, so the cursor works correctly regardless of how many event types share the stream.

**Fat payload pattern:** triggers store all fields needed to render the event directly in the `payload` column. The hot delivery path reads `sse_events` alone and avoids joins.

**Retention:** the admin daemon deletes rows older than one hour from its main loop. Autovacuum handles dead tuples from those deletes.

---

## Event Targeting

Each row in `sse_events` carries two targeting columns that control which users receive it:

| `user_id` | `admin_only` | Delivered to |
|---|---|---|
| `NULL` | `FALSE` | All authenticated users |
| `NULL` | `TRUE` | Admin users only |
| `<id>` | `FALSE` | Specific user only |
| `<id>` | `TRUE` | Specific user, only if admin |

The delivery query enforces targeting with a simple WHERE filter:

```sql
SELECT id AS sse_id, event_type, payload::text AS event_data
FROM sse_events
WHERE id > :cursor
  AND (user_id IS NULL OR user_id = :user_id)
  AND (admin_only = FALSE OR admin_only = TRUE)
ORDER BY id ASC
LIMIT 200
```

The `admin_only` check uses an inlined SQL literal (`TRUE` or `FALSE`) rather than a bound parameter to avoid PostgreSQL boolean/text type issues with PDO.

For new event types, no changes to the delivery query are needed. Set `user_id` and `admin_only` when inserting into `sse_events` and targeting is enforced automatically.

---

## Admin Daemon Role

The admin daemon's only BinkStream-specific responsibility today is periodic cleanup:

```sql
DELETE FROM sse_events WHERE created_at < NOW() - INTERVAL '1 hour'
```

The daemon does not deliver events to browsers directly. Event delivery happens through `/api/stream` or the WebSocket server.

---

## The `/api/stream` Endpoint

`GET /api/stream` requires authentication and returns `Content-Type: text/event-stream`.

`session_write_close()` is called immediately after authentication to release the PHP session file lock. Without that, a long-lived stream would block other requests from the same browser session.

### Cursor

The endpoint needs a starting position to know which events to deliver. On reconnect, the worker passes the last seen `sse_events.id` as the `?cursor=` URL parameter. The endpoint also accepts the standard `Last-Event-ID` HTTP header, but the worker always supplies `?cursor=` explicitly because browsers can clear `EventSource.lastEventId` when the connection is manually closed.

Priority:

1. `Last-Event-ID` header
2. `?cursor=` query parameter
3. `0` on first connect

### First connection

On first connect, the endpoint anchors at the current max cursor without replaying historical events. Historical page state remains the responsibility of the page's normal load APIs.

### Reconnect with known cursor

The endpoint immediately runs the catch-up query and emits any pending events, then stays open for the configured SSE window, polling every 200 ms and sending keepalive comments every 15 seconds. When the window expires, production mode emits `event: reconnect` and closes so the worker can reconnect immediately.

### Polling window

`SSE_WINDOW_SECONDS` controls how long each SSE request stays open.

- normal default: `60`
- Apache + `BINKSTREAM_TRANSPORT_MODE=auto`: implicit default `2`
- built-in PHP dev server: forced `0`

An explicit `SSE_WINDOW_SECONDS` in `.env` always wins.

### Apache caveat

On Apache, even a short window may still be buffered and delivered in clumps at window close. That is why the system treats short-window SSE as degraded compatibility behavior, not a true fix for Apache realtime streaming.

---

## Command Path

Outbound events and inbound commands share the same interface but use different directions:

- `GET /api/stream`
  - BBS -> UI
  - SSE event delivery
- `POST /api/stream`
  - UI -> BBS
  - command submission when using SSE transport
- WebSocket
  - BBS -> UI events and UI -> BBS commands over one socket

From page code, the transport-specific details stay behind `window.BinkStream`:

```javascript
window.BinkStream.on('chat_message', handler);
window.BinkStream.send('get_dashboard_stats', {});
```

In WebSocket mode, `send()` writes a command frame to the socket. In SSE mode, `send()` uses `POST /api/stream` and returns the command result over HTTP.

---

## SharedWorker (`binkstream-worker-v2.js`)

The SharedWorker owns one active transport for the whole origin and fans events out to all tabs through `MessagePort`.

It is responsible for:

- maintaining the last seen `sse_events.id` cursor
- trying the preferred transport
- falling back from WebSocket to SSE when needed
- re-probing WebSocket every 30 seconds while running on SSE fallback
- reporting active transport changes back to tabs

### Cursor tracking

The worker keeps a `lastCursor` value containing the most recent `sse_events.id`. On SSE reconnect it sends that cursor back to `/api/stream`. In WebSocket mode the same cursor is used for initial catch-up and reconnect continuity.

### Connection lifecycle

```text
auto mode
  -> try WebSocket first when server preference is ws
  -> if WS reaches connected: use ws
  -> if WS handshake fails: switch to sse
  -> while on sse fallback: re-probe ws every 30 s
```

### Dynamic event type subscription

Tabs call `BinkStream.on('some_type', fn)`, which causes the client to send `{action: 'subscribe', type: 'some_type'}` to the worker. The worker tracks subscribed types and re-registers them whenever the underlying transport reconnects.

### Backoff

- forced `ws` mode retries WebSocket with exponential backoff from 1 second to 30 seconds
- `auto` mode falls back to SSE after WS handshake failure, then periodically re-probes WS
- SSE reconnects continue to follow the existing reconnect logic around `event: reconnect` and error backoff

---

## Client Library (`binkstream-client.js`)

Loaded on authenticated pages and exposes `window.BinkStream`.

### API

```javascript
window.BinkStream.on('chat_message', function (payload) {
    console.log('New message:', payload);
});

window.BinkStream.off('chat_message', handler);

window.BinkStream.send('get_dashboard_stats', {}).then(function (result) {
    console.log(result);
});

window.BinkStream.getMode(); // "ws", "sse", or null until connected
```

The client talks to the SharedWorker and keeps the page API transport-agnostic.

---

## Subscribing to Events in JavaScript

### Example: chat notifications on every page

`public_html/js/chat-notify.js` can subscribe to `chat_message` without caring whether the underlying transport is WebSocket or SSE:

```javascript
window.BinkStream.on('chat_message', function (payload) {
    if (payload.from_user_id === window.currentUserId) return;
    playNotificationSound();
});
```

### Example: updating the chat thread in real time

`public_html/js/chat-page.js` subscribes on the chat page only:

```javascript
window.BinkStream.on('chat_message', function (payload) {
    if (!payload || !payload.id) return;
    payload._source = window.BinkStream.getMode() || 'sse';
    handleIncoming(payload);
    if (payload.id > state.lastChatId) {
        state.lastChatId = payload.id;
        saveState();
    }
});
```

`payload.id` is the domain ID such as `chat_messages.id`, not the stream cursor. The cursor is internal to the worker.

---

## Adding a New Event Type

### Step 1: insert a fat payload into `sse_events`

If the event is triggered by a DB change, add a trigger that inserts a fully renderable payload into `sse_events`:

```sql
INSERT INTO sse_events (event_type, payload, user_id, admin_only)
VALUES (
    'user_online',
    json_build_object(
        'user_id',  NEW.id,
        'username', NEW.username,
        'online',   NEW.is_online
    ),
    NULL,
    FALSE
);
```

If inserting from PHP:

```php
$db = Database::getInstance()->getPdo();
$stmt = $db->prepare("
    INSERT INTO sse_events (event_type, payload, user_id, admin_only)
    VALUES ('user_online', :payload, :user_id, FALSE)
");
$stmt->execute([
    ':payload' => json_encode([
        'user_id' => $userId,
        'username' => $username,
        'online' => true,
    ]),
    ':user_id' => null,
]);
```

### Step 2: delivery is automatic

No transport code changes are needed. The shared event fetch query already returns all event types from `sse_events`.

### Step 3: subscribe in JavaScript

```javascript
window.BinkStream.on('user_online', function (payload) {
    updateUserPresenceIndicator(payload.user_id, payload.online);
});
```

No changes to `binkstream-worker-v2.js` or `binkstream-client.js` are required for a new event type.

### Step 4: bump the service worker cache

When changing `binkstream-worker-v2.js`, `binkstream-client.js`, or i18n strings used by those scripts, increment `CACHE_NAME` in `public_html/sw.js`.

---

## Connection Lifecycle

```text
Page load
  |
  +-- binkstream-client.js -> SharedWorker('/js/binkstream-worker-v2.js')
  |       |
  |       +-- choose ws or sse based on config and runtime availability
  |
  +-- WebSocket path
  |       |
  |       +-- connect to configured WS URL
  |       +-- authenticate via existing session cookie
  |       +-- subscribe / send commands / receive events
  |
  +-- SSE path
  |       |
  |       +-- GET /api/stream?cursor=N
  |       +-- receive connected event
  |       +-- receive event batches until reconnect or close
  |       +-- POST /api/stream for commands
  |
  +-- database change
          |
          +-- trigger or PHP inserts row into sse_events
          +-- StreamService fetches it
          +-- transport delivers it to worker
          +-- worker fans it out to tabs
```

---

## Debugging

### Inspect the SharedWorker

SharedWorker network requests do not appear in the main frame's Network tab. To inspect them:

1. Open `chrome://inspect/#workers` or `edge://inspect/#workers`
2. Find `binkstream-worker-v2.js`
3. Click `inspect`

The worker console logs its transport decisions, including:

- configured and preferred mode at init
- trying WebSocket
- using WebSocket
- WebSocket failure and SSE fallback
- trying SSE
- using SSE
- periodic WebSocket re-probes from SSE fallback

### Dev mode source badge

When `IS_DEV=true`, chat messages can show a small source badge:

- `ws`
- `sse`
- `poll`

This makes it easy to verify which delivery path actually delivered a message.

### Check recent stream rows

```sql
SELECT id, event_type, user_id, admin_only, payload, created_at
FROM sse_events
ORDER BY id DESC
LIMIT 20;
```

```sql
SELECT event_type, count(*)
FROM sse_events
WHERE created_at > NOW() - INTERVAL '1 hour'
GROUP BY event_type;
```
