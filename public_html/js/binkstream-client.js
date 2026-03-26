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
        if (!listeners[type]) {
            return;
        }
        listeners[type].forEach(function (fn) {
            try {
                fn(data);
            } catch (_) {}
        });
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
        if (!hadListeners) {
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
            postSubscription(type, 'unsubscribe');
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
        const worker = new SharedWorker('/js/binkstream-worker-v2.js', { name: 'binkstream' });
        workerPort = worker.port;
        workerPort.onmessage = function (e) {
            const msg = e.data || {};
            if (msg.type === '__transport' && msg.data && msg.data.mode) {
                currentTransportMode = String(msg.data.mode);
                dispatch('transport', { mode: currentTransportMode });
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
                csrfToken: getCsrfToken()
            }
        });

        Object.keys(listeners).forEach(function (type) {
            workerPort.postMessage({ action: 'subscribe', type: type });
        });
    } catch (_) {
        workerPort = null;
    }
})();
