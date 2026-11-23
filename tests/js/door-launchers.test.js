'use strict';

/**
 * Door launch strategies (scripts/dosbox-bridge/door-launchers.js, door-launcher.js,
 * door-launcher-protocol.js). Everything here runs without root, sudo, a real
 * pty or unix sockets, so it works on any platform.
 *
 * Run: node tests/js/door-launchers.test.js
 */
const assert = require('assert');
const fs = require('fs');
const net = require('net');
const os = require('os');
const path = require('path');

const bridge = path.resolve(__dirname, '../../scripts/dosbox-bridge');
const protocol = require(path.join(bridge, 'door-launcher-protocol.js'));
const launcher = require(path.join(bridge, 'door-launcher.js'));
const launchers = require(path.join(bridge, 'door-launchers.js'));

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'doorlaunch-'));
const results = [];

function test(name, fn) {
    results.push({ name, fn });
}

const silent = { log() {}, error() {}, warn() {} };

// ---------------------------------------------------------------- protocol

test('frames round-trip, including split and coalesced chunks', () => {
    const a = protocol.encodeFrame('D', Buffer.from('hello'));
    const b = protocol.encodeJsonFrame('R', { cols: 80, rows: 25 });
    const joined = Buffer.concat([a, b]);
    const decoder = new protocol.FrameDecoder();

    let frames = decoder.push(joined.subarray(0, 3));
    assert.strictEqual(frames.length, 0);
    frames = decoder.push(joined.subarray(3));
    assert.strictEqual(frames.length, 2);
    assert.strictEqual(frames[0].type, 'D');
    assert.strictEqual(frames[0].payload.toString(), 'hello');
    assert.deepStrictEqual(protocol.parseJsonPayload(frames[1].payload), { cols: 80, rows: 25 });
});

test('an oversized frame is rejected', () => {
    const header = Buffer.alloc(5);
    header.write('D', 0, 1, 'latin1');
    header.writeUInt32BE(protocol.MAX_FRAME_BYTES + 1, 1);
    assert.throws(() => new protocol.FrameDecoder().push(header), /too large/);
});

// ------------------------------------------------------------ exec policy

const rootsDir = path.join(tmp, 'doors');
const doorDir = path.join(rootsDir, 'mydoor');
const outsideDir = path.join(tmp, 'outside');
fs.mkdirSync(doorDir, { recursive: true });
fs.mkdirSync(outsideDir, { recursive: true });
fs.writeFileSync(path.join(doorDir, 'run.sh'), '#!/bin/sh\n');
fs.writeFileSync(path.join(doorDir, 'door.bin'), 'x');
fs.writeFileSync(path.join(outsideDir, 'evil.sh'), 'x');
fs.writeFileSync(path.join(outsideDir, 'fakeshell'), 'x');
const policy = { execRoots: [rootsDir], interpreters: [path.join(outsideDir, 'fakeshell')] };
const interp = path.join(outsideDir, 'fakeshell');

test('an executable inside the roots is allowed', () => {
    const v = launcher.checkExec({ argv: [path.join(doorDir, 'door.bin'), '-f', 'x'], cwd: doorDir, env: {} }, policy);
    assert.strictEqual(v.ok, true, v.reason);
    assert.deepStrictEqual(v.args, ['-f', 'x']);
});

test('a relative command is resolved against the working directory', () => {
    const v = launcher.checkExec({ argv: ['./door.bin'], cwd: doorDir, env: {} }, policy);
    assert.strictEqual(v.ok, true, v.reason);
});

test('an executable outside the roots is refused', () => {
    const v = launcher.checkExec({ argv: [path.join(outsideDir, 'evil.sh')], cwd: doorDir, env: {} }, policy);
    assert.strictEqual(v.ok, false);
    assert.match(v.reason, /outside the allowed roots/);
});

test('a working directory outside the roots is refused', () => {
    const v = launcher.checkExec({ argv: [path.join(doorDir, 'door.bin')], cwd: outsideDir, env: {} }, policy);
    assert.strictEqual(v.ok, false);
    assert.match(v.reason, /working directory/);
});

