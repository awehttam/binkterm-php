'use strict';

/**
 * Native door launch strategies.
 *
 * One interface, three strategies:
 *   direct  The bridge spawns the door itself (the only strategy on Windows and macOS).
 *   sudo    The bridge spawns the door through `sudo -n -u <user>`.
 *   socket  The bridge asks a launcher already running as the door user to spawn it.
 *
 * Every strategy returns the same handle: pid, onData, onExit, write, resize,
 * kill, forceKill, isAlive. The strategy is chosen per door by the sysop in
 * config/nativedoors.json (never by the door's manifest) and fails closed: if a
 * non-direct mode cannot be confirmed safe, the launch is refused and the door
 * is never run as the app user.
 *
 * See docs/proposals/NativeDoorHardening.md.
 */

const fs = require('fs');
const net = require('net');
const path = require('path');
const childProcess = require('child_process');
const {
    TO_LAUNCHER,
    FROM_LAUNCHER,
    encodeFrame,
    encodeJsonFrame,
    FrameDecoder,
    parseJsonPayload
} = require('./door-launcher-protocol');
const { DEFAULT_DOOR_PATH } = require('./door-env');

const MODES = ['direct', 'sudo', 'socket'];
const NAME_PATTERN = /^[A-Za-z_][A-Za-z0-9_-]{0,31}$/;
const DEFAULT_SOCKET_DIR = '/run/binkterm-doors';
const DEFAULT_DOORS_GROUP = 'doors';
const HANDSHAKE_TIMEOUT_MS = 5000;
const FAST_EXIT_MS = 3000;

/**
 * @param {number} pid
 * @returns {boolean} True if the process exists. EPERM means it exists but belongs to another user.
 */
function probePid(pid) {
    try {
        process.kill(pid, 0);
        return true;
    } catch (e) {
        return e.code === 'EPERM';
    }
}

/**
 * Real implementations of the system lookups, replaceable in tests.
 */
const defaultDeps = {
    appUid() {
        return typeof process.getuid === 'function' ? process.getuid() : -1;
    },

    /**
     * @param {string} name
     * @returns {{uid: number, gid: number}|null}
     */
    lookupUser(name) {
        const uid = childProcess.spawnSync('id', ['-u', name], { encoding: 'utf8' });
        const gid = childProcess.spawnSync('id', ['-g', name], { encoding: 'utf8' });
        if (uid.status !== 0 || gid.status !== 0) {
            return null;
        }
        return { uid: parseInt(uid.stdout, 10), gid: parseInt(gid.stdout, 10) };
    },

    /**
     * @param {string} group
     * @returns {string[]} Member user names (supplementary members only)
     */
    groupMembers(group) {
        try {
            const lines = fs.readFileSync('/etc/group', 'utf8').split('\n');
            for (const line of lines) {
                const parts = line.split(':');
                if (parts[0] === group) {
                    return (parts[3] || '').split(',').map((s) => s.trim()).filter(Boolean);
                }
            }
        } catch (_) {
            // fall through
        }
        return [];
    },

    /**
     * @returns {{status: number|null, stdout: string, stderr: string}}
     */
    run(command, args, options) {
        const r = childProcess.spawnSync(command, args, { encoding: 'utf8', timeout: 10000, ...(options || {}) });
        return { status: r.status, stdout: r.stdout || '', stderr: r.stderr || '' };
    },

    socketExists(socketPath) {
        try {
            return fs.statSync(socketPath).isSocket();
        } catch (_) {
            return false;
        }
    }
};

/**
 * Work out how a door is to be launched.
 *
 * Resolution: the door's own `launch_mode` in nativedoors.json, else the global
 * NATIVEDOOR_LAUNCH_MODE. If neither is set and any door or the global default
 * uses a non-direct mode, the door is refused rather than run as `direct`.
 *
 * @param {string} doorId
 * @param {Object} allConfigs Parsed config/nativedoors.json
 * @param {Object} [options]
 * @param {Object} [options.env]      Defaults to process.env
 * @param {string} [options.platform] Defaults to process.platform
 * @returns {{mode?: string, runAs?: string, socketName?: string, socketPath?: string, notice?: string, error?: string}}
 */
