/**
 * binkstream-worker-v2.js - SharedWorker transport layer for BinkStream.
 *
 * Maintains one realtime connection per browser profile and fans events out to
 * all connected tabs. Supports:
 *   - WebSocket transport (preferred when configured)
 *   - SSE transport (including auto mode)
 *   - POST /api/stream command fallback for non-WebSocket transports
 */

'use strict';

const STREAM_URL = '/api/stream';
const MIN_BACKOFF = 1000;
const MAX_BACKOFF = 30000;
const INTENTIONAL_RECONNECT_DELAY_MS = 2500;
const WS_HANDSHAKE_TIMEOUT_MS = 3500;
const MIN_WS_RETRY_PROBE_DELAY_MS = 3000;
const MAX_WS_RETRY_PROBE_DELAY_MS = 30000;

const ports = new Set();
const subscribedTypes = new Set();
const requestPorts = new Map();

let transportMode = 'sse';
let preferredTransportMode = 'sse';
let wsUrl = '';
let csrfToken = '';
let es = null;
let ws = null;
let backoff = MIN_BACKOFF;
let reconnectTimer = null;
let wsConnectTimer = null;
let wsRetryProbeTimer = null;
let wsRetryProbeDelay = MIN_WS_RETRY_PROBE_DELAY_MS;
let lastCursor = '';
let isInitialized = false;

function debugLog() {
    if (typeof console === 'undefined' || typeof console.log !== 'function') {
        return;
    }
    console.log.apply(console, arguments);
}

self.onconnect = function (e) {
    const port = e.ports[0];
    ports.add(port);

    port.onmessage = function (msg) {
        const data = msg.data || {};
        switch (data.action) {
            case 'init':
                initializeConfig(data.config || {});
                isInitialized = true;
                ensureTransport();
                break;
            case 'subscribe':
                subscribeType(String(data.type || '').trim());
                break;
            case 'unsubscribe':
                unsubscribeType(String(data.type || '').trim());
                break;
            case 'command':
                handleCommand(port, data);
                break;
        }
    };

    port.start();

    // Push the current cursor to this port immediately so that UserStorage is
    // up-to-date as soon as the page connects, even if no new events arrive
    // before the next reconnect cycle.  Without this, a refresh where the
    // SharedWorker survives but no events fire leaves UserStorage pointing at
    // a stale position, causing replay if the worker is later restarted.
    if (lastCursor) {
        try {
            port.postMessage({ type: '__cursor', data: { cursor: lastCursor } });
        } catch (_) {}
    }
};

function initializeConfig(config) {
    const rawTransportMode = String(config.transportMode || 'sse');
    const rawPreferredTransportMode = String(config.preferredTransportMode || 'sse');
    const mode = String(config.transportMode || 'sse').toLowerCase();
    transportMode = ['auto', 'sse', 'ws'].includes(mode) ? mode : 'sse';
    const preferred = String(config.preferredTransportMode || 'sse').toLowerCase();
    preferredTransportMode = ['sse', 'ws'].includes(preferred) ? preferred : 'sse';
    wsUrl = typeof config.wsUrl === 'string' ? config.wsUrl : '';
    csrfToken = typeof config.csrfToken === 'string' ? config.csrfToken : '';
    // Seed the cursor from the client's persisted value if the worker doesn't
    // already have one (e.g. first tab after a worker restart).
    if (!lastCursor && typeof config.cursor === 'string' && config.cursor) {
        lastCursor = config.cursor;
    }
    if (preferredTransportMode === 'ws') {
        wsRetryProbeDelay = MIN_WS_RETRY_PROBE_DELAY_MS;
    }
    debugLog('[BinkStream worker] init', {
        rawTransportMode: rawTransportMode,
        rawPreferredTransportMode: rawPreferredTransportMode,
        rawWsUrl: typeof config.wsUrl === 'string' ? config.wsUrl : '(missing)',
        configuredTransportMode: transportMode,
        preferredTransportMode: preferredTransportMode,
        wsUrl: wsUrl || '(default)',
        cursor: lastCursor || '(none)'
    });
}

function effectiveTransportMode() {
    return transportMode === 'auto' ? preferredTransportMode : transportMode;
}

