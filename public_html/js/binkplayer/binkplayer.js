(function(window) {
    'use strict';

    /**
     * Thin wrapper around Plyr that initialises a player on a given
     * <video> or <audio> element and returns the Plyr instance.
     * Falls back to the native element if Plyr is unavailable.
     *
     * @param {HTMLMediaElement} el
     * @returns {object|null} Plyr instance or null
     */
    function init(el) {
        if (!el) return null;
        if (typeof window.Plyr === 'undefined') return null;

        var options = {
            controls: ['play-large', 'play', 'progress', 'current-time', 'mute', 'volume', 'fullscreen'],
            resetOnEnd: false,
            tooltips: { controls: false, seek: true }
        };

        if (el.tagName.toLowerCase() === 'audio') {
            options.controls = ['play', 'progress', 'current-time', 'mute', 'volume'];
        }

        return new window.Plyr(el, options);
    }

    window.BinkPlayer = { init: init };

})(window);
