/**
 * Shared test helpers.
 */
const path = require('path');
const fs = require('fs');

const CSRF_PATH = path.join(__dirname, '.csrf-token.json');

/**
 * Returns the CSRF token saved during auth setup.
 * All state-changing API requests (POST/PUT/DELETE) must include this
 * as the X-CSRF-TOKEN header.
 */
function getCsrfToken() {
    if (!fs.existsSync(CSRF_PATH)) {
        throw new Error('CSRF token file not found — run the setup project first');
    }
    const data = JSON.parse(fs.readFileSync(CSRF_PATH, 'utf-8'));
    return data.csrf_token ?? '';
}

/**
 * Returns headers required for state-changing requests.
 */
function authHeaders() {
    return {
        'X-CSRF-TOKEN': getCsrfToken(),
        'Content-Type': 'application/json',
    };
}

module.exports = { getCsrfToken, authHeaders };
