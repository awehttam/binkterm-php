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
        worker.port.onmessage = function (e) {
            const msg = e.data;
            if (msg && msg.type) {
                dispatch(msg.type, msg.data);
            }
        };
        worker.port.start();
    } catch (_) {
        // Construction failed (e.g. network error loading worker script).
        // Polling fallback continues unaffected.
    }
})();
