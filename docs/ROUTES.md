# Mishka — Routes

## Public (anonymous)

| Method | Path | Behaviour |
|---|---|---|
| GET  | / | Anonymous: pitch + Register/Sign-in CTAs. Logged in: see below. |
| GET  | /register | Show form (redirect / if logged in) |
| POST | /register | Validate, create user, auto-login, redirect /household/setup |
| GET  | /login | Show form (redirect / if logged in) |
| POST | /login | Timing-safe verify; set session; restore active_household from `user_preferences.last_household_id`; redirect / |

## Authenticated

| Method | Path | Behaviour |
|---|---|---|
| GET  | / | Logged in w/ household: home with household name + Manage link. Logged in w/o household: redirect /household/setup |
| POST | /logout | Destroy session, clear cookie, redirect /login |

## Households (v0.2)

All require an active session. Most require household membership (via `HouseholdAuthorizer::requireMember`); some additionally require ownership (`requireOwner`).

| Method | Path | Behaviour |
|---|---|---|
| GET  | /household/setup | Create/Join form. Redirect /household if user already has an active household. |
| POST | /household/setup | `action=create` OR `action=join`; sets session + writes `user_preferences.last_household_id`; redirect / |
| GET  | /household | Settings page; owner sees invite code + rename form + kick buttons |
| POST | /household/rename | Owner-only (`requireOwner`) |
| POST | /household/members/{userId}/remove | Owner-only; blocks owner-self and removing the sole owner |
| POST | /household/switch | Switch active household (`requireMember` on target); persists in `user_preferences` |

## Calendar (v0.3.0)

All gated on `Session::has('user_id')` + `Session::has('active_household_id')` + `HouseholdAuthorizer::requireMember`. POST handlers whitelist-extract form input to `[title, description, location, starts_at_local, ends_at_local, all_day]`.

| Method | Path | Behaviour |
|---|---|---|
| GET  | /calendar | Month grid. Optional `?ym=YYYY-MM` (defaults to current month in household tz). |
| GET  | /calendar/agenda | Agenda list grouped by event start. `?ym=YYYY-MM` (same default). |
| GET  | /calendar/events/new | Create form; defaults to tomorrow 9am in household tz. |
| POST | /calendar/events | Create; 303 to `/calendar?ym=…`. 422 + form re-render on validation failure. |
| GET  | /calendar/events/{id} | Edit form (shared template with create). 404 if event not in active household. |
| POST | /calendar/events/{id} | Update with `_expected_updated_at` optimistic-concurrency. 409 + stale-data partial on mismatch. |
| POST | /calendar/events/{id}/delete | Delete; 303 to /calendar |

## Calendar single-occurrence (v0.3.1)

Same gates as the calendar routes above. Slug regex: `^\d{4}-\d{2}-\d{2}T\d{2}-\d{2}$`.

| Method | Path | Behaviour |
|---|---|---|
| GET  | /calendar/events/{id}/occurrences/{key}/edit | Pre-fills from existing override OR series defaults. Cancellation state shown as a banner. 404 on malformed slug / non-occurrence / non-recurring series. |
| POST | /calendar/events/{id}/occurrences/{key} | Save override. Wipes any prior exception for this occurrence (cancellation OR earlier override) before `addOverride` runs, so the UNIQUE constraint can't trip. |
| POST | /calendar/events/{id}/occurrences/{key}/cancel | Insert a cancellation row (idempotent via UNIQUE). |
| POST | /calendar/events/{id}/occurrences/{key}/restore | Drop the exception (two-step DELETE if it was an override). |

## Series-edit confirm dialogs (v0.3.1)

POST `/calendar/events/{id}` either applies the change directly or — when there are existing exceptions and the edit is a clean time-shift or structural — renders one of:
- `_cascade_confirm.twig` — clean time-shift; confirming sets `_cascade_confirmed=1` and resubmits to the same route
- `_drop_confirm.twig` — structural change; confirming sets `_drop_confirmed=1` and resubmits

Both confirm forms also carry `_expected_updated_at` + `_expected_exception_count` so the second submit re-runs the optimistic-concurrency checks against fresh state.

## iCal feed (v0.3.2)

| Method | Path | Notes |
|---|---|---|
| GET | /me/calendar/feed | Settings page; lists active tokens (created_at + last_used_at). Session-gated. |
| POST | /me/calendar/feed/generate | Cap-aware generate; renders the post-generate page with the raw URL shown ONCE. `Referrer-Policy: no-referrer` header. |
| POST | /me/calendar/feed/tokens/{id}/revoke | Owner-gated; 303 → /me/calendar/feed. Stranger-revoke throws RuntimeException at repo layer. |
| GET | /ical/{token}.ics | **UNAUTHENTICATED.** Token IS the auth. 200 `text/calendar` on hit, 404 on invalid / revoked. `Referrer-Policy: no-referrer` + `Cache-Control: private, max-age=300` headers. |

The public feed route shape-checks the token against `/^[0-9a-f]{64}$/` before hashing — cheap bot filter, no DB lookup on malformed input.

## Chores (v0.4.0)

