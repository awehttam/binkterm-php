'use strict';

/**
 * Coverage for the generic /dosdoor WebSocket keepalive
 * (scripts/dosbox-bridge/ws-keepalive.js).
 *
 * Proves the keepalive contract only: a bridge-level 20s interval that sends a
 * protocol PING to every OPEN client, skips non-OPEN clients, survives a
 * throwing ping(), and does not hold the event loop open. This is generic
 * bridge behaviour and has no dependency on any specific door.
 *
 * Run: node tests/js/ws-keepalive.test.js
 */

const assert = require('assert');

const {
    KEEPALIVE_PING_INTERVAL_SECONDS,
    keepalivePingSweep,
    startKeepalive,
} = require('../../scripts/dosbox-bridge/ws-keepalive');

// ws WebSocket.OPEN === 1 (RFC 6455 / ws readyState); use the literal so the
// helper's genericity does not depend on importing ws here.
const OPEN = 1;
const CLOSING = 2;
const CLOSED = 3;
const CONNECTING = 0;

function fakeSocket(readyState) {
    const s = { readyState, pings: 0 };
    s.ping = () => { s.pings += 1; };
    return s;
}

/* ------------------------------------------------------------------ *
 * 1. interval constant is 20 seconds, matching BinkStream
 * ------------------------------------------------------------------ */
assert.strictEqual(
    KEEPALIVE_PING_INTERVAL_SECONDS,
    20,
    'keepalive interval must be 20 seconds'
);

/* ------------------------------------------------------------------ *
 * 2. OPEN clients are pinged; non-OPEN clients are skipped
 * ------------------------------------------------------------------ */
{
    const open1 = fakeSocket(OPEN);
    const open2 = fakeSocket(OPEN);
    const connecting = fakeSocket(CONNECTING);
    const closing = fakeSocket(CLOSING);
    const closed = fakeSocket(CLOSED);
    const clients = new Set([open1, connecting, open2, closing, closed]);

    const result = keepalivePingSweep(clients, OPEN);

    assert.strictEqual(open1.pings, 1, 'first OPEN client must be pinged once');
    assert.strictEqual(open2.pings, 1, 'second OPEN client must be pinged once');
    assert.strictEqual(connecting.pings, 0, 'CONNECTING client must not be pinged');
    assert.strictEqual(closing.pings, 0, 'CLOSING client must not be pinged');
    assert.strictEqual(closed.pings, 0, 'CLOSED client must not be pinged');
    assert.deepStrictEqual(
        result,
        { pinged: 2, skipped: 3, failed: 0 },
        'sweep must report 2 pinged / 3 skipped / 0 failed'
    );
}

/* ------------------------------------------------------------------ *
 * 3. a ping() that throws cannot abort the sweep or crash the bridge
 * ------------------------------------------------------------------ */
{
    const good1 = fakeSocket(OPEN);
    const bad = fakeSocket(OPEN);
    bad.ping = () => { throw new Error('socket went away mid-sweep'); };
    const good2 = fakeSocket(OPEN);
    const clients = [good1, bad, good2];

    const errors = [];
    let result;
    assert.doesNotThrow(() => {
        result = keepalivePingSweep(clients, OPEN, (err, ws) => errors.push({ err, ws }));
    }, 'a throwing ping() must never propagate out of the sweep');

    assert.strictEqual(good1.pings, 1, 'client before the bad socket is pinged');
    assert.strictEqual(good2.pings, 1, 'client after the bad socket is still pinged');
    assert.deepStrictEqual(
        result,
        { pinged: 2, skipped: 0, failed: 1 },
        'sweep must report the single failure and keep going'
    );
    assert.strictEqual(errors.length, 1, 'onError hook is invoked once for the failure');
    assert.strictEqual(errors[0].ws, bad, 'onError receives the offending socket');

    // A hook that itself throws must also not break the sweep.
    const g = fakeSocket(OPEN);
    const b = fakeSocket(OPEN);
    b.ping = () => { throw new Error('boom'); };
    assert.doesNotThrow(() => {
        keepalivePingSweep([b, g], OPEN, () => { throw new Error('bad hook'); });
    }, 'a throwing onError hook must not break the sweep');
    assert.strictEqual(g.pings, 1, 'sweep continues past a throwing hook');
}

/* ------------------------------------------------------------------ *
 * 4. empty / undefined clients collection is a no-op
 * ------------------------------------------------------------------ */
{
    assert.deepStrictEqual(keepalivePingSweep(new Set(), OPEN), { pinged: 0, skipped: 0, failed: 0 });
    assert.deepStrictEqual(keepalivePingSweep(undefined, OPEN), { pinged: 0, skipped: 0, failed: 0 });
}

