/**
 * binkstream-worker.js — SharedWorker
 *
 * Holds a single EventSource connection to /api/stream on behalf of all
 * tabs from the same origin. Incoming events are fanned out to every
 * connected tab port. If the connection drops, it reconnects automatically
 * with exponential back-off.
 *
 * Event type subscriptions are dynamic: when a tab calls
 * BinkStream.on('some_type', fn), binkstream.js sends a
 * {action:'subscribe', type:'some_type'} message to this worker. The
 * worker adds an EventSource listener for that type (if not already present)
 * so it is automatically fanned out. This means no changes to this file are
 * needed when new SSE event types are added.
 */

'use strict';

const STREAM_URL   = '/api/stream';
const MIN_BACKOFF  = 1000;
const MAX_BACKOFF  = 30000;

let es          = null;
let backoff     = MIN_BACKOFF;
let lastCursor  = '';   // explicitly tracked; es.lastEventId is unreliable after close
const ports     = new Set();

// Event types we have registered listeners for on the current EventSource.
// Pre-populated with the core type always needed.
const subscribedTypes = new Set(['chat_message']);

// ── Port management ──────────────────────────────────────────────────────────

self.onconnect = function (e) {
    const port = e.ports[0];
    ports.add(port);

    port.onmessage = function (msg) {
        if (msg.data && msg.data.action === 'subscribe') {
            subscribeType(msg.data.type);
        }
    };

    port.addEventListener('close', function () {
        ports.delete(port);
    });

    port.start();

    // Start the SSE connection the first time a tab connects.
    if (!es || es.readyState === EventSource.CLOSED) {
        connect();
    }
};

// ── SSE connection ───────────────────────────────────────────────────────────

function connect() {
    if (es) {
        es.close();
    }

    // Pass the last known cursor as a URL param. A new EventSource instance
    // always starts with lastEventId = "" so the browser never sends the
    // Last-Event-ID header on manually-created reconnects. We track the cursor
    // ourselves (lastCursor) so the server can resume from the right position.
    const url = lastCursor ? `${STREAM_URL}?cursor=${encodeURIComponent(lastCursor)}` : STREAM_URL;
    es = new EventSource(url);

    // Capture this specific instance. Each listener checks `thisEs === es`
    // before acting — this prevents stale listeners from a previous connection
    // firing after a new one has already been created (which would incorrectly
    // trigger scheduleReconnect() and introduce seconds of delay).
    const thisEs = es;

    thisEs.addEventListener('connected', function (e) {
        if (thisEs !== es) return;
        backoff = MIN_BACKOFF;
        if (e.lastEventId) lastCursor = e.lastEventId;
        broadcast('connected', tryParse(e.data));
    });

    thisEs.addEventListener('reconnect', function (e) {
        if (thisEs !== es) return;
        if (e.lastEventId) lastCursor = e.lastEventId;
        // Server closed intentionally — reconnect immediately, no backoff.
        thisEs.close();
        connect();
    });

    thisEs.addEventListener('error', function () {
        if (thisEs !== es) return;
        thisEs.close();
        scheduleReconnect();
    });

    // Register listeners for all currently subscribed event types.
    subscribedTypes.forEach(function (type) {
        addListenerForType(thisEs, type);
    });
}

/**
 * Add a broadcast listener for a specific event type on a given EventSource.
 * Uses a closure to capture the EventSource instance for the stale-listener guard.
 */
function addListenerForType(targetEs, type) {
    targetEs.addEventListener(type, function (e) {
        if (targetEs !== es) return;
        if (e.lastEventId) lastCursor = e.lastEventId;
        broadcast(type, tryParse(e.data));
    });
}

/**
 * Register interest in an event type. If the current EventSource is active,
 * adds the listener immediately. The type is also stored so connect() picks
 * it up on any future reconnection.
 */
function subscribeType(type) {
    if (subscribedTypes.has(type)) return;
    subscribedTypes.add(type);
    if (es && es.readyState !== EventSource.CLOSED) {
        addListenerForType(es, type);
    }
}

function scheduleReconnect() {
    if (ports.size === 0) {
        // No tabs open — nothing to do; next onconnect will call connect()
        es = null;
        return;
    }
    setTimeout(connect, backoff);
    backoff = Math.min(backoff * 2, MAX_BACKOFF);
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function broadcast(type, data) {
    const deadPorts = [];
    ports.forEach(function (port) {
        try {
            port.postMessage({ type: type, data: data });
        } catch (_) {
            deadPorts.push(port);
        }
    });
    deadPorts.forEach(function (p) { ports.delete(p); });
}

function tryParse(str) {
    try { return JSON.parse(str); } catch (_) { return str; }
}
