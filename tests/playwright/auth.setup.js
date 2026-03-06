/**
 * Auth setup: logs in once and saves the session cookie for all tests.
 * Runs as the 'setup' project before any other tests.
 */
const { test: setup } = require('@playwright/test');
const path = require('path');

const STORAGE_PATH = path.join(__dirname, '.auth-state.json');

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

    await page.context().storageState({ path: STORAGE_PATH });
    console.log(`Auth session saved to ${STORAGE_PATH}`);
});