/* ------------------------------------------------------------------ *
 * 5. startKeepalive: one bridge-level interval, unref'd, cleanly stoppable
 * ------------------------------------------------------------------ */
{
    const realSetInterval = global.setInterval;
    const realClearInterval = global.clearInterval;
    let scheduled = 0;
    let capturedHandle = null;
    let capturedMs = null;
    let cleared = [];
    global.setInterval = (fn, ms) => {
        scheduled += 1;
        capturedMs = ms;
        capturedHandle = realSetInterval(fn, ms);
        return capturedHandle;
    };
    global.clearInterval = (h) => {
        cleared.push(h);
        return realClearInterval(h);
    };

    let keepalive;
    const logs = [];
    try {
        const open = fakeSocket(OPEN);
        const wsServer = { clients: new Set([open]) };
        keepalive = startKeepalive(wsServer, { OPEN }, { log: (m) => logs.push(m) });

        assert.strictEqual(keepalive.intervalSeconds, 20, 'default interval is 20s');
        assert.strictEqual(scheduled, 1, 'startKeepalive must schedule exactly one bridge-level interval');
        assert.strictEqual(capturedMs, 20000, 'interval fires every 20000ms');
        assert.strictEqual(
            typeof capturedHandle.hasRef === 'function' ? capturedHandle.hasRef() : false,
            false,
            "keepalive interval must be unref'd so it cannot hold the process open"
        );
        assert.ok(
            logs.some((l) => /keepalive/i.test(l) && /20s/.test(l)),
            'startKeepalive logs a single start line mentioning the interval'
        );

        // sweepNow drives the same sweep on demand
        const r = keepalive.sweepNow();
        assert.strictEqual(open.pings, 1, 'sweepNow pings the OPEN client');
        assert.deepStrictEqual(r, { pinged: 1, skipped: 0, failed: 0 });

        // stop() clears exactly the interval it scheduled
        keepalive.stop();
        assert.deepStrictEqual(cleared, [capturedHandle], 'stop() clears the keepalive interval and only that');
    } finally {
        global.setInterval = realSetInterval;
        global.clearInterval = realClearInterval;
        if (keepalive) keepalive.stop();
    }
}

/* ------------------------------------------------------------------ *
 * 6. wiring: the bridge starts the keepalive and stops it on shutdown
 * ------------------------------------------------------------------ */
{
    const fs = require('fs');
    const path = require('path');
    const bridge = fs.readFileSync(
        path.resolve(__dirname, '../../scripts/dosbox-bridge/multiplexing-server.js'),
        'utf8'
    );
    assert.ok(/const wsKeepalive = startKeepalive\(wsServer, WebSocket/.test(bridge), 'bridge must start the keepalive on its WebSocket server');
    assert.strictEqual((bridge.match(/wsKeepalive\.stop\(\);/g) || []).length, 2, 'SIGINT and SIGTERM handlers must stop the keepalive');
}

/* ------------------------------------------------------------------ *
 * 7. integration: real ws server + real client actually receives a PING frame
 * ------------------------------------------------------------------ */
(async () => {
    let WebSocket;
    try {
        WebSocket = require('../../scripts/dosbox-bridge/node_modules/ws');
    } catch (_) {
        console.log('ws-keepalive tests: unit checks passed (ws module unavailable, integration skipped)');
        return;
    }

    const wss = new WebSocket.Server({ host: '127.0.0.1', port: 0 });
    await new Promise((resolve) => wss.once('listening', resolve));
    const { port } = wss.address();

    const serverSockets = [];
    wss.on('connection', (ws) => serverSockets.push(ws));

    const client = new WebSocket(`ws://127.0.0.1:${port}`);
    let pingsReceived = 0;
    client.on('ping', () => { pingsReceived += 1; });

    await new Promise((resolve, reject) => {
        client.once('open', resolve);
        client.once('error', reject);
    });
    // let the server-side connection settle
    await new Promise((r) => setTimeout(r, 50));

    const keepalive = startKeepalive(wss, WebSocket, { intervalSeconds: 3600, log: () => {} });
    const result = keepalive.sweepNow();
    assert.strictEqual(result.pinged, 1, 'the one OPEN server-side client is pinged');

    await new Promise((r) => setTimeout(r, 100));
    assert.ok(pingsReceived >= 1, 'the browser-side client actually received a protocol PING frame');

    // a non-OPEN client is skipped: close one, add another, re-sweep
    client.close();
    await new Promise((r) => setTimeout(r, 50));
    const afterClose = keepalive.sweepNow();
    assert.strictEqual(afterClose.pinged, 0, 'a closed client is not pinged');

    keepalive.stop();
    wss.close();
    await new Promise((r) => setTimeout(r, 20));

    console.log('ws-keepalive tests passed (unit + integration)');
})().catch((err) => {
    console.error(err);
    process.exitCode = 1;
});
