'use strict';

/**
 * Stale-CSRF self-heal in the global fetch() wrapper (public_html/js/app.js).
 * Runs the real wrapper from app.js in a vm with a mocked fetch/document.
 *
 * Run: node tests/js/csrf-self-heal.test.js
 */
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const src = fs.readFileSync(path.resolve(__dirname, '../../public_html/js/app.js'), 'utf8');
const start = src.indexOf('// Intercept native fetch() calls');
const end = src.indexOf('}());', start) + '}());'.length;
assert.ok(start > 0 && end > start, 'fetch wrapper not found in app.js');
const wrapperSrc = src.slice(start, end);

function harness(serverToken, opts = {}) {
    const calls = [];
    const meta = { content: 'stale-token' };
    const json = (status, body) => ({
        status, ok: status >= 200 && status < 300,
        json: async () => body, clone() { return this; },
    });
    const fetch = async (url, options = {}) => {
        const headers = options.headers || {};
        calls.push({ url, method: (options.method || 'GET').toUpperCase(), token: headers['X-CSRF-Token'] });
        if (url === '/api/auth/csrf-token') {
            return opts.resyncFails ? json(500, {}) : json(200, { success: true, csrf_token: serverToken });
        }
        if (opts.otherForbidden) return json(403, { error_code: 'errors.auth.forbidden' });
        if (headers['X-CSRF-Token'] !== serverToken) return json(403, { error_code: 'errors.auth.invalid_csrf_token' });
        return json(200, { success: true });
    };
    const ctx = {
        window: { fetch, location: { origin: 'https://bbs.example' } },
        document: { querySelector: () => meta },
        Headers: class {},
        Promise, Object,
    };
    vm.createContext(ctx);
    vm.runInContext(wrapperSrc.replace('const _fetch = window.fetch;', 'const _fetch = window.fetch;'), ctx);
    return { fetch: ctx.window.fetch, calls, meta };
}

(async () => {
    // 1. stale token: re-sync once, retry with the fresh token, update meta.
    {
        const h = harness('fresh-token');
        const resp = await h.fetch('/api/thing', { method: 'POST', headers: {} });
        assert.strictEqual(resp.status, 200);
        assert.deepStrictEqual(h.calls.map(c => `${c.method} ${c.url} ${c.token || ''}`), [
            'POST /api/thing stale-token',
            'GET /api/auth/csrf-token ',
            'POST /api/thing fresh-token',
        ]);
        assert.strictEqual(h.meta.content, 'fresh-token');
    }
    // 2. other 403s are not retried.
    {
        const h = harness('stale-token', { otherForbidden: true });
        const resp = await h.fetch('/api/thing', { method: 'POST', headers: {} });
        assert.strictEqual(resp.status, 403);
        assert.strictEqual(h.calls.length, 1);
    }
    // 3. GET and cross-origin requests are left alone.
    {
        const h = harness('fresh-token');
        await h.fetch('/api/thing', { method: 'GET' });
        await h.fetch('https://elsewhere.example/x', { method: 'POST', headers: {} });
        assert.strictEqual(h.calls.length, 2);
        assert.strictEqual(h.calls[1].token, undefined, 'no CSRF header sent cross-origin');
    }
    // 4. failed re-sync returns the original 403, no retry loop.
    {
        const h = harness('fresh-token', { resyncFails: true });
        const resp = await h.fetch('/api/thing', { method: 'POST', headers: {} });
        assert.strictEqual(resp.status, 403);
        assert.strictEqual(h.calls.length, 2);
    }
    console.log('csrf-self-heal tests passed');
})().catch(err => { console.error(err); process.exit(1); });
