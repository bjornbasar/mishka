// mishka-idb v0.8.4 — thin IndexedDB wrapper for the offline logging
// queue + library-cache. Zero dependencies.
//
// Two object stores in DB `mishka-offline` (version 1):
//
// queued_writes — auto-inc key. Row shape:
//   {id: auto-int, uuid: string, user_id: int, household_id: int,
//    endpoint: string, payload: object, queued_at: ISO, retry_count: int}
//
// library_cache — string key. Row shape:
//   {key: `${household_id}:${endpoint}:${query}`,
//    results: array, cached_at: ISO}
//
// Row-scoping by (user_id, household_id) prevents cross-user queue
// leakage on shared devices (v0.8.4 blocker fold — DOCS #74 B2).
// library_cache key includes household_id so mum's custom recipes
// don't render for dad on the same iPad.
//
// crypto.randomUUID() requires a secure context (HTTPS or localhost);
// dev-mode over http://192.168.4.9:5177 doesn't have it. Fallback:
// RFC-4122 v4 assembled from crypto.getRandomValues (always available).
//
// See DOCS #74 for full v0.8.4 design.
(function () {
    'use strict';
    var DB_NAME = 'mishka-offline';
    var DB_VERSION = 1;
    var STORE_QUEUE = 'queued_writes';
    var STORE_CACHE = 'library_cache';
    var LIBRARY_TTL_MS = 30 * 60 * 1000;   // 30 minutes

    function uuidV4() {
        if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID();
        }
        // Fallback for dev-mode (192.168.*, HTTP). RFC-4122 v4 shape.
        var b = new Uint8Array(16);
        crypto.getRandomValues(b);
        b[6] = (b[6] & 0x0f) | 0x40;   // version 4
        b[8] = (b[8] & 0x3f) | 0x80;   // variant 10
        var h = Array.from(b, function (x) { return (x + 0x100).toString(16).slice(1); });
        return h[0] + h[1] + h[2] + h[3] + '-' + h[4] + h[5] + '-' + h[6] + h[7] + '-' + h[8] + h[9] + '-' + h[10] + h[11] + h[12] + h[13] + h[14] + h[15];
    }

    // Cached open — one connection per page load.
    var dbPromise = null;
    function open() {
        if (dbPromise) return dbPromise;
        dbPromise = new Promise(function (resolve, reject) {
            var req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = function () {
                var db = req.result;
                if (!db.objectStoreNames.contains(STORE_QUEUE)) {
                    var qs = db.createObjectStore(STORE_QUEUE, { keyPath: 'id', autoIncrement: true });
                    qs.createIndex('by_session', ['user_id', 'household_id'], { unique: false });
                }
                if (!db.objectStoreNames.contains(STORE_CACHE)) {
                    db.createObjectStore(STORE_CACHE, { keyPath: 'key' });
                }
            };
            req.onerror = function () { reject(req.error); };
            req.onsuccess = function () { resolve(req.result); };
        });
        return dbPromise;
    }

    function tx(storeName, mode) {
        return open().then(function (db) {
            return db.transaction(storeName, mode).objectStore(storeName);
        });
    }

    function req(store) {
        // Wrap an IDBRequest chain into a Promise.
        return new Promise(function (resolve, reject) {
            store.onsuccess = function () { resolve(store.result); };
            store.onerror = function () { reject(store.error); };
        });
    }

    // ============================================================
    // queued_writes
    // ============================================================

    function queueWrite(userId, householdId, endpoint, payload) {
        return tx(STORE_QUEUE, 'readwrite').then(function (store) {
            var row = {
                uuid: uuidV4(),
                user_id: userId,
                household_id: householdId,
                endpoint: endpoint,
                payload: payload,
                queued_at: new Date().toISOString(),
                retry_count: 0,
            };
            return req(store.add(row));
        });
    }

    function listQueueForSession(userId, householdId) {
        return tx(STORE_QUEUE, 'readonly').then(function (store) {
            var idx = store.index('by_session');
            return req(idx.getAll(IDBKeyRange.only([userId, householdId])));
        });
    }

    function countQueueForSession(userId, householdId) {
        return tx(STORE_QUEUE, 'readonly').then(function (store) {
            var idx = store.index('by_session');
            return req(idx.count(IDBKeyRange.only([userId, householdId])));
        });
    }

    function removeQueue(id) {
        return tx(STORE_QUEUE, 'readwrite').then(function (store) {
            return req(store.delete(id));
        });
    }

    function incrementRetry(id) {
        return tx(STORE_QUEUE, 'readwrite').then(function (store) {
            return req(store.get(id)).then(function (row) {
                if (!row) return null;
                row.retry_count = (row.retry_count || 0) + 1;
                return req(store.put(row));
            });
        });
    }

    // ============================================================
    // library_cache
    // ============================================================

    function cacheKey(householdId, endpoint, q) {
        return householdId + ':' + endpoint + ':' + (q || '');
    }

    function cacheLibrary(householdId, endpoint, q, results) {
        return tx(STORE_CACHE, 'readwrite').then(function (store) {
            var row = {
                key: cacheKey(householdId, endpoint, q),
                results: results,
                cached_at: new Date().toISOString(),
            };
            return req(store.put(row));
        });
    }

    function getCachedLibrary(householdId, endpoint, q) {
        return tx(STORE_CACHE, 'readonly').then(function (store) {
            return req(store.get(cacheKey(householdId, endpoint, q)));
        }).then(function (row) {
            if (!row) return null;
            var age = Date.now() - new Date(row.cached_at).getTime();
            if (age > LIBRARY_TTL_MS) return null;
            return row.results;
        });
    }

    // Expose on window for the offline IIFE + live-search extension.
    window.MishkaIDB = {
        queueWrite: queueWrite,
        listQueueForSession: listQueueForSession,
        countQueueForSession: countQueueForSession,
        removeQueue: removeQueue,
        incrementRetry: incrementRetry,
        cacheLibrary: cacheLibrary,
        getCachedLibrary: getCachedLibrary,
    };
})();
