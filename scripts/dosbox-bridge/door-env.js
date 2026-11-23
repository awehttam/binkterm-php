'use strict';

/**
 * Door environment isolation.
 *
 * Doors never receive a copy of the bridge's process.env. Their environment is
 * built from scratch out of a fixed allow-list plus bridge-supplied values, so a
 * secret added to .env (or injected by the container runtime) cannot leak to a
 * door by omission.
 *
 * See docs/proposals/NativeDoorHardening.md.
 */

const os = require('os');

const IS_WINDOWS = process.platform === 'win32';

const DEFAULT_DOOR_PATH = '/usr/local/bin:/usr/bin:/bin';
const DEFAULT_LANG = 'C.UTF-8';
const DEFAULT_TERM = 'xterm-256color';

/**
 * Names that must never reach a door through a manifest-controlled path
 * (door.env, env_passthrough, ${VAR:-default} substitution).
 */
const SECRET_NAME_PATTERNS = [
    /^DB_/i,
    /^SMTP_/i,
    /^ADMIN_DAEMON_/i,
    /SECRET/i,
    /PASS(WORD|WD)?(_|$)/i,
    /TOKEN/i,
    /(^|_)KEY$/i,
    /(^|_)KEY_/i,
    /CREDENTIAL/i,
    /^AWS_/i
];

/**
 * Names the bridge reads from .env. Anything else in .env is ignored by the
 * bridge, so it is never present in process.env to be leaked.
 */
const BRIDGE_ENV_ALLOW_PATTERNS = [
    /^DB_/,
    /^DOSDOOR_/,
    /^DOSBOX_/,
    /^DOSEMU_/,
    /^DOOR_EMULATOR$/,
    /^NATIVEDOOR_/,
    /^TELNET_BIND_HOST$/,
    /^TELNET_PORT$/,
    /^PUBTERM_/
];

/**
 * System variables emulators (DOSBox/DOSEMU) legitimately need from the bridge.
 * These are not secrets. Native doors do not receive these.
 */
const EMULATOR_PASSTHROUGH = [
    'HOME', 'USER', 'LOGNAME', 'LANG', 'TMPDIR',
    'DISPLAY', 'XAUTHORITY', 'XDG_RUNTIME_DIR', 'WAYLAND_DISPLAY',
    'SDL_VIDEODRIVER', 'SDL_AUDIODRIVER', 'ALSA_CONFIG_PATH', 'PULSE_SERVER'
];

/**
 * Windows requires a handful of system variables for any process to start.
 */
const WINDOWS_SYSTEM_VARS = [
    'PATH', 'PATHEXT', 'SystemRoot', 'SYSTEMROOT', 'windir', 'ComSpec', 'COMSPEC',
    'TEMP', 'TMP', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA', 'ProgramFiles',
    'ProgramFiles(x86)', 'ProgramData', 'SystemDrive', 'USERNAME'
];

/**
 * @param {string} name
 * @returns {boolean} True if the variable name looks like a secret.
 */
function isSecretName(name) {
    return SECRET_NAME_PATTERNS.some((re) => re.test(name));
}

/**
 * @param {string} name
 * @returns {boolean} True if the bridge is allowed to load this name from .env.
 */
function isBridgeEnvName(name) {
    return BRIDGE_ENV_ALLOW_PATTERNS.some((re) => re.test(name));
}

/**
 * Load .env into process.env, but only names the bridge uses.
 *
 * Defense in depth: the bridge's own user can read the .env file regardless, and
 * secrets injected by the container runtime are already in the real environment.
 *
 * @param {string} envPath Absolute path to .env
 * @param {boolean} override Overwrite values already set (reload path)
 * @returns {string[]} Names that were applied
 */
function loadBridgeEnv(envPath, override) {
    const fs = require('fs');
    const dotenv = require('dotenv');
    let parsed = {};
    try {
        parsed = dotenv.parse(fs.readFileSync(envPath));
    } catch (_) {
        return [];
    }
    const applied = [];
    for (const [name, value] of Object.entries(parsed)) {
        if (!isBridgeEnvName(name)) {
            continue;
        }
        if (!override && Object.prototype.hasOwnProperty.call(process.env, name)) {
            continue;
        }
        process.env[name] = value;
        applied.push(name);
    }
    return applied;
}

/**
 * @param {string} name
 * @returns {boolean} True if name is a valid environment variable name.
 */
function isValidName(name) {
    return typeof name === 'string' && /^[A-Za-z_][A-Za-z0-9_]*$/.test(name);
}

/**
 * Resolve the PATH a native door receives.
 * @returns {string}
 */
function getDoorPath() {
    if (IS_WINDOWS) {
        return process.env.PATH || process.env.Path || '';
    }
    return process.env.NATIVEDOOR_PATH || DEFAULT_DOOR_PATH;
}

/**
 * Name of the OS account the door runs as (the bridge's own user in direct mode).
 * @returns {string}
 */
