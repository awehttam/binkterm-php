'use strict';

/**
 * /dosdoor WebSocket keepalive.
 *
 * Door sessions reach the bridge through one or more reverse proxies (for
 * example Cloudflare, nginx, or Apache mod_proxy_wstunnel). Many of these
 * close a WebSocket that carries no frames in either direction for a fixed
 * idle period (Cloudflare: about 100 seconds). A door sitting at an idle menu
 * produces no output and the browser sends nothing unless the user types, so
 * the proxy drops the connection and the player sees "[Connection closed]".
 *
 * The realtime WebSocket server already handles this
 * (src/Realtime/WebSocketServer.php, PING_INTERVAL_SECONDS = 20). This module
 * is the bridge-level equivalent: a single interval that sends a protocol-level
 * PING frame to every OPEN client every 20 seconds.
 *
 * Design notes:
 *  - Protocol PING/PONG only. No application/terminal bytes are written, so
 *    terminal data, authentication, tokens and the reconnect window are
 *    untouched.
 *  - Compliant WebSocket clients (every browser) answer PING with PONG at the
 *    protocol layer, invisible to page JavaScript.
 *  - One bridge-level interval over wsServer.clients, not a timer per socket.
 *  - Keepalive only. No pong-timeout / dead-peer reaping.
 *  - Generic: this operates purely on a ws clients collection and the OPEN
 *    constant. It has no knowledge of any specific door.
 */

const KEEPALIVE_PING_INTERVAL_SECONDS = 20;

/**
 * Send one PING to every OPEN client. Non-OPEN sockets are skipped. A ping()
 * that throws (socket torn down mid-sweep) is swallowed so a single bad socket
 * can neither abort the sweep nor crash the shared bridge.
 *
 * @param {Iterable<{readyState: number, ping: Function}>} clients  wsServer.clients
 * @param {number} openState   WebSocket.OPEN
 * @param {(err: Error, ws: object) => void} [onError]  optional per-failure hook
 * @returns {{pinged: number, skipped: number, failed: number}}
 */
function keepalivePingSweep(clients, openState, onError) {
    let pinged = 0;
    let skipped = 0;
    let failed = 0;

    for (const ws of clients || []) {
        if (!ws || ws.readyState !== openState) {
            skipped++;
            continue;
        }
        try {
            ws.ping();
            pinged++;
        } catch (err) {
            failed++;
            if (typeof onError === 'function') {
                try {
                    onError(err, ws);
                } catch (_) {
                    /* a broken hook must never break the sweep */
                }
            }
        }
    }

    return { pinged, skipped, failed };
}

/**
 * Start the keepalive interval for a ws WebSocket.Server.
 *
 * @param {{clients: Iterable<object>}} wsServer   ws server with clientTracking
 * @param {{OPEN: number}} WebSocket               the ws module (for WebSocket.OPEN)
 * @param {object} [opts]
 * @param {number} [opts.intervalSeconds]          default 20
 * @param {(msg: string) => void} [opts.log]       start line + first failure only
 * @returns {{stop: () => void, sweepNow: () => object, intervalSeconds: number}}
 */
function startKeepalive(wsServer, WebSocket, opts) {
    opts = opts || {};
    const intervalSeconds = opts.intervalSeconds || KEEPALIVE_PING_INTERVAL_SECONDS;
    const log = typeof opts.log === 'function' ? opts.log : function () {};

    let warnedOnce = false;
    const onError = function (err) {
        if (warnedOnce) {
            return;
        }
        warnedOnce = true;
        const detail = err && err.message ? err.message : String(err);
        log(`[WS] keepalive: a client ping failed (${detail}); sweep continues`);
    };

    const sweepNow = function () {
        return keepalivePingSweep(wsServer.clients, WebSocket.OPEN, onError);
    };

    const handle = setInterval(sweepNow, intervalSeconds * 1000);
    // Never let the keepalive timer hold the process open during shutdown.
    if (handle && typeof handle.unref === 'function') {
        handle.unref();
    }

    log(`[WS] keepalive: PING every ${intervalSeconds}s to OPEN clients (proxy idle-timeout guard)`);

    return {
        stop: function () {
            clearInterval(handle);
        },
        sweepNow,
        intervalSeconds,
    };
}

module.exports = {
    KEEPALIVE_PING_INTERVAL_SECONDS,
    keepalivePingSweep,
    startKeepalive,
};
