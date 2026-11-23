'use strict';

/**
 * Door environment isolation (scripts/dosbox-bridge/door-env.js).
 *
 * Run: node tests/js/door-env.test.js
 */
const assert = require('assert');
const path = require('path');
const lib = require(path.resolve(__dirname, '../../scripts/dosbox-bridge/door-env.js'));

process.env.DB_PASS = 'hunter2';
process.env.SMTP_PASSWORD = 'smtp';
process.env.ADMIN_DAEMON_SECRET = 'adm';
process.env.RANDOM_LEAK = 'leak';
process.env.http_proxy = 'http://proxy:3128';
process.env.MY_PLAIN = 'plain';
const before = JSON.stringify(process.env);

// Only allow-listed and bridge-supplied variables are present
let { env } = lib.buildDoorEnv({ DOOR_NODE: '3' }, { home: '/h', cols: 80, rows: 25 });
for (const leaked of ['DB_PASS', 'SMTP_PASSWORD', 'ADMIN_DAEMON_SECRET', 'RANDOM_LEAK', 'http_proxy']) {
    assert.strictEqual(env[leaked], undefined, leaked + ' must not leak');
}
assert.strictEqual(env.DOOR_NODE, '3');
assert.strictEqual(env.HOME, '/h');
assert.strictEqual(env.COLUMNS, '80');
assert.strictEqual(env.LINES, '25');
assert.strictEqual(env.TERM, 'xterm-256color');
assert.ok(env.PATH.length > 0);

// door.env literals: any valid name is allowed, and the value is the manifest's, never the bridge's
({ env } = lib.buildDoorEnv({}, { manifestEnv: { DB_PASS: 'door-value', FOO: 'bar', MY_TOKEN: 't', '1bad': 'x', OBJ: {} } }));
assert.strictEqual(env.DB_PASS, 'door-value');
assert.notStrictEqual(env.DB_PASS, process.env.DB_PASS);
assert.strictEqual(env.MY_TOKEN, 't');
assert.strictEqual(env.FOO, 'bar');
assert.strictEqual(env['1bad'], undefined);
assert.strictEqual(env.OBJ, undefined);

// Passthrough only when approved; secrets never
let res = lib.buildDoorEnv({}, {
    requested: ['http_proxy', 'MY_PLAIN', 'DB_PASS', 'RANDOM_LEAK'],
    approved: ['http_proxy', 'DB_PASS']
});
assert.strictEqual(res.env.http_proxy, 'http://proxy:3128');
assert.strictEqual(res.env.MY_PLAIN, undefined);
assert.strictEqual(res.env.DB_PASS, undefined);
assert.deepStrictEqual(res.dropped.unapproved.sort(), ['MY_PLAIN', 'RANDOM_LEAK']);
assert.deepStrictEqual(res.dropped.secret, ['DB_PASS']);

// Approved but not requested is not passed
res = lib.buildDoorEnv({}, { requested: [], approved: ['http_proxy'] });
assert.strictEqual(res.env.http_proxy, undefined);

// Approved but missing in bridge env: skipped quietly
res = lib.buildDoorEnv({}, { requested: ['NOT_SET_ANYWHERE'], approved: ['NOT_SET_ANYWHERE'] });
assert.strictEqual(res.env.NOT_SET_ANYWHERE, undefined);

// Launch-command substitution refuses secret names
assert.strictEqual(lib.resolveLaunchVar('DB_PASS', 'dflt'), 'dflt');
assert.strictEqual(lib.resolveLaunchVar('MY_PLAIN', 'dflt'), 'plain');
assert.strictEqual(lib.resolveLaunchVar('UNSET_X', 'dflt'), 'dflt');

// Emulator env never carries secrets
env = lib.buildEmulatorEnv({ SDL_VIDEODRIVER: 'dummy' });
assert.strictEqual(env.DB_PASS, undefined);
assert.strictEqual(env.RANDOM_LEAK, undefined);
assert.strictEqual(env.SDL_VIDEODRIVER, 'dummy');

// .env allow-list
assert.ok(lib.isBridgeEnvName('DOSDOOR_WS_PORT'));
assert.ok(lib.isBridgeEnvName('DB_HOST'));
assert.ok(!lib.isBridgeEnvName('SMTP_PASS'));
assert.ok(!lib.isBridgeEnvName('ADMIN_DAEMON_SECRET'));

// Real process environment untouched
assert.strictEqual(JSON.stringify(process.env), before);

console.log('door-env tests passed');
