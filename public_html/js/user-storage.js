/**
 * UserStorage — user-scoped storage wrapper.
 *
 * When a user is logged in (window.currentUserId is set), all keys are
 * stored in localStorage prefixed with the user's ID — different accounts
 * on the same browser cannot see each other's state.
 *
 * When no user is logged in, sessionStorage is used instead of localStorage
 * so that anonymous state is never persisted across browser sessions and
 * cannot leak to the next user who opens the browser.
 *
 * Usage is identical to localStorage:
 *
 *   UserStorage.setItem('myKey', 'value');
 *   UserStorage.getItem('myKey');       // null if not set for this user
 *   UserStorage.removeItem('myKey');
 *   UserStorage.clear();                // removes only the current user's keys
 *
 * The storage backend and prefix are resolved at call time from
 * window.currentUserId, so the module can load before the user ID is set
 * as long as the ID is available before any storage calls are made.
 */
(function () {
    'use strict';

    function isLoggedIn() {
        return window.currentUserId != null;
    }

    function store() {
        return isLoggedIn() ? localStorage : sessionStorage;
    }

    function prefix() {
        return isLoggedIn() ? ('u:' + window.currentUserId + ':') : 'anon:';
    }

    window.UserStorage = {
        getItem: function (key) {
            return store().getItem(prefix() + key);
        },
        setItem: function (key, value) {
            store().setItem(prefix() + key, value);
        },
        removeItem: function (key) {
            store().removeItem(prefix() + key);
        },
        /** Remove every key belonging to the current user/session. */
        clear: function () {
            var s = store();
            var p = prefix();
            Object.keys(s)
                .filter(function (k) { return k.startsWith(p); })
                .forEach(function (k) { s.removeItem(k); });
        }
    };
}());