test('a missing executable is refused', () => {
    const v = launcher.checkExec({ argv: [path.join(doorDir, 'nope')], cwd: doorDir, env: {} }, policy);
    assert.strictEqual(v.ok, false);
});

test('an interpreter may run a script inside the roots', () => {
    const v = launcher.checkExec({ argv: [interp, 'run.sh', 'menu'], cwd: doorDir, env: {} }, policy);
    assert.strictEqual(v.ok, true, v.reason);
});

test('an interpreter running a script outside the roots is refused', () => {
    const v = launcher.checkExec({ argv: [interp, path.join(outsideDir, 'evil.sh')], cwd: doorDir, env: {} }, policy);
    assert.strictEqual(v.ok, false);
    assert.match(v.reason, /script is outside/);
});

test('an interpreter given inline code is refused', () => {
    for (const flag of ['-c', '-ec', '--rcfile', '-o']) {
        const v = launcher.checkExec({ argv: [interp, flag, 'run.sh'], cwd: doorDir, env: {} }, policy);
        assert.strictEqual(v.ok, false, flag);
    }
});

test('an interpreter with no script is refused', () => {
    const v = launcher.checkExec({ argv: [interp, '-e'], cwd: doorDir, env: {} }, policy);
    assert.strictEqual(v.ok, false);
});

test('a symlink pointing outside the roots is refused', () => {
    const link = path.join(doorDir, 'link.bin');
    try {
        fs.symlinkSync(path.join(outsideDir, 'evil.sh'), link);
    } catch (_) {
        return; // symlinks need privileges on some Windows setups
    }
    const v = launcher.checkExec({ argv: [link], cwd: doorDir, env: {} }, policy);
    assert.strictEqual(v.ok, false);
});

test('spawn requests are validated', () => {
    const good = { argv: ['a'], cwd: '/x', env: { A: 'b' }, cols: 80, rows: 25, encoding: 'utf8' };
    assert.strictEqual(launcher.validateSpawnHeader(good), null);
    assert.ok(launcher.validateSpawnHeader({ ...good, argv: [] }));
    assert.ok(launcher.validateSpawnHeader({ ...good, env: { A: 1 } }));
    assert.ok(launcher.validateSpawnHeader({ ...good, cols: 0 }));
    assert.ok(launcher.validateSpawnHeader({ ...good, encoding: 'latin1' }));
});

// ------------------------------------------------------------- resolution

test('no configuration at all resolves to direct', () => {
    const s = launchers.resolveLaunchSettings('door', {}, { env: {}, platform: 'linux' });
    assert.strictEqual(s.mode, 'direct');
});

test('a per-door mode overrides the global default', () => {
    const s = launchers.resolveLaunchSettings('door',
        { door: { launch_mode: 'sudo', run_as: 'door_a' } },
        { env: { NATIVEDOOR_LAUNCH_MODE: 'direct' }, platform: 'linux' });
    assert.strictEqual(s.mode, 'sudo');
    assert.strictEqual(s.runAs, 'door_a');
});

test('the global default applies to a door with no mode', () => {
    const s = launchers.resolveLaunchSettings('door', { door: {} },
        { env: { NATIVEDOOR_LAUNCH_MODE: 'socket', NATIVEDOOR_RUN_AS_USER: 'door_b' }, platform: 'linux' });
    assert.strictEqual(s.mode, 'socket');
    assert.strictEqual(s.socketName, 'door_b');
    assert.strictEqual(s.socketPath, path.join('/run/binkterm-doors', 'door_b', 'launcher.sock'));
});

test('a door with no mode is refused once any door uses a non-direct mode', () => {
    const s = launchers.resolveLaunchSettings('plain',
        { plain: {}, other: { launch_mode: 'sudo', run_as: 'door_a' } }, { env: {}, platform: 'linux' });
    assert.ok(s.error);
    assert.strictEqual(s.mode, undefined);
});

test('an explicit direct door is still allowed beside non-direct ones', () => {
    const s = launchers.resolveLaunchSettings('plain',
        { plain: { launch_mode: 'direct' }, other: { launch_mode: 'sudo', run_as: 'door_a' } },
        { env: {}, platform: 'linux' });
    assert.strictEqual(s.mode, 'direct');
});