function ensureTransport() {
    if (!isInitialized) {
        return;
    }
    if (ports.size === 0) {
        closeTransport();
        return;
    }
    debugLog('[BinkStream worker] ensureTransport', {
        configuredTransportMode: transportMode,
        activeTransportMode: effectiveTransportMode(),
        ports: ports.size
    });
    if (effectiveTransportMode() === 'ws') {
        if (!ws || ws.readyState === WebSocket.CLOSED) {
            connectWebSocket();
        }
        return;
    }
    if (!es || es.readyState === EventSource.CLOSED) {
        connectSse();
    }
}

function closeTransport() {
    clearReconnectTimer();
    clearWsConnectTimer();
    clearWsRetryProbeTimer();
    if (es) {
        es.close();
        es = null;
    }
    if (ws) {
        try {
            ws.close();
        } catch (_) {}
        ws = null;
    }
}

function clearReconnectTimer() {
    if (reconnectTimer) {
        clearTimeout(reconnectTimer);
        reconnectTimer = null;
    }
}

function clearWsConnectTimer() {
    if (wsConnectTimer) {
        clearTimeout(wsConnectTimer);
        wsConnectTimer = null;
    }
}

function clearWsRetryProbeTimer() {
    if (wsRetryProbeTimer) {
        clearTimeout(wsRetryProbeTimer);
        wsRetryProbeTimer = null;
    }
}

function scheduleReconnect() {
    if (ports.size === 0) {
        closeTransport();
        return;
    }
    clearReconnectTimer();
    reconnectTimer = setTimeout(function () {
        reconnectTimer = null;
        ensureTransport();
    }, backoff);
    backoff = Math.min(backoff * 2, MAX_BACKOFF);
}

function scheduleWsRetryProbe() {
    if (transportMode !== 'auto' || preferredTransportMode !== 'sse' || ports.size === 0) {
        return;
    }
    if (wsRetryProbeTimer) {
        return;
    }
    const delay = wsRetryProbeDelay;
    wsRetryProbeTimer = setTimeout(function () {
        wsRetryProbeTimer = null;
        if (transportMode !== 'auto' || preferredTransportMode !== 'sse' || ports.size === 0) {
            return;
        }
        debugLog('[BinkStream worker] probing WebSocket transport again from SSE fallback');
        preferredTransportMode = 'ws';
        ensureTransport();
    }, delay);
    wsRetryProbeDelay = Math.min(wsRetryProbeDelay * 2, MAX_WS_RETRY_PROBE_DELAY_MS);
}

function subscribeType(type) {
    if (!type || subscribedTypes.has(type)) {
        return;
    }
    subscribedTypes.add(type);
    if (es && es.readyState !== EventSource.CLOSED) {
        addSseListener(type, es);
    }
    if (ws && ws.readyState === WebSocket.OPEN) {
        sendWsJson({ action: 'subscribe', type: type });
    }
}

function unsubscribeType(type) {
    if (!type) {
        return;
    }
    subscribedTypes.delete(type);
    if (ws && ws.readyState === WebSocket.OPEN) {
        sendWsJson({ action: 'unsubscribe', type: type });
    }
}

