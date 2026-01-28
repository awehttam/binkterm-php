const CACHE_NAME = 'binkcache-v1';
const urlsToCache = [
    '/favicon.svg',
];

// The install handler takes care of precaching the resources
// that we want to save for offline use.
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                return cache.addAll(urlsToCache);
            })
    );
});

// Purge any old caches on activate so clients drop stale assets.
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (cacheName === CACHE_NAME) {
                        return null;
                    }
                    return caches.delete(cacheName);
                })
            );
        })
    );
});

// The fetch handler serves responses for precached resources only.
self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (!urlsToCache.includes(url.pathname)) {
        return;
    }

    event.respondWith(
        caches.match(request).then((response) => response || fetch(request))
    );
});
