const CACHE_NAME = 'my-pwa-cache-v1';
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

// The fetch handler serves responses for same-origin resources from a cache.
self.addEventListener('fetch', (event) => {
    event.respondWith(
        caches.match(event.request)
            .then((response) => {
                // Return the cached response if it's available.
                if (response) {
                    return response;
                }

                // Otherwise, go to the network.
                return fetch(event.request);
            })
    );
});