test('an unknown mode, or sudo without a user, is an error', () => {
    assert.ok(launchers.resolveLaunchSettings('d', { d: { launch_mode: 'chroot' } }, { env: {}, platform: 'linux' }).error);
    assert.ok(launchers.resolveLaunchSettings('d', { d: { launch_mode: 'sudo' } }, { env: {}, platform: 'linux' }).error);
    assert.ok(launchers.resolveLaunchSettings('d', { d: { launch_mode: 'sudo', run_as: '-bad name' } }, { env: {}, platform: 'linux' }).error);
    assert.ok(launchers.resolveLaunchSettings('d', {}, { env: { NATIVEDOOR_LAUNCH_MODE: 'x' }, platform: 'linux' }).error);
});

test('non-direct modes are ignored with a notice on Windows and macOS', () => {
    for (const platform of ['win32', 'darwin']) {
        const s = launchers.resolveLaunchSettings('d', { d: { launch_mode: 'sudo', run_as: 'a' } }, { env: {}, platform });
        assert.strictEqual(s.mode, 'direct');
        assert.ok(s.notice);
    }
});

// ------------------------------------------------------------ user checks

function fakeDeps(overrides) {
    return {
        appUid: () => 1000,
        lookupUser: (name) => ({ door_a: { uid: 2001, gid: 2001 }, root: { uid: 0, gid: 0 }, binkterm: { uid: 1000, gid: 1000 } }[name] || null),
        groupMembers: () => ['door_a'],
        run: () => ({ status: 0, stdout: '', stderr: '' }),
        socketExists: () => true,
        ...overrides
    };
}

test('door users must exist, not be root, not be the app user, and be in the group', () => {
    const d = fakeDeps();
    assert.strictEqual(launchers.validateDoorUser('door_a', { requireGroup: 'doors' }, d).ok, true);
    assert.match(launchers.validateDoorUser('ghost', {}, d).error, /does not exist/);
    assert.match(launchers.validateDoorUser('root', {}, d).error, /root/);
    assert.match(launchers.validateDoorUser('binkterm', {}, d).error, /own user/);
    assert.match(launchers.validateDoorUser('door_a', { requireGroup: 'doors' }, fakeDeps({ groupMembers: () => [] })).error, /not a member/);
    assert.match(launchers.validateDoorUser('bad name', {}, d).error, /not a valid/);
});

// ------------------------------------------------------- sudo preflight

const baseDir = path.join(tmp, 'base');
fs.mkdirSync(path.join(baseDir, 'config'), { recursive: true });
fs.writeFileSync(path.join(baseDir, '.env'), 'X=1');
fs.writeFileSync(path.join(baseDir, 'config', 'binkp.json'), '{}');
const sudoSettings = { mode: 'sudo', runAs: 'door_a', doorsGroup: 'doors' };

test('sudo preflight passes when sudo works and secrets are unreadable', async () => {
    const calls = [];
    const deps = fakeDeps({
        run: (cmd, args) => {
            calls.push(args.join(' '));
            return { status: args.includes('-r') ? 1 : 0, stdout: '', stderr: '' };
        }
    });
    const l = launchers.createLauncher(sudoSettings, { basePath: baseDir, logger: silent, deps });
    const r = await l.preflight();
    assert.strictEqual(r.ok, true, r.error);
    assert.strictEqual(r.gid, 2001);
    assert.ok(calls.some((c) => c.includes('test -r') && c.endsWith('.env')));
});

test('sudo preflight fails closed when sudo is not permitted', async () => {
    const deps = fakeDeps({ run: () => ({ status: 1, stdout: '', stderr: 'sudo: a password is required' }) });
    const r = await launchers.createLauncher(sudoSettings, { basePath: baseDir, logger: silent, deps }).preflight();
    assert.strictEqual(r.ok, false);
    assert.match(r.error, /password is required/);
});

