# Mishka Den — Test Plan (v0.6.6)

Manual test plan covering every feature published to https://mishka.minified.work as of v0.6.6 (creation-time push categories — chore_assigned + new_event — landed 2026-06-07).

> **Known issue deferred to v0.6.5:** the household-switcher dropdown (only visible to users in 2+ households) misbehaves inside the open hamburger drawer — the dropdown's `position: fixed` + inline `top: 1.5rem` causes it to float at viewport-top instead of below its trigger. Pre-existing bug, only reachable now that the drawer makes the switcher accessible on mobile. Fix in v0.6.5.

Use this for release-candidate verification, regression sweeps after risky changes, and onboarding test users. Each section is independently runnable.

**Priorities:**
- **P0 (smoke)** — must pass before any deploy. Total time ~15 min.
- **P1 (regression)** — run after feature changes in the same area. Total time ~60 min.
- **P2 (edge)** — adversarial / race / cross-tenant / failure-mode. Total time ~45 min.

**Setup:**
- Fresh Chrome profile (or Incognito) for clean push permission state.
- A second browser/profile to test cross-tenant gates.
- MailHog UI open at http://192.168.4.9:8025 to catch outbound mail.
- Optional: `docker logs -f mishka-worker` in a terminal while running notification tests.

---

## 0. Smoke (P0) — ~15 min

| # | Area | Action | Expected |
|---|---|---|---|
| SMOKE-01 | Home (anon) | Visit `/` logged out | Sees pitch + Register/Sign-in CTAs |
| SMOKE-02 | Register | `/register` → fill form → submit | Redirected to `/household/setup`; verify banner shown |
| SMOKE-03 | Email verify | Click link from MailHog | Banner gone; "Email verified — thanks!" flashed |
| SMOKE-04 | Household setup | "Create" → name → Continue | Redirected to `/`; "You're in Playwright Test Household" shown |
| SMOKE-05 | Login/logout | Sign out, sign back in | Lands on `/` with household restored |
| SMOKE-06 | Calendar | `/calendar` → New event → save → view | Event visible in month grid + agenda |
| SMOKE-07 | Chores | `/chores` → New chore → save → mark done | Chore moves to Done section; doer credited |
| SMOKE-08 | Notifications | `/me/notifications` → real Chrome → Enable → test push | OS notification arrives within ~5s |
| SMOKE-09 | iCal feed | `/me/calendar/feed` → Generate | Token URL shown once; `curl` returns `text/calendar` |
| SMOKE-10 | Mobile | Devtools 375px → home, calendar, chores | No horizontal scroll; tap targets ≥48px |

---

## 1. Auth & Account (P1)

### 1.1 Registration
- **AUTH-01** Email format validation — `notanemail` → 422, form re-renders with error
- **AUTH-02** Duplicate email — register same email twice → 422 "already registered" (timing-safe — same response time as fresh)
- **AUTH-03** Password mismatch — different confirm → 422
- **AUTH-04** Password too short (<12 chars) → 422
- **AUTH-05** Display name optional — leave blank → succeeds; default to email-local-part

### 1.2 Email verification
- **AUTH-10** Resend banner — click Resend → MailHog gets new mail; old token still works (idempotent reissue)
- **AUTH-11** Verify link expired — wait >24h or manually expire DB row → 404 with "link expired"
- **AUTH-12** Verify link reused — click twice → first works, second 404 (single-use)
- **AUTH-13** Verify while logged out — clicking the link works without an active session; redirects `/login`
- **AUTH-14** Rate limit on resend — 4 resends in 10 min from same user → 4th blocked with friendly error

### 1.3 Login
- **AUTH-20** Wrong password — same response time as wrong email (timing-safe, dummy-hash verify)
- **AUTH-21** Wrong email — same as above
- **AUTH-22** Session restore — login restores `active_household_id` from `user_preferences.last_household_id`
- **AUTH-23** Already logged in — `/login` → 302 `/`