function resolveLaunchSettings(doorId, allConfigs, options) {
    const opts = options || {};
    const env = opts.env || process.env;
    const platform = opts.platform || process.platform;
    const configs = allConfigs && typeof allConfigs === 'object' ? allConfigs : {};
    const cfg = configs[doorId] && typeof configs[doorId] === 'object' ? configs[doorId] : {};

    const globalMode = String(env.NATIVEDOOR_LAUNCH_MODE || '').trim().toLowerCase();
    const doorMode = typeof cfg.launch_mode === 'string' ? cfg.launch_mode.trim().toLowerCase() : '';

    if (globalMode && !MODES.includes(globalMode)) {
        return { error: `NATIVEDOOR_LAUNCH_MODE '${globalMode}' is not one of ${MODES.join(', ')}` };
    }
    if (doorMode && !MODES.includes(doorMode)) {
        return { error: `launch_mode '${doorMode}' for door '${doorId}' is not one of ${MODES.join(', ')}` };
    }

    const anyNonDirect = (globalMode && globalMode !== 'direct')
        || Object.values(configs).some((c) => c && typeof c === 'object'
            && MODES.includes(c.launch_mode) && c.launch_mode !== 'direct');

    let mode = doorMode || globalMode;
    if (!mode) {
        if (anyNonDirect) {
            return { error: `Door '${doorId}' has no launch mode and a non-direct mode is in use elsewhere; refusing to run it as direct` };
        }
        mode = 'direct';
    }

    if (mode !== 'direct' && (platform === 'win32' || platform === 'darwin')) {
        return { mode: 'direct', notice: `launch mode '${mode}' is not supported on ${platform}; running direct` };
    }

    const runAs = String(cfg.run_as || env.NATIVEDOOR_RUN_AS_USER || '').trim();
    const socketName = String(cfg.launcher_socket || env.NATIVEDOOR_LAUNCHER_SOCKET || runAs).trim();
    const socketDir = env.NATIVEDOOR_SOCKET_DIR || DEFAULT_SOCKET_DIR;
    const doorsGroup = env.NATIVEDOOR_DOORS_GROUP || DEFAULT_DOORS_GROUP;

    if (mode === 'sudo' && !NAME_PATTERN.test(runAs)) {
        return { error: `Door '${doorId}' uses sudo but has no valid run_as user` };
    }
    if (mode === 'socket' && !NAME_PATTERN.test(socketName)) {
        return { error: `Door '${doorId}' uses socket but has no valid launcher socket` };
    }

    return {
        mode,
        runAs,
        socketName,
        socketPath: path.join(socketDir, socketName, 'launcher.sock'),
        doorsGroup
    };
}

/**
 * Confirm the door user exists, is not root or the app user, and (for sudo) is
 * a member of the permitted door group.
 *
 * @param {string} name
 * @param {{requireGroup?: string}} options
 * @param {Object} deps
 * @returns {{ok: boolean, error?: string, uid?: number, gid?: number}}
 */
function validateDoorUser(name, options, deps) {
    if (!NAME_PATTERN.test(name)) {
        return { ok: false, error: `'${name}' is not a valid user name` };
    }
    const info = deps.lookupUser(name);
    if (!info) {
        return { ok: false, error: `user '${name}' does not exist` };
    }
    if (info.uid === 0 || name === 'root') {
        return { ok: false, error: `refusing to run doors as root ('${name}')` };
    }
    if (info.uid === deps.appUid()) {
        return { ok: false, error: `'${name}' is the bridge's own user; a door user must be a different account` };
    }
    if (options.requireGroup && !deps.groupMembers(options.requireGroup).includes(name)) {
        return { ok: false, error: `user '${name}' is not a member of the '${options.requireGroup}' group` };
    }
    return { ok: true, uid: info.uid, gid: info.gid };
}

/**
 * Create the per-user door directory with a group the door user can write.
 * Parents are traverse-only; the leaf is group-writable and setgid.
 *
 * @param {string} homeDir
 * @param {number} gid Door user's primary group
 */
function prepareDoorHome(homeDir, gid) {
    fs.mkdirSync(homeDir, { recursive: true, mode: 0o711 });
    fs.chownSync(homeDir, -1, gid);
    fs.chmodSync(homeDir, 0o2770);
}

/**
 * Handle for a door the bridge spawned itself (direct and sudo strategies).
 */
class PtyHandle {
    /**
     * @param {Object} ptyProcess node-pty process
     */
    constructor(ptyProcess) {
        this.pty = ptyProcess;
        this.pid = ptyProcess.pid;
    }

