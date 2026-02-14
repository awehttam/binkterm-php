/**
 * WebDoor SDK - Credits Integration
 *
 * Provides methods for displaying and updating user credit balances.
 *
 * IMPORTANT SECURITY NOTE:
 * - Credits can ONLY be modified server-side through business action APIs
 * - This SDK only handles display updates with values returned from the server
 * - Never attempt to modify credits directly from client-side code
 */

const WebDoorCredits = (function() {
    'use strict';

    /**
     * Update the parent window's credit balance display
     *
     * This function sends a postMessage to the parent BBS window to update
     * the credit balance shown in the header. The balance must come from
     * a server API response - never calculate or modify credits client-side.
     *
     * @param {number} balance - New credit balance (from server API response)
     *
     * @example
     * // After a server API call that returns the new balance
     * WebDoorAPI.post('/api/webdoor/mygame/buy-item', { itemId: 123 })
     *     .then(data => {
     *         if (data.success) {
     *             // Server performed credit transaction and returned new balance
     *             WebDoorCredits.updateDisplay(data.balance);
     *         }
     *     });
     */
    function updateDisplay(balance) {
        if (typeof balance !== 'number' || balance < 0) {
            console.error('Invalid credit balance:', balance);
            return;
        }

        // Send message to parent window to update credit display
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                type: 'binkterm:updateCredits',
                credits: balance
            }, '*');
        }
    }

    /**
     * Format a credit amount with the configured symbol
     *
     * @param {number} amount - Credit amount to format
     * @param {string} symbol - Credit symbol (default: '¤')
     * @returns {string} - Formatted credit string (e.g., "¤100")
     *
     * @example
     * const formatted = WebDoorCredits.format(150, '⚡');
     * // Returns: "⚡150"
     */
    function format(amount, symbol = '¤') {
        if (typeof amount !== 'number') {
            return symbol + '0';
        }
        return symbol + Math.floor(amount).toLocaleString();
    }

    /**
     * Create a DOM element displaying a credit amount
     *
     * @param {number} amount - Credit amount to display
     * @param {string} symbol - Credit symbol (default: '¤')
     * @param {string} className - CSS class for the element
     * @returns {HTMLElement} - Span element with formatted credits
     *
     * @example
     * const creditDisplay = WebDoorCredits.createElement(100, '⚡', 'text-warning');
     * document.body.appendChild(creditDisplay);
     */
    function createElement(amount, symbol = '¤', className = '') {
        const span = document.createElement('span');
        span.textContent = format(amount, symbol);
        if (className) {
            span.className = className;
        }
        return span;
    }

    // Public API
    return {
        updateDisplay,
        format,
        createElement
    };
})();