### 1.4 Password reset
- **AUTH-30** Request reset — always-200 + identical body for hit vs miss (don't leak account existence)
- **AUTH-31** Timing floor — both hit + miss take ≥1.5s
- **AUTH-32** Rate limit — 6 requests in 10 min from same IP → 6th silently throttles (200 returned, no email sent)
- **AUTH-33** Token used twice — first redeems, second 404 (atomic single-use)
- **AUTH-34** Token expired — past TTL → 404
- **AUTH-35** Reset success → redirected `/login?reset=ok`, NOT auto-logged-in
- **AUTH-36** Other pending tokens invalidated — request 2 resets, use second → first now 404
- **AUTH-37** `Referrer-Policy: no-referrer` on the reset page (devtools → check headers)

### 1.5 Password change (logged in)
- **AUTH-40** `/me/password` with wrong current password → 422 (verify always runs — M1)
- **AUTH-41** New = current → 422 "must differ"
- **AUTH-42** New password 12–128 chars
- **AUTH-43** Session regenerated after change — old cookie no longer works
- **AUTH-44** Other sessions revoked — log into mishka in two browsers; change password in one; other 302s to login next request (SessionRevocationGuard)

### 1.6 Profile
- **AUTH-50** Update display name (1–120 chars)
- **AUTH-51** Display name blank → 422

---

## 2. Households (P1)

### 2.1 Setup
- **HH-01** Create flow (already in smoke) — see SMOKE-04
- **HH-02** Join flow — second user enters invite code → joins; first user sees new member in `/household`
- **HH-03** Bad join code → 422

### 2.2 Membership management
- **HH-10** Owner sees invite code + rename form + kick buttons
- **HH-11** Non-owner sees member list only (no kick buttons)
- **HH-12** Kick member → removed; their session active-household-id cleared on their next request
- **HH-13** Cannot kick yourself (owner) — 422
- **HH-14** Cannot kick the sole owner — 422 (blocks accidental orphan)

### 2.3 Switch household
- **HH-20** Be in 2 households, switch via `/household/switch` → calendar/chores reload with new household's data
- **HH-21** Switch persists across logout/login (writes `user_preferences.last_household_id`)
- **HH-22** Switch to a household you don't belong to (forged POST) → 403

### 2.4 Lifecycle (v0.5.0)
- **HH-30** Regenerate join code — owner only; old code 422s for new joiners
- **HH-31** Leave (non-owner) → removed; redirected to another household OR `/household/setup`
- **HH-32** Leave (owner) → 422 "transfer or delete first"
- **HH-33** Transfer ownership — to current non-owner member; ownership swaps atomically; `Csrf::regenerate()`; old owner now sees no kick buttons
- **HH-34** Transfer to non-member → 422
- **HH-35** Transfer to self → 422
- **HH-36** Delete household — typed-name confirm; FK CASCADE wipes events, chores, prefs, push subs, dispatches; session cleared; redirected `/`
- **HH-37** Delete with wrong typed name → 422

---

## 3. Calendar (P1)

### 3.1 Single events (v0.3.0)
- **CAL-01** Create one-off event in household tz → shows on correct calendar day
- **CAL-02** All-day event → no time shown; correctly spans 00:00–24:00
- **CAL-03** Event with description + location renders both on detail/edit
- **CAL-04** Edit → save → optimistic-concurrency token bumps
- **CAL-05** Stale edit (another user edits between your GET and POST) → 409 + stale-data partial
- **CAL-06** Delete event → gone from calendar + agenda
- **CAL-07** Month grid `?ym=2027-03` shows the right month
- **CAL-08** Agenda groups events by start date

### 3.2 Recurring events (v0.3.1)
- **CAL-20** Create recurring event (weekly on Tuesdays) → shows every Tuesday in grid
- **CAL-21** Edit single occurrence — change time on 2026-06-09 only → that one occurrence shifts; others unaffected
- **CAL-22** Cancel single occurrence → that day shows nothing; banner says "cancelled" on the edit form
- **CAL-23** Restore cancelled occurrence → back on the calendar
- **CAL-24** Series-edit time-shift with existing overrides → `_cascade_confirm.twig` modal appears
- **CAL-25** Confirm cascade → overrides updated (or kept, per the dialog choice)
- **CAL-26** Series-edit structural change (different RRULE) with existing exceptions → `_drop_confirm.twig` appears

### 3.3 iCal feed (v0.3.2)
- **ICAL-01** Generate token — URL shown once; reload page → URL no longer visible
- **ICAL-02** `curl <feed-url>` returns 200 `text/calendar` with VEVENTs
- **ICAL-03** Revoke token → next curl returns 404
- **ICAL-04** Malformed token (not 64-hex) → 404 fast (no DB hit)
- **ICAL-05** Wrong token → 404
- **ICAL-06** Stranger tries to revoke someone else's token → 500/RuntimeException (server-side guard)
- **ICAL-07** `Referrer-Policy: no-referrer` + `Cache-Control: private, max-age=300` on feed response
- **ICAL-08** Importing into Google Calendar / Apple Calendar — events visible (smoke once per major release)

---

## 4. Chores (P1)

### 4.1 One-off chores (v0.4.0)
- **CHORE-01** Create one-off chore with points + due date + assignee
- **CHORE-02** Mark done → moves to Done section; doer's points board increments
- **CHORE-03** Reopen → done state cleared; points decremented
- **CHORE-04** Done is idempotent — clicking twice doesn't double-credit
- **CHORE-05** Overdue badge appears on chores past due_at_local
- **CHORE-06** Points whitelist — non-numeric / negative / >1000 → 422
- **CHORE-07** Assignee = non-member (forged form) → silently coerced to NULL
- **CHORE-08** Home page (`/`) shows points board + open/overdue count
- **CHORE-09** Edit chore → all fields persist
- **CHORE-10** Delete chore → gone from list

### 4.2 Recurring chore schedules (v0.4.1+)
- **SCHED-01** Create weekly schedule, fixed assignee → generates upcoming occurrences within 14-day horizon
- **SCHED-02** Create weekly schedule, rotation → assignees cycle through members
- **SCHED-03** Rotation with `participants[]` subset → only that subset rotates
- **SCHED-04** Pause schedule → no new occurrences generated; existing ones stay
- **SCHED-05** Resume → new occurrences from now forward (no backfill)
- **SCHED-06** Edit schedule (change time/RRULE) → future open occurrences regenerate
- **SCHED-07** Delete schedule → open generated occurrences dropped; completed kept (detached)
- **SCHED-08** Preset = 'none' on create → 422
- **SCHED-09** Fixed mode + non-member assignee → 422
- **SCHED-10** `/chores/schedules` not captured by `/chores/{id}` route (ordering)

### 4.3 Gamification
- **GAME-01** Points board on `/chores` shows per-member totals for the current week
- **GAME-02** Leaderboard on `/` shows top members
- **GAME-03** Badges (if implemented) — render once user crosses thresholds
- **GAME-04** Streaks — consecutive-week streak increments
- **GAME-05** Missed-chore tally — overdue past-due chores increment the missed counter

---

## 5. Notifications + Web Push (P1 — v0.6.0)

### 5.1 Preferences
- **NOTIF-01** Default `event_reminder_minutes = 15`, `overdue_chore_digest = true`, `new_chore_assigned_enabled = true`, `new_event_enabled = true` *(v0.6.6: 4 defaults)*
- **NOTIF-02** Save preferences — change to 30 min + digest off → persists, reloaded form reflects change
- **NOTIF-03** Reminder = "Off" (0 min) → no event reminders fire
- **NOTIF-04** Out-of-range (negative / >1440) — server validation rejects (form select gates this client-side too)

### 5.2 Subscribe / revoke (real browser only — Playwright can't grant push permission)
- **PUSH-01** First subscribe — Chrome permission prompt → grant → row appears in "Subscribed devices"
- **PUSH-02** Subscribe twice on same device — row not duplicated (UNIQUE on user+endpoint; revoked rows wake)
- **PUSH-03** Revoke device → row gone; further pushes don't go to that endpoint
- **PUSH-04** Subscribe rejects `http://` endpoint (forged POST) → 422 (H3)
- **PUSH-05** Other user's subscription revoke (forged ID) → 403 (H6)
- **PUSH-06** Subscribe from second browser → second row appears alongside the first

### 5.3 Test push
- **PUSH-10** Test push button → OS notification arrives within ~5s; clicking it lands on `/`
- **PUSH-11** 10-second cooldown — click twice fast, second flashes cooldown message (H2)
- **PUSH-12** Test push with zero active subs → flashes "Enable on a device first" (H6)
- **PUSH-13** Worker logs show success (`docker logs mishka-worker`)
- **PUSH-14** `last_used_at` updated on `push_subscriptions` row after success

### 5.4 Event reminders (cron-driven; needs `push:scan` cron installed)
- **PUSH-20** Add event 16 min in the future → reminder arrives at T-15 (default pref)
- **PUSH-21** Change pref to 30 min, add event 31 min out → reminder at T-30
- **PUSH-22** Reminder NOT duplicated — manually invoke `push:scan` 3 times → only one reminder arrives (dispatch dedup)
- **PUSH-23** Member kicked from household between scan ticks → reminder skipped (B10)
- **PUSH-24** Notification click → lands on `/calendar`

### 5.5 Daily overdue chore digest
- **PUSH-30** Mark a chore overdue, wait until 07:30–08:30 household-tz next day → digest arrives ONCE
- **PUSH-31** Outside the 07:30–08:30 window → no digest
- **PUSH-32** Digest disabled in prefs → no digest
- **PUSH-33** Two members both overdue → each gets their own digest, not duplicated
- **PUSH-34** Re-run `push:scan` within the window after a digest fired → no second digest (dedup keyed on YYYYMMDD)
- **PUSH-35** Digest copy — "You have 1 overdue chore" (singular) vs "You have 3 overdue chores" (plural)
- **PUSH-36** Notification click → lands on `/chores`

### 5.6 Dead-subscription cleanup
- **PUSH-40** Revoke subscription in browser settings (or wipe site data) → next push attempt receives 410 → worker marks `revoked_at`
- **PUSH-41** Revoked subscription excluded from next `push:scan` candidate set

### 5.7 Operations
- **PUSH-50** `docker exec mishka-app php vendor/bjornbasar/karhu/bin/karhu push:scan` runs cleanly
- **PUSH-51** `mishka-worker` container restart picks up where it left off
- **PUSH-52** `notification_dispatches` rows older than 90 days pruned on each scan (B4)

### 5.8 New-chore-assigned push (v0.6.6)
Two test users in the same household (Alice = creator, Bob = assignee). Both subscribed to push from a real browser. Drain MailHog / clear jobs between cases.
- **PUSH-70** Alice creates a chore assigned to Bob → Bob receives "🐻 New chore for you" / body contains the chore title / click → /chores. `notification_dispatches` has 1 row `(bob_id, 'new_chore_assigned', chore_id)`.
- **PUSH-71** Alice self-assigns a chore (assigned_to = Alice) → no push to anyone. `notification_dispatches` unchanged.
- **PUSH-72** Alice creates a chore with null assignee (open pool) → no push to anyone.
- **PUSH-73** Bob toggles "new chore assigned" off on /me/notifications → Alice creates another chore assigned to Bob → no push for Bob.
- **PUSH-74** Edit-path doesn't push: Alice creates chore (null assignee), then edits to assign to Bob → no push (edits are out of scope in v0.6.6).
- **PUSH-75** Schedule-generated recurring chores don't push: create a daily recurring chore schedule assigned to Bob → schedule generator materialises occurrences via push:scan / page-view → none of those generated occurrences push Bob.

### 5.9 New-event-added fan-out push (v0.6.6)
Three test users: Alice (creator), Bob, Carol — all in the same household, all push-subscribed.
- **PUSH-80** Alice creates a one-off event → Bob and Carol each receive "🐻 New event added" / body = event title / click → /calendar. Alice does NOT receive. `notification_dispatches` has 2 rows `(bob, 'new_event', eid)` and `(carol, 'new_event', eid)`.
- **PUSH-81** Solo household (Alice only) creates event → no pushes at all.
- **PUSH-82** Carol toggles "new event" off → Alice creates another event → only Bob pushed; Carol skipped.
- **PUSH-83** Alice creates a weekly recurring event → Bob receives exactly ONE push (not one per occurrence). T-15 event reminders still fire per occurrence via push:scan (verify PUSH-20 still works).
- **PUSH-84** Override-occurrence edit: Alice edits a single occurrence of a recurring event → no `new_event` push (override route is separate from `handleCreate`).
- **PUSH-85** Edit-path doesn't push: Alice edits the title of an existing event → no `new_event` push.

---

## 6. Mobile UX (P1 — v0.6.0)

Test in Chrome devtools at 375 × 667 (iPhone SE), AND on an actual iPhone if possible.

- **MOBILE-01** Home page — leaderboard + open/overdue stack vertically, no overflow
- **MOBILE-02** Agenda items — date stacks above content (was eating 53% of viewport)
- **MOBILE-03** Chore cards — Done button full-width below body, ≥48px tall
- **MOBILE-04** Household-switcher dropdown — fits viewport (left/right: 1rem)
- **MOBILE-05** Calendar nav buttons — centred + on their own line below heading
- **MOBILE-06** All form submit buttons ≥48px tall
- **MOBILE-07** No horizontal scrollbar on any documented route
- **MOBILE-08** PWA install on iOS 16.4+ Safari → can be added to home screen; launches in standalone mode (no Safari chrome) — the v0.6.3 manifest is what makes this work
- **MOBILE-09** Push on installed PWA (iOS 16.4+) — Enable + test push works after fresh install (NOT a re-used pre-v0.6.3 bookmark)
- **MOBILE-10** Manifest link present in HTML — `curl -s https://mishka.minified.work/ | grep 'rel="manifest"'` returns the `<link>` tag; same for `/login` and `/help` (cross-template guard)
- **MOBILE-11** Manifest + icons fetch — `curl -sI` returns 200 on `/manifest.webmanifest`, `/icon-192.png`, `/icon-512.png`, `/icon-512-maskable.png`, `/apple-touch-icon.png`. Manifest content-type is `application/manifest+json` (PHP `-S` default) or `application/json` (Apache fallback) — both acceptable
- **MOBILE-12** Android Chrome shows install mini-infobar / address-bar install icon on first visit; Chrome DevTools → Application → Manifest shows green check with all three icons resolved
- **MOBILE-13** iOS 16.4+ Safari: pre-existing bookmark-style "PWA" users must delete + re-add to pick up the manifest (one-time per device — documented in USERGUIDE callout)
- **MOBILE-14** Hamburger visible at 375px (v0.6.4) — for logged-in-with-household users, `☰` button appears in the brand row; nav links are hidden; `body.scrollWidth === window.innerWidth` (no horizontal scroll). For anonymous / no-household users, hamburger is NOT rendered (nav is short enough to fit naturally).
- **MOBILE-15** Hamburger open/close (v0.6.4) — click `☰` → drawer opens below brand row, items stacked vertically, `aria-expanded="true"` on the button. Click `☰` again or any nav link → drawer closes (or navigates).
- **MOBILE-16** Hamburger ESC + viewport-cross (v0.6.4) — open drawer, press ESC → drawer closes, `aria-expanded="false"`, focus returns to `☰` button. Open drawer at 375px, resize browser to 1024px → drawer auto-closes (matchMedia listener resets state cleanly).

---

## 7. Help (P2)

- **HELP-01** `/help` renders the user guide with anchored sections
- **HELP-02** `/help#notifications` deep-links to the notifications section
- **HELP-03** All footer/banner "Help" links land on `/help`

---

## 8. Cross-cutting security (P2)

- **SEC-01** CSRF token required on every POST — strip the `_csrf_token` field / header → 403
- **SEC-02** CSRF token per-session (does NOT rotate per POST — multi-tab safe)
- **SEC-03** CSRF rotates on login, logout, password change, transfer ownership
- **SEC-04** SessionRevocationGuard kicks sessions older than the latest password change
- **SEC-05** Optimistic-concurrency token bump on every event/chore update (409 on stale)
- **SEC-06** Timing-safe verify — wrong-email-domain login takes same time as wrong-password
- **SEC-07** Host-header injection blocked — set `Host: evil.com` on reset request; emailed link still uses `APP_URL` (B1)
- **SEC-08** Push payload size — title >100 / body >200 chars get truncated before encryption (H5)
- **SEC-09** Foreign-user kick / revoke / delete attempts → 403/422, never 500
- **SEC-10** SQL injection probes on free-text fields (title, description) → stored verbatim, no execution
- **SEC-11** XSS probes (`<script>alert(1)</script>` in titles) → escaped on render

---

## 9. Failure modes (P2)

- **FAIL-01** Database down — friendly 500 page, not a stack trace
- **FAIL-02** SMTP down (stop MailHog) — register flow still succeeds; verify banner shows "Resend"; no 500
- **FAIL-03** mishka-worker down — test push enqueues but doesn't deliver; subscriptions intact; manual `docker compose up mishka-worker` resumes
- **FAIL-04** Browser denies notification permission — Enable button error toast, no infinite retry
- **FAIL-05** push:scan crashes mid-run — `notification_dispatches` claim row left → next scan skips the same event (B3 — atomicity)
- **FAIL-06** VAPID keys rotated mid-flight — old subscriptions invalidated cleanly; new ones work after the user re-subscribes

---

## 10. Tooling reference

### Quick DB cleanup of a test user
```bash
docker exec mishka-app php -r "
require '/app/vendor/autoload.php';
Dotenv\Dotenv::createImmutable('/app')->safeLoad();
\$pdo = new PDO(\$_ENV['DB_DSN'], \$_ENV['DB_USER'], \$_ENV['DB_PASS']);
\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
\$pdo->exec(\"DELETE FROM users WHERE email = 'TEST-EMAIL'\");
"
```

### Force a `push:scan` run
```bash
docker exec mishka-app php vendor/bjornbasar/karhu/bin/karhu push:scan
```

### Watch worker logs
```bash
docker logs -f mishka-worker
```

### MailHog inbox
```
http://192.168.4.9:8025
```

### Reset MailHog between test runs
```bash
curl -sS -X DELETE http://localhost:8025/api/v1/messages
```

### Pin clock for digest-window tests
The `ClockInterface` / `FixedClock` injection lets `tests/Commands/PushScanCommandTest.php` exercise the 07:30–08:30 window deterministically; for manual testing, change your laptop's clock or temporarily set `TZ=Pacific/Auckland` and wait for the next morning window.

---

## Coverage gaps (known)

The following features have automated test coverage and don't strictly need manual verification each release, but should still appear in the quarterly regression sweep:
- All controllers have request-shape tests (NotificationsControllerTest, AuthControllerTest, etc. — 533 PHPUnit tests total)
- PushScanCommand has 8 dedicated tests (FixedClock-driven)
- PushSenderTest exercises the success / dead / transient / truncation branches
- Per-route CSRF tests in `MiddlewareIntegrationTestCase` descendants

The following are NOT covered by automated tests and must be exercised manually:
- Real-browser push subscribe + OS notification rendering (PUSH-01 through PUSH-14)
- Mobile @media rules (MOBILE-01 through MOBILE-09)
- Email rendering in real mail clients (manual once per major release; MailHog is good for delivery + content verification)
- iCal import into Google Calendar / Apple Calendar (ICAL-08)
- PWA install + iOS 16.4+ push (MOBILE-08, MOBILE-09)