function connectSse() {
    debugLog('[BinkStream worker] trying SSE transport', {
        cursor: lastCursor || '(none)'
    });
    clearReconnectTimer();
    if (ws) {
        try {
            ws.close();
        } catch (_) {}
        ws = null;
    }
    if (es) {
        es.close();
    }

    const url = lastCursor ? STREAM_URL + '?cursor=' + encodeURIComponent(lastCursor) : STREAM_URL;
    es = new EventSource(url);
    const current = es;

    current.addEventListener('connected', function (e) {
        if (current !== es) {
            return;
        }
        backoff = MIN_BACKOFF;
        if (e.lastEventId) {
            lastCursor = e.lastEventId;
            broadcastCursor(lastCursor);
        }
        debugLog('[BinkStream worker] using SSE transport', {
            cursor: lastCursor || '(none)'
        });
        broadcastTransportMode('sse');
        scheduleWsRetryProbe();
        broadcast('connected', tryParse(e.data));
    });

    current.addEventListener('reconnect', function (e) {
        if (current !== es) {
            return;
        }
        if (e.lastEventId) {
            lastCursor = e.lastEventId;
            broadcastCursor(lastCursor);
        }
        current.close();
        es = null;
        broadcastTransportMode('sse-reconnecting');
        scheduleWsRetryProbe();
        clearReconnectTimer();
        reconnectTimer = setTimeout(function () {
            reconnectTimer = null;
            ensureTransport();
        }, INTENTIONAL_RECONNECT_DELAY_MS);
    });

    current.addEventListener('error', function () {
        if (current !== es) {
            return;
        }
        // Sync cursor from the browser's native lastEventId before closing.
        // The browser advances lastEventId for every SSE id: field regardless
        // of whether a named-event listener is registered, so this ensures the
        // cursor advances past events whose types are not currently subscribed
        // (e.g. admin events on a non-admin page, or events the page hasn't
        // subscribed to yet).  Without this, unsubscribed events are replayed
        // on every reconnect because lastCursor never moves past them.
        const nativeId = current.lastEventId;
        if (nativeId && nativeId !== lastCursor) {
            lastCursor = nativeId;
            broadcastCursor(lastCursor);
        }
        current.close();
        es = null;
        broadcastTransportMode('sse-reconnecting');
        scheduleWsRetryProbe();
        scheduleReconnect();
    });

    subscribedTypes.forEach(function (type) {
        addSseListener(type, current);
    });
}

function addSseListener(type, targetEs) {
    targetEs.addEventListener(type, function (e) {
        if (targetEs !== es) {
            return;
        }
        if (e.lastEventId) {
            lastCursor = e.lastEventId;
            broadcastCursor(lastCursor);
        }
        broadcast(type, tryParse(e.data));
    });
}

function connectWebSocket() {
    debugLog('[BinkStream worker] trying WebSocket transport', {
        url: wsUrl || defaultWsUrl(),
        cursor: lastCursor || '(none)'
    });
    clearReconnectTimer();
    clearWsConnectTimer();
    clearWsRetryProbeTimer();
    if (es) {
        es.close();
        es = null;
    }
    if (ws) {
        try {
            ws.close();
        } catch (_) {}
    }

    const baseUrl = wsUrl || defaultWsUrl();
    const socketUrl = lastCursor ? appendCursor(baseUrl, lastCursor) : baseUrl;
    ws = new WebSocket(socketUrl);
    const current = ws;
    let handshakeComplete = false;

    if (transportMode === 'auto') {
        wsConnectTimer = setTimeout(function () {
            if (current !== ws || handshakeComplete) {
                return;
            }
            debugLog('[BinkStream worker] websocket handshake timed out, falling back to SSE');
            try {
                current.close();
            } catch (_) {}
            ws = null;
            preferredTransportMode = 'sse';
            connectSse();
        }, WS_HANDSHAKE_TIMEOUT_MS);
    }

    current.onopen = function () {
        if (current !== ws) {
            return;
        }
        backoff = MIN_BACKOFF;
        debugLog('[BinkStream worker] websocket open');
        subscribedTypes.forEach(function (type) {
            sendWsJson({ action: 'subscribe', type: type });
        });
    };

    current.onmessage = function (event) {
        if (current !== ws) {
            return;
        }
        let payload = null;
        try {
            payload = JSON.parse(event.data);
        } catch (_) {
            return;
        }
        if (!payload || !payload.type) {
            return;
        }

        if (payload.id) {
            lastCursor = String(payload.id);
            broadcastCursor(lastCursor);
        }

        if (payload.type === 'connected') {
            handshakeComplete = true;
            clearWsConnectTimer();
            clearWsRetryProbeTimer();
            wsRetryProbeDelay = MIN_WS_RETRY_PROBE_DELAY_MS;
            debugLog('[BinkStream worker] using WebSocket transport', {
                cursor: lastCursor || '(none)'
            });
            broadcastTransportMode('ws');
        }

        if (payload.type === 'command_result' && payload.requestId) {
            const port = requestPorts.get(payload.requestId);
            if (port) {
                requestPorts.delete(payload.requestId);
                try {
                    port.postMessage(payload);
                } catch (_) {}
            }
            return;
        }

        broadcast(payload.type, payload.data);
    };

    current.onerror = function () {
        if (current !== ws) {
            return;
        }
        clearWsConnectTimer();
        debugLog('[BinkStream worker] websocket error');
        try {
            current.close();
        } catch (_) {}
    };

    current.onclose = function () {
        if (current !== ws) {
            return;
        }
        clearWsConnectTimer();
        ws = null;
        if (transportMode === 'auto' && !handshakeComplete) {
            debugLog('[BinkStream worker] websocket unavailable, switching to SSE');
            preferredTransportMode = 'sse';
            connectSse();
            return;
        }
        // WS was working (or explicit WS mode) and just closed — signal reconnecting.
        broadcastTransportMode('reconnecting');
        debugLog('[BinkStream worker] websocket closed, scheduling reconnect');
        scheduleReconnect();
    };
}

