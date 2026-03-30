/**
 * binkstream-client.js - client-side API for the BinkStream SharedWorker.
 *
 * Exposes window.BinkStream with:
 *   BinkStream.on(type, fn)
 *   BinkStream.off(type, fn)
 *   BinkStream.send(command, payload)
 *
 * Transport selection happens inside the SharedWorker. When SharedWorker is
 * unavailable, command sends fall back to POST /api/stream and event
 * subscriptions remain a no-op so page-level polling continues to work.
 */

(function () {
    'use strict';

    const listeners = {};
    const pendingRequests = new Map();
    let workerPort = null;
    let requestSeq = 0;
    let currentTransportMode = 'poll';
    let initCursorSaved = false;
    let lastKnownCursor = '';

    function getConfiguredTransportMode() {
        const mode = String(window.siteConfig?.configuredRealtimeTransportMode || window.siteConfig?.realtimeTransportMode || window.siteConfig?.sseTransportMode || 'sse').toLowerCase();
        return ['auto', 'sse', 'ws'].includes(mode) ? mode : 'sse';
    }

    function getPreferredTransportMode() {
        const mode = String(window.siteConfig?.realtimeTransportMode || window.siteConfig?.sseTransportMode || 'sse').toLowerCase();
        return ['sse', 'ws'].includes(mode) ? mode : 'sse';
    }

    function getWsUrl() {
        const configured = window.siteConfig?.realtimeWsUrl;
        if (configured && typeof configured === 'string') {
            return configured;
        }
        const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
        return protocol + '//' + window.location.host + '/ws';
    }

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta && meta.content ? meta.content : '';
    }

    function dispatch(type, data) {
        if (listeners[type]) {
            listeners[type].forEach(function (fn) {
                try {
                    fn(data);
                } catch (_) {}
            });
        }
        // Wildcard listeners receive every event as (type, data)
        if (type !== '*' && listeners['*']) {
            listeners['*'].forEach(function (fn) {
                try {
                    fn(type, data);
                } catch (_) {}
            });
        }
    }

    function postSubscription(type, action) {
        if (!workerPort) {
            return;
        }
        workerPort.postMessage({ action: action, type: type });
    }

    function on(type, fn) {
        if (!listeners[type]) {
            listeners[type] = new Set();
        }
        const hadListeners = listeners[type].size > 0;
        listeners[type].add(fn);
        // '*' is a client-side wildcard — no worker subscription needed
        if (!hadListeners && type !== '*') {
            postSubscription(type, 'subscribe');
        }
    }

    function off(type, fn) {
        if (!listeners[type]) {
            return;
        }
        listeners[type].delete(fn);
        if (listeners[type].size === 0) {
            delete listeners[type];
            if (type !== '*') {
                postSubscription(type, 'unsubscribe');
            }
        }
    }

    function sendViaHttp(command, payload) {
        const headers = { 'Content-Type': 'application/json' };
        const csrfToken = getCsrfToken();
        if (csrfToken) {
            headers['X-CSRF-Token'] = csrfToken;
        }

        return fetch('/api/stream', {
            method: 'POST',
            headers: headers,
            credentials: 'same-origin',
            body: JSON.stringify({
                command: command,
                payload: payload || {}
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

    function send(command, payload) {
        const normalizedCommand = String(command || '').trim();
        const normalizedPayload = payload && typeof payload === 'object' ? payload : {};
        if (normalizedCommand === '') {
            return Promise.reject(new Error('Realtime command is required'));
        }

        if (!workerPort) {
            return sendViaHttp(normalizedCommand, normalizedPayload);
        }

        const requestId = 'req-' + Date.now() + '-' + (++requestSeq);
        return new Promise(function (resolve, reject) {
            pendingRequests.set(requestId, { resolve: resolve, reject: reject });
            workerPort.postMessage({
                action: 'command',
                requestId: requestId,
                command: normalizedCommand,
                payload: normalizedPayload
            });
        });
    }

    function getMode() {
        return currentTransportMode;
    }

    window.BinkStream = { on: on, off: off, send: send, getMode: getMode };

    if (typeof SharedWorker === 'undefined') {
        return;
    }

    try {
        const WORKER_BUILD = 7;
        const worker = new SharedWorker('/js/binkstream-worker-v2.js?v=' + WORKER_BUILD, { name: 'binkstream-v' + WORKER_BUILD });
        workerPort = worker.port;
        workerPort.onmessage = function (e) {
            const msg = e.data || {};
            if (msg.type === '__transport' && msg.data && msg.data.mode) {
                const m = String(msg.data.mode);
                // Don't overwrite the last known transport mode for transient states
                if (m !== 'reconnecting' && m !== 'sse-reconnecting') {
                    currentTransportMode = m;
                }
                dispatch('transport', { mode: m });
                return;
            }
            if (msg.type === '__cursor' && msg.data && msg.data.cursor) {
                // Persist the stream cursor so a new worker instance can resume
                // from the same position rather than replaying old events.
                // A timestamp is saved alongside so stale cursors are discarded
                // on worker restart rather than flooding the client with old events.
                lastKnownCursor = String(msg.data.cursor);
                try {
                    UserStorage.setItem('binkstream_cursor', lastKnownCursor);
                    UserStorage.setItem('binkstream_cursor_ts', String(Date.now()));
                } catch (_) {}
                // Capture the first cursor received on this page load as the
                // init cursor for diagnostics (admin terminal "stream" command).
                // Done here rather than at script init time so UserStorage is
                // guaranteed to be available and the worker's actual starting
                // position is used rather than the pre-TTL-check stored value.
                if (!initCursorSaved) {
                    initCursorSaved = true;
                    try { sessionStorage.setItem('binkstream_cursor_init', String(msg.data.cursor)); } catch (_) {}
                }
                return;
            }
            if (msg.type === 'command_result' && msg.requestId) {
                const pending = pendingRequests.get(msg.requestId);
                if (!pending) {
                    return;
                }
                pendingRequests.delete(msg.requestId);
                if (msg.success === false) {
                    const error = new Error(msg.error || 'Realtime command failed');
                    error.payload = msg;
                    pending.reject(error);
                    return;
                }
                pending.resolve(msg.result);
                return;
            }

            if (msg.type) {
                dispatch(msg.type, msg.data);
            }
        };
        workerPort.start();
        workerPort.postMessage({
            action: 'init',
            config: {
                transportMode: getConfiguredTransportMode(),
                preferredTransportMode: getPreferredTransportMode(),
                wsUrl: getWsUrl(),
                csrfToken: getCsrfToken(),
                cursor: (function () {
                    try {
                        const c = UserStorage.getItem('binkstream_cursor') || '';
                        const ts = parseInt(UserStorage.getItem('binkstream_cursor_ts') || '0', 10);
                        // Discard the cursor if it is older than 5 minutes.  A stale cursor
                        // causes the worker to replay a large backlog of old events when
                        // reconnecting after a long absence (browser close/reopen, sleep/wake).
                        // Within-session reconnects (page refresh, tab close/reopen) happen
                        // in seconds so they are well within the window.
                        const CURSOR_TTL_MS = 5 * 60 * 1000;
                        return (c && ts && (Date.now() - ts) < CURSOR_TTL_MS) ? c : '';
                    } catch (_) { return ''; }
                })()
            }
        });

        Object.keys(listeners).forEach(function (type) {
            workerPort.postMessage({ action: 'subscribe', type: type });
        });

        // Flush the most recent cursor to UserStorage synchronously on page
        // unload so the next worker start seeds from an accurate position.
        // __cursor messages flow through the port asynchronously and may be
        // dropped if the page unloads before they are processed, leaving
        // UserStorage lagging behind the worker's true cursor.
        window.addEventListener('beforeunload', function () {
            if (!lastKnownCursor) { return; }
            try {
                UserStorage.setItem('binkstream_cursor', lastKnownCursor);
                UserStorage.setItem('binkstream_cursor_ts', String(Date.now()));
            } catch (_) {}
        });
    } catch (_) {
        workerPort = null;
    }
})();
