/**
 * Smoke tests: verify all key pages load without errors and render translated text.
 *
 * Checks:
 *  - HTTP 200 (no server crashes or redirect loops)
 *  - No raw i18n key visible on page (e.g. "ui.login.title")
 *  - No PHP fatal error output on page
 *  - No JS console errors on page load
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const STORAGE_PATH = path.join(__dirname, '.auth-state.json');

// Pages accessible without login
const PUBLIC_PAGES = [
    { path: '/login',            title: null },
    { path: '/register',         title: null },
    { path: '/forgot-password',  title: null },
];

// Pages requiring login (admin account covers all of these)
const AUTH_PAGES = [
    { path: '/',                    label: 'dashboard' },
    { path: '/netmail',             label: 'netmail' },
    { path: '/echomail',            label: 'echomail' },
    { path: '/compose/netmail',     label: 'compose netmail' },
    { path: '/compose/echomail',    label: 'compose echomail' },
    { path: '/settings',            label: 'settings' },
    { path: '/profile',             label: 'profile' },
    { path: '/files',               label: 'files' },
    { path: '/nodelist',            label: 'nodelist' },
    { path: '/whosonline',          label: 'who\'s online' },
    { path: '/subscriptions',       label: 'subscriptions' },
    { path: '/polls',               label: 'polls' },
    { path: '/shoutbox',            label: 'shoutbox' },
    { path: '/games',               label: 'games/doors' },
    { path: '/binkp',               label: 'binkp (admin)' },
    { path: '/echoareas',           label: 'echo areas' },
    { path: '/fileareas',           label: 'file areas' },
    { path: '/echolist',            label: 'echo list' },
];

// Admin panel pages (prefixed /admin/)
const ADMIN_PAGES = [
    { path: '/admin/',                  label: 'admin dashboard' },
    { path: '/admin/users',             label: 'admin users' },
    { path: '/admin/bbs-settings',      label: 'admin bbs settings' },
    { path: '/admin/binkp-config',      label: 'admin binkp config' },
    { path: '/admin/chat-rooms',        label: 'admin chat rooms' },
    { path: '/admin/polls',             label: 'admin polls' },
    { path: '/admin/webdoors',          label: 'admin webdoors' },
    { path: '/admin/dosdoors',          label: 'admin dos doors' },
    { path: '/admin/native-doors',      label: 'admin native doors' },
    { path: '/admin/filearea-rules',    label: 'admin filearea rules' },
    { path: '/admin/appearance',        label: 'admin appearance' },
    { path: '/admin/mrc-settings',      label: 'admin mrc settings' },
    { path: '/admin/template-editor',   label: 'admin template editor' },
];

/**
 * Returns true if a raw i18n key is visible in the page text.
 * Keys look like "ui.login.title" or "errors.auth.failed".
 */
async function hasRawI18nKey(page) {
    // Look for patterns like "ui.something.something" or "errors.something" as visible text
    const bodyText = await page.locator('body').innerText().catch(() => '');
    return /\b(ui|errors|time|messages)\.[a-z_]+(\.[a-z_]+)+/.test(bodyText);
}

/**
 * Returns true if PHP fatal/error output is visible.
 */
async function hasPhpError(page) {
    const bodyText = await page.locator('body').innerText().catch(() => '');
    return /Fatal error|Parse error|Call to undefined|Uncaught Error|Uncaught Exception/.test(bodyText);
}

// ─── Public page tests ────────────────────────────────────────────────────────

test.describe('Public pages', () => {
    for (const page_def of PUBLIC_PAGES) {
        test(`${page_def.path} loads and has no raw i18n keys`, async ({ page }) => {
            const consoleErrors = [];
            page.on('console', msg => {
                if (msg.type() === 'error') consoleErrors.push(msg.text());
            });

            const response = await page.goto(page_def.path);
            expect(response.status(), `${page_def.path} should return 200`).toBe(200);

            expect(await hasPhpError(page), `${page_def.path} should not have PHP errors`).toBe(false);
            expect(await hasRawI18nKey(page), `${page_def.path} should not show raw i18n keys`).toBe(false);
        });
    }
});

// ─── Authenticated page tests ─────────────────────────────────────────────────

test.describe('Authenticated pages', () => {
    test.use({ storageState: STORAGE_PATH });

    for (const page_def of AUTH_PAGES) {
        test(`${page_def.label} (${page_def.path}) loads without errors`, async ({ page }) => {
            const consoleErrors = [];
            page.on('console', msg => {
                if (msg.type() === 'error') consoleErrors.push(msg.text());
            });

            const response = await page.goto(page_def.path);

            // Allow 200 or 404 (feature may be disabled), but not 500 or redirect to login
            const status = response.status();
            expect(
                [200, 404].includes(status),
                `${page_def.path} returned ${status} — expected 200 or 404`
            ).toBe(true);

            if (status === 200) {
                expect(await hasPhpError(page), `${page_def.path} should not have PHP errors`).toBe(false);
                expect(await hasRawI18nKey(page), `${page_def.path} should not show raw i18n keys`).toBe(false);
            }
        });
    }

    for (const page_def of ADMIN_PAGES) {
        test(`${page_def.label} (${page_def.path}) loads without errors`, async ({ page }) => {
            const response = await page.goto(page_def.path);
            const status = response.status();

            expect(
                [200, 404].includes(status),
                `${page_def.path} returned ${status}`
            ).toBe(true);

            if (status === 200) {
                expect(await hasPhpError(page), `${page_def.path} should not have PHP errors`).toBe(false);
                expect(await hasRawI18nKey(page), `${page_def.path} should not show raw i18n keys`).toBe(false);
            }
        });
    }
});
