const CACHE_NAME = 'binkcache-v474';

// Static assets to precache
const staticAssets = [
    '/favicon.svg',
    '/js/app.js',
    '/js/netmail.js',
    '/js/echomail.js',
    '/js/chat-page.js',
    '/js/chat-notify.js',
    '/js/ansisys.js',
    '/js/file-preview.js',
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
    '/vendor/bootstrap-5.3.0/css/bootstrap.min.css',
    '/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js',
    '/vendor/jquery-3.7.1/jquery-3.7.1.min.js',
    '/vendor/fontawesome-6.4.0/css/all.min.css',
    '/vendor/fontawesome-6.4.0/webfonts/fa-solid-900.woff2',
    '/vendor/fontawesome-6.4.0/webfonts/fa-regular-400.woff2',
    '/vendor/riptermjs/BGI.js',
    '/vendor/riptermjs/ripterm.js',
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

// Install event - cache static assets and activate immediately
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(async (cache) => {
                _cache = cache;
                // Only fetch assets not already in this cache version
                const missing = (await Promise.all(
                    staticAssets.map(url => cache.match(url).then(hit => hit ? null : url))
                )).filter(Boolean);
                if (missing.length > 0) {
                    console.log('[SW] Caching', missing.length, 'new static assets');
                    await cache.addAll(missing);
                } else {
                    console.log('[SW] All static assets already cached');
                }
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
                    // Not in cache yet — fetch, store, and return
                    return fetch(request).then((networkResponse) => {
                        cache.put(request, networkResponse.clone());
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
                        if (networkResponse.ok) {
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

