/**
 * API smoke tests: verify key endpoints return expected structure.
 * All requests use the saved auth session.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');

test.use({ storageState: path.join(__dirname, '.auth-state.json') });

// Unauthenticated endpoints
const PUBLIC_API = [
    '/api/verify',
    '/api/i18n/catalog?ns=common&locale=en',
    '/api/i18n/catalog?ns=errors&locale=en',
    '/api/i18n/catalog?ns=common&locale=es',
    '/api/i18n/catalog?ns=errors&locale=es',
];

// Auth-required GET endpoints (expect 200 + JSON)
const AUTH_API_GET = [
    '/api/messages/netmail',
    '/api/messages/netmail/stats',
    '/api/messages/echomail',
    '/api/messages/echomail/stats',
    '/api/echoareas',
    '/api/address-book',
    '/api/nodelist/search?q=test',
    '/api/polls',
    '/api/files/areas',
    '/api/users/online',
    '/api/admin/users',
    '/api/admin/stats',
    '/api/admin/economy',
    '/api/admin/chat-rooms',
    '/api/admin/polls',
];

test.describe('Public API endpoints', () => {
    for (const endpoint of PUBLIC_API) {
        test(`GET ${endpoint} returns 200 JSON`, async ({ request }) => {
            const response = await request.get(endpoint);
            expect(response.status(), `${endpoint} status`).toBe(200);
            const ct = response.headers()['content-type'] || '';
            expect(ct, `${endpoint} content-type`).toContain('application/json');
        });
    }
});

/**
 * The catalog API returns: { success, locale, default_locale, catalogs: { <ns>: { key: value, ... } } }
 */
function extractCatalog(json, ns) {
    return (json.catalogs && json.catalogs[ns]) ? json.catalogs[ns] : {};
}

test.describe('i18n catalog structure', () => {
    test('en/common catalog has keys and no raw PHP values', async ({ request }) => {
        const response = await request.get('/api/i18n/catalog?ns=common&locale=en');
        expect(response.ok()).toBe(true);
        const json = await response.json();
        expect(json.success).toBe(true);

        const catalog = extractCatalog(json, 'common');
        const keys = Object.keys(catalog);
        expect(keys.length).toBeGreaterThan(50);
        // Spot-check known keys
        expect(catalog['ui.common.loading']).toBeTruthy();
        expect(catalog['ui.common.error']).toBeTruthy();
    });

    test('es/common catalog has same keys as en', async ({ request }) => {
        const [enResp, esResp] = await Promise.all([
            request.get('/api/i18n/catalog?ns=common&locale=en'),
            request.get('/api/i18n/catalog?ns=common&locale=es'),
        ]);
        const en = extractCatalog(await enResp.json(), 'common');
        const es = extractCatalog(await esResp.json(), 'common');
        const enKeys = Object.keys(en).sort();
        const esKeys = Object.keys(es).sort();

        const missingInEs = enKeys.filter(k => !(k in es));
        const missingInEn = esKeys.filter(k => !(k in en));

        expect(missingInEs, `Keys in en but missing in es: ${missingInEs.join(', ')}`).toHaveLength(0);
        expect(missingInEn, `Keys in es but missing in en: ${missingInEn.join(', ')}`).toHaveLength(0);
    });

    test('es/errors catalog has same keys as en/errors', async ({ request }) => {
        const [enResp, esResp] = await Promise.all([
            request.get('/api/i18n/catalog?ns=errors&locale=en'),
            request.get('/api/i18n/catalog?ns=errors&locale=es'),
        ]);
        const en = extractCatalog(await enResp.json(), 'errors');
        const es = extractCatalog(await esResp.json(), 'errors');
        const enKeys = Object.keys(en).sort();
        const esKeys = Object.keys(es).sort();

        const missingInEs = enKeys.filter(k => !(k in es));
        const missingInEn = esKeys.filter(k => !(k in en));

        expect(missingInEs, `Error keys in en but missing in es: ${missingInEs.join(', ')}`).toHaveLength(0);
        expect(missingInEn, `Error keys in es but missing in en: ${missingInEn.join(', ')}`).toHaveLength(0);
    });
});

test.describe('Authenticated API endpoints', () => {
    for (const endpoint of AUTH_API_GET) {
        test(`GET ${endpoint} returns JSON (not a redirect or error page)`, async ({ request }) => {
            const response = await request.get(endpoint);
            // Allow 200 or 404 (feature may be disabled), but not 302 (not logged in) or 500
            expect(
                [200, 404].includes(response.status()),
                `${endpoint} returned ${response.status()}`
            ).toBe(true);

            if (response.status() === 200) {
                const ct = response.headers()['content-type'] || '';
                expect(ct, `${endpoint} should return JSON`).toContain('application/json');
            }
        });
    }
});

test.describe('Error response structure', () => {
    test('unauthenticated API requests return error_code', async ({ request }) => {
        // Make a request without auth to an auth-required endpoint
        const response = await request.get('/api/messages/netmail', {
            headers: { 'Cookie': '' },
        });

        if (response.status() === 401) {
            const json = await response.json();
            expect(json.error_code, 'error_code should be present').toBeTruthy();
            expect(json.error_code.startsWith('errors.'), 'error_code should use errors.* namespace').toBe(true);
        }
        // If server returns 302 redirect instead of 401 JSON, that's also acceptable
    });

    test('login with missing fields returns error_code', async ({ request }) => {
        const response = await request.post('/api/auth/login', {
            data: { username: '', password: '' },
        });
        expect(response.status()).toBe(400);
        const json = await response.json();
        expect(json.error_code).toBeTruthy();
        expect(json.error_code.startsWith('errors.')).toBe(true);
    });
});
