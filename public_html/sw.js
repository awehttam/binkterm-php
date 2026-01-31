const CACHE_NAME = 'binkcache-v5';

// Static assets to precache
const staticAssets = [
    '/favicon.svg',
    '/js/app.js',
    '/js/netmail.js',
    '/js/echomail.js',
    '/js/chat-page.js',
    '/js/chat-notify.js',
    '/js/ansisys.js',
    '/css/ansisys.css',
    '/css/chat-page.css'
];

// Install event - cache static assets but don't activate yet
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('[SW] Caching static assets');
                return cache.addAll(staticAssets);
            })
            .then(() => {
                console.log('[SW] New version installed, waiting for activation');
                // Notify all clients that an update is available
                return self.clients.matchAll().then(clients => {
                    clients.forEach(client => {
                        client.postMessage({
                            type: 'UPDATE_AVAILABLE',
                            version: CACHE_NAME
                        });
                    });
                });
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

    // Don't cache API calls or HTML pages
    if (url.pathname.startsWith('/api/') || request.headers.get('accept')?.includes('text/html')) {
        return;
    }

    // Handle CSS/JS files with stale-while-revalidate strategy
    if (url.pathname.match(/\.(css|js)$/)) {
        event.respondWith(
            caches.open(CACHE_NAME).then((cache) => {
                return cache.match(request).then((cachedResponse) => {
                    // Fetch from network in background to update cache
                    const fetchPromise = fetch(request).then((networkResponse) => {
                        // Update cache with fresh copy
                        cache.put(request, networkResponse.clone());
                        return networkResponse;
                    });

                    // Return cached version immediately, or wait for network
                    return cachedResponse || fetchPromise;
                });
            })
        );
    }
});
