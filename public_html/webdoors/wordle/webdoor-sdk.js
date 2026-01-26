/**
 * WebDoor SDK - Client-side library for WebDoor games
 * Version 1.0.0
 */
class WebDoor {
    constructor(options = {}) {
        this.apiBase = options.apiBase || '/api/webdoor';
        this.session = null;
        this.gameId = options.gameId || null;
        this.eventHandlers = {};
    }

    /**
     * Build URL with game_id query parameter
     */
    _buildUrl(path, extraParams = {}) {
        const url = new URL(this.apiBase + path, window.location.origin);
        if (this.gameId) {
            url.searchParams.set('game_id', this.gameId);
        }
        for (const [key, value] of Object.entries(extraParams)) {
            url.searchParams.set(key, value);
        }
        return url.toString();
    }

    /**
     * Initialize the WebDoor session
     * @returns {Promise<Object>} Session data including user info
     */
    async init() {
        try {
            const response = await fetch(this._buildUrl('/session'), {
                credentials: 'include',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`Session init failed: ${response.status}`);
            }

            this.session = await response.json();
            return this.session;
        } catch (error) {
            console.error('WebDoor init error:', error);
            // Return a default session for development/testing
            this.session = {
                session_id: 'dev-session',
                user: {
                    display_name: 'Guest',
                    user_id_hash: 'guest'
                },
                host: {
                    name: 'Development',
                    version: '1.0.0',
                    features: ['storage', 'leaderboard']
                },
                game: {
                    id: this.gameId || 'unknown',
                    name: 'Game'
                }
            };
            return this.session;
        }
    }

    /**
     * List all save slots for the current game
     * @returns {Promise<Object>} Save slots data
     */
    async listSaves() {
        try {
            const response = await fetch(this._buildUrl('/storage'), {
                credentials: 'include',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`List saves failed: ${response.status}`);
            }

            return await response.json();
        } catch (error) {
            console.error('WebDoor listSaves error:', error);
            // Return from localStorage as fallback
            return this._getLocalStorage();
        }
    }

    /**
     * Load a specific save slot
     * @param {number} slot - Slot number (0 = auto-save)
     * @returns {Promise<Object>} Save data
     */
    async load(slot = 0) {
        try {
            const response = await fetch(this._buildUrl(`/storage/${slot}`), {
                credentials: 'include',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (response.status === 404) {
                return null;
            }

            if (!response.ok) {
                throw new Error(`Load failed: ${response.status}`);
            }

            return await response.json();
        } catch (error) {
            console.error('WebDoor load error:', error);
            // Fallback to localStorage
            return this._loadLocal(slot);
        }
    }

    /**
     * Save game data to a slot
     * @param {number} slot - Slot number (0 = auto-save)
     * @param {Object} data - Game data to save
     * @param {Object} metadata - Optional metadata
     * @returns {Promise<Object>} Save confirmation
     */
    async save(slot, data, metadata = {}) {
        try {
            const response = await fetch(this._buildUrl(`/storage/${slot}`), {
                method: 'PUT',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ data, metadata })
            });

            if (!response.ok) {
                throw new Error(`Save failed: ${response.status}`);
            }

            return await response.json();
        } catch (error) {
            console.error('WebDoor save error:', error);
            // Fallback to localStorage
            return this._saveLocal(slot, data, metadata);
        }
    }

    /**
     * Delete a save slot
     * @param {number} slot - Slot number
     * @returns {Promise<boolean>} Success
     */
    async deleteSave(slot) {
        try {
            const response = await fetch(this._buildUrl(`/storage/${slot}`), {
                method: 'DELETE',
                credentials: 'include'
            });

            return response.ok;
        } catch (error) {
            console.error('WebDoor deleteSave error:', error);
            this._deleteLocal(slot);
            return true;
        }
    }

    /**
     * Get leaderboard entries
     * @param {string} board - Leaderboard name
     * @param {Object} options - Query options (limit, scope)
     * @returns {Promise<Object>} Leaderboard data
     */
    async getLeaderboard(board, options = {}) {
        try {
            const extraParams = {};
            if (options.limit) extraParams.limit = options.limit;
            if (options.scope) extraParams.scope = options.scope;

            const response = await fetch(this._buildUrl(`/leaderboard/${board}`, extraParams), {
                credentials: 'include',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`Get leaderboard failed: ${response.status}`);
            }

            return await response.json();
        } catch (error) {
            console.error('WebDoor getLeaderboard error:', error);
            // Return empty leaderboard
            return {
                board: board,
                entries: [],
                user_entry: null,
                total_entries: 0
            };
        }
    }

    /**
     * Submit a score to the leaderboard
     * @param {string} board - Leaderboard name
     * @param {number} score - Score value
     * @param {Object} metadata - Optional metadata
     * @returns {Promise<Object>} Submission result
     */
    async submitScore(board, score, metadata = {}) {
        try {
            const response = await fetch(this._buildUrl(`/leaderboard/${board}`), {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ score, metadata })
            });

            if (!response.ok) {
                throw new Error(`Submit score failed: ${response.status}`);
            }

            return await response.json();
        } catch (error) {
            console.error('WebDoor submitScore error:', error);
            return {
                accepted: false,
                error: error.message
            };
        }
    }

    /**
     * End the current session
     * @param {number} playtimeSeconds - Total playtime
     * @returns {Promise<boolean>} Success
     */
    async endSession(playtimeSeconds = 0) {
        try {
            const response = await fetch(this._buildUrl('/session/end'), {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ playtime_seconds: playtimeSeconds })
            });

            return response.ok;
        } catch (error) {
            console.error('WebDoor endSession error:', error);
            return false;
        }
    }

    // Event handling
    on(event, callback) {
        if (!this.eventHandlers[event]) {
            this.eventHandlers[event] = [];
        }
        this.eventHandlers[event].push(callback);
    }

    off(event, callback) {
        if (this.eventHandlers[event]) {
            this.eventHandlers[event] = this.eventHandlers[event].filter(cb => cb !== callback);
        }
    }

    emit(event, data) {
        if (this.eventHandlers[event]) {
            this.eventHandlers[event].forEach(cb => cb(data));
        }
    }

    // LocalStorage fallback methods
    _getStorageKey(slot) {
        const gameId = this.gameId || this.session?.game?.id || 'unknown';
        return `webdoor_${gameId}_slot_${slot}`;
    }

    _getLocalStorage() {
        const gameId = this.gameId || this.session?.game?.id || 'unknown';
        const slots = [];
        for (let i = 0; i < 5; i++) {
            const key = `webdoor_${gameId}_slot_${i}`;
            const data = localStorage.getItem(key);
            if (data) {
                const parsed = JSON.parse(data);
                slots.push({
                    slot: i,
                    metadata: parsed.metadata || {},
                    saved_at: parsed.saved_at
                });
            }
        }
        return { slots, max_slots: 5 };
    }

    _loadLocal(slot) {
        const key = this._getStorageKey(slot);
        const data = localStorage.getItem(key);
        if (data) {
            return JSON.parse(data);
        }
        return null;
    }

    _saveLocal(slot, data, metadata) {
        const key = this._getStorageKey(slot);
        const saveData = {
            slot,
            data,
            metadata,
            saved_at: new Date().toISOString()
        };
        localStorage.setItem(key, JSON.stringify(saveData));
        return { success: true, slot, saved_at: saveData.saved_at };
    }

    _deleteLocal(slot) {
        const key = this._getStorageKey(slot);
        localStorage.removeItem(key);
    }
}

// Export for module usage or attach to window for script tag usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = WebDoor;
} else {
    window.WebDoor = WebDoor;
}
