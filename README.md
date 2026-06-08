# Mishka Den — the den mother for your family

A family hub web app: one place for the household calendar, chores, lists, and the everyday coordination a family needs. Built on the [karhu](https://github.com/bjornbasar/karhu) PHP microframework.

> *Mishka* is Russian for "little bear / cub". A **den** is where cubs live. A **den mother** (Cub Scouts) is the adult who keeps a troop of cubs organised. Mishka the app is the den mother for your family.

## Status

**v0.6.10** — SW-version discipline correction. v0.6.7 introduced a `test_sw_version_matches_release` CI gate that asserts `SW_VERSION` in `public/service-worker.js` always matches the README `## Status` version. The v0.6.7 release-checklist wording, however, said to bump `SW_VERSION` only "if the release changes any precached asset" — a conditional rule that the test does NOT honour. v0.6.9 didn't touch any precached asset, didn't bump `SW_VERSION`, and CI flagged the gap. v0.6.10 syncs `SW_VERSION` to `mishka-v0.6.10`, tightens `docs/RELEASE.md` to "always bump on every release regardless of asset changes" (matches the test reality), and documents the corrected discipline as DOCS.md decision #51. No user-facing behavioural change other than the SW cache invalidating once on update — clients pick up no new assets because none changed since v0.6.9.

**v0.6.9** — Stuck-job recovery. The `mishka-worker` container's try/catch only catches handler exceptions; SIGKILL (OOM, host reboot, manual `docker kill`) leaves the job row stuck in `processing` forever. New `php bin/karhu jobs:unstick` command (cron-wired to `*/10 * * * *` on Nalle) resets rows that have been processing for >5 min back to pending so the worker picks them up again. The UPDATE's WHERE clause is the dedup — a live worker that completes a row mid-cron flips status='completed' so the unstick row simply no longer matches; no race window. Closes the v0.6.0 plan's deferred `B9 — v0.6.1 candidate`. Bumps `bjornbasar/karhu-queue` to v0.2.0 (adds `QueueInterface::unstick` + bumps `updated_at` on every status transition + status-guards `complete()`/`fail()`). The push handler's existing dedup ledger means at-most-once recovers cleanly to at-least-once.

**v0.6.8** — Auto-refresh CSRF token on every page load. Tiny inline IIFE in `layout.twig` fetches `GET /csrf-token` on `DOMContentLoaded` (and on bfcache restore) and silently overwrites the in-page `<meta name="csrf-token">` content + every `input[name="_csrf_token"]` value with the live server-side token. Closes the cross-tab session-rotation gap from v0.6.7's known limitation — submitting a form from a tab that was open across a login/logout/password-change boundary no longer shows the plain-text "CSRF token mismatch" 403. Best-effort; offline pages keep their existing tokens. Also fixes a same-class bug in `push-subscribe.js` (the meta-tag read moved inside the click handler, was closure-captured at script-load time).

**v0.6.7** — PWA-grade service worker. The v0.6.0 push-only worker grew a versioned precache (app shell + manifest + icons + push-subscribe.js + a session-state-free /offline fallback), cache-first for static assets, network-first with a 3s timeout race for HTML pages, and silent skipWaiting+clients.claim updates — no "update available" banner, no mid-session reloads. Offline reads land; offline writes still need network (mishka does not queue POSTs). Bind-mount dev workflow protected by a hostname-based escape hatch. First release where `SW_VERSION` is a release-checklist line item.

**v0.6.6** — Creation-time push categories. Two new push kinds wired into the existing v0.6 stack: when a household member assigns you a chore, you get pushed at chore-creation time; when someone adds an event, every other household member gets pushed once at series-creation. Both default opt-in with per-user toggles on /me/notifications. Mishka's first explicit schema ALTER (PG-only fenced migration) — pre-v0.6.6 the schema was strictly additive via CREATE TABLE IF NOT EXISTS.

**v0.6.4** — Hamburger nav for narrow viewports. At 375px the top nav was overflowing by 217px (`<nav>` was 568px wide, body scrollWidth 592px). Mishka now ships a progressively-enhanced `<button aria-expanded>` hamburger that collapses the nav into a vertical drawer below 700px. ESC closes; viewport-cross resets state. No-JS users fall back to the pre-v0.6.4 behaviour — no lockout. First explicit ARIA disclosure pattern in mishka templates.

**v0.6.3** — PWA manifest + installability. Mishka is now a real installable Progressive Web App: Android Chrome offers an install affordance, and iOS 16.4+ Safari users get a true standalone PWA when they "Add to Home Screen" — which is what makes iOS Web Push actually fire. Existing bookmark-style installs need to be deleted and re-added to pick up the manifest.

**v0.6.0** — mobile polish + web push reminders. Mishka nudges family members on their phones when an event is about to start or chores have slipped past their due date. Web Push Protocol via VAPID, a dedicated worker container drains a karhu-queue, and the cron pushes happen at-most-once via a dedup ledger. Plus five `@media` rules in `layout.twig` close the worst of the 375px-viewport rough edges.

## Quick start

```bash
git clone https://github.com/bjornbasar/mishka
cd mishka
composer install
cp .env.example .env
$EDITOR .env                              # set DB_USER / DB_PASS for your PostgreSQL
php vendor/bjornbasar/karhu/bin/karhu migrate
composer serve                            # http://localhost:8080
```

Then open `http://localhost:8080/register`, create your account, and set up your household.

## Stack

- **Language:** PHP 8.4+
- **Framework:** [karhu ^0.1.1](https://github.com/bjornbasar/karhu) (HTTP, DI, middleware, auth primitives)
- **Database:** PostgreSQL via [karhu-db](https://github.com/bjornbasar/karhu-db)
- **Views:** Twig via [karhu-view](https://github.com/bjornbasar/karhu-view)
- **Passwords:** argon2id (via `Karhu\Auth\PasswordHasher`)
- **Tests:** PHPUnit 11 — SQLite in-memory for unit/integration + a PostgreSQL smoke job in CI for dialect-sensitive behavior
- **Static analysis:** PHPStan level 6

## What works in v0.6.0

Everything in v0.5.x plus:

- **Web push notifications** — opt-in per device via `/me/notifications`. Two notification types: event reminder (N min before, per-user; default 15) and a once-daily overdue-chore digest (07:30–08:30 in the household's timezone). At-most-once dedup. Click an event reminder → land on `/calendar`; click a digest → land on `/chores`.
- **Per-user preferences** — minutes-before slider, digest toggle. Defaults: 15 min + digest on.
- **Mobile polish** — agenda items stack date+title vertically, chore cards put the Done button on its own row (full-width, touch-tappable), the household switcher dropdown fits a 375px viewport, 48px touch-target floor.
- **🔔 nav icon** in the header points to `/me/notifications` for quick access.
- **CSRF token meta** — `<meta name="csrf-token">` in layout so JS can send `X-CSRF-Token` on the push subscribe POST.
- **VAPID boot guard** — mishka refuses to start without `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` / `VAPID_SUBJECT` (RFC 8292 compliant).
- **`mishka-worker` container** — long-lived karhu-queue consumer; deployed under the `mishka` compose profile alongside MailHog.

## What works in v0.5.0

Carried forward from v0.1–v0.4.3: registration + login, households (N:M membership + invite codes), the full household calendar, per-user signed iCal feed, chores (one-off + recurring with round-robin/fixed assignment, overdue badges, durable points ledger, weekly + all-time leaderboard, pause/resume, per-chore rotation pools, kid-friendly badges + weekly streaks).

**New in v0.5.0:**
- **Email transport** — symfony/mailer (^7.2) wired in, MailHog dev compose, Postmark recommended for prod. `APP_URL` is required at boot (B1 — host-header injection in email links is impossible by construction).
- **Profile editing** — `/me/profile` lets a user edit their display name. Email change is deferred to v0.6+ (FK identifier; loud warning on /register).
- **Password change** — `/me/password` confirms the current password, validates the new one (12–128 chars, must differ), and rotates the session ID. The pinned-`$now` invariant (BL-2) ensures the user does NOT self-revoke.
- **Password reset** — anonymous `/password-reset` issues a 1h single-use token. Always-200 response + 1.5s timing floor + equalised miss-path work (defence vs. enumeration). Session is NOT auto-logged-in on success.
- **Email verification** — soft banner only ("Please verify your email — [Resend]"). 24h single-use token, sent at registration. Verifying flips the banner off immediately for the logged-in tab.
- **Session revocation** — `SessionRevocationGuard` middleware kicks any session whose `auth_time` predates the latest `user_password_changes.password_changed_at`. Pre-v0.5 sessions are grandfathered (decision U-1).
- **Household lifecycle** — owners can regenerate the invite code, transfer ownership atomically (`SELECT … FOR UPDATE` on PG; BL-3), or delete the household with typed-name confirmation (FK CASCADE wipes everything). Non-owners can leave; owners get a 422 directing them to transfer or delete first.
- **App-layer rate limit** — `email_send_attempts` caps `/password-reset` to 5/10min/IP and `/me/verify-email/resend` to 3/10min/user.

## Roadmap

- **v0.6+ candidates:** email change (FK touchpoints), account delete (FK RESTRICT chain on `events.created_by` / `chores.created_by`), per-device session revoke (sessions-list UI), persistent badge history, daily streaks alongside weekly, subscribe-to-external-calendar.

## Docs

- [docs/USERGUIDE.md](docs/USERGUIDE.md) — the family-facing walkthrough (also served in-product at `/help`)
- [DOCS.md](DOCS.md) — full architecture, schema, routes, design decisions
- [karhu](https://github.com/bjornbasar/karhu) — the microframework this is built on

## License

MIT
