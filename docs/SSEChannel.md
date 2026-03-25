# SSE Back-Channel

BinktermPHP uses a Server-Sent Events (SSE) back-channel to push real-time events to browser tabs without polling. The system is designed to work correctly on PHP's single-threaded built-in development server as well as production deployments.

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Key Files](#key-files)
3. [The `sse_events` Table](#the-sse_events-table)
4. [Event Targeting](#event-targeting)
5. [Admin Daemon Role](#admin-daemon-role)
6. [The `/api/stream` Endpoint](#the-apistream-endpoint)
7. [SharedWorker (`binkstream-worker.js`)](#sharedworker-binkstream-workerjs)
8. [Client Library (`binkstream.js`)](#client-library-binkstreamjs)
9. [Subscribing to Events in JavaScript](#subscribing-to-events-in-javascript)
10. [Adding a New Event Type](#adding-a-new-event-type)
11. [Connection Lifecycle](#connection-lifecycle)
12. [Debugging](#debugging)

---

## Architecture Overview

```
PostgreSQL trigger
      │
      │  INSERT INTO sse_events (event_type, payload, user_id, admin_only)
      ▼
PHP /api/stream  polls SELECT ... FROM sse_events every 200 ms
      │  (filtered by user_id / admin_only)
      │
      │  id: <sse_events.id>
      │  event: chat_message
      │  data: { fat payload — all display fields included }
      ▼
SharedWorker (binkstream-worker.js)  ──► fan-out to all tabs
      │
      ▼
binkstream.js  ──► window.BinkStream.on('chat_message', handler)
```

---

## Key Files

| File | Purpose |
|---|---|
| `database/migrations/v1.11.0.55_sse_events_table.php` | Creates `sse_events` table and installs the initial DB trigger |
| `database/migrations/v1.11.0.57_sse_events_user_targeting.php` | Adds `user_id` / `admin_only` targeting columns; rewrites chat trigger for fat payload |
| `src/Admin/AdminDaemonServer.php` | Daemon: periodic `sse_events` pruning |
| `routes/api-routes.php` | `GET /api/stream` — the SSE endpoint |
| `public_html/js/binkstream-worker.js` | SharedWorker holding the single EventSource |
| `public_html/js/binkstream.js` | Per-tab client; exposes `window.BinkStream` |

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

**UNLOGGED** — no WAL writes, so inserts are fast. The table is automatically truncated on a Postgres crash, which is acceptable because it is a transient delivery queue. Actual data (messages, etc.) lives in domain tables permanently.

**Why a separate table instead of using domain IDs?**

Using `chat_messages.id` as the SSE cursor would break the moment a second event type is added (e.g. MRC messages, notifications). `sse_events.id` is a single monotonic sequence across all event types, so the cursor works correctly regardless of how many event types share the stream.

**Fat payload pattern:** Triggers store all fields needed to render the event directly in the `payload` column. This means the delivery query requires no JOINs — it reads `sse_events` alone. This keeps the hot path simple and fast, and ensures new event types work automatically without changes to the delivery query.

**Retention:** The admin daemon deletes rows older than one hour from its main loop (roughly once per minute). Autovacuum handles dead tuples from those deletes. No manual maintenance is required.

---

## Event Targeting

Each row in `sse_events` carries two targeting columns that control which users receive it:

| `user_id` | `admin_only` | Delivered to |
|---|---|---|
| `NULL` | `FALSE` | All authenticated users |
| `NULL` | `TRUE` | Admin users only |
| `<id>` | `FALSE` | Specific user only |
| `<id>` | `TRUE` | Specific user, only if admin |

The delivery query in `/api/stream` enforces targeting with a simple WHERE filter:

```sql
SELECT id AS sse_id, event_type, payload::text AS event_data
FROM sse_events
WHERE id > :cursor
  AND (user_id IS NULL OR user_id = :user_id)
  AND (admin_only = FALSE OR admin_only = TRUE)  -- TRUE literal when user is admin
ORDER BY id ASC
LIMIT 200
```

The `admin_only` check uses an inlined SQL literal (`TRUE` or `FALSE`) rather than a bound parameter to avoid PostgreSQL's strict boolean/text type checking with PDO.

**For new event types, no changes to the delivery query are needed.** Set `user_id` and `admin_only` when inserting into `sse_events` and the targeting is enforced automatically.

---

## Admin Daemon Role

The admin daemon's only SSE-related responsibility is periodic cleanup:

### Every ~60 seconds: `pruneSSEEvents()`

```sql
DELETE FROM sse_events WHERE created_at < NOW() - INTERVAL '1 hour'
```

---

## The `/api/stream` Endpoint

`GET /api/stream` — requires authentication.

**Response format:** `Content-Type: text/event-stream`

`session_write_close()` is called immediately after authentication to release the PHP session file lock. Without this, the SSE connection would hold the lock for its entire duration, blocking all other requests from the same browser.

### Cursor

The endpoint needs a starting position to know which events to deliver. On reconnect, the SharedWorker passes the last seen `sse_events.id` as the `?cursor=` URL parameter (e.g. `/api/stream?cursor=42`). The endpoint also accepts the standard `Last-Event-ID` HTTP header (sent by the browser on native reconnects), but in practice the worker always supplies `?cursor=` explicitly because `EventSource.lastEventId` is cleared by Chrome when the connection is closed manually.

Priority: `Last-Event-ID` header → `?cursor=` query param → 0 (first connect).

### First connection (cursor = 0)

The browser has no cursor yet. The endpoint anchors it at the current max without delivering any historical messages — those are the page's responsibility via its normal load API calls.

```
id: 42
event: connected
data: {"user_id":7,"cursor":42}

```

The `id: 42` field on the `connected` event advances the worker's `lastCursor` to 42. On the next reconnect, only events with `sse_events.id > 42` will be delivered.

### Reconnect with known cursor

The endpoint runs the catch-up query immediately and emits any pending events. It then stays open for the full `SSE_WINDOW_SECONDS` window, polling every 200 ms and delivering further events as they arrive. A keepalive comment (`: keepalive`) is sent every 15 seconds to prevent proxy timeouts. When the window expires the endpoint sends `event: reconnect` (production only) and closes, prompting the SharedWorker to reconnect immediately.

```
id: 43
event: connected
data: {"user_id":7,"cursor":43}

id: 44
event: chat_message
data: {"id":101,"type":"room","room_id":1,"room_name":"Lobby","from_user_id":3,...}

id: 45
event: chat_message
data: {"id":102,"type":"dm","from_user_id":3,"to_user_id":7,...}

event: reconnect
data: {}
```

If there are no new events during the window, only `event: connected` and `event: reconnect` are sent.

### Polling window

```
SSE_WINDOW_SECONDS=60   (default; set in .env)
```

The endpoint holds the PHP-FPM worker open for this many seconds, polling `SELECT MAX(id) FROM sse_events` every 200 ms. Multiple event batches can be delivered within a single window. On the PHP built-in dev server (`cli-server`) the window is always **0** — the worker is released immediately after the `connected` event. This is required because the dev server is single-threaded; a long-lived SSE connection would block all other requests.

### `retry:` hint

The endpoint sends a `retry:` field on connect:

```
retry: 1000    (dev server)
retry: 3000    (production)
```

This is a hint to the browser for native reconnects. Because the SharedWorker always manually creates `new EventSource()` rather than relying on native reconnect, this field does not control the actual reconnect cadence — `scheduleReconnect()` in the worker does. The field is sent as a courtesy in case a future code path uses native reconnect.

### `event: reconnect` (production only)

On production, when the SSE window expires the endpoint sends `event: reconnect` with no data. The SharedWorker handles this by closing the current EventSource and immediately calling `connect()` — bypassing the exponential backoff that applies to error-triggered reconnects. This keeps the connection fresh with near-zero gap.

On the dev server, `event: reconnect` is **not** sent. The connection simply closes, and the worker's error handler routes through `scheduleReconnect()` (1 s minimum delay) to avoid hammering the single-threaded server.

---

## SharedWorker (`binkstream-worker.js`)

The SharedWorker holds **one** EventSource for the entire origin. All tabs connect to it via `MessagePort`.

### Cursor tracking

The worker maintains a `lastCursor` variable that holds the most recently seen `sse_events.id` from the SSE stream. It is updated whenever an `id:` field arrives with a non-empty value:

```javascript
let lastCursor = '';

thisEs.addEventListener('connected', function (e) {
    if (thisEs !== es) return;
    backoff = MIN_BACKOFF;
    if (e.lastEventId) lastCursor = e.lastEventId;
    broadcast('connected', tryParse(e.data));
});
```

Every named event handler (`chat_message`, etc.) also updates `lastCursor` via `e.lastEventId`. On reconnect, `lastCursor` is passed as `?cursor=` in the EventSource URL:

```javascript
const url = lastCursor
    ? `${STREAM_URL}?cursor=${encodeURIComponent(lastCursor)}`
    : STREAM_URL;
es = new EventSource(url);
```

This explicit tracking is necessary because Chrome clears `EventSource.lastEventId` when the connection is manually closed, which is what the worker always does.

### Connection lifecycle

```
connect()
  └─► new EventSource('/api/stream?cursor=N')
        │
        ├─ event: connected  → reset backoff, update lastCursor, broadcast to tabs
        ├─ event: <type>     → update lastCursor, broadcast to tabs
        ├─ event: reconnect  → update lastCursor, close + connect() immediately
        └─ error             → close + scheduleReconnect() (exponential backoff 1–30 s)
```

All error conditions (including clean server closes on dev where no `reconnect` event is sent) route through `scheduleReconnect()`. The `reconnect` event is the only path for an immediate reconnect without backoff.

### Stale listener prevention

Each call to `connect()` captures the new EventSource in a closure variable `thisEs`. Every event listener checks `if (thisEs !== es) return` before acting. This prevents a race condition where:

1. `event: reconnect` fires → `connect()` creates new EventSource (`es = B`)
2. `error` fires for the old connection — but `es` is now `B`, whose `readyState` is CONNECTING
3. Without the guard, the error handler would call `scheduleReconnect()`, kill `B`, and introduce seconds of delay

### Dynamic event type subscription

Tabs call `BinkStream.on('some_type', fn)`, which causes `binkstream.js` to send `{action: 'subscribe', type: 'some_type'}` to the worker. The worker adds an EventSource listener for that type (if not already present) and stores it in `subscribedTypes` so `connect()` registers it on every future reconnection too. No changes to worker code are needed when new SSE event types are added.

### Backoff

On error-triggered reconnects, delay starts at 1 second and doubles up to 30 seconds. It resets to 1 second on each successful `connected` event.

---

## Client Library (`binkstream.js`)

Loaded on every authenticated page (injected in `base.twig`). Connects to the SharedWorker and exposes `window.BinkStream`.

### API

```javascript
// Subscribe to an event type
window.BinkStream.on('chat_message', function(payload) {
    console.log('New message:', payload);
});

// Unsubscribe
window.BinkStream.off('chat_message', handler);
```

`window.BinkStream` is always defined (even when SharedWorker is not supported). When SharedWorker is unavailable the object is a no-op — subscribers never fire but no errors are thrown. This means all subscriber code works without `if (window.BinkStream)` guards.

---

## Subscribing to Events in JavaScript

### Example: chat notifications on every page

`public_html/js/chat-notify.js` is loaded on all authenticated pages and subscribes to `chat_message` to play notification sounds:

```javascript
// chat-notify.js (simplified)
window.BinkStream.on('chat_message', function(payload) {
    if (payload.from_user_id === window.currentUserId) return;
    playNotificationSound();
});
```

### Example: updating the chat thread in real time

`public_html/js/chat-page.js` subscribes on the chat page only:

```javascript
window.BinkStream.on('chat_message', function(payload) {
    if (!payload || !payload.id) return;
    payload._source = 'sse';           // debug badge (IS_DEV only)
    handleIncoming(payload);           // render or increment unread badge
    if (payload.id > state.lastChatId) {
        state.lastChatId = payload.id; // advance poll fallback cursor
        saveState();
    }
});
```

Note: `payload.id` is the **domain id** (`chat_messages.id`), not the SSE cursor. The SSE cursor is managed automatically by the SharedWorker in its `lastCursor` variable — subscriber code never needs to interact with it.

---

## Adding a New Event Type

This section walks through adding a hypothetical `user_online` event that fires when a user connects or disconnects.

### Step 1: Add a Postgres trigger (or insert directly)

If the event is triggered by a DB change, add a trigger that inserts into `sse_events` with a **fat payload** — include all fields needed to render the event so the delivery query requires no JOINs:

```sql
CREATE OR REPLACE FUNCTION notify_user_online()
RETURNS trigger AS $$
BEGIN
    INSERT INTO sse_events (event_type, payload, user_id, admin_only)
    VALUES (
        'user_online',
        json_build_object(
            'user_id',  NEW.id,
            'username', NEW.username,
            'online',   NEW.is_online
        ),
        NULL,    -- NULL = broadcast to all users; set to a user id for targeted delivery
        FALSE    -- TRUE = admins only
    );
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_user_online_notify
    AFTER UPDATE OF is_online ON users
    FOR EACH ROW EXECUTE FUNCTION notify_user_online();
```

If inserting from PHP code:

```php
$db = Database::getInstance()->getPdo();
$stmt = $db->prepare("
    INSERT INTO sse_events (event_type, payload, user_id, admin_only)
    VALUES ('user_online', :payload, :user_id, FALSE)
");
$stmt->execute([
    ':payload'  => json_encode(['user_id' => $userId, 'username' => $username, 'online' => true]),
    ':user_id'  => null,   // null = broadcast; integer = targeted
]);
```

No `pg_notify` call is needed — the SSE endpoint polls `sse_events` directly.

### Step 2: Delivery is automatic

No changes to `routes/api-routes.php` are needed. The delivery query selects all event types in a single pass:

```sql
SELECT id AS sse_id, event_type, payload::text AS event_data
FROM sse_events
WHERE id > :cursor
  AND (user_id IS NULL OR user_id = :user_id)
  AND (admin_only = FALSE OR admin_only = <TRUE|FALSE>)
ORDER BY id ASC
LIMIT 200
```

The new `user_online` rows will be included automatically, with their `user_id`/`admin_only` targeting enforced by the WHERE clause.

### Step 3: Subscribe in JavaScript

```javascript
window.BinkStream.on('user_online', function(payload) {
    // payload = { user_id: 7, username: 'alice', online: true }
    updateUserPresenceIndicator(payload.user_id, payload.online);
});
```

No changes to `binkstream-worker.js` or `binkstream.js` are needed — the first call to `BinkStream.on('user_online', ...)` triggers a `subscribe` message to the worker, which registers the EventSource listener dynamically.

### Step 4: Bump the service worker cache

Any time you change `binkstream-worker.js` or `binkstream.js`, increment `CACHE_NAME` in `public_html/sw.js`:

```javascript
const CACHE_NAME = 'binkcache-v591'; // was v590
```

---

## Connection Lifecycle

```
Page load
  │
  ├─ binkstream.js: new SharedWorker('/js/binkstream-worker.js')
  │     └─ worker onconnect: connect() → EventSource('/api/stream')
  │
  ├─ /api/stream (cursor = 0, first connect)
  │     └─ anchor at MAX(sse_events.id); send connected event with id: field; close (dev)
  │          or keep open for SSE_WINDOW_SECONDS (production)
  │
  ├─ worker: lastCursor = id from connected event
  │
  ├─ /api/stream (cursor = N)  ← reconnect
  │     ├─ run catch-up query immediately, deliver any events with id > N
  │     ├─ poll every 200 ms for SSE_WINDOW_SECONDS (production only)
  │     ├─ send ": keepalive" comment every 15 s (production)
  │     └─ when window expires: send reconnect event, close
  │
  └─ message arrives in DB
        ├─ trigger → sse_events INSERT (with fat payload + targeting columns)
        └─ next poll iteration: new id > lastCursor → deliver event
```

**End-to-end latency** is one SSE poll interval — typically under 50 ms on localhost, under 300 ms on a production server (200 ms poll sleep + query time).

---

## Debugging

### Inspect the SharedWorker

SharedWorker network requests do **not** appear in the main frame's Network tab. To inspect them:

1. Open `chrome://inspect/#workers` (or `edge://inspect/#workers`)
2. Find `binkstream-worker.js` and click **inspect**
3. The worker's DevTools shows its own Console, Network tab, and Sources

In the worker console, watch for `lastCursor` advancing as events arrive. If the EventSource URL shows no `?cursor=` param, the cursor is being lost between reconnects.

### Dev mode source badge

When `IS_DEV=true` in `.env`, incoming chat messages show a small `(sse)` or `(poll)` badge next to the sender's name, indicating which delivery path delivered the message. This helps verify that SSE is working and messages are not silently falling back to polling.

### Check the SSE event log

```sql
-- Recent SSE events with targeting info
SELECT id, event_type, user_id, admin_only, payload, created_at
FROM sse_events
ORDER BY id DESC
LIMIT 20;

-- Event counts by type in the last hour
SELECT event_type, count(*)
FROM sse_events
WHERE created_at > NOW() - INTERVAL '1 hour'
GROUP BY event_type;

-- Check if a specific user would receive an event
SELECT id, event_type, payload
FROM sse_events
WHERE (user_id IS NULL OR user_id = <user_id>)
  AND admin_only = FALSE
ORDER BY id DESC
LIMIT 10;
```

### Force-reload the SharedWorker

The SharedWorker persists across page reloads as long as at least one tab is open. To force it to reload new code:

1. Close all tabs for the site
2. Open a new tab — a fresh worker starts with the new code

Or from `chrome://inspect/#workers`, click **terminate** next to the worker, then reload a tab.

### Test the stream endpoint directly

```bash
curl -N -H 'Cookie: your_session_cookie' http://localhost:1244/api/stream
```

`-N` disables curl's own buffering so you see events as they arrive. To test reconnect with a cursor:

```bash
curl -N -H 'Cookie: your_session_cookie' 'http://localhost:1244/api/stream?cursor=42'
```