test('sudo preflight fails closed when the door user can read .env', async () => {
    const deps = fakeDeps({ run: () => ({ status: 0, stdout: '', stderr: '' }) }); // test -r succeeds
    const r = await launchers.createLauncher(sudoSettings, { basePath: baseDir, logger: silent, deps }).preflight();
    assert.strictEqual(r.ok, false);
    assert.match(r.error, /can read/);
});

test('sudo preflight refuses a user outside the door group', async () => {
    const deps = fakeDeps({ groupMembers: () => [] });
    const r = await launchers.createLauncher(sudoSettings, { basePath: baseDir, logger: silent, deps }).preflight();
    assert.strictEqual(r.ok, false);
});

test('sudo write check fails when the home directory is not writable', async () => {
    const deps = fakeDeps({ run: () => ({ status: 1, stdout: '', stderr: '' }) });
    const r = await launchers.createLauncher(sudoSettings, { basePath: baseDir, logger: silent, deps }).checkWritable('/x');
    assert.strictEqual(r.ok, false);
});

// ----------------------------------------- socket launcher, end to end

/**
 * A fake node-pty so the launcher can be exercised without a real pty.
 */
function fakePty(log) {
    return {
        spawn(exe, args, opts) {
            const proc = {
                pid: 4242,
                dataCb: null,
                exitCb: null,
                onData(cb) { this.dataCb = cb; },
                onExit(cb) { this.exitCb = cb; },
                write(d) { log.written.push(d); this.dataCb && this.dataCb('echo:' + d); },
                resize(c, r) { log.resized.push([c, r]); },
                kill() { log.killed += 1; }
            };
            log.spawned = { exe, args, opts, proc };
            setImmediate(() => proc.dataCb && proc.dataCb('éready'));
            return proc;
        }
    };
}

