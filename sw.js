/* Chasha Bhai service worker: app shell cache-first, API network-first with cached fallback (offline-first). */
const VER = 'chasha-v2-6';
const SHELL = ['./', './index.html', './assets/chasha-v2.css', './assets/chasha-core.js', './assets/chasha-v2.js', './manifest.webmanifest', './icons/icon-192.png', './icons/icon-512.png'];
self.addEventListener('install', e => { e.waitUntil(caches.open(VER).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== VER).map(k => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', e => {
  const req = e.request; if (req.method !== 'GET') return;
  const url = new URL(req.url);
  const isApi = url.origin === location.origin && url.pathname.includes('/api/');
  if (isApi) {
    // only cache read-only, non-personal endpoints
    if (!/\/api\/(weather|outbreak|geocode|status)\.php/.test(url.pathname)) return;
    e.respondWith(fetch(req).then(r => { if (r.ok) { const cp = r.clone(); caches.open(VER + '-api').then(c => c.put(req, cp)); } return r; })
      .catch(() => caches.open(VER + '-api').then(c => c.match(req)).then(r => r || new Response(JSON.stringify({ error: 'offline' }), { status: 503, headers: { 'Content-Type': 'application/json' } }))));
    return;
  }
  if (url.origin === location.origin || /unpkg\.com|fonts\.(googleapis|gstatic)\.com|cdnjs/.test(url.host)) {
    e.respondWith(caches.match(req).then(hit => {
      const net = fetch(req).then(r => { if (r.ok || r.type === 'opaque') { const cp = r.clone(); caches.open(VER).then(c => c.put(req, cp)); } return r; }).catch(() => hit || caches.match('./index.html'));
      return hit || net;
    }));
  }
});
