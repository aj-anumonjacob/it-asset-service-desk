/**
 * IT Portal service worker. Caches only the look (CSS, JS, icons) so the app
 * opens fast. Pages are never cached: they hold passwords and private data,
 * and must always come fresh from the server. Offline shows offline.html.
 */
const CACHE = 'it-portal-v1';
const SHELL = ['assets/it.css', 'assets/it.js', 'assets/icon.svg', 'assets/icon-192.png', 'assets/icon-512.png', 'offline.html'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== self.location.origin) return;
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match('offline.html')));
    return;
  }
  if (/\/assets\//.test(url.pathname)) {
    e.respondWith(fetch(req).then((res) => {
      if (res.ok) { const copy = res.clone(); caches.open(CACHE).then((c) => c.put(req, copy)); }
      return res;
    }).catch(() => caches.match(req, { ignoreSearch: true })));
  }
});