    onData(cb) { this.pty.onData(cb); }
    onExit(cb) { this.pty.onExit(cb); }
    write(data) { this.pty.write(data); }
    resize(cols, rows) { this.pty.resize(cols, rows); }
    kill() { this.pty.kill(); }
    isAlive() { return probePid(this.pid); }

    forceKill() {
        try {
            if (process.platform === 'win32') {
                childProcess.execSync(`taskkill /F /PID ${this.pid}`, { stdio: 'ignore' });
            } else {
                process.kill(this.pid, 'SIGKILL');
            }
        } catch (_) {
            // already gone
        }
    }
}

/**
 * Handle for a door run under sudo. SIGKILL to sudo cannot be relayed, so the
 * door is killed by session instead (the sudo process leads the pty session).
 */
class SudoHandle extends PtyHandle {
    constructor(ptyProcess, user, deps) {
        super(ptyProcess);
        this.user = user;
        this.deps = deps;
    }

    forceKill() {
        // Never `pkill -u <user>`: that would kill the user's other sessions.
        this.deps.run('sudo', ['-n', '-u', this.user, 'pkill', '-KILL', '-s', String(this.pid)]);
        super.forceKill();
    }
}

/**
 * One connection to a launcher socket.
 */
class LauncherConnection {
    constructor(socket) {
        this.socket = socket;
        this.decoder = new FrameDecoder();
        this.listeners = new Map();
        this.backlog = new Map();
        this.closed = false;

        socket.on('data', (chunk) => {
            let frames;
            try {
                frames = this.decoder.push(chunk);
            } catch (e) {
                this.emit('error', { message: e.message });
                socket.destroy();
                return;
            }
            frames.forEach((f) => this.emit(f.type, f.payload));
        });
        socket.on('close', () => {
            this.closed = true;
            this.emit('close');
        });
        socket.on('error', (e) => {
            this.emit('error', { message: e.message });
        });
    }

    /**
     * @param {string} socketPath
     * @returns {Promise<LauncherConnection>}
     */
    static connect(socketPath) {
        return new Promise((resolve, reject) => {
            const socket = net.connect(socketPath);
            const onError = (e) => reject(new Error(`cannot connect to launcher socket ${socketPath}: ${e.message}`));
            socket.once('error', onError);
            socket.once('connect', () => {
                socket.removeListener('error', onError);
                resolve(new LauncherConnection(socket));
            });
        });
    }

    on(type, cb) {
        const list = this.listeners.get(type) || [];
        list.push(cb);
        this.listeners.set(type, list);

        // Output and exit can arrive in the same chunk as the reply that creates the
        // handle, before the handle has registered. Replay what was held back.
        if (this.backlog.has(type)) {
            this.backlog.get(type).splice(0).forEach((payload) => cb(payload));
        }
    }

    off(type, cb) {
        const list = (this.listeners.get(type) || []).filter((l) => l !== cb);
        if (list.length > 0) {
            this.listeners.set(type, list);
        } else {
            this.listeners.delete(type);
        }
    }

    emit(type, payload) {
        const list = this.listeners.get(type) || [];
        if (list.length === 0 && (type === FROM_LAUNCHER.OUTPUT || type === FROM_LAUNCHER.EXIT)) {
            const held = this.backlog.get(type) || [];
            held.push(payload);
            this.backlog.set(type, held);
            return;
        }
        for (const cb of list.slice()) {
            cb(payload);
        }
    }

    send(type, payload) {
        if (this.closed || this.socket.destroyed) {
            return;
        }
        this.socket.write(typeof payload === 'object' && !Buffer.isBuffer(payload)
            ? encodeJsonFrame(type, payload)
            : encodeFrame(type, payload));
    }

    /**
     * Send a request and wait for one reply type (or an ERROR frame).
     *
     * @param {string} type
     * @param {Object} body
     * @param {string} replyType
     * @returns {Promise<Object>} Parsed reply
     */
    request(type, body, replyType) {
        return new Promise((resolve, reject) => {
            let timer = null;
            const cleanup = () => {
                clearTimeout(timer);
                this.off(replyType, onReply);
                this.off(FROM_LAUNCHER.ERROR, onError);
                this.off('close', onClose);
            };
            const onReply = (payload) => { cleanup(); resolve(parseJsonPayload(payload) || {}); };
            const onError = (payload) => {
                cleanup();
                const info = Buffer.isBuffer(payload) ? parseJsonPayload(payload) : payload;
                reject(new Error((info && info.message) || 'launcher error'));
            };
            const onClose = () => { cleanup(); reject(new Error('launcher closed the connection')); };
            timer = setTimeout(() => { cleanup(); reject(new Error('launcher did not answer in time')); }, HANDSHAKE_TIMEOUT_MS);
            this.on(replyType, onReply);
            this.on(FROM_LAUNCHER.ERROR, onError);
            this.on('close', onClose);
            this.send(type, body);
        });
    }

