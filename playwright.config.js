// @ts-check
const { defineConfig, devices } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

// Load test env file
const envFile = path.join(__dirname, 'tests', '.env.test');
if (fs.existsSync(envFile)) {
    fs.readFileSync(envFile, 'utf-8').split('\n').forEach(line => {
        const trimmed = line.trim();
        if (!trimmed || trimmed.startsWith('#')) return;
        const eq = trimmed.indexOf('=');
        if (eq === -1) return;
        const key = trimmed.slice(0, eq).trim();
        const val = trimmed.slice(eq + 1).trim();
        if (key) process.env[key] = val;
    });
}

const STORAGE_PATH = path.join(__dirname, 'tests', 'playwright', '.auth-state.json');

module.exports = defineConfig({
    testDir: './tests/playwright',
    timeout: 30000,
    retries: 0,
    workers: 1, // Sequential to avoid session conflicts (logout test would invalidate shared cookie)
    reporter: [['list'], ['html', { outputFolder: 'tests/playwright-report', open: 'never' }]],

    use: {
        baseURL: process.env.TEST_URL || 'http://localhost:1244',
        screenshot: 'only-on-failure',
        video: 'off',
    },

    projects: [
        // 1. Login once and save cookies
        {
            name: 'setup',
            testMatch: /auth\.setup\.js/,
        },
        // 2. Run all tests using saved session
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                storageState: STORAGE_PATH,
            },
            dependencies: ['setup'],
            testIgnore: /auth\.setup\.js/,
        },
    ],
});
