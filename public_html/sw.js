const CACHE_NAME = 'binkcache-v799';

// Static assets to precache
const staticAssets = [
    '/favicon.svg',
    '/js/user-storage.js',
    '/js/app.js',
    '/js/interest-picker.js',
    '/js/netmail.js',
    '/js/echomail.js',
    '/js/message-list-context-menu.js',
    '/js/chat-page.js',
    '/js/notifier.js',
    '/js/binkstream-client.js',
    '/js/binkstream-worker-v2.js',
    '/js/ansisys.js',
    '/js/ansi-editor.js',
    '/js/pcboard.js',
    '/css/ansisys.css',
    '/css/chat-page.css',
    // Theme stylesheets
    '/css/style.css',
    '/css/amber.css',
    '/css/dark.css',
    '/css/greenterm.css',
    '/css/cyberpunk.css',
    // Vendor libraries
    '/vendor/toastui-editor-3.2.2/toastui-editor-all.min.js',
    '/vendor/toastui-editor-3.2.2/toastui-editor.css',
    '/vendor/toastui-editor-3.2.2/toastui-editor-dark.css',
    '/js/markdown-editor.js',
    '/vendor/bootstrap-5.3.0/css/bootstrap.min.css',
    '/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js',
    '/vendor/jquery-3.7.1/jquery-3.7.1.min.js',
    '/vendor/fontawesome-6.4.0/css/all.min.css',
    '/vendor/fontawesome-6.4.0/webfonts/fa-solid-900.woff2',
    '/vendor/fontawesome-6.4.0/webfonts/fa-regular-400.woff2',
    '/vendor/riptermjs/BGI.js',
    '/vendor/riptermjs/ripterm.js',
    '/vendor/marked.min.js',
    // Notification sounds
    '/sounds/notify1.mp3',
    '/sounds/notify2.mp3',
    '/sounds/notify3.mp3',
    '/sounds/notify4.mp3',
    '/sounds/notify5.mp3'
];

// Keep a reference to the open cache to avoid re-opening on every fetch
let _cache = null;
function getCache() {
    if (_cache) return Promise.resolve(_cache);
    // Race against a timeout — Cache Storage can deadlock in Edge when another
    // browser process holds the cache lock (e.g. PWA + browser window both open).
    const timeout = new Promise((_, reject) =>
        setTimeout(() => reject(new Error('cache-open-timeout')), 3000)
    );
    return Promise.race([
        caches.open(CACHE_NAME).then((c) => { _cache = c; return c; }),
        timeout
    ]);
}

async function precacheStaticAssets(cache) {
    for (const asset of staticAssets) {
        const request = new Request(asset, { cache: 'reload' });
        const response = await fetch(request);
        if (!response.ok) {
            throw new Error(`precache-failed:${asset}:${response.status}`);
        }
        await cache.put(request, response);
    }
}

// Install event - cache static assets and activate immediately.
// Always delete and re-create the cache on install so that a version bump
// guarantees fresh assets even if the same cache name was previously populated
// with older content (e.g. a redeployment without a CACHE_NAME bump, or
// DevTools "Update on reload" forcing a reinstall of the same version).
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.delete(CACHE_NAME)
            .then(() => caches.open(CACHE_NAME))
            .then(async (cache) => {
                _cache = cache;
                console.log('[SW] Caching', staticAssets.length, 'static assets');
                await precacheStaticAssets(cache);
            })
            .then(() => {
                console.log('[SW] New version installed, skipping wait');
                return self.skipWaiting();
            })
    );
});

// Activate event - purge old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            const oldCaches = cacheNames.filter(name => name !== CACHE_NAME);
            return Promise.all(
                oldCaches.map((cacheName) => {
                    console.log('[SW] Deleting old cache:', cacheName);
                    return caches.delete(cacheName);
                })
            ).then(() => oldCaches.length > 0); // true only when replacing an older version
        }).then((replacedOld) => {
            console.log('[SW] New version activated');
            return self.clients.claim().then(() => replacedOld);
        }).then((replacedOld) => {
            // Only notify pages when we actually replaced an older cache version.
            // Skipping on first install and on re-activations of the same version
            // prevents the "Update available" prompt from looping after a reload.
            if (!replacedOld) return;
            return self.clients.matchAll({ type: 'window' }).then(clients => {
                clients.forEach(client => {
                    client.postMessage({ type: 'UPDATE_AVAILABLE', version: CACHE_NAME });
                });
            });
        })
    );
});


// Fetch event - serve from cache, update in background
self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    // Only handle same-origin requests
    if (url.origin !== self.location.origin) {
        return;
    }

    // Hard refresh (Ctrl+Shift+R) sets cache mode to 'reload' — bypass SW cache
    // entirely so the browser always gets fresh files on a forced reload.
    if (request.cache === 'reload') {
        return;
    }

    // Don't cache API calls or HTML pages (except i18n catalog which is static per deployment)
    if (url.pathname.startsWith('/api/') && url.pathname !== '/api/i18n/catalog') {
        return;
    }
    if (request.headers.get('accept')?.includes('text/html')) {
        return;
    }

    // Admin-only files are excluded from caching — they change frequently during
    // development and are only loaded for admins, so per-user caching isn't worth it.
    const adminPaths = ['/js/admin-terminal.js', '/js/xterm.js', '/js/xterm-addon-fit.js', '/css/xterm.css'];
    if (adminPaths.includes(url.pathname)) {
        return;
    }

    // Handle CSS/JS/font files with cache-first strategy.
    // Version bumps to CACHE_NAME purge and repopulate the cache at install time,
    // so there is no need to re-fetch on every request.
    if (url.pathname.match(/\.(css|js|woff2?|ttf|eot|svg|mp3|ogg)$/)) {
        event.respondWith(
            getCache().then((cache) => {
                return cache.match(request).then((cachedResponse) => {
                    if (cachedResponse) {
                        return cachedResponse;
                    }
                    // Not in cache yet — fetch, store, and return.
                    // Do not cache partial responses (206) — the Cache API
                    // rejects them and audio range requests can trigger this.
                    return fetch(request).then((networkResponse) => {
                        if (networkResponse.ok && networkResponse.status === 200) {
                            cache.put(request, networkResponse.clone());
                        }
                        return networkResponse;
                    });
                });
            }).catch(() => {
                // Cache unavailable (lock timeout or error) — fall back to network
                // so the page loads rather than hanging with a white screen.
                return fetch(request);
            })
        );
    }

    // Cache i18n catalog with cache-first strategy (same as CSS/JS)
    if (url.pathname === '/api/i18n/catalog') {
        event.respondWith(
            getCache().then((cache) => {
                return cache.match(request).then((cachedResponse) => {
                    if (cachedResponse) {
                        return cachedResponse;
                    }
                    return fetch(request).then((networkResponse) => {
                        if (networkResponse.ok && networkResponse.status === 200) {
                            cache.put(request, networkResponse.clone());
                        }
                        return networkResponse;
                    });
                });
            }).catch(() => {
                return fetch(request);
            })
        );
    }
});

