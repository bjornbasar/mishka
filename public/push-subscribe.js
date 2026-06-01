// mishka push-subscribe v0.6.0
//
// Loaded only on /me/notifications. Drives the "Enable on this device" flow:
//   1. ask the browser for notification permission
//   2. wait for the service worker to be ready
//   3. pushManager.subscribe({applicationServerKey: VAPID_PUBLIC_KEY, userVisibleOnly: true})
//   4. POST the returned {endpoint, p256dh, auth} to /me/push/subscribe
//
// The VAPID public key is read from data-vapid-public-key on the wrapper.
// CSRF token comes from <meta name="csrf-token"> in layout.twig (B1 fix).

(function () {
    'use strict';

    const wrapper = document.querySelector('[data-vapid-public-key]');
    const button = document.getElementById('enable-push');
    if (!wrapper || !button) return;

    const vapidPublicKey = wrapper.getAttribute('data-vapid-public-key') || '';
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') || '' : '';

    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        button.disabled = true;
        button.textContent = 'Push not supported in this browser';
        return;
    }

    // base64url → Uint8Array conversion (the PushManager wants binary).
    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw = atob(base64);
        const out = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; ++i) out[i] = raw.charCodeAt(i);
        return out;
    }

    button.addEventListener('click', async function () {
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                alert('Notification permission denied. You can re-enable in browser settings.');
                return;
            }

            const reg = await navigator.serviceWorker.ready;
            const subscription = await reg.pushManager.subscribe({
                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
                userVisibleOnly: true,
            });

            // Convert the binary keys to base64url for the POST.
            const rawP256dh = subscription.getKey ? subscription.getKey('p256dh') : null;
            const rawAuth = subscription.getKey ? subscription.getKey('auth') : null;
            const p256dh = rawP256dh ? arrayBufferToBase64Url(rawP256dh) : '';
            const auth = rawAuth ? arrayBufferToBase64Url(rawAuth) : '';

            const body = new URLSearchParams();
            body.set('endpoint', subscription.endpoint);
            body.set('p256dh', p256dh);
            body.set('auth', auth);

            const res = await fetch('/me/push/subscribe', {
                method: 'POST',
                body: body,
                headers: {
                    'X-CSRF-Token': csrfToken,
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                credentials: 'same-origin',
            });

            if (res.ok) {
                // Reload so the device list re-renders with the new row.
                window.location.reload();
            } else {
                alert('Could not register this device. Try again, or refresh the page.');
            }
        } catch (e) {
            console.error('push subscribe failed:', e);
            alert('Could not enable push: ' + (e && e.message ? e.message : 'unknown error'));
        }
    });

    function arrayBufferToBase64Url(buf) {
        const bytes = new Uint8Array(buf);
        let bin = '';
        for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
})();
