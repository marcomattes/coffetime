/* Coffee Time service worker: cache the static shell, never the API. */
'use strict';
const sw = self;
const CACHE = 'coffeetime-v5';
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
function euros(cents) {
    const value = typeof cents === 'number' && isFinite(cents) ? cents : 0;
    const sign = value < 0 ? '-' : '';
    return sign + (Math.abs(value) / 100).toFixed(2) + ' €';
}
async function checkReminders() {
    if (Notification.permission !== 'granted') {
        return;
    }
    let data;
    try {
        const response = await fetch('/api/reminders', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        });
        if (!response.ok) {
            // 401 = signed out on this device; anything else: try again later.
            return;
        }
        data = await response.json();
    }
    catch (e) {
        // Offline – the next trigger checks again.
        return;
    }
    const ack = {};
    const monthEnd = data && data.monthEnd;
    if (monthEnd && typeof monthEnd.month === 'string') {
        await sw.registration.showNotification('Coffee Time', {
            body: 'The month is ending — remember to settle your coffee tab ('
                + euros(monthEnd.balanceCents) + ' outstanding).',
            tag: 'month-end-' + monthEnd.month,
            icon: '/icons/icon-192.png'
        });
        ack.month = monthEnd.month;
    }
    const admin = data && data.admin;
    if (admin && typeof admin.requestedAt === 'number') {
        await sw.registration.showNotification('Coffee Time', {
            body: 'Your admin asks you to settle your coffee tab ('
                + euros(admin.balanceCents) + ' outstanding).',
            tag: 'admin-reminder',
            icon: '/icons/icon-192.png'
        });
        ack.adminRequestedAt = admin.requestedAt;
    }
    if (ack.month === undefined && ack.adminRequestedAt === undefined) {
        return;
    }
    try {
        await fetch('/api/reminders/ack', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(ack)
        });
    }
    catch (e) {
        // The ack is retried implicitly: an unacknowledged reminder is shown
        // again on the next check, replacing itself via the notification tag.
    }
}
sw.addEventListener('message', (event) => {
    const data = event.data;
    if (data && data.type === 'check-reminders') {
        event.waitUntil(checkReminders());
    }
});
// Installed PWA on Chromium only: check while the app is closed. The page
// registers the 'reminders' periodic sync after the permission is granted.
sw.addEventListener('periodicsync', (event) => {
    const sync = event;
    if (sync.tag === 'reminders' && typeof sync.waitUntil === 'function') {
        sync.waitUntil(checkReminders());
    }
});
sw.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(sw.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
        for (const client of clients) {
            if ('focus' in client) {
                return client.focus();
            }
        }
        return sw.clients.openWindow('/');
    }));
});