function currentUserName() {
    try {
        return os.userInfo().username;
    } catch (_) {
        return '';
    }
}

/**
 * Build the environment for a native door.
 *
 * @param {Object} extra       Bridge-supplied variables (DOOR_*, adapter extras). Trusted.
 * @param {Object} [options]
 * @param {string} [options.home]            Door home directory (HOME).
 * @param {number} [options.cols]            COLUMNS
 * @param {number} [options.rows]            LINES
 * @param {Object} [options.manifestEnv]     door.env from the manifest. Untrusted; secret names dropped.
 * @param {string[]} [options.requested]     door.env_passthrough from the manifest.
 * @param {string[]} [options.approved]      Names the sysop approved for this door.
 * @param {Object} [options.logger]          Object with warn().
 * @returns {{env: Object, dropped: {secret: string[], unapproved: string[]}}}
 */
function buildDoorEnv(extra, options) {
    const opts = options || {};
    const logger = opts.logger || null;
    const env = {};
    const dropped = { secret: [], unapproved: [] };

    if (IS_WINDOWS) {
        for (const name of WINDOWS_SYSTEM_VARS) {
            if (process.env[name] !== undefined) {
                env[name] = process.env[name];
            }
        }
    }

    env.PATH = getDoorPath();
    if (opts.home) {
        env.HOME = opts.home;
    }
    const user = currentUserName();
    if (user) {
        env.USER = user;
        env.LOGNAME = user;
    }
    env.TERM = DEFAULT_TERM;
    env.LANG = DEFAULT_LANG;
    if (opts.cols) {
        env.COLUMNS = String(opts.cols);
    }
    if (opts.rows) {
        env.LINES = String(opts.rows);
    }

    // Static literals from the manifest. Untrusted: secret names are refused.
    for (const [name, value] of Object.entries(opts.manifestEnv || {})) {
        if (!isValidName(name) || value === null || typeof value === 'object') {
            continue;
        }
        if (isSecretName(name)) {
            dropped.secret.push(name);
            continue;
        }
        env[name] = String(value);
    }

    // Passthrough: requested by the manifest, honored only when approved.
    const approved = new Set(Array.isArray(opts.approved) ? opts.approved : []);
    for (const name of (Array.isArray(opts.requested) ? opts.requested : [])) {
        if (!isValidName(name)) {
            continue;
        }
        if (isSecretName(name)) {
            dropped.secret.push(name);
            continue;
        }
        if (!approved.has(name)) {
            dropped.unapproved.push(name);
            continue;
        }
        // Missing variables are skipped quietly.
        if (process.env[name] !== undefined) {
            env[name] = process.env[name];
        }
    }

    // Bridge-supplied values win over everything above.
    for (const [name, value] of Object.entries(extra || {})) {
        if (value !== undefined && value !== null) {
            env[name] = String(value);
        }
    }

    if (logger) {
        if (dropped.secret.length > 0) {
            logger.warn(`[DoorEnv] Refused secret-named variables from manifest: ${dropped.secret.join(', ')}`);
        }
        if (dropped.unapproved.length > 0) {
            logger.warn(`[DoorEnv] Manifest requests variables not approved by the sysop (dropped): ${dropped.unapproved.join(', ')}`);
        }
    }

    return { env, dropped };
}

/**
 * Build the environment for an emulator (DOSBox/DOSEMU). Emulators are launched
 * by the bridge and need a few system variables (HOME, display), but never the
 * bridge's full environment.
 *
 * @param {Object} [extra] Bridge-supplied variables (for example SDL_VIDEODRIVER).
 * @returns {Object}
 */
function buildEmulatorEnv(extra) {
    const env = {};
    if (IS_WINDOWS) {
        for (const name of WINDOWS_SYSTEM_VARS) {
            if (process.env[name] !== undefined) {
                env[name] = process.env[name];
            }
        }
    } else {
        env.PATH = getDoorPath();
        for (const name of EMULATOR_PASSTHROUGH) {
            if (process.env[name] !== undefined) {
                env[name] = process.env[name];
            }
        }
        if (!env.LANG) {
            env.LANG = DEFAULT_LANG;
        }
    }
    for (const [name, value] of Object.entries(extra || {})) {
        if (value !== undefined && value !== null) {
            env[name] = String(value);
        }
    }
    return env;
}

/**
 * Resolve a ${VAR:-default} launch-command reference against the bridge's
 * environment. Secret-named variables are never substituted; the default is
 * used instead.
 *
 * @param {string} name
 * @param {string} defaultVal
 * @returns {string}
 */
function resolveLaunchVar(name, defaultVal) {
    if (isSecretName(name)) {
        return defaultVal;
    }
    return process.env[name] || defaultVal;
}

module.exports = {
    DEFAULT_DOOR_PATH,
    isSecretName,
    isBridgeEnvName,
    loadBridgeEnv,
    buildDoorEnv,
    buildEmulatorEnv,
    resolveLaunchVar
};
