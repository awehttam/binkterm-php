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