    close() {
        this.closed = true;
        this.socket.destroy();
    }
}

/**
 * Handle for a door run by a socket launcher.
 */
class SocketHandle {
    /**
     * @param {LauncherConnection} connection
     * @param {number} pid Pid of the door, as seen by the launcher
     * @param {string} encoding 'binary' or 'utf8'
     */
    constructor(connection, pid, encoding) {
        this.connection = connection;
        this.pid = pid;
        this.decodeAs = encoding === 'binary' ? 'latin1' : 'utf8';
        this.exited = false;
        this.dataCallback = null;
        this.exitCallback = null;
        this.pendingData = [];
        this.pendingExit = null;

        connection.on(FROM_LAUNCHER.OUTPUT, (payload) => {
            const text = payload.toString(this.decodeAs);
            if (this.dataCallback) {
                this.dataCallback(text);
            } else {
                this.pendingData.push(text);
            }
        });
        connection.on(FROM_LAUNCHER.EXIT, (payload) => {
            const info = parseJsonPayload(payload) || {};
            this.finish({ exitCode: Number.isInteger(info.code) ? info.code : 0, signal: info.signal || 0 });
        });
        connection.on('close', () => {
            // Launcher gone without an exit frame: it kills the door when the connection drops.
            this.finish({ exitCode: -1, signal: 0 });
        });
    }

    finish(result) {
        if (this.exited) {
            return;
        }
        this.exited = true;
        if (this.exitCallback) {
            this.exitCallback(result);
        } else {
            this.pendingExit = result;
        }
    }

    onData(cb) {
        this.dataCallback = cb;
        this.pendingData.splice(0).forEach(cb);
    }

    onExit(cb) {
        this.exitCallback = cb;
        if (this.pendingExit) {
            const result = this.pendingExit;
            this.pendingExit = null;
            cb(result);
        }
    }

    write(data) {
        this.connection.send(TO_LAUNCHER.DATA, Buffer.from(String(data), 'utf8'));
    }

    resize(cols, rows) {
        this.connection.send(TO_LAUNCHER.RESIZE, { cols, rows });
    }

    kill() {
        this.connection.send(TO_LAUNCHER.KILL, { force: false });
    }

    forceKill() {
        this.connection.send(TO_LAUNCHER.KILL, { force: true });
    }

    isAlive() {
        return !this.exited && !this.connection.closed;
    }
}

/**
 * Spawns doors directly in a pty, as the bridge's own user.
 */
class DirectLauncher {
    constructor(ctx) {
        this.ctx = ctx;
    }

    async preflight() {
        return { ok: true };
    }

    async checkWritable() {
        return { ok: true };
    }

    getUser() {
        return '';
    }

    /**
     * @param {Object} spec {cmd, args, env, cwd, cols, rows, encoding}
     * @returns {Promise<PtyHandle>}
     */
    async spawn(spec) {
        const pty = require('node-pty');
        const isWindows = process.platform === 'win32';
        const proc = pty.spawn(spec.cmd, spec.args, {
            name: 'xterm-256color',
            cols: spec.cols,
            rows: spec.rows,
            cwd: spec.cwd,
            // node-pty on Windows (conpty) does not support encoding
            ...(isWindows ? {} : { encoding: spec.encoding }),
            env: spec.env
        });
        return new PtyHandle(proc);
    }
}

/**
 * Spawns doors through `sudo -n -u <user>`.
 */
class SudoLauncher {
    /**
     * @param {Object} settings Result of resolveLaunchSettings()
     * @param {Object} ctx {basePath, logger, deps}
     */
    constructor(settings, ctx) {
        this.settings = settings;
        this.ctx = ctx;
        this.deps = ctx.deps || defaultDeps;
    }

    getUser() {
        return this.settings.runAs;
    }

