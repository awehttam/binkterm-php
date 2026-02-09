/**
 * WebDoor SDK - PostMessage Communication
 *
 * Provides a wrapper for communicating with the parent BBS window via postMessage API.
 */

const WebDoorMessaging = (function() {
    'use strict';

    const listeners = new Map();

    /**
     * Send a message to the parent BBS window
     *
     * @param {string} type - Message type (should be prefixed with 'binkterm:')
     * @param {Object} data - Additional data to send with the message
     *
     * @example
     * WebDoorMessaging.send('binkterm:gameEvent', {
     *     event: 'level_up',
     *     level: 5
     * });
     */
    function send(type, data = {}) {
        if (!window.parent || window.parent === window) {
            console.warn('WebDoor not running in iframe, cannot send message');
            return;
        }

        window.parent.postMessage({
            type: type,
            ...data
        }, '*');
    }

    /**
     * Listen for messages from the parent BBS window
     *
     * @param {string} type - Message type to listen for
     * @param {Function} callback - Callback function to handle the message
     * @returns {Function} - Unsubscribe function
     *
     * @example
     * const unsubscribe = WebDoorMessaging.on('binkterm:settingsChanged', (data) => {
     *     console.log('Settings updated:', data);
     * });
     *
     * // Later, to stop listening:
     * unsubscribe();
     */
    function on(type, callback) {
        if (!listeners.has(type)) {
            listeners.set(type, new Set());
        }

        listeners.get(type).add(callback);

        // Return unsubscribe function
        return () => {
            const callbacks = listeners.get(type);
            if (callbacks) {
                callbacks.delete(callback);
                if (callbacks.size === 0) {
                    listeners.delete(type);
                }
            }
        };
    }

    /**
     * Remove a message listener
     *
     * @param {string} type - Message type
     * @param {Function} callback - Callback function to remove
     */
    function off(type, callback) {
        const callbacks = listeners.get(type);
        if (callbacks) {
            callbacks.delete(callback);
            if (callbacks.size === 0) {
                listeners.delete(type);
            }
        }
    }

    /**
     * Initialize the messaging system
     * Sets up the global message event listener
     */
    function init() {
        window.addEventListener('message', (event) => {
            // Basic origin validation (you may want to make this stricter)
            // For now, we accept messages from same origin

            const { type, ...data } = event.data;

            if (!type) return;

            const callbacks = listeners.get(type);
            if (callbacks) {
                callbacks.forEach(callback => {
                    try {
                        callback(data);
                    } catch (error) {
                        console.error(`Error in message handler for ${type}:`, error);
                    }
                });
            }
        });
    }

    // Auto-initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Public API
    return {
        send,
        on,
        off
    };
})();
