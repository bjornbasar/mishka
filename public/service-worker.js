// mishka-sw v0.7.5 — push handler + PWA-grade cache strategy
//
// Three lifecycle handlers (install / activate / fetch) + push +
// notificationclick. Versioned precache + cache-first statics +
// network-first HTML + /offline shell fallback + silent updates.
//
// DISCIPLINE: bump SW_VERSION on EVERY release that changes any precached
// asset (the 7 below, layout.twig HTML shape, push-subscribe.js, or any
// cached page route's form-shape). Browsers detect SW updates via byte-
// comparison of this file; the version constant doubles as the byte-change
// trigger AND the activate-handler's cache discriminator. Without a bump on
// an asset-changing release, users keep prior cache until SW source happens
// to change for an unrelated reason.
//
// See DOCS.md decision #48 and docs/RELEASE.md.
// tests/View/ServiceWorkerStructureTest::test_sw_version_matches_release
// asserts SW_VERSION matches README's ## Status line — CI fails if forgot.

const SW_VERSION = 'mishka-v0.8.3';
const CACHE_NAME = 'mishka-cache-' + SW_VERSION;

const PRECACHE_URLS = [
    '/offline',                   // session-state-free offline shell
    '/apple-touch-icon.png',
    '/icon-192.png',
    '/icon-512.png',
    '/icon-512-maskable.png',
    '/manifest.webmanifest',
    '/push-subscribe.js',
];

const STATIC_EXTENSIONS = /\.(png|ico|css|js|json|webmanifest|svg|woff2?)$/i;

// Dev-mode escape hatch: lifecycle runs (install/activate fire so we test
// the wiring in dev), but precache + fetch interception are skipped so
// bind-mount edits to layout.twig / public/* land instantly with one reload.
// Substring-style alternation: my earlier /^(...|192\.168\.|...)$/ failed
// because the ^...$ anchors applied to the whole group, so `192.168.4.9`
// didn't match `192\.168\.` (trailing chars fell outside $). Each alternative
// now anchors itself:
//   - exact: ^localhost$, ^127.0.0.1$, ^[::1]$
//   - prefix: ^192.168., ^10., ^ruxa.
//   - suffix: \.local$
// False positives in prod = none — mishka.minified.work matches none.
const DEV_HOSTNAME_RE = /(^localhost$|^127\.0\.0\.1$|^\[::1\]$|^192\.168\.|^10\.|^ruxa\.|\.local$)/i;
const IS_DEV = DEV_HOSTNAME_RE.test(self.location.hostname);

// ============================================================
// Lifecycle
// ============================================================

