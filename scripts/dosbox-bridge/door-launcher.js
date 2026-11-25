#!/usr/bin/env node
'use strict';

/**
 * Door launcher (socket strategy).
 *
 * A small process that runs as an unprivileged door user and spawns doors on
 * behalf of the bridge. It already holds the right uid, so it needs no setuid
 * code. Per connection it reads a spawn request, starts the door in a pty with
 * exactly the environment in that request (never its own), relays I/O over the
 * connection, and kills the door's process group when the connection closes.
 *
 * Configuration comes from the launcher's own environment, set by the systemd
 * unit or supervisor program that hosts it (never from a web-writable file):
 *
 *   NATIVEDOOR_LAUNCHER_SOCKET       Socket path, when not socket-activated
 *   NATIVEDOOR_LAUNCHER_EXEC_ROOTS   Colon-separated roots a door may live in
 *                                    (default: native-doors/doors/)
 *   NATIVEDOOR_LAUNCHER_INTERPRETERS Colon-separated interpreters that may run
 *                                    a script inside the roots
 *                                    (default: /bin/bash:/usr/bin/bash:/bin/sh)
 *
 * Under systemd socket activation (LISTEN_FDS=1) the listening socket is fd 3.
 *
 * See docs/proposals/NativeDoorHardening.md.
 */

const fs = require('fs');
const net = require('net');
const path = require('path');
const {
    TO_LAUNCHER,
    FROM_LAUNCHER,
    encodeFrame,
    encodeJsonFrame,
    FrameDecoder,
    parseJsonPayload
} = require('./door-launcher-protocol');

const DEFAULT_INTERPRETERS = ['/bin/bash', '/usr/bin/bash', '/bin/sh'];

/** Short options an interpreter may be given. None of them take an argument or run inline code. */
const SAFE_INTERPRETER_OPTION = /^-[eufvxn]+$/;
const SAFE_INTERPRETER_LONG_OPTIONS = new Set(['--norc', '--noprofile', '--posix']);

/**
 * @param {string} p
 * @returns {string|null} Real path, or null if it does not exist
 */
function realpathOrNull(p) {
    try {
        return fs.realpathSync(p);
    } catch (_) {
        return null;
    }
}

/**
 * @param {string} child Real path
 * @param {string} root Real path
 * @returns {boolean} True if child is root or inside it
 */
function isInside(child, root) {
    const rel = path.relative(root, child);
    return rel === '' || (!rel.startsWith('..') && !path.isAbsolute(rel));
}

/**
 * @param {string} p Real path
 * @param {string[]} roots Real paths
 * @returns {boolean}
 */
function isInsideAny(p, roots) {
    return roots.some((root) => isInside(p, root));
}

/**
 * Find an executable on a PATH string.
 *
 * @param {string} name Bare command name
 * @param {string} searchPath
 * @returns {string|null}
 */
function findOnPath(name, searchPath) {
    for (const dir of String(searchPath || '').split(path.delimiter)) {
        if (!dir) {
            continue;
        }
        const candidate = path.join(dir, name);
        try {
            fs.accessSync(candidate, fs.constants.X_OK);
            return candidate;
        } catch (_) {
            // try the next directory
        }
    }
    return null;
}

/**
 * Decide whether the launcher may run a spawn request.
 *
 * The executable's resolved real path must be inside an allowed root, so a
 * symlink pointing outside is refused. A script run through an allowed
 * interpreter is also permitted when the script is inside an allowed root, and
 * the interpreter is not given options that take inline code. This narrows what
 * a compromised bridge can ask for; it is not a sandbox. The door user's file
 * permissions remain the real boundary.
 *
 * @param {{argv: string[], cwd: string, env: Object}} spec
 * @param {{execRoots: string[], interpreters: string[]}} policy
 * @returns {{ok: boolean, reason?: string, exe?: string, args?: string[]}}
 */