| Method | Path | Notes |
|---|---|---|
| GET | /chores | List: per-member points board + open chores (overdue badge) + collapsible "Done" section (recently-done first). |
| GET | /chores/new | Create form; assignee dropdown from current members; due date optional. |
| POST | /chores | Create. Whitelist `[title, description, points, due_at_local, assigned_to]`; forces household tz; 303 → /chores; 422 on error. |
| GET | /chores/{id} | Edit form. 404 if the chore isn't in the active household. |
| POST | /chores/{id} | Update (same whitelist); 303 → /chores; 422 on error. |
| POST | /chores/{id}/delete | Delete (confirm dialog on the form); 303 → /chores. |
| POST | /chores/{id}/done | Mark done (idempotent, credits the doer); 303 → /chores. |
| POST | /chores/{id}/reopen | Reopen (clears completion, un-credits); 303 → /chores. |

All routes are member-gated (`requireSession` + `requireMember`). Any member may act on any chore. `points` is shape-validated (blank → 0; non-numeric / negative / >1000 → 422); a non-member `assigned_to` is silently coerced to NULL. The home page (`/`) also surfaces the points board + an open/overdue count.

## Recurring chores (v0.4.1)

| Method | Path | Notes |
|---|---|---|
| GET | /chores/schedules/new | Recurring-chore form (recurrence fieldset + due time + rotate/fixed). |
| POST | /chores/schedules | Create; rrule via RruleTranslator; **preset 'none' → 422**; fixed mode requires a current member; 303 → /chores. |
| GET | /chores/schedules/{id} | Edit form (repopulates the preset via `toForm`). |
| POST | /chores/schedules/{id} | Update + refresh upcoming (delete future-open occurrences, rewind watermark); 303 → /chores. |
| POST | /chores/schedules/{id}/delete | Delete (confirm dialog): drop open generated, detach completed; 303 → /chores. |
| POST | /chores/schedules/{id}/pause | Pause: stop generating new occurrences (existing ones kept); 303 → /chores. (v0.4.2) |
| POST | /chores/schedules/{id}/resume | Resume: rewinds the watermark to now (forward-only); 303 → /chores. (v0.4.2) |

The create/edit form (v0.4.2) also accepts `participants[]` — a rotation pool subset (current members only; empty = rotate across everyone).

`ChoreSchedulesController` is registered **before** `ChoresController` so the sequential router doesn't let `/chores/{id}` capture the static `/chores/schedules` paths. Occurrences are materialised lazily on view (in `/chores` and `/`) by `ChoreScheduleGenerator`, bounded to a 14-day rolling horizon and idempotent via `UNIQUE(schedule_id, occurrence_date)`.

## Account (v0.5.0)

| Method | Path | Notes |
|---|---|---|
| GET | /me/profile | Render display-name form (pre-filled). 302 → /login if anonymous. |
| POST | /me/profile | Whitelist `[display_name]`. Validate 1–120 chars; 303 → /me/profile; 422 on error. |
| GET | /me/password | Render change-password form (3 inputs). |
| POST | /me/password | Whitelist `[current_password, new_password, new_password_confirm]`. `$hasher->verify` is **always** called (M1). New is 12–128 chars, matches confirm, differs from current. Pinned-`$now` (BL-2) shared between `updatePassword`'s stamp + `Session::set('auth_time')`. `Session::regenerate()` + `Csrf::regenerate()` (M4 + H-7). 303 → /me/profile. |

## Email-dependent flows (v0.5.0)

| Method | Path | Notes |
|---|---|---|
| GET | /password-reset | Anonymous request form. |
| POST | /password-reset | Whitelist `[email]`. Always-200 + identical body for hit/miss (B4) + 1.5s timing floor + dummy argon2id verify on miss (H-4). Rate limited 5/10min/IP (H4). Issues + emails the link on hit; silently throttles on over-limit. |
| GET | /password-reset/{64-hex} | Render reset form if token pending + unexpired. 404 on bad shape / unknown / used / expired. **`Referrer-Policy: no-referrer`** (H-5). |
| POST | /password-reset/{64-hex} | Whitelist `[new_password, new_password_confirm]`. **Atomic single-use** (B6) via `redeemAtomically`. Updates hash + stamps revocation (pinned `$now`, BL-2). Invalidates other pending tokens. `Csrf::regenerate()`. 303 → `/login?reset=ok`. **No auto-login.** |
| GET | /verify-email/{64-hex} | Atomic single-use redeem + `markEmailVerified`. If session present, stamps `Session::set('email_verified_at', now)` (H5). 303 → `/` (or `/login`). `Referrer-Policy: no-referrer`. |
| POST | /me/verify-email/resend | Session-gated; rate limited 3/10min/user. Invalidates pending + reissues + emails. 303 → referrer. Idempotent for already-verified users. |

All emailed URLs are built via `App\Mail\UrlBuilder` which reads ONLY `$_ENV['APP_URL']` — host-header injection (B1) is impossible by construction.

## Household lifecycle (v0.5.0)

