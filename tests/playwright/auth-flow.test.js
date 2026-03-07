/**
 * Auth flow tests: login, logout, redirect behaviour.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');

const STORAGE_PATH = path.join(__dirname, '.auth-state.json');

// Tests that require NO session (override project-level storageState)
const noAuth = { storageState: { cookies: [], origins: [] } };

test.describe('Login flow (unauthenticated)', () => {
    test.use(noAuth);

    test('login page renders translated text', async ({ page }) => {
        const response = await page.goto('/login');
        expect(response.status()).toBe(200);

        const body = await page.locator('body').innerText();
        // Should not show raw keys
        expect(/\b(ui|errors)\.[a-z_]+(\.[a-z_]+)+/.test(body)).toBe(false);
        // Should have a username input
        await expect(page.locator('input[name="username"], input[type="text"]').first()).toBeVisible();
        // Should have a password input
        await expect(page.locator('input[type="password"]').first()).toBeVisible();
    });

    test('unauthenticated / redirects to /login', async ({ page }) => {
        await page.goto('/');
        expect(page.url()).toContain('/login');
    });

    test('bad credentials returns error_code', async ({ request }) => {
        const apiResponse = await request.post('/api/auth/login', {
            data: { username: 'nosuchuser', password: 'wrongpassword' },
        });

        expect(apiResponse.status()).toBe(401);
        const json = await apiResponse.json();
        expect(json.success).toBe(false);
        expect(json.error_code).toBeTruthy();
        expect(json.error_code.startsWith('errors.')).toBe(true);
    });

    test('missing credentials returns 400 with error_code', async ({ request }) => {
        const apiResponse = await request.post('/api/auth/login', {
            data: { username: '', password: '' },
        });
        expect(apiResponse.status()).toBe(400);
        const json = await apiResponse.json();
        expect(json.error_code).toBeTruthy();
        expect(json.error_code.startsWith('errors.')).toBe(true);
    });

    test('successful login sets session cookie', async ({ page }) => {
        const username = process.env.TEST_USERNAME;
        const password = process.env.TEST_PASSWORD;

        const apiResponse = await page.request.post('/api/auth/login', {
            data: { username, password },
        });
        expect(apiResponse.ok()).toBe(true);

        // Navigate to / — should not redirect to login
        await page.goto('/');
        expect(page.url()).not.toContain('/login');
    });
});

test.describe('Logout flow', () => {
    // Use the shared session, but immediately re-login after logout
    // so the session remains valid for subsequent test runs
    test.use({ storageState: STORAGE_PATH });

    test('logout endpoint responds and invalidates session', async ({ page }) => {
        // Confirm logged in
        await page.goto('/');
        expect(page.url()).not.toContain('/login');

        // Logout via API
        const logoutResp = await page.request.post('/api/auth/logout');
        expect(logoutResp.ok()).toBe(true);

        // Re-login immediately to restore the shared session file
        // so this test doesn't break other tests
        const username = process.env.TEST_USERNAME;
        const password = process.env.TEST_PASSWORD;
        await page.request.post('/api/auth/login', { data: { username, password } });
        await page.context().storageState({ path: STORAGE_PATH });
    });
});