    /**
     * @returns {Promise<{ok: boolean, error?: string, gid?: number}>}
     */
    async preflight() {
        const user = this.settings.runAs;
        const verdict = validateDoorUser(user, { requireGroup: this.settings.doorsGroup }, this.deps);
        if (!verdict.ok) {
            return verdict;
        }

        const probe = this.deps.run('sudo', ['-n', '-u', user, 'true']);
        if (probe.status !== 0) {
            return { ok: false, error: `sudo -n -u ${user} failed: ${(probe.stderr || probe.stdout).trim() || 'no output'}` };
        }

        return { ok: true, gid: verdict.gid };
    }

    /**
     * Confirm the door user can write its per-user directory.
     *
     * @param {string} dir
     * @returns {Promise<{ok: boolean, error?: string}>}
     */
    async checkWritable(dir) {
        const user = this.settings.runAs;
        const write = this.deps.run('sudo', ['-n', '-u', user, 'test', '-w', dir]);
        return write.status === 0
            ? { ok: true }
            : { ok: false, error: `door user '${user}' cannot write ${dir}` };
    }

    /**
     * @param {Object} spec {cmd, args, env, cwd, cols, rows, encoding}
     * @returns {Promise<SudoHandle>}
     */
    async spawn(spec) {
        const user = this.settings.runAs;
        const envArgs = Object.entries(spec.env).map(([k, v]) => `${k}=${v}`);
        // sudo resets the environment, so the strict one is passed as env arguments.
        // The umask wrapper keeps files the door creates removable by the app user.
        const args = ['-n', '-u', user, '--', 'env', '-i', ...envArgs,
            'sh', '-c', 'umask 002; exec "$@"', 'sh', spec.cmd, ...spec.args];

        const pty = require('node-pty');
        const proc = pty.spawn('sudo', args, {
            name: 'xterm-256color',
            cols: spec.cols,
            rows: spec.rows,
            cwd: spec.cwd,
            encoding: spec.encoding,
            env: { PATH: DEFAULT_DOOR_PATH, TERM: 'xterm-256color' }
        });
        const handle = new SudoHandle(proc, user, this.deps);

        // A launch that fails because sudo changed after startup looks like a door that
        // exits at once. Report the cause instead of leaving a silent dead session.
        const startedAt = Date.now();
        let early = '';
        proc.onData((d) => {
            if (early.length < 2000) {
                early += String(d);
            }
        });
        proc.onExit(({ exitCode }) => {
            if (exitCode !== 0 && Date.now() - startedAt < FAST_EXIT_MS && /sudo|password|not allowed/i.test(early)) {
                this.ctx.logger.error(`[Launcher] sudo launch as '${user}' failed: ${early.trim().slice(0, 300)}`);
            }
        });
        return handle;
    }
}

/**
 * Spawns doors by asking a launcher process that runs as the door user.
 */
class SocketLauncher {
    /**
     * @param {Object} settings Result of resolveLaunchSettings()
     * @param {Object} ctx {basePath, logger, deps}
     */
    constructor(settings, ctx) {
        this.settings = settings;
        this.ctx = ctx;
        this.deps = ctx.deps || defaultDeps;
    }

    getUser() {
        return this.settings.socketName;
    }

    /**
     * Open a connection, complete the handshake and refuse a launcher that is not
     * an unprivileged, separate user.
     *
     * @returns {Promise<{connection?: LauncherConnection, ready?: Object, error?: string}>}
     */
    async handshake() {
        const socketPath = this.settings.socketPath;
        if (!this.deps.socketExists(socketPath)) {
            return { error: `launcher socket ${socketPath} does not exist` };
        }
        let connection;
        try {
            connection = await (this.deps.connect || LauncherConnection.connect)(socketPath);
            const ready = await connection.request(TO_LAUNCHER.HELLO, {}, FROM_LAUNCHER.READY);
            if (ready.uid === 0) {
                connection.close();
                return { error: 'launcher is running as root' };
            }
            if (ready.uid === this.deps.appUid()) {
                connection.close();
                return { error: "launcher is running as the bridge's own user" };
            }
            return { connection, ready };
        } catch (e) {
            if (connection) {
                connection.close();
            }
            return { error: e.message };
        }
    }

    /**
     * @returns {Promise<{ok: boolean, error?: string, gid?: number}>}
     */
    async preflight() {
        const hello = await this.handshake();
        if (hello.error) {
            return { ok: false, error: hello.error };
        }
        try {
            return { ok: true, gid: hello.ready.gid };
        } catch (e) {
            return { ok: false, error: e.message };
        } finally {
            hello.connection.close();
        }
    }

