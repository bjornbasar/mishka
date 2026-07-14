// mishka-offline v0.8.4 — offline logging IIFE.
//
// Runs on every authenticated page load. Two responsibilities:
//
// 1. Form-submit interception on [data-offline-queue] markers. When
//    navigator.onLine is false, prevent the native submit and stash
//    the payload in IDB via MishkaIDB.queueWrite instead. Client-
//    stamps `logged_on` (household TZ) so a Tuesday-queued workout
//    lands on Tuesday when replayed Wednesday morning.
//
// 2. Flush the queue back to the server via /csrf-token auth-probe +
//    per-row POST with fresh token. Runs on DOMContentLoaded (catches
//    "PWA reopened after going offline") + window.online (catches
//    "connectivity just returned while page is open").
//
// Session scoping (v0.8.4 blocker fold — DOCS #74 B2): IDB is per-
// origin, not per-user. Shared iPad + mum→dad session swap MUST NOT
// leak mum's queue into dad's session. Every queued row carries
// user_id + household_id; flush only replays rows matching the
// current session's ids (read from <meta> tags).
//
// Auth-loss handling (v0.8.4 B1 fold): fetch() follows redirects by
// default → 302→/login returns 200 after auto-follow. To avoid
// silently draining the queue against an anonymous session, we probe
// /csrf-token's `authenticated` field BEFORE any POST + the tracker
// POST endpoints return 401 JSON (not 302 HTML) when Accept is JSON.
//
// Concurrency: module-scoped `flushInFlight` mutex prevents
// DOMContentLoaded + window.online co-firing double-flushes.
//
// Live-search offline fallback: exposes window.MishkaOffline.
// {searchLibrary, cacheLibraryResponse} for the layout.twig live-
// search IIFE's fetch-catch + write-through hooks.
//
// See DOCS #74.
(function () {
    'use strict';

    // --- Session context (from layout.twig meta tags) ---

    function readMeta(name) {
        var el = document.querySelector('meta[name="' + name + '"]');
        var v = el && el.getAttribute('content');
        return v && v !== '' ? v : null;
    }

    var metaUserId = readMeta('mishka-user-id');
    var metaHouseholdId = readMeta('mishka-household-id');
    var metaHouseholdTz = readMeta('mishka-household-tz');
    var userIdInt = metaUserId ? parseInt(metaUserId, 10) : null;
    var householdIdInt = metaHouseholdId ? parseInt(metaHouseholdId, 10) : null;

    // Anonymous pages (/login, /register, /offline) have none of these
    // metas. Skip everything — no queueing, no flushing.
    var HAS_SESSION_CONTEXT = userIdInt !== null && !isNaN(userIdInt) && userIdInt > 0
        && householdIdInt !== null && !isNaN(householdIdInt) && householdIdInt > 0;

    var flushInFlight = false;

    // --- Utilities ---

    function stampLoggedOnToday() {
        // Household-local Y-m-d via Intl (respects DST + calendar day).
        // 'en-CA' gives ISO YYYY-MM-DD format via toLocaleDateString.
        if (metaHouseholdTz) {
            try {
                return new Date().toLocaleDateString('en-CA', { timeZone: metaHouseholdTz });
            } catch (e) {
                // Invalid TZ (shouldn't happen — server validates households.timezone).
                // Fall through: omit logged_on and let server default to today.
            }
        }
        return null;
    }

    function serializeForm(form) {
        var fd = new FormData(form);
        var obj = {};
        fd.forEach(function (v, k) {
            // Drop CSRF token — must be freshened at replay time.
            if (k === '_csrf_token') return;
            // FormData values are strings for text inputs; single-valued forms only.
            obj[k] = typeof v === 'string' ? v : String(v);
        });
        // Client-stamp logged_on if not already set.
        if (!obj.logged_on) {
            var today = stampLoggedOnToday();
            if (today) obj.logged_on = today;
        }
        return obj;
    }

    function inlineFlash(form, message, isError) {
        // Simple, unstyled inline flash near the form. Fades after 4s.
        var span = document.createElement('div');
        span.textContent = message;
        span.style.cssText = 'padding:0.5rem 0.75rem; margin:0.5rem 0; border-radius:0.25rem; font-size:0.9rem;'
            + (isError
                ? 'background:#f8d7da; color:#842029; border:1px solid #f5c2c7;'
                : 'background:#d1e7dd; color:#0f5132; border:1px solid #badbcc;');
        form.parentNode.insertBefore(span, form);
        setTimeout(function () { span.remove(); }, 4000);
    }

    function updateBadge() {
        var badge = document.querySelector('[data-offline-badge]');
        if (!badge || !HAS_SESSION_CONTEXT) return;
        window.MishkaIDB.countQueueForSession(userIdInt, householdIdInt).then(function (count) {
            if (count > 0) {
                badge.textContent = '⚠ ' + count + ' queued';
                badge.style.display = '';
            } else {
                badge.textContent = '';
                badge.style.display = 'none';
            }
        }).catch(function () { /* best-effort */ });
    }

    // --- Form-submit interception (capture-phase, priority-first) ---

    // Capture-phase fires BEFORE v0.7.4's capture-phase double-submit
    // guard. If we preventDefault + stopPropagation when offline, the
    // v0.7.4 handler never runs — buttons stay enabled, no dataset
    // flag lingers. When online, we no-op and v0.7.4 proceeds normally.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') return;
        if (!form.hasAttribute('data-offline-queue')) return;
        if (navigator.onLine) return;
        if (!HAS_SESSION_CONTEXT || !window.MishkaIDB) return;

        event.preventDefault();
        event.stopPropagation();

        var endpoint = form.getAttribute('action') || location.pathname;
        var payload = serializeForm(form);
        window.MishkaIDB.queueWrite(userIdInt, householdIdInt, endpoint, payload).then(function () {
            inlineFlash(form, 'Queued — will sync when online.', false);
            form.reset();
            updateBadge();
        }).catch(function (err) {
            console.warn('[mishka-offline] queue write failed:', err);
            inlineFlash(form, 'Offline queue is unavailable. Try again.', true);
        });
    }, true);   // capture-phase

    // --- Flush ---

    function freshenAuth() {
        return fetch('/csrf-token', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); });
    }

    function replayRow(row, token) {
        var body = new URLSearchParams();
        Object.keys(row.payload || {}).forEach(function (k) {
            var v = row.payload[k];
            if (v !== null && v !== undefined) body.append(k, String(v));
        });
        return fetch(row.endpoint, {
            method: 'POST',
            headers: {
                'X-CSRF-Token': token,
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
            redirect: 'follow',
            body: body,
        }).then(function (resp) {
            if (resp.status === 200) {
                console.info('[mishka-offline] replayed row', row.id, 'after', row.retry_count, 'retries');
                return window.MishkaIDB.removeQueue(row.id);
            }
            if (resp.status === 400) {
                // Validation reject — hard-fail. Row is bad; don't retry forever.
                console.warn('[mishka-offline] hard-fail (validation) row', row.id, row.payload);
                return window.MishkaIDB.removeQueue(row.id);
            }
            if (resp.status === 401) {
                // Session gone. Hold the row for the next authenticated flush.
                return null;
            }
            if (resp.status === 403) {
                // CSRF stale. Freshen once and retry (guarded — no infinite loop).
                return freshenAuth().then(function (csrf) {
                    if (!csrf.authenticated) return null;
                    return fetch(row.endpoint, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-Token': csrf.token,
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'Accept': 'application/json',
                        },
                        credentials: 'same-origin',
                        body: body,
                    }).then(function (retry) {
                        if (retry.status === 200) return window.MishkaIDB.removeQueue(row.id);
                        // Second 403 or anything else → hold the row.
                        return null;
                    });
                });
            }
            if (resp.status === 429 || resp.status >= 500) {
                // Transient — increment retry, hard-fail at 5.
                return window.MishkaIDB.incrementRetry(row.id).then(function () {
                    if ((row.retry_count || 0) + 1 >= 5) {
                        console.warn('[mishka-offline] hard-fail (retry-cap) row', row.id);
                        return window.MishkaIDB.removeQueue(row.id);
                    }
                    return null;
                });
            }
            // Unknown status — hard-fail.
            console.warn('[mishka-offline] hard-fail (unknown status', resp.status, ') row', row.id);
            return window.MishkaIDB.removeQueue(row.id);
        }).catch(function (err) {
            // Network error — increment retry.
            console.warn('[mishka-offline] network error row', row.id, err);
            return window.MishkaIDB.incrementRetry(row.id);
        });
    }

    function flushQueue() {
        if (flushInFlight) return Promise.resolve();
        if (!HAS_SESSION_CONTEXT || !window.MishkaIDB) return Promise.resolve();
        flushInFlight = true;
        return freshenAuth().then(function (csrf) {
            if (!csrf || !csrf.authenticated) return null;
            if (csrf.user_id !== userIdInt) return null;   // wrong user signed in
            return window.MishkaIDB.listQueueForSession(userIdInt, householdIdInt).then(function (rows) {
                // Serialise replays — no concurrent POSTs, no parallel
                // CSRF-refresh dance.
                var chain = Promise.resolve();
                rows.forEach(function (row) {
                    chain = chain.then(function () { return replayRow(row, csrf.token); });
                });
                return chain;
            });
        }).catch(function (err) {
            console.warn('[mishka-offline] flush failed:', err);
        }).then(function () {
            flushInFlight = false;
            updateBadge();
        });
    }

    // --- Live-search offline fallback (window.MishkaOffline) ---

    window.MishkaOffline = {
        // Called from layout.twig's live-search IIFE fetch-catch.
        // `url` = e.g. '/health/log/food/search'; `q` = query string.
        searchLibrary: function (url, q) {
            if (!HAS_SESSION_CONTEXT || !window.MishkaIDB) return Promise.resolve(null);
            return window.MishkaIDB.getCachedLibrary(householdIdInt, url, q);
        },
        // Called after every SUCCESSFUL online search — write-through cache.
        cacheLibraryResponse: function (url, q, results) {
            if (!HAS_SESSION_CONTEXT || !window.MishkaIDB) return Promise.resolve();
            return window.MishkaIDB.cacheLibrary(householdIdInt, url, q, results);
        },
    };

    // --- Bootstrap ---

    // Auto-flush on page load + on connectivity return.
    document.addEventListener('DOMContentLoaded', function () {
        updateBadge();
        flushQueue();
    });
    window.addEventListener('online', flushQueue);
})();
