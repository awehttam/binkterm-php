const CACHE_NAME = 'binkcache-v300';

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
    '/vendor/fontawesome-6.4.0/webfonts/fa-brands-400.woff2'
];

// Keep a reference to the open cache to avoid re-opening on every fetch
let _cache = null;
function getCache() {
    if (_cache) return Promise.resolve(_cache);
    return caches.open(CACHE_NAME).then((c) => { _cache = c; return c; });
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
                console.log('[SW] New version installed, waiting for activation');
            })
    );
});

// Activate event - purge old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (cacheName !== CACHE_NAME) {
                        console.log('[SW] Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        }).then(() => {
            console.log('[SW] New version activated');
            return self.clients.claim();
        })
    );
});

// Listen for skip waiting message from page
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        console.log('[SW] Activating new version now');
        self.skipWaiting();
    }
});

// Fetch event - serve from cache, update in background
self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    // Only handle same-origin requests
    if (url.origin !== self.location.origin) {
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
    if (url.pathname.match(/\.(css|js|woff2?|ttf|eot|svg)$/)) {
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
            })
        );
    }
});