function checkExec(spec, policy) {
    const roots = policy.execRoots.map(realpathOrNull).filter(Boolean);
    if (roots.length === 0) {
        return { ok: false, reason: 'no valid exec roots configured' };
    }

    const cwdReal = realpathOrNull(spec.cwd);
    if (!cwdReal || !isInsideAny(cwdReal, roots)) {
        return { ok: false, reason: 'working directory is outside the allowed roots' };
    }

    const command = spec.argv[0];
    let resolved;
    if (path.isAbsolute(command)) {
        resolved = command;
    } else if (command.includes('/') || command.includes(path.sep)) {
        resolved = path.resolve(spec.cwd, command);
    } else {
        resolved = findOnPath(command, spec.env && spec.env.PATH);
        if (!resolved) {
            return { ok: false, reason: `command '${command}' not found on PATH` };
        }
    }

    const exe = realpathOrNull(resolved);
    if (!exe) {
        return { ok: false, reason: `executable '${resolved}' does not exist` };
    }

    const args = spec.argv.slice(1);
    if (isInsideAny(exe, roots)) {
        return { ok: true, exe, args };
    }

    const interpreters = policy.interpreters.map(realpathOrNull).filter(Boolean);
    if (!interpreters.includes(exe)) {
        return { ok: false, reason: `executable '${exe}' is outside the allowed roots` };
    }

    for (let i = 0; i < args.length; i++) {
        const arg = args[i];
        if (!arg.startsWith('-')) {
            const script = realpathOrNull(path.resolve(spec.cwd, arg));
            if (!script || !isInsideAny(script, roots)) {
                return { ok: false, reason: 'interpreter script is outside the allowed roots' };
            }
            return { ok: true, exe, args };
        }
        if (!SAFE_INTERPRETER_OPTION.test(arg) && !SAFE_INTERPRETER_LONG_OPTIONS.has(arg)) {
            return { ok: false, reason: `interpreter option '${arg}' is not permitted` };
        }
    }
    return { ok: false, reason: 'interpreter was not given a script' };
}

/**
 * @param {Object} header Untrusted spawn request
 * @returns {string|null} Error message, or null when well formed
 */
function validateSpawnHeader(header) {
    if (!Array.isArray(header.argv) || header.argv.length === 0
        || header.argv.some((a) => typeof a !== 'string' || a.includes('\0'))) {
        return 'argv must be a non-empty array of strings';
    }
    if (typeof header.cwd !== 'string' || header.cwd === '') {
        return 'cwd must be a string';
    }
    if (!header.env || typeof header.env !== 'object' || Array.isArray(header.env)
        || Object.values(header.env).some((v) => typeof v !== 'string')) {
        return 'env must be an object of strings';
    }
    if (!Number.isInteger(header.cols) || !Number.isInteger(header.rows)
        || header.cols < 1 || header.cols > 1000 || header.rows < 1 || header.rows > 1000) {
        return 'cols and rows must be sensible integers';
    }
    if (header.encoding !== 'binary' && header.encoding !== 'utf8') {
        return 'encoding must be binary or utf8';
    }
    return null;
}

/**
 * Kill a door's whole process group (node-pty makes the door a session leader).
 *
 * @param {number} pid
 */
function killGroup(pid) {
    try {
        process.kill(-pid, 'SIGKILL');
    } catch (_) {
        try {
            process.kill(pid, 'SIGKILL');
        } catch (_) {
            // already gone
        }
    }
}

/**
 * Build the launcher server.
 *
 * @param {Object} options
 * @param {string[]} options.execRoots
 * @param {string[]} options.interpreters
 * @param {Object} [options.pty]    node-pty module (injectable for tests)
 * @param {Object} [options.logger] Object with log() and error()
 * @returns {net.Server}
 */