| Method | Path | Notes |
|---|---|---|
| POST | /household/regenerate-code | Owner-only. Rotates the join code; old code stops working. 303 → /household. |
| POST | /household/leave | Member-gated. Owners get 422 "transfer or delete first". Non-owners are removed; session active-household keys cleared; 303 → another membership or `/household/setup`. |
| POST | /household/transfer | Owner-only. Whitelist `[new_owner_user_id]`. Atomic via `SELECT … FOR UPDATE` (PG; no-op SQLite) + guarded promote/demote (BL-3). 422 if target not a current non-owner member. `Csrf::regenerate()` (M4). 303 → /household. |
| POST | /household/delete | Owner-only. Whitelist `[confirm_name]`. Typed-name confirm via `hash_equals` (H7). FK CASCADE wipes all child rows. Clears active-household session keys. `Csrf::regenerate()`. 303 → /. |

Pipeline order (round-4 BL-1): **Session → SessionRevocationGuard → Csrf → router**. The guard kicks any session whose `auth_time` predates the latest `user_password_changes.password_changed_at`; tests that need the full pipe extend `MiddlewareIntegrationTestCase` (round-4 H-6).

## Notifications + web push (v0.6.0)

| Method | Path | Notes |
|---|---|---|
| GET | /me/notifications | Renders the preferences form + the list of subscribed devices. Exposes the VAPID public key as `data-vapid-public-key` on the wrapper for `push-subscribe.js` to read. |
| POST | /me/notifications | Whitelist `[event_reminder_minutes, overdue_chore_digest]`. Validate minutes 0–1440 (CHECK constraint enforces upstream). Upsert prefs via `UserNotificationPrefsRepository::setFor`. 303 → /me/notifications. |
| POST | /me/push/subscribe | JS-driven; form-urlencoded body `endpoint`, `p256dh`, `auth`. Validates `endpoint` shape (HTTPS scheme + non-empty host, H3). `PushSubscriptionRepository::register` is idempotent — re-subscribe wakes the revoked row. CSRF via `X-CSRF-Token` header (meta tag in layout). |
| POST | /me/push/subscriptions/{id}/delete | Revoke if owned by the session user; 403 on foreign. 303 → /me/notifications. |
| POST | /me/push/test | Enqueues a `SendPushNotification` job for the calling user. 10-second session-level cooldown (H2). Flashes "Enable on a device first" if zero active subs (H6). |

All routes session-gated; anonymous → 302 /login.

The actual push delivery happens out-of-band:
- `php vendor/bjornbasar/karhu/bin/karhu push:scan` runs every 5 min on Nalle's cron. Three passes: prune dispatch ledger >90 days; event reminders (per-user threshold + 5-min cron jitter buffer); overdue digest (07:30–08:30 household-tz only).
- `php vendor/bjornbasar/karhu/bin/karhu push:worker` runs as the `mishka-worker` container. Consumes the `jobs` table; for each `SendPushNotification`, fans out to every active subscription for the user via `PushSender`. HTTP 410 → `markRevoked`; success → `touch`; transient → `error_log` (lands in `docker logs mishka-worker`).

All emailed-URL safety properties from v0.5 carry over: the click-action URL in the push payload is built from `APP_URL` via `UrlBuilder` (B1); the payload is JSON-encoded with `JSON_THROW_ON_ERROR`; title truncated to 100 chars + body to 200 chars before encryption (H5).

## Static assets (v0.6.3)

Served by the front-end (PHP `-S` in current prod, Apache in any future deploy) directly from `public/` — `.htaccess` `RewriteCond %{REQUEST_FILENAME} -f` short-circuits to the file before karhu's front controller runs. No session, no cookies, no PHP execution.

| Method | Path | Notes |
|---|---|---|
| GET | /manifest.webmanifest | PWA manifest. `application/manifest+json` under PHP `-S`; same under Apache via the `AddType` in `.htaccess` |
| GET | /service-worker.js | v0.6.0 push handler. Cacheless, scope `/` |
| GET | /icon-192.png | 192×192 PNG (manifest `any` icon, also notification icon + badge in service-worker.js) |
| GET | /icon-512.png | 512×512 PNG (manifest `any` icon — drives Chrome installability) |
| GET | /icon-512-maskable.png | 512×512 PNG with 80% safe zone (manifest `maskable` icon for Android adaptive launchers) |
| GET | /apple-touch-icon.png | 180×180 PNG (iOS home-screen icon — referenced by `<link rel="apple-touch-icon">` in layout.twig) |
| GET | /favicon.ico | Browser tab favicon |

## CSRF token endpoint (v0.6.8)

| Method | Path | Notes |
|---|---|---|
| GET | /csrf-token | Returns `{"token": "..."}` JSON with `Cache-Control: no-store`. Unauthenticated (works for anonymous + logged-in alike via karhu's session/cookie token storage). Powers the inline IIFE in `layout.twig` that refreshes the in-page CSRF token on every page load — closes the cross-tab session-rotation gap (login in tab A invalidates tab B's CSRF token; old behaviour was a plain-text "CSRF token mismatch" 403; new behaviour is silent refresh on tab B's next nav). Consumed only by `layout.twig`'s inline script; not part of any external API contract. GET-safelisted by Csrf middleware so the endpoint doesn't require a token to call itself. |