function handleCommand(port, data) {
    const requestId = String(data.requestId || '').trim();
    const command = String(data.command || '').trim();
    const payload = data.payload && typeof data.payload === 'object' ? data.payload : {};

    if (!requestId || !command) {
        respondToPort(port, {
            type: 'command_result',
            requestId: requestId,
            success: false,
            error: 'Invalid realtime command payload',
            errorCode: 'errors.realtime.invalid_payload'
        });
        return;
    }

    if (effectiveTransportMode() === 'ws') {
        if (!ws || ws.readyState !== WebSocket.OPEN) {
            respondToPort(port, {
                type: 'command_result',
                requestId: requestId,
                success: false,
                error: 'Realtime websocket is not connected'
            });
            return;
        }
        requestPorts.set(requestId, port);
        sendWsJson({
            action: 'command',
            requestId: requestId,
            command: command,
            payload: payload
        });
        return;
    }

    sendHttpCommand(requestId, command, payload).then(function (result) {
        respondToPort(port, {
            type: 'command_result',
            requestId: requestId,
            success: true,
            result: result
        });
    }).catch(function (error) {
        const payload = error && error.payload ? error.payload : {};
        const response = {
            type: 'command_result',
            requestId: requestId,
            success: false,
            error: payload.error || error.message || 'Realtime command failed'
        };
        if (payload.error_code) {
            response.errorCode = payload.error_code;
        }
        respondToPort(port, {
            type: response.type,
            requestId: response.requestId,
            success: response.success,
            error: response.error,
            errorCode: response.errorCode
        });
    });
}

function sendHttpCommand(requestId, command, payload) {
    const headers = { 'Content-Type': 'application/json' };
    if (csrfToken) {
        headers['X-CSRF-Token'] = csrfToken;
    }
    return fetch(STREAM_URL, {
        method: 'POST',
        headers: headers,
        credentials: 'same-origin',
        body: JSON.stringify({
            command: command,
            payload: payload
        })
    }).then(function (response) {
        return response.json().then(function (body) {
            if (!response.ok || body.success === false) {
                const error = new Error(body.error || 'Realtime command failed');
                error.payload = body;
                throw error;
            }
            return body.result;
        });
    });
}

function sendWsJson(payload) {
    if (!ws || ws.readyState !== WebSocket.OPEN) {
        return;
    }
    try {
        ws.send(JSON.stringify(payload));
    } catch (_) {}
}

function respondToPort(port, payload) {
    try {
        port.postMessage(payload);
    } catch (_) {}
}

function broadcast(type, data) {
    const deadPorts = [];
    ports.forEach(function (port) {
        try {
            port.postMessage({ type: type, data: data });
        } catch (_) {
            deadPorts.push(port);
        }
    });
    deadPorts.forEach(function (port) {
        ports.delete(port);
    });
    if (ports.size === 0) {
        closeTransport();
    }
}

function broadcastTransportMode(mode) {
    broadcast('__transport', { mode: mode });
}

function broadcastCursor(cursor) {
    broadcast('__cursor', { cursor: cursor });
}

function tryParse(str) {
    try {
        return JSON.parse(str);
    } catch (_) {
        return str;
    }
}

function defaultWsUrl() {
    const protocol = self.location.protocol === 'https:' ? 'wss:' : 'ws:';
    return protocol + '//' + self.location.host + '/ws';
}

function appendCursor(url, cursor) {
    const separator = url.indexOf('?') === -1 ? '?' : '&';
    return url + separator + 'cursor=' + encodeURIComponent(cursor);
}
