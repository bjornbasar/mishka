// mishka-sw v0.6.0 — push notification handler
//
// Served from / (file path: /data/personal/mishka/public/service-worker.js)
// so the worker scope covers the whole origin.
//
// Intentionally cacheless in v0.6 — no install/activate/fetch handlers means
// no stale-asset risk and no versioning logic needed. The worker exists only
// to receive push events and surface them as system notifications.
//
// Future v0.6.1 candidate: add a version constant + skipWaiting on update.

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
