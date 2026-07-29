/* RC ERP POS Service Worker
 *
 * R28 (2026-07-22) — PWA installability.
 *
 * Strategy:
 *   - install: pre-cache the offline shell (layout CSS + JS + icon).
 *   - activate: clean up old cache versions.
 *   - fetch:
 *       - For /assets/* → cache-first (immutable static assets, served locally).
 *       - For everything else → network-first, fall back to cached shell
 *         only for navigation (HTML) requests. POST/PUT/DELETE always
 *         pass through to the network.
 *
 * NOTE: This SW is intentionally minimal — its job is to make the cart
 * page installable (Chrome requires a SW with a fetch handler). It is
 * NOT a full offline-first cache. POS workflows that need to post
 * invoices offline are out of scope.
 */

const CACHE_VERSION = 'rc-erp-pos-v1';
const OFFLINE_SHELL = [
  '/admin/sales/cart',
  '/assets/css/bootstrap.min.css',
  '/assets/css/all.min.css',
  '/assets/css/select2.min.css',
  '/assets/css/sweetalert2.min.css',
  '/assets/css/jquery.dataTables.min.css',
  '/assets/css/custom.css',
  '/assets/css/footer-dropup.css',
  '/assets/js/bootstrep/jquery-3.6.0.min.js',
  '/assets/js/bootstrep/bootstrap.bundle.min.js',
  '/assets/js/bootstrep/select2.min.js',
  '/assets/js/bootstrep/sweetalert2@11.js',
  '/assets/js/bootstrep/jquery.dataTables.min.js',
  '/assets/js/bootstrep/chart.umd.min.js',
  '/assets/js/custom.js',
  '/assets/images/icon.svg',
  '/manifest.json',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION)
      .then((cache) => cache.addAll(OFFLINE_SHELL).catch(() => {
        // addAll is atomic — if any single fetch fails, none are cached.
        // We swallow the error so install always succeeds; the SW will
        // populate the cache lazily on fetch.
      }))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;

  // Only handle GET — never intercept writes (POST/PUT/DELETE/PATCH).
  if (req.method !== 'GET') {
    return;
  }

  const url = new URL(req.url);

  // Same-origin only — let cross-origin requests (none expected) pass through.
  if (url.origin !== self.location.origin) {
    return;
  }

  // Static local assets → cache-first.
  if (url.pathname.startsWith('/assets/') || url.pathname === '/manifest.json') {
    event.respondWith(
      caches.match(req).then((cached) => cached || fetch(req).then((resp) => {
        // Cache a copy for next time.
        const copy = resp.clone();
        caches.open(CACHE_VERSION).then((c) => c.put(req, copy)).catch(() => {});
        return resp;
      }).catch(() => cached))
    );
    return;
  }

  // HTML navigations → network-first, fall back to cached cart shell.
  if (req.mode === 'navigate' || (req.headers.get('accept') || '').includes('text/html')) {
    event.respondWith(
      fetch(req).catch(() => caches.match('/admin/sales/cart').then(
        (cached) => cached || caches.match(req)
      ))
    );
    return;
  }

  // Everything else (AJAX JSON, etc.) → network-only.
  // (Don't accidentally serve stale API responses from cache.)
});
