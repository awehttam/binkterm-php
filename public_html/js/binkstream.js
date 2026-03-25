/**
 * binkstream.js — client-side API for the BinkStream SharedWorker.
 *
 * Exposes window.BinkStream with two methods:
 *   BinkStream.on(type, fn)   — subscribe to an event type
 *   BinkStream.off(type, fn)  — unsubscribe
 *
 * Falls back silently if SharedWorker is not supported by the browser.
 */

(function () {
    'use strict';

    const listeners = {};   // type → Set of callbacks
    let workerPort = null;  // MessagePort to the SharedWorker, once connected

    function dispatch(type, data) {
        if (listeners[type]) {
            listeners[type].forEach(function (fn) {
                try { fn(data); } catch (_) {}
            });
        }
    }

    function on(type, fn) {
        if (!listeners[type]) listeners[type] = new Set();
        listeners[type].add(fn);
        // Tell the worker to subscribe to this event type so it registers an
        // EventSource listener and fans it out to all tabs.
        if (workerPort) {
            workerPort.postMessage({ action: 'subscribe', type: type });
        }
    }

    function off(type, fn) {
        if (listeners[type]) listeners[type].delete(fn);
    }

    window.BinkStream = { on: on, off: off };

    if (typeof SharedWorker === 'undefined') {
        // Browser doesn't support SharedWorker — no real-time push,
        // existing polling in each page continues as-is.
        return;
    }

    try {
        const worker = new SharedWorker('/js/binkstream-worker.js', { name: 'binkstream' });
        workerPort = worker.port;
        workerPort.onmessage = function (e) {
            const msg = e.data;
            if (msg && msg.type) {
                dispatch(msg.type, msg.data);
            }
        };
        workerPort.start();
        // Replay any subscriptions that were registered before the worker connected.
        Object.keys(listeners).forEach(function (type) {
            workerPort.postMessage({ action: 'subscribe', type: type });
        });
    } catch (_) {
        // Construction failed (e.g. network error loading worker script).
        // Polling fallback continues unaffected.
    }
})();
