/**
 * Auth setup: logs in once, saves session cookies and CSRF token for all tests.
 * Runs as the 'setup' project before any other tests.
 */
const { test: setup } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const STORAGE_PATH = path.join(__dirname, '.auth-state.json');
const CSRF_PATH    = path.join(__dirname, '.csrf-token.json');

setup('authenticate', async ({ page, baseURL }) => {
    const username = process.env.TEST_USERNAME;
    const password = process.env.TEST_PASSWORD;

    if (!username || !password) {
        throw new Error('TEST_USERNAME and TEST_PASSWORD must be set in tests/.env.test');
    }

    const response = await page.request.post(`${baseURL}/api/auth/login`, {
        data: { username, password },
    });

    if (!response.ok()) {
        throw new Error(`Login failed: ${response.status()} ${await response.text()}`);
    }

    const json = await response.json();
    const csrfToken = json.csrf_token ?? '';

    // Save cookies so other tests can reuse the session
    await page.context().storageState({ path: STORAGE_PATH });

    // Save CSRF token separately — required for POST/PUT/DELETE requests
    fs.writeFileSync(CSRF_PATH, JSON.stringify({ csrf_token: csrfToken }));

    console.log(`Auth session saved. CSRF token: ${csrfToken ? csrfToken.slice(0, 8) + '...' : '(none)'}`);
});