function createLauncherServer(options) {
    const policy = { execRoots: options.execRoots, interpreters: options.interpreters };
    const logger = options.logger || console;
    const getPty = () => options.pty || require('node-pty');

    return net.createServer((socket) => {
        const decoder = new FrameDecoder();
        let child = null;
        let finished = false;

        const send = (type, payload) => {
            if (!socket.destroyed && socket.writable) {
                socket.write(Buffer.isBuffer(payload) ? encodeFrame(type, payload) : encodeJsonFrame(type, payload));
            }
        };
        const fail = (code, message) => {
            logger.error(`[Launcher] ${code}: ${message}`);
            send(FROM_LAUNCHER.ERROR, { code, message });
            socket.end();
        };

        const handleSpawn = (header) => {
            if (child) {
                return fail('already_spawned', 'a door is already running on this connection');
            }
            const problem = validateSpawnHeader(header);
            if (problem) {
                return fail('bad_request', problem);
            }
            const verdict = checkExec(header, policy);
            if (!verdict.ok) {
                return fail('exec_refused', verdict.reason);
            }

            try {
                child = getPty().spawn(verdict.exe, verdict.args, {
                    name: 'xterm-256color',
                    cols: header.cols,
                    rows: header.rows,
                    cwd: header.cwd,
                    encoding: header.encoding,
                    env: header.env
                });
            } catch (e) {
                return fail('spawn_failed', e.message);
            }

            const outEncoding = header.encoding === 'binary' ? 'latin1' : 'utf8';
            child.onData((data) => send(FROM_LAUNCHER.OUTPUT, Buffer.from(data, outEncoding)));
            child.onExit(({ exitCode, signal }) => {
                finished = true;
                send(FROM_LAUNCHER.EXIT, { code: exitCode, signal: signal || 0 });
                socket.end();
            });
            send(FROM_LAUNCHER.SPAWNED, { pid: child.pid });
        };

        const handleProbe = (request) => {
            const result = { read: {}, write: {} };
            for (const p of Array.isArray(request.read) ? request.read : []) {
                try {
                    fs.accessSync(p, fs.constants.R_OK);
                    result.read[p] = true;
                } catch (_) {
                    result.read[p] = false;
                }
            }
            for (const p of Array.isArray(request.write) ? request.write : []) {
                try {
                    fs.accessSync(p, fs.constants.W_OK);
                    result.write[p] = true;
                } catch (_) {
                    result.write[p] = false;
                }
            }
            send(FROM_LAUNCHER.PROBED, result);
        };

        const handleFrame = (frame) => {
            switch (frame.type) {
                case TO_LAUNCHER.HELLO:
                    send(FROM_LAUNCHER.READY, {
                        uid: typeof process.getuid === 'function' ? process.getuid() : -1,
                        gid: typeof process.getgid === 'function' ? process.getgid() : -1,
                        version: 1
                    });
                    break;
                case TO_LAUNCHER.PROBE:
                    handleProbe(parseJsonPayload(frame.payload) || {});
                    break;
                case TO_LAUNCHER.SPAWN: {
                    const header = parseJsonPayload(frame.payload);
                    if (!header) {
                        return fail('bad_request', 'spawn request is not a JSON object');
                    }
                    handleSpawn(header);
                    break;
                }
                case TO_LAUNCHER.DATA:
                    if (child && !finished) {
                        child.write(frame.payload.toString('utf8'));
                    }
                    break;
                case TO_LAUNCHER.RESIZE: {
                    const size = parseJsonPayload(frame.payload);
                    if (child && !finished && size && Number.isInteger(size.cols) && Number.isInteger(size.rows)
                        && size.cols > 0 && size.rows > 0 && size.cols <= 1000 && size.rows <= 1000) {
                        child.resize(size.cols, size.rows);
                    }
                    break;
                }
                case TO_LAUNCHER.KILL: {
                    const request = parseJsonPayload(frame.payload) || {};
                    if (child && !finished) {
                        if (request.force) {
                            killGroup(child.pid);
                        } else {
                            child.kill();
                        }
                    }
                    break;
                }
                default:
                    fail('bad_request', `unknown frame type '${frame.type}'`);
            }
        };

        socket.on('data', (chunk) => {
            let frames;
            try {
                frames = decoder.push(chunk);
            } catch (e) {
                return fail('bad_request', e.message);
            }
            frames.forEach(handleFrame);
        });

        // Connection gone (bridge crashed, session ended): the door must not outlive it.
        const cleanup = () => {
            if (child && !finished) {
                killGroup(child.pid);
            }
        };
        socket.on('close', cleanup);
        socket.on('error', cleanup);
    });
}

/**
 * Read the launcher's settings from its own environment.
 *
 * @param {Object} env
 * @returns {{socketPath: string, execRoots: string[], interpreters: string[]}}
 */
function readLauncherConfig(env) {
    const split = (value, fallback) => {
        const items = String(value || '').split(':').map((s) => s.trim()).filter(Boolean);
        return items.length > 0 ? items : fallback;
    };
    return {
        socketPath: env.NATIVEDOOR_LAUNCHER_SOCKET || '',
        execRoots: split(env.NATIVEDOOR_LAUNCHER_EXEC_ROOTS, [path.resolve(__dirname, '../../native-doors/doors')]),
        interpreters: split(env.NATIVEDOOR_LAUNCHER_INTERPRETERS, DEFAULT_INTERPRETERS)
    };
}

function main() {
    if (process.platform === 'win32') {
        console.error('[Launcher] The door launcher is not supported on Windows.');
        process.exit(1);
    }
    const config = readLauncherConfig(process.env);

    // Files a door creates must stay removable by the app user, which is in the door's group.
    process.umask(0o002);

    const server = createLauncherServer({ execRoots: config.execRoots, interpreters: config.interpreters });
    server.on('error', (e) => {
        console.error(`[Launcher] Server error: ${e.message}`);
        process.exit(1);
    });

    if (parseInt(process.env.LISTEN_FDS, 10) >= 1 && String(process.env.LISTEN_PID || process.pid) === String(process.pid)) {
        server.listen({ fd: 3 }, () => console.log('[Launcher] Listening on the socket-activated descriptor'));
    } else if (config.socketPath) {
        try {
            fs.unlinkSync(config.socketPath);
        } catch (_) {
            // not there
        }
        server.listen(config.socketPath, () => {
            try {
                fs.chmodSync(config.socketPath, 0o660);
            } catch (e) {
                console.error(`[Launcher] Could not set socket mode: ${e.message}`);
            }
            console.log(`[Launcher] Listening on ${config.socketPath}`);
        });
    } else {
        console.error('[Launcher] No socket given: set NATIVEDOOR_LAUNCHER_SOCKET or use socket activation.');
        process.exit(1);
    }
}

if (require.main === module) {
    main();
}

module.exports = {
    checkExec,
    validateSpawnHeader,
    createLauncherServer,
    readLauncherConfig,
    killGroup
};