async function withLauncher(policyOverride, fn) {
    const log = { written: [], resized: [], killed: 0, spawned: null };
    const server = launcher.createLauncherServer({
        execRoots: policyOverride.execRoots,
        interpreters: policyOverride.interpreters,
        pty: fakePty(log),
        logger: silent
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const port = server.address().port;
    const deps = fakeDeps({
        appUid: () => 1000,
        socketExists: () => true,
        connect: () => new Promise((resolve) => {
            const socket = net.connect(port, '127.0.0.1', () => resolve(new launchers.LauncherConnection(socket)));
        })
    });
    try {
        await fn({ log, deps });
    } finally {
        server.close();
    }
}

const socketSettings = { mode: 'socket', socketName: 'door_a', socketPath: '/run/binkterm-doors/door_a/launcher.sock' };

test('the socket launcher spawns a door and relays data, resize and exit', async () => {
    await withLauncher(policy, async ({ log, deps }) => {
        // The launcher process reports its own uid; make it differ from the bridge's
        deps.appUid = () => -12345;
        const l = launchers.createLauncher(socketSettings, { basePath: baseDir, logger: silent, deps });

        const handle = await l.spawn({
            cmd: path.join(doorDir, 'door.bin'), args: ['-f', 'drop'],
            env: { DOOR_NODE: '1' }, cwd: doorDir, cols: 100, rows: 30, encoding: 'binary'
        });
        assert.strictEqual(handle.pid, 4242);
        assert.deepStrictEqual(log.spawned.opts.env, { DOOR_NODE: '1' });
        assert.strictEqual(log.spawned.opts.cols, 100);
        assert.strictEqual(log.spawned.opts.encoding, 'binary');

        const received = [];
        handle.onData((d) => received.push(d));
        handle.write('hi');
        handle.resize(120, 40);
        await new Promise((resolve) => setTimeout(resolve, 50));
        assert.ok(received.includes('éready'), 'output held back before onData is replayed: ' + JSON.stringify(received));
        assert.ok(received.includes('echo:hi'));
        assert.deepStrictEqual(log.written, ['hi']);
        assert.deepStrictEqual(log.resized, [[120, 40]]);
        assert.strictEqual(handle.isAlive(), true);

        let exit = null;
        handle.onExit((e) => { exit = e; });
        log.spawned.proc.exitCb({ exitCode: 3, signal: 0 });
        await new Promise((resolve) => setTimeout(resolve, 50));
        assert.deepStrictEqual(exit, { exitCode: 3, signal: 0 });
        assert.strictEqual(handle.isAlive(), false);
    });
});

test('the socket launcher refuses an executable outside its roots', async () => {
    await withLauncher(policy, async ({ deps }) => {
        deps.appUid = () => -12345;
        const l = launchers.createLauncher(socketSettings, { basePath: baseDir, logger: silent, deps });
        await assert.rejects(
            l.spawn({ cmd: path.join(outsideDir, 'evil.sh'), args: [], env: {}, cwd: doorDir, cols: 80, rows: 25, encoding: 'utf8' }),
            /outside the allowed roots/
        );
    });
});

test('closing the connection kills the door', async () => {
    await withLauncher(policy, async ({ log, deps }) => {
        deps.appUid = () => -12345;
        const l = launchers.createLauncher(socketSettings, { basePath: baseDir, logger: silent, deps });
        const handle = await l.spawn({
            cmd: path.join(doorDir, 'door.bin'), args: [], env: {}, cwd: doorDir, cols: 80, rows: 25, encoding: 'utf8'
        });
        const killed = [];
        const realKill = process.kill;
        process.kill = (pid, sig) => { killed.push([pid, sig]); };
        try {
            handle.connection.close();
            await new Promise((resolve) => setTimeout(resolve, 80));
        } finally {
            process.kill = realKill;
        }
        assert.ok(killed.some(([pid, sig]) => Math.abs(pid) === 4242 && sig === 'SIGKILL'), JSON.stringify(killed));
    });
});

test('socket preflight refuses a launcher running as the bridge user', async () => {
    await withLauncher(policy, async ({ deps }) => {
        deps.appUid = () => (typeof process.getuid === 'function' ? process.getuid() : -1);
        const l = launchers.createLauncher(socketSettings, { basePath: baseDir, logger: silent, deps });
        const r = await l.preflight();
        assert.strictEqual(r.ok, false);
        assert.match(r.error, /bridge's own user/);
    });
});

test('socket preflight fails when the socket is missing', async () => {
    const deps = fakeDeps({ socketExists: () => false });
    const r = await launchers.createLauncher(socketSettings, { basePath: baseDir, logger: silent, deps }).preflight();
    assert.strictEqual(r.ok, false);
    assert.match(r.error, /does not exist/);
});

test('socket preflight passes when secrets are unreadable, and fails when readable', async () => {
    await withLauncher(policy, async ({ deps }) => {
        deps.appUid = () => -12345;
        // The launcher here runs as the test's own user, which can read the temp files.
        const l = launchers.createLauncher(socketSettings, { basePath: baseDir, logger: silent, deps });
        const r = await l.preflight();
        assert.strictEqual(r.ok, false);
        assert.match(r.error, /can read/);
    });
    // With nothing to protect the same launcher passes.
    const emptyBase = path.join(tmp, 'emptybase');
    fs.mkdirSync(emptyBase, { recursive: true });
    await withLauncher(policy, async ({ deps }) => {
        deps.appUid = () => -12345;
        const r = await launchers.createLauncher(socketSettings, { basePath: emptyBase, logger: silent, deps }).preflight();
        assert.strictEqual(r.ok, true, r.error);
        const w = await launchers.createLauncher(socketSettings, { basePath: emptyBase, logger: silent, deps }).checkWritable(tmp);
        assert.strictEqual(w.ok, true, w.error);
    });
});

// ------------------------------------------------------------------ run

(async () => {
    let failed = 0;
    for (const t of results) {
        try {
            await t.fn();
            console.log('ok   ' + t.name);
        } catch (e) {
            failed += 1;
            console.log('FAIL ' + t.name + '\n     ' + (e && e.stack ? e.stack.split('\n').slice(0, 3).join('\n     ') : e));
        }
    }
    fs.rmSync(tmp, { recursive: true, force: true });
    if (failed > 0) {
        console.log(failed + ' of ' + results.length + ' failed');
        process.exit(1);
    }
    console.log('door-launchers tests passed (' + results.length + ')');
})();
