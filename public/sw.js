// WiBuy service worker: lets phones install WiBuy as an app and shows a friendly page when offline.
// Pages are always fetched fresh from the network (prices, stock and orders must be current);
// only the offline page and the app icon are cached.
const CACHE = 'wibuy-v1';
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll([OFFLINE_URL, '/icon-192.png'])));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    // Only page loads get the offline fallback; everything else goes straight to the network.
    if (event.request.mode !== 'navigate') return;

    event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE_URL)));
});