    /**
     * Confirm the launcher user can write the per-user directory.
     *
     * @param {string} dir
     * @returns {Promise<{ok: boolean, error?: string}>}
     */
    async checkWritable(dir) {
        const hello = await this.handshake();
        if (hello.error) {
            return { ok: false, error: hello.error };
        }
        try {
            const probe = await hello.connection.request(TO_LAUNCHER.PROBE, { read: [], write: [dir] }, FROM_LAUNCHER.PROBED);
            return probe.write && probe.write[dir]
                ? { ok: true }
                : { ok: false, error: `launcher user cannot write ${dir}` };
        } catch (e) {
            return { ok: false, error: e.message };
        } finally {
            hello.connection.close();
        }
    }

    /**
     * @param {Object} spec {cmd, args, env, cwd, cols, rows, encoding}
     * @returns {Promise<SocketHandle>}
     */
    async spawn(spec) {
        const connection = await (this.deps.connect || LauncherConnection.connect)(this.settings.socketPath);
        try {
            await connection.request(TO_LAUNCHER.HELLO, {}, FROM_LAUNCHER.READY);
            const spawned = await connection.request(TO_LAUNCHER.SPAWN, {
                argv: [spec.cmd, ...spec.args],
                env: spec.env,
                cwd: spec.cwd,
                cols: spec.cols,
                rows: spec.rows,
                encoding: spec.encoding
            }, FROM_LAUNCHER.SPAWNED);
            return new SocketHandle(connection, spawned.pid, spec.encoding);
        } catch (e) {
            connection.close();
            throw new Error(`launcher refused the door: ${e.message}`);
        }
    }
}

/**
 * Build the launcher for a resolved setting.
 *
 * @param {{mode: string}} settings
 * @param {{basePath: string, logger: Object, deps?: Object}} ctx
 * @returns {DirectLauncher|SudoLauncher|SocketLauncher}
 */
function createLauncher(settings, ctx) {
    if (settings.mode === 'sudo') {
        return new SudoLauncher(settings, ctx);
    }
    if (settings.mode === 'socket') {
        return new SocketLauncher(settings, ctx);
    }
    return new DirectLauncher(ctx);
}

/**
 * Read config/nativedoors.json, tolerating a missing or broken file.
 *
 * @param {string} basePath
 * @returns {Object}
 */
function readDoorConfigs(basePath) {
    try {
        const parsed = JSON.parse(fs.readFileSync(path.join(basePath, 'config', 'nativedoors.json'), 'utf8'));
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch (_) {
        return {};
    }
}

/**
 * Preflight every configured door and report its state (healthy or failing).
 * Used for the bridge's startup and reload status output.
 *
 * @param {string} basePath
 * @param {Object} logger
 * @param {Object} [deps]
 * @returns {Promise<{doorId: string, mode: string, ok: boolean, detail: string}[]>}
 */
async function describeLaunchStatus(basePath, logger, deps) {
    const configs = readDoorConfigs(basePath);
    const results = [];
    for (const doorId of Object.keys(configs)) {
        const settings = resolveLaunchSettings(doorId, configs);
        if (settings.error) {
            results.push({ doorId, mode: 'unresolved', ok: false, detail: settings.error });
            continue;
        }
        if (settings.mode === 'direct') {
            continue;
        }
        const launcher = createLauncher(settings, { basePath, logger, deps });
        const verdict = await launcher.preflight();
        results.push({
            doorId,
            mode: settings.mode,
            ok: verdict.ok,
            detail: verdict.ok ? `ready as '${launcher.getUser()}'` : verdict.error
        });
    }
    results.forEach((r) => {
        const line = `[Launcher] ${r.doorId}: ${r.mode} ${r.ok ? 'healthy' : 'FAILING'} - ${r.detail}`;
        if (r.ok) {
            logger.log(line);
        } else {
            logger.error(line);
        }
    });
    return results;
}

module.exports = {
    MODES,
    NAME_PATTERN,
    defaultDeps,
    probePid,
    resolveLaunchSettings,
    validateDoorUser,
    prepareDoorHome,
    PtyHandle,
    SudoHandle,
    SocketHandle,
    LauncherConnection,
    DirectLauncher,
    SudoLauncher,
    SocketLauncher,
    createLauncher,
    readDoorConfigs,
    describeLaunchStatus
};