self.addEventListener('install', function (event) {
    event.waitUntil((async () => {
        if (!IS_DEV) {
            try {
                const cache = await caches.open(CACHE_NAME);
                // Promise.allSettled (not cache.addAll) so a single 404/blip
                // doesn't abort the whole precache. Per-URL isolation lets
                // each asset succeed/fail independently — the SW still installs
                // and the missing asset falls through to network on next fetch.
                // {cache: 'reload'} bypasses HTTP cache to grab freshest origin.
                const results = await Promise.allSettled(
                    PRECACHE_URLS.map(function (url) {
                        return cache.add(new Request(url, { cache: 'reload' }));
                    })
                );
                const misses = results
                    .map(function (r, i) { return r.status === 'rejected' ? PRECACHE_URLS[i] : null; })
                    .filter(function (x) { return x !== null; });
                if (misses.length > 0) {
                    console.warn('[mishka-sw] precache: ' + (PRECACHE_URLS.length - misses.length) + '/' + PRECACHE_URLS.length + ' succeeded, missed: ' + misses.join(', '));
                }
            } catch (err) {
                // caches.open() itself failed (quota? private mode?). SW
                // continues without precache; fetch handler degrades to
                // network-only for everything.
                console.warn('[mishka-sw] install: cache open failed:', err);
            }
        }
        // skipWaiting last — new SW activates immediately on next nav.
        // Silent update: no banner, no client coordination.
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', function (event) {
    event.waitUntil((async () => {
        try {
            const names = await caches.keys();
            await Promise.all(
                names
                    .filter(function (n) { return n !== CACHE_NAME; })
                    .map(function (n) {
                        return caches.delete(n).catch(function (err) {
                            // Per-cache catch so one stuck delete doesn't
                            // block the others. Log + continue.
                            console.warn('[mishka-sw] cache delete failed:', n, err);
                            return false;
                        });
                    })
            );
        } catch (err) {
            console.warn('[mishka-sw] activate: caches.keys failed:', err);
        }
        // claim() runs even if cache cleanup throws — intentional. Don't move
        // this inside the try/catch above; if cleanup fails we still want
        // existing tabs controlled so user behaviour is consistent across
        // open tabs after a deploy.
        await self.clients.claim();
    })());
});

// ============================================================
// Fetch router
// ============================================================

self.addEventListener('fetch', function (event) {
    if (IS_DEV) return;  // network-only in dev — bind-mount edits land instantly
    const req = event.request;
    if (req.method !== 'GET') return;  // writes are never cached
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;  // don't pollute our cache with third-party

    // (1) Same-origin static: cache-first.
    if (PRECACHE_URLS.indexOf(url.pathname) !== -1 || STATIC_EXTENSIONS.test(url.pathname)) {
        event.respondWith(cacheFirst(req));
        return;
    }

    // (2) Same-origin HTML page: network-first with /offline shell fallback.
    // Network-first (NOT stale-while-revalidate) because cached HTML carries
    // session_email + household chrome + CSRF token; SWR would serve stale
    // identity-bound HTML across user-switches on shared family devices.
    // Network-first guarantees fresh-or-offline.
    const accept = req.headers.get('accept') || '';
    if (accept.includes('text/html') || req.mode === 'navigate') {
        event.respondWith(networkFirstWithOfflineFallback(req));
        return;
    }

    // (3) Everything else (rare): network with cache fallback on error.
    event.respondWith(networkWithCacheFallback(req));
});

// ============================================================
// Cache strategies
// ============================================================

function isCacheable(response) {
    // Don't cache opaque, error, or no-store responses.
    if (!response || response.status < 200 || response.status >= 400) return false;
    // Don't cache redirected responses under the requesting URL — a 302
    // from /calendar -> /login would otherwise poison the /calendar cache
    // slot with /login HTML, permanently breaking /calendar for that browser.
    if (response.redirected) return false;
    const cc = response.headers.get('Cache-Control') || '';
    if (cc.indexOf('no-store') !== -1) return false;
    return true;
}

async function cacheFirst(req) {
    const cache = await caches.open(CACHE_NAME);
    const hit = await cache.match(req);
    if (hit) return hit;
    try {
        const fresh = await fetch(req);
        if (isCacheable(fresh)) {
            // clone() because the body is a one-shot stream. .catch quota errors.
            cache.put(req, fresh.clone()).catch(function () { /* quota: ignore */ });
        }
        return fresh;
    } catch (err) {
        // Offline + cache miss for a static asset. Synthesised 504 — keeps
        // the browser's network panel sane vs throwing.
        return new Response('', { status: 504, statusText: 'Offline' });
    }
}

async function networkFirstWithOfflineFallback(req) {
    const cache = await caches.open(CACHE_NAME);
    // 3-second timeout race so flaky-connection users don't stare at blank
    // tabs for minutes. fetch().catch() neutralises rejection so the race
    // result is always a Response, null, or 'timeout'.
    const fetchPromise = fetch(req).catch(function () { return null; });
    const timeoutPromise = new Promise(function (r) { setTimeout(function () { r('timeout'); }, 3000); });
    const winner = await Promise.race([fetchPromise, timeoutPromise]);

    if (winner && winner !== 'timeout') {
        // Network reached origin (might be 4xx/5xx — return as-is, don't
        // hide errors). isCacheable rejects 4xx/5xx + redirected + no-store
        // so cache only updates on legitimate 2xx non-redirected responses.
        if (isCacheable(winner)) {
            cache.put(req, winner.clone()).catch(function () { /* quota: ignore */ });
        }
        return winner;
    }

    // Either timeout OR fetch() rejected. Fall through to cache, then
    // offline shell. If timeout, keep the original fetch alive in the
    // background so the cache updates for the next nav.
    if (winner === 'timeout') {
        fetchPromise.then(function (res) {
            if (res && isCacheable(res)) {
                cache.put(req, res.clone()).catch(function () { /* quota: ignore */ });
            }
        });
    }
    const hit = await cache.match(req);
    if (hit) return hit;
    const shell = await cache.match('/offline');
    if (shell) return shell;
    return new Response('Offline', { status: 504, statusText: 'Offline' });
}

async function networkWithCacheFallback(req) {
    try {
        return await fetch(req);
    } catch (err) {
        const cache = await caches.open(CACHE_NAME);
        const hit = await cache.match(req);
        if (hit) return hit;
        throw err;
    }
}

// ============================================================
// Push (preserved from v0.6.0 — must not regress)
// ============================================================

self.addEventListener('push', function (event) {
    const data = event.data ? event.data.json() : {};
    const title = data.title || 'Mishka';
    const options = {
        body: data.body || '',
        icon: '/icon-192.png',
        badge: '/icon-192.png',
        // The click target is carried in data so the click handler can route.
        data: { url: data.url || '/' },
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    // Open (or focus) the URL the server-side dispatcher chose for this
    // notification kind — /calendar for event reminders, /chores for the
    // overdue-chore digest, / for test pushes.
    const target = (event.notification.data && event.notification.data.url) || '/';
    event.waitUntil(clients.openWindow(target));
});
