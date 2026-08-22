/* Coffee Time service worker: cache the static shell, never the API. */
'use strict';
const sw = self;
const CACHE = 'kaffeeliste-v3';
const SHELL = [
    '/',
    '/style.css',
    '/app.js',
    '/manifest.webmanifest',
    '/icons/icon-192.png',
    '/icons/icon-512.png'
];
sw.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE)
        .then((cache) => cache.addAll(SHELL))
        .then(() => sw.skipWaiting()));
});
sw.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys()
        .then((keys) => Promise.all(keys
        .filter((key) => key !== CACHE)
        .map((key) => caches.delete(key))))
        .then(() => sw.clients.claim()));
});
sw.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }
    const url = new URL(request.url);
    if (url.origin !== sw.location.origin) {
        return;
    }
    // The server-authoritative API always uses the network.
    if (url.pathname.startsWith('/api/')) {
        return;
    }
    // Allow shortcut and NFC navigations to use the cached shell regardless of query parameters.
    const matchOptions = request.mode === 'navigate' ? { ignoreSearch: true } : undefined;
    event.respondWith(caches.match(request, matchOptions).then((cached) => {
        const network = fetch(request)
            .then((response) => {
            if (response && response.ok) {
                const copy = response.clone();
                caches.open(CACHE).then((cache) => {
                    cache.put(request, copy);
                });
            }
            return response;
        })
            .catch(() => cached);
        return cached || network;
    }));
});
