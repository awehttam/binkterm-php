/**
 * WebDoor SDK - API Communication Helpers
 *
 * Provides standardized methods for making authenticated API calls to BBS endpoints.
 */

const WebDoorAPI = (function() {
    'use strict';

    /**
     * Make an authenticated API call to a BBS endpoint
     *
     * @param {string} endpoint - API endpoint path (e.g., '/api/webdoor/game/action')
     * @param {Object} options - Fetch options (method, body, headers, etc.)
     * @returns {Promise<Object>} - JSON response from the API
     *
     * @example
     * WebDoorAPI.call('/api/webdoor/mygame/score', {
     *     method: 'POST',
     *     body: JSON.stringify({ score: 100 })
     * }).then(data => {
     *     console.log('Score saved:', data);
     * });
     */
    async function call(endpoint, options = {}) {
        const defaultOptions = {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json'
            }
        };

        const fetchOptions = {
            ...defaultOptions,
            ...options,
            headers: {
                ...defaultOptions.headers,
                ...(options.headers || {})
            }
        };

        try {
            const response = await fetch(endpoint, fetchOptions);

            if (!response.ok) {
                const error = await response.json().catch(() => ({
                    error: `HTTP ${response.status}: ${response.statusText}`
                }));
                throw new Error(error.error || error.message || 'API call failed');
            }

            return await response.json();
        } catch (error) {
            console.error('WebDoor API Error:', error);
            throw error;
        }
    }

    /**
     * Make a GET request to a BBS API endpoint
     *
     * @param {string} endpoint - API endpoint path
     * @returns {Promise<Object>} - JSON response from the API
     */
    async function get(endpoint) {
        return call(endpoint, { method: 'GET' });
    }

    /**
     * Make a POST request to a BBS API endpoint
     *
     * @param {string} endpoint - API endpoint path
     * @param {Object} data - Data to send in request body
     * @returns {Promise<Object>} - JSON response from the API
     */
    async function post(endpoint, data = {}) {
        return call(endpoint, {
            method: 'POST',
            body: JSON.stringify(data)
        });
    }

    /**
     * Make a PUT request to a BBS API endpoint
     *
     * @param {string} endpoint - API endpoint path
     * @param {Object} data - Data to send in request body
     * @returns {Promise<Object>} - JSON response from the API
     */
    async function put(endpoint, data = {}) {
        return call(endpoint, {
            method: 'PUT',
            body: JSON.stringify(data)
        });
    }

    /**
     * Make a DELETE request to a BBS API endpoint
     *
     * @param {string} endpoint - API endpoint path
     * @returns {Promise<Object>} - JSON response from the API
     */
    async function del(endpoint) {
        return call(endpoint, { method: 'DELETE' });
    }

    // Public API
    return {
        call,
        get,
        post,
        put,
        delete: del
    };
})();
