/* Kaffeeliste – Service Worker. Cacht nur die statische Hülle, nie /api/*. */
'use strict';

var CACHE = 'kaffeeliste-v2';
var SHELL = [
  '/',
  '/style.css',
  '/app.js',
  '/manifest.webmanifest',
  '/icons/icon-192.png',
  '/icons/icon-512.png'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE)
      .then(function (cache) {
        return cache.addAll(SHELL);
      })
      .then(function () {
        return self.skipWaiting();
      })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys()
      .then(function (keys) {
        return Promise.all(
          keys
            .filter(function (key) { return key !== CACHE; })
            .map(function (key) { return caches.delete(key); })
        );
      })
      .then(function () {
        return self.clients.claim();
      })
  );
});

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') {
    return;
  }
  var url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    return;
  }
  // Die API ist immer live: serverautoritativer Zähler, nie aus dem Cache.
  if (url.pathname.startsWith('/api/')) {
    return;
  }

  // Shortcut/NFC-Link "/?book=1" soll auch offline die gecachte Hülle "/"
  // treffen – Suchparameter spielen für den Seitenaufbau keine Rolle.
  var matchOptions = request.mode === 'navigate' ? { ignoreSearch: true } : undefined;

  event.respondWith(
    caches.match(request, matchOptions).then(function (cached) {
      var network = fetch(request)
        .then(function (response) {
          if (response && response.ok) {
            var copy = response.clone();
            caches.open(CACHE).then(function (cache) {
              cache.put(request, copy);
            });
          }
          return response;
        })
        .catch(function () {
          return cached;
        });

      return cached || network;
    })
  );
});
