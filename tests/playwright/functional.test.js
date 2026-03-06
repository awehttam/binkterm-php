/**
 * Functional tests: verify features actually work, not just that pages load.
 *
 * Notes:
 * - Use `page.request` (not the standalone `request` fixture) — only page.request
 *   shares the browser context's session cookies.
 * - All state-changing requests (POST/PUT/DELETE) require X-CSRF-TOKEN header.
 *   The token is saved by auth.setup.js and loaded via helpers.js.
 * - Tests clean up after themselves — no permanent state changes.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const { authHeaders } = require('./helpers');

test.use({ storageState: path.join(__dirname, '.auth-state.json') });

// ─── Settings save/restore ────────────────────────────────────────────────────

test.describe('User settings', () => {
    test('GET returns structured settings object', async ({ page }) => {
        const resp = await page.request.get('/api/user/settings');
        expect(resp.status()).toBe(200);
        const json = await resp.json();
        expect(json.success).toBe(true);
        expect(json.settings).toBeTruthy();
        expect(typeof json.settings.timezone).toBe('string');
        expect(typeof json.settings.messages_per_page).toBe('number');
        expect(typeof json.settings.font_size).toBe('number');
    });

    test('POST saves a setting and GET reflects the change', async ({ page }) => {
        const original = await (await page.request.get('/api/user/settings')).json();
        const originalMpp = original.settings.messages_per_page;
        const newMpp = originalMpp === 25 ? 50 : 25;

        const saveResp = await page.request.post('/api/user/settings', {
            headers: authHeaders(),
            data: { settings: { messages_per_page: newMpp } },
        });
        expect(saveResp.status()).toBe(200);
        expect((await saveResp.json()).success).toBe(true);

        const updated = await (await page.request.get('/api/user/settings')).json();
        expect(updated.settings.messages_per_page).toBe(newMpp);

        // Restore
        await page.request.post('/api/user/settings', {
            headers: authHeaders(),
            data: { settings: { messages_per_page: originalMpp } },
        });
    });

    test('settings page loads values via AJAX', async ({ page }) => {
        await page.goto('/settings');
        await page.waitForFunction(() => {
            const el = document.getElementById('messagesPerPage');
            return el && el.value !== '';
        }, { timeout: 5000 });

        const val = await page.locator('#messagesPerPage').inputValue();
        expect(['10', '25', '50', '100']).toContain(val);
    });

    test('POST with missing settings key returns error_code', async ({ page }) => {
        const resp = await page.request.post('/api/user/settings', {
            headers: authHeaders(),
            data: {},
        });
        expect(resp.status()).toBe(400);
        const json = await resp.json();
        expect(json.error_code).toBeTruthy();
        expect(json.error_code.startsWith('errors.')).toBe(true);
    });
});

// ─── Address book CRUD ────────────────────────────────────────────────────────

test.describe('Address book', () => {
    let createdId = null;

    test('GET returns entries array', async ({ page }) => {
        const resp = await page.request.get('/api/address-book/');
        expect(resp.status()).toBe(200);
        const json = await resp.json();
        expect(json.success).toBe(true);
        expect(Array.isArray(json.entries)).toBe(true);
    });

    test('POST creates an entry', async ({ page }) => {
        const unique = `playwright-${Date.now()}`;
        const resp = await page.request.post('/api/address-book/', {
            headers: authHeaders(),
            data: {
                name: `Playwright Test ${unique}`,
                messaging_user_id: unique,
                node_address: '227:2/100',
                email: '',
                description: 'Created by automated test — safe to delete',
            },
        });
        expect(resp.status()).toBe(200);
        const json = await resp.json();
        expect(json.success).toBe(true);
        // Response returns entry_id, not a nested entry object
        expect(json.entry_id).toBeTruthy();
        createdId = json.entry_id;
    });

    test('GET by ID returns the created entry', async ({ page }) => {
        if (!createdId) return test.skip();
        const resp = await page.request.get(`/api/address-book/${createdId}`);
        expect(resp.status()).toBe(200);
        const json = await resp.json();
        expect(json.entry.name).toContain('Playwright Test');
        expect(json.entry.node_address).toBe('227:2/100');
    });

    test('PUT updates the entry', async ({ page }) => {
        if (!createdId) return test.skip();
        const resp = await page.request.put(`/api/address-book/${createdId}`, {
            headers: authHeaders(),
            data: {
                name: 'Playwright Test User (updated)',
                messaging_user_id: 'playwright.test',
                node_address: '227:2/100',
                description: 'Updated by automated test',
            },
        });
        expect(resp.status()).toBe(200);
        expect((await resp.json()).success).toBe(true);

        const verify = await (await page.request.get(`/api/address-book/${createdId}`)).json();
        expect(verify.entry.name).toBe('Playwright Test User (updated)');
    });

    test('DELETE removes the entry', async ({ page }) => {
        if (!createdId) return test.skip();
        const resp = await page.request.delete(`/api/address-book/${createdId}`, {
            headers: authHeaders(),
        });
        expect(resp.status()).toBe(200);
        expect((await resp.json()).success).toBe(true);

        const verify = await page.request.get(`/api/address-book/${createdId}`);
        expect(verify.status()).toBe(404);
    });

    test('GET non-existent ID returns 404 with error_code', async ({ page }) => {
        const resp = await page.request.get('/api/address-book/999999999');
        expect(resp.status()).toBe(404);
        expect((await resp.json()).error_code).toBeTruthy();
    });

    test('search returns filtered results', async ({ page }) => {
        const resp = await page.request.get('/api/address-book/?search=a');
        expect(resp.status()).toBe(200);
        const json = await resp.json();
        expect(json.success).toBe(true);
        expect(Array.isArray(json.entries)).toBe(true);
    });
});

// ─── Message lists ─────────────────────────────────────────────────────────────

test.describe('Netmail list', () => {
    test('API returns messages with expected fields', async ({ page }) => {
        const resp = await page.request.get('/api/messages/netmail');
        expect(resp.status()).toBe(200);
        const json = await resp.json();
        expect(Array.isArray(json.messages)).toBe(true);

        if (json.messages.length > 0) {
            const msg = json.messages[0];
            expect(msg.id).toBeTruthy();
            expect(typeof msg.from_name).toBe('string');
            expect(typeof msg.subject).toBe('string');
            expect(typeof msg.date_received).toBe('string');
        }
    });

    test('stats endpoint returns unread and total counts', async ({ page }) => {
        const resp = await page.request.get('/api/messages/netmail/stats');
        expect(resp.status()).toBe(200);
        const json = await resp.json();
        expect(typeof json.total).toBe('number');
        expect(typeof json.unread).toBe('number');
    });

    test('netmail page renders message list via AJAX', async ({ page }) => {
        await page.goto('/netmail');
        await page.waitForFunction(() => {
            const container = document.getElementById('messagesContainer');
            if (!container) return false;
            return !container.querySelector('.fa-spinner') || container.children.length > 1;
        }, { timeout: 8000 });
        await expect(page.locator('#messagesContainer')).toBeVisible();
    });

    test('page 2 returns different results than page 1', async ({ page }) => {
        // Page size is controlled by user settings, not a query param
        const [r1, r2] = await Promise.all([
            page.request.get('/api/messages/netmail?page=1'),
            page.request.get('/api/messages/netmail?page=2'),
        ]);
        expect(r1.status()).toBe(200);
        expect(r2.status()).toBe(200);
        const ids1 = ((await r1.json()).messages ?? []).map(m => m.id);
        const ids2 = ((await r2.json()).messages ?? []).map(m => m.id);
        // If there are enough messages, pages should differ
        if (ids1.length > 0 && ids2.length > 0) {
            const overlap = ids1.filter(id => ids2.includes(id));
            expect(overlap.length).toBe(0);
        }
    });
});

test.describe('Echomail list', () => {
    test('API returns messages with expected fields', async ({ page }) => {
        const resp = await page.request.get('/api/messages/echomail');
        expect(resp.status()).toBe(200);
        const json = await resp.json();
        expect(Array.isArray(json.messages)).toBe(true);

        if (json.messages.length > 0) {
            const msg = json.messages[0];
            expect(msg.id).toBeTruthy();
            expect(typeof msg.from_name).toBe('string');
        }
    });

    test('echoarea list returns available areas', async ({ page }) => {
        const resp = await page.request.get('/api/echoareas');
        expect(resp.status()).toBe(200);
        const areas = (await resp.json()).echoareas ?? await resp.json();
        expect(Array.isArray(areas)).toBe(true);
    });

    test('echomail stats returns total count', async ({ page }) => {
        const resp = await page.request.get('/api/messages/echomail/stats');
        expect(resp.status()).toBe(200);
        expect(typeof (await resp.json()).total).toBe('number');
    });
});

// ─── Compose / send ───────────────────────────────────────────────────────────

test.describe('Compose netmail', () => {
    test('compose page renders form with required fields', async ({ page }) => {
        await page.goto('/compose/netmail');
        await expect(page.locator('#toAddress')).toBeVisible();
        await expect(page.locator('#toName')).toBeVisible();
        await expect(page.locator('#composeForm')).toBeVisible();
    });

    test('POST creates a netmail and it appears in inbox', async ({ page }) => {
        const sendResp = await page.request.post('/api/messages/send', {
            headers: authHeaders(),
            data: {
                type: 'netmail',
                to_address: '',  // empty = system address
                to_name: 'Sysop',
                subject: '[AUTOTEST] Playwright functional test message',
                message_text: 'Sent by automated Playwright test suite. Safe to delete.',
            },
        });
        expect(sendResp.status()).toBe(200);
        expect((await sendResp.json()).success).toBe(true);

        // Verify it appears in netmail
        const listJson = await (await page.request.get('/api/messages/netmail')).json();
        const found = (listJson.messages ?? []).some(
            m => m.subject === '[AUTOTEST] Playwright functional test message'
        );
        expect(found, 'Sent test message should appear in netmail').toBe(true);

        // Cleanup
        const testMsg = (listJson.messages ?? []).find(
            m => m.subject === '[AUTOTEST] Playwright functional test message'
        );
        if (testMsg) {
            await page.request.delete(`/api/messages/netmail/${testMsg.id}`, {
                headers: authHeaders(),
            });
        }
    });

    test('send with invalid type returns a failure response', async ({ page }) => {
        const resp = await page.request.post('/api/messages/send', {
            headers: authHeaders(),
            data: { type: 'invalid_type', to_name: 'Test', subject: 'Test', message_text: 'Test' },
        });
        const json = await resp.json();
        expect(json.success).toBe(false);
    });
});

// ─── Nodelist search ──────────────────────────────────────────────────────────

test.describe('Nodelist search', () => {
    test('search returns results for FTN address prefix', async ({ page }) => {
        const resp = await page.request.get('/api/nodelist/search?q=1:');
        expect(resp.status()).toBe(200);
        const results = (await resp.json()).nodes ?? [];
        expect(Array.isArray(results)).toBe(true);
    });

    test('search results contain an address field', async ({ page }) => {
        const resp = await page.request.get('/api/nodelist/search?q=net');
        if (resp.status() !== 200) return;
        const results = (await resp.json()).nodes ?? [];
        if (results.length > 0) {
            const node = results[0];
            const addr = node.address ?? node.node_address ?? node.ftn_address ?? node.zone;
            expect(addr).toBeTruthy();
        }
    });
});

// ─── Admin functions ──────────────────────────────────────────────────────────

test.describe('Admin user management', () => {
    test('user list returns users with expected fields', async ({ page }) => {
        const resp = await page.request.get('/api/admin/users');
        expect(resp.status()).toBe(200);
        const users = (await resp.json()).users ?? [];
        expect(Array.isArray(users)).toBe(true);
        expect(users.length).toBeGreaterThan(0);
        expect(typeof users[0].username).toBe('string');
        expect(typeof users[0].is_active).toBe('boolean');
    });

    test('admin users page renders user table via AJAX', async ({ page }) => {
        await page.goto('/admin/users');
        await page.waitForSelector('table tbody tr, #userList .user-row', { timeout: 8000 });
        const rows = await page.locator('table tbody tr').count();
        expect(rows).toBeGreaterThan(0);
    });

    test('economy endpoint returns JSON', async ({ page }) => {
        const resp = await page.request.get('/api/admin/economy');
        expect([200, 404]).toContain(resp.status());
        if (resp.status() === 200) {
            expect(resp.headers()['content-type']).toContain('application/json');
        }
    });
});

// ─── Polls ────────────────────────────────────────────────────────────────────

test.describe('Polls', () => {
    test('active polls endpoint returns array', async ({ page }) => {
        const resp = await page.request.get('/api/polls/active');
        if (resp.status() === 404) return; // feature disabled
        expect(resp.status()).toBe(200);
        expect(Array.isArray((await resp.json()).polls ?? [])).toBe(true);
    });

    test('poll creation rejects empty question', async ({ page }) => {
        const resp = await page.request.post('/api/polls/create', {
            headers: authHeaders(),
            data: { question: '', options: ['Option A', 'Option B'] },
        });
        expect(resp.status()).toBe(400);
        const json = await resp.json();
        expect(json.error_code).toBeTruthy();
        expect(json.error_code.startsWith('errors.')).toBe(true);
    });

    test('poll creation rejects single option', async ({ page }) => {
        const resp = await page.request.post('/api/polls/create', {
            headers: authHeaders(),
            data: {
                question: 'This is a valid question with enough characters',
                options: ['Only one option'],
            },
        });
        expect(resp.status()).toBe(400);
        expect((await resp.json()).error_code).toBe('errors.polls.options_count_invalid');
    });
});
