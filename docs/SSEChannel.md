# SSE Back-Channel

BinktermPHP uses a Server-Sent Events (SSE) back-channel to push real-time events to browser tabs without polling. The system is designed to work correctly on PHP's single-threaded built-in development server as well as production deployments.

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Key Files](#key-files)
3. [The `sse_events` Table](#the-sse_events-table)
4. [Admin Daemon Role](#admin-daemon-role)
5. [The `/api/stream` Endpoint](#the-apistream-endpoint)
6. [SharedWorker (`binkstream-worker.js`)](#sharedworker-binkstream-workerjs)
7. [Client Library (`binkstream.js`)](#client-library-binkstreamjs)
8. [Subscribing to Events in JavaScript](#subscribing-to-events-in-javascript)
9. [Adding a New Event Type](#adding-a-new-event-type)
10. [Connection Lifecycle](#connection-lifecycle)
11. [Debugging](#debugging)

---

## Architecture Overview

```
PostgreSQL trigger
      │
      │  INSERT INTO sse_events
      ▼
PHP /api/stream  polls SELECT MAX(id) FROM sse_events every 200 ms
      │
      │  event: chat_message
      │  id: <sse_events.id>
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
| `database/migrations/v1.11.0.55_sse_events_table.php` | Creates `sse_events` table and installs the DB trigger |
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
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);
```

**UNLOGGED** — no WAL writes, so inserts are fast. The table is automatically truncated on a Postgres crash, which is acceptable because it is a transient delivery queue. Actual data (messages, etc.) lives in domain tables permanently.

**Why a separate table instead of using domain IDs?**

Using `chat_messages.id` as the SSE cursor would break the moment a second event type is added (e.g. MRC messages, notifications). `sse_events.id` is a single monotonic sequence across all event types, so `Last-Event-ID` works correctly regardless of how many event types share the stream.

**Retention:** The admin daemon deletes rows older than one hour from its main loop (roughly once per minute). Autovacuum handles dead tuples from those deletes. No manual maintenance is required.

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

### First connection (`Last-Event-ID` absent or 0)

The browser has no cursor yet. The endpoint anchors it at the current max without delivering any historical messages — those are the page's responsibility via its normal load API calls.

```
event: connected
data: {"user_id":7}

id: 42

event: reconnect
data: {}
```

The `id: 42` line advances the browser's `Last-Event-ID` to 42. On the next reconnect, only events with `sse_events.id > 42` will be delivered.

### Reconnect with known cursor (`Last-Event-ID: 42`)

The endpoint runs the catch-up query immediately and emits any pending events. It then stays open for the full `SSE_WINDOW_SECONDS` window, polling every 200 ms and delivering further events as they arrive. A keepalive comment (`: keepalive`) is sent every 15 seconds to prevent proxy timeouts. When the window expires the endpoint sends `event: reconnect` and the SharedWorker reconnects immediately.

```
event: connected
data: {"user_id":7}

id: 43
event: chat_message
data: {"id":101,"type":"room","room_id":1,"room_name":"Lobby",...}

id: 44
event: chat_message
data: {"id":102,"type":"dm","from_user_id":3,...}

event: reconnect
data: {}
```

If there are no new events during the window, only `event: connected` and `event: reconnect` are sent.

### Polling window

```
SSE_WINDOW_SECONDS=60   (default; set in .env)
```

The endpoint holds the PHP-FPM worker open for this many seconds, polling `SELECT MAX(id) FROM sse_events` every 200 ms. Multiple event batches can be delivered within a single window. A keepalive comment is emitted every 15 seconds to prevent reverse proxies from closing the idle connection. On the PHP built-in dev server (`cli-server`) the window is always 0 — the worker is released immediately after serving the initial cursor.

### SSE filter: own messages

The catch-up query deliberately excludes messages sent by the authenticated user (`from_user_id != userId` for room messages; DMs only match `to_user_id`). The sender's own message is rendered immediately on the client via the `local_message` field returned in the send API response — it never goes through SSE.

---

## SharedWorker (`binkstream-worker.js`)

The SharedWorker holds **one** EventSource for the entire origin. All tabs connect to it via `MessagePort`.

### Connection lifecycle

```
connect()
  └─► new EventSource('/api/stream')
        │
        ├─ event: connected  → reset backoff, broadcast 'connected' to tabs
        ├─ event: <type>     → broadcast to tabs
        ├─ event: reconnect  → close + connect() immediately (no backoff)
        └─ error
              ├─ readyState CLOSED → close + connect() immediately
              └─ readyState other  → close + scheduleReconnect() (exponential backoff)
```

### Stale listener prevention

Each call to `connect()` captures the new EventSource in `thisEs`. Every event listener checks `if (thisEs !== es) return` before acting. This prevents a common race condition where:

1. `event: reconnect` fires → `connect()` creates new EventSource (`es = B`)
2. `error` fires for the old connection — but `es` is now `B`, whose `readyState` is CONNECTING
3. Without the guard, the error handler would call `scheduleReconnect()`, kill `B`, and wait 1–30 seconds

### Backoff

On network errors (not clean server closes), reconnect delay starts at 1 second and doubles up to 30 seconds. It resets to 1 second on each successful `connected` event.

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
    handleIncoming(payload);               // render or increment unread badge
    if (payload.id > state.lastChatId) {   // advance poll fallback cursor
        state.lastChatId = payload.id;
        saveState();
    }
});
```

Note: `payload.id` is the **domain id** (`chat_messages.id`), not the SSE event id. The SSE cursor (`Last-Event-ID`) is managed automatically by the browser's EventSource — you never need to track it in JavaScript.

---

## Adding a New Event Type

This section walks through adding a hypothetical `user_online` event that fires when a user connects or disconnects.

### Step 1: Add a Postgres trigger (or insert directly)

If the event is triggered by a DB change, add a trigger that inserts into `sse_events`:

```sql
CREATE OR REPLACE FUNCTION notify_user_online()
RETURNS trigger AS $$
DECLARE
    evt_id BIGINT;
BEGIN
    INSERT INTO sse_events (event_type, payload)
    VALUES (
        'user_online',
        json_build_object(
            'user_id',  NEW.id,
            'username', NEW.username,
            'online',   NEW.is_online
        )
    )
    RETURNING id INTO evt_id;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_user_online_notify
    AFTER UPDATE OF is_online ON users
    FOR EACH ROW EXECUTE FUNCTION notify_user_online();
```

Wrap this in a migration file: `database/migrations/v1.11.0.XX_user_online_trigger.php`.

If the event is triggered by PHP code (not a DB trigger), insert directly:

```php
// In a PHP route or service
$db = Database::getInstance()->getPdo();
$stmt = $db->prepare("
    INSERT INTO sse_events (event_type, payload)
    VALUES ('user_online', :payload)
");
$stmt->execute([':payload' => json_encode([
    'user_id'  => $userId,
    'username' => $username,
    'online'   => true,
])]);
```

No `pg_notify` call is needed — the SSE endpoint polls `sse_events` directly.

### Step 2: Deliver the event in `/api/stream`

In `routes/api-routes.php`, extend the `$deliverEvents` closure to handle the new event type alongside `chat_message`. The simplest approach is a `UNION ALL`:

```php
$stmt = $db->prepare("
    -- existing chat_message branch
    SELECT e.id AS sse_id, 'chat_message' AS event_type,
           json_build_object(
               'id', m.id, 'type', CASE WHEN m.room_id IS NOT NULL THEN 'room' ELSE 'dm' END,
               ...
           )::text AS event_data
    FROM sse_events e
    JOIN chat_messages m ON (e.payload->>'chat_id')::int = m.id
    ...
    WHERE e.id > ? AND e.event_type = 'chat_message' AND ...

    UNION ALL

    -- new user_online branch (no auth filter needed — presence is public)
    SELECT e.id AS sse_id, 'user_online' AS event_type,
           e.payload::text AS event_data
    FROM sse_events e
    WHERE e.id > ? AND e.event_type = 'user_online'

    ORDER BY sse_id ASC
    LIMIT 200
");
$stmt->execute([$lastEventId, $userId, $userId, $lastEventId]);

foreach ($stmt->fetchAll() as $row) {
    echo "id: " . (int)$row['sse_id'] . "\n";
    echo "event: " . $row['event_type'] . "\n";
    echo "data: " . $row['event_data'] . "\n\n";
}
```

### Step 3: Subscribe in JavaScript

```javascript
window.BinkStream.on('user_online', function(payload) {
    // payload = { user_id: 7, username: 'alice', online: true }
    updateUserPresenceIndicator(payload.user_id, payload.online);
});
```

No changes to `binkstream-worker.js` or `binkstream.js` are needed — the worker fans out any named event type to subscribers automatically.

### Step 4: Bump the service worker cache

Any time you change `binkstream-worker.js` or `binkstream.js`, increment `CACHE_NAME` in `public_html/sw.js`:

```javascript
const CACHE_NAME = 'binkcache-v581'; // was v580
```

---

## Connection Lifecycle

```
Page load
  │
  ├─ binkstream.js: new SharedWorker('/js/binkstream-worker.js')
  │     └─ worker onconnect: connect() → EventSource('/api/stream')
  │
  ├─ /api/stream (Last-Event-ID: 0)
  │     └─ anchor cursor at MAX(sse_events.id), send reconnect
  │
  ├─ /api/stream (Last-Event-ID: N)  ← long-lived window
  │     ├─ run catch-up query immediately, deliver any pending events
  │     ├─ poll every 200 ms for SSE_WINDOW_SECONDS, delivering as events arrive
  │     ├─ send ": keepalive" comment every 15 s to prevent proxy timeout
  │     └─ when window expires: send reconnect, worker released
  │
  └─ message arrives in DB
        ├─ trigger → sse_events INSERT
        └─ next poll iteration: MAX(id) > N → deliver (no reconnect needed)
```

**End-to-end latency** is one SSE poll interval — typically under 50 ms on localhost, under 300 ms on a production server (200 ms poll sleep + query time).

---

## Debugging

### Inspect the SharedWorker

SharedWorker network requests do **not** appear in the main frame's Network tab. To inspect them:

1. Open `chrome://inspect/#workers` (or `edge://inspect/#workers`)
2. Find `binkstream-worker.js` and click **inspect**
3. The worker's DevTools shows its own Console, Network tab, and Sources

### Check the SSE event log

```sql
-- Recent SSE events
SELECT id, event_type, payload, created_at
FROM sse_events
ORDER BY id DESC
LIMIT 20;

-- Event counts by type in the last hour
SELECT event_type, count(*)
FROM sse_events
WHERE created_at > NOW() - INTERVAL '1 hour'
GROUP BY event_type;
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

`-N` disables curl's own buffering so you see events as they arrive.
