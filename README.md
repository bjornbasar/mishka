# Mishka Den — the den mother for your family

A family hub web app: one place for the household calendar, chores, lists, and the everyday coordination a family needs. Built on the [karhu](https://github.com/bjornbasar/karhu) PHP microframework.

> *Mishka* is Russian for "little bear / cub". A **den** is where cubs live. A **den mother** (Cub Scouts) is the adult who keeps a troop of cubs organised. Mishka the app is the den mother for your family.

## Status

**v0.8.5** — Non-root container user. Closes DOCS #64's v1.0+ candidate + the paired v0.7.6 tripwire on `/var/lib/mishka/sessions` (mode-733 works for root but breaks for `www-data` because it can't stat existing session files). Dockerfile now `USER www-data`; sessions dir `chown www-data:www-data` + `chmod 700`. Prod compose gains `security_opt: no-new-privileges:true` + `cap_drop: [ALL]`. CI deploy step migrates the existing named volume via a one-shot alpine chown before container recreate. Dev container on Ruxa keeps `user: "0:0"` for bind-mount write access. `composer test` 1001 / 2469 / 0, 1 skipped. See DOCS.md decision #75.

**v0.8.4** — Tracker Phase 5: offline logging + PWA shortcuts. Fifth and final Tracker release. Family PWA now works offline: log food / exercise / weight while offline → payload queues into IndexedDB → auto-replays with a fresh CSRF token on `window.online` or next page load. Three home-screen shortcuts on Android/Chromium install (Log Food · Log Exercise · Today). See DOCS.md decision #74 + [docs/TRACKER.md §13](docs/TRACKER.md).

**v0.8.3** — Tracker Phase 4: household effort leaderboard + badges + streaks. Household-shared `/health/leaderboard` ranking on weekly MET-minutes (strength contributes a session-count sidecar; no synthetic reps→minutes conversion). 9 new tracker badges + effort/consistency streaks reuse the Chores machinery. Intake / weight / net stay PRIVATE per user per TRACKER-PLAN §5's invariant. See DOCS.md decision #73 + [docs/TRACKER.md §12](docs/TRACKER.md).

**v0.8.2** — Tracker Phase 3: `tracker_profiles` + Mifflin-St Jeor BMR + Today energy-balance widget. Per-user body profile (sex / birth_year / height / base_activity) drives BMR + expenditure math; Today shows intake vs expenditure with double-count-trap UI copy on the base-activity input. Privacy invariant locked: intake / weight / net PRIVATE per user, regression-tested. See DOCS.md decision #72 + [docs/TRACKER.md §11](docs/TRACKER.md).

**v0.8.1** — Tracker Phase 2: exercise catalog + logging + `weight_log`. Discriminated-union `exercise_log` (duration vs strength; no set-rep→minutes conversion per user-lock). 36-exercise Compendium 2024 seed. `weight_log` brought forward from v0.8.2 so kcal math lands from day one. See DOCS.md decision #71 + [docs/TRACKER.md §10](docs/TRACKER.md).

**v0.8.0** — Tracker Phase 1: dish library + serving-first food logging + 41-dish seed. New `/health` Today dashboard, food logging via live-search + serving picker, food library CRUD, `bin/karhu tracker:seed-foods` for the initial Filipino / NZ / universal dish catalog. Internal namespace `Tracker`, UI label **Health**. See DOCS.md decision #70 + [docs/TRACKER.md §§1–8](docs/TRACKER.md).

**v0.7.7** — Family "stay logged in" — 30-day session lifetime. Paired config bumps for both server-side `session.gc_maxlifetime` and client-side cookie `Max-Age`. Family PWA now stays signed in for 30 days from login instead of 24 minutes idle. See DOCS.md decision #69.

**v0.7.6** — Multi-stage docker image + `default_socket_timeout=5` ini pin. Splits `builder` and `runtime` stages so the deployed image no longer carries `-dev` apt packages, composer, or unzip. Also pins PHP's SMTP-relevant socket timeout to 5s. Image size 625MB → 555MB. See DOCS.md decision #68.

**v0.7.5** — Real email delivery via Google Workspace SMTP relay. Switches prod from MailHog to authenticated SMTP relay via IP-allowlist on the homelab's static IPv4. New `bin/karhu mail:test` command for post-deploy verification. SPF / DKIM / DMARC aligned under the `minified.work` apex. See DOCS.md decision #67.

**v0.7.4** — Hotfix: client-side double-submit prevention (Android PWA CSRF mismatch part 2). Single inline capture-phase submit guard in `layout.twig` disables submit buttons on the first click so a native double-tap on Android Chrome can't fire a second stale-token POST. See DOCS.md decision #66.

**v0.7.3** — Hotfix: persistent PHP session storage. `session.save_path` moved from `/tmp` (container-ephemeral) to a named-volume `/var/lib/mishka/sessions`. Fixes mobile/tablet CSRF-403s where the deploy wiped session files while long-lived tabs held stale cookies. See DOCS.md decision #65.

**v0.7.2** — Self-contained docker image (no more bind-mount). `mishka-php` now ships app code + `composer install --no-dev` baked in via `COPY`; deploy pulls the image instead of rsyncing source to Bosco. New Hurska-runner-driven CI `build` job pushes to the local registry. See DOCS.md decision #64.

**v0.7.1** — Auto-migrate on deploy + `schema_versions` audit. New `schema_versions` table records each migrate apply with SHA-256 hash-check. `MigrateCommand` re-runs are cheap no-ops when unchanged. CI's deploy job runs `bin/karhu migrate --applied-by=ci-deploy` after container recreate. See DOCS.md decision #63.

**v0.7.0** — Per-device session revoke UI. `/me/sessions` lists every device the user is signed in on with per-row revoke + bulk "revoke all other sessions". New `user_sessions` table keyed by an app-level UUID that survives `Session::regenerate()`. `SessionRevocationGuard` handles both mass-revoke (password-change) and per-session revoke. See DOCS.md decision #62.

**v0.6.20** — `AccountController` dep bundle refactor. Bundles the 5 email-change-specific deps into an `App\Account\AccountEmailFlow` value-object; controller ctor drops 14 → 9 params. No behaviour change, no schema change. See DOCS.md decision #61.

**v0.6.19** — Account-delete hardening + admin-promote UI. Admin-presence pre-check on self-delete (blocks the only sysadmin from destroying their own account), new `/me/admin/promote` endpoint for granting sysadmin to another user, `user_deletions` audit table for a GDPR trail. See DOCS.md decision #60.

**v0.6.18** — Worker bootstrap regression guard. New `WorkerBootstrapSmokeTest` replays what `bin/karhu` does up to but not including `dispatch()` and asserts every command class auto-wires through the container without throwing. Closes the gap left by v0.6.16 (web-only bootstrap smoke). See DOCS.md decision #59.

**v0.6.17** — Hotfix: BootstrapSmokeTest stub `.env` for GitHub-hosted CI. Test now `touch`es an empty stub `.env` before requiring bootstrap and `unlink`s in `finally` if it created it — was failing on the hosted runner because `Dotenv::safeLoad`'s `InvalidPathException` catch is bypassed by karhu's strict error handler. See DOCS.md decision #58.

**v0.6.16** — Bootstrap regression guards. `public/` added to `phpstan.neon.dist` analysis paths; `public/index.php` extracted to `public/bootstrap.php` (returns a configured `App`); new `BootstrapSmokeTest` requires the bootstrap in an isolated process and asserts non-null router + Connection. Closes v0.6.15's namespace-collision class of bug at analyse-time. See DOCS.md decision #57.

**v0.6.15** — Hotfix: namespace-collision in `public/index.php`. `use Karhu\App;` on line 45 aliased the bare name `App` to `Karhu\App`; v0.6.13's wiring `new App\Chores\BadgeAwardRepository($db)` resolved to `Karhu\App\Chores\BadgeAwardRepository` and 500'd every request. Fix: explicit `use App\Chores\BadgeAwardRepository;` + `BadgeAwarder;` imports. See DOCS.md decision #56.

**v0.6.14** — Daily streaks alongside weekly. New `seven_day_streak` + `thirty_day_streak` badges; new `DayWindow` helper mirrors `WeekWindow`'s DST-safe posture for day granularity. `Achievements::computeDailyStreak` sibling of `computeStreak`. See DOCS.md decision #55.

**v0.6.13** — Persistent badge history via `badge_awards` table. Badges are no longer stateless — they're stored with `earned_at` per `(household_id, user_id, badge_code)`. New `BadgeAwarder` fires eagerly from `ChoresController::handleDone`. New `/badges` page shows the family's card wall. `bin/karhu badges:backfill` walks history retroactively. See DOCS.md decision #54.

**v0.6.12** — Account deletion via SET NULL on authored content. Three FK columns (`events.created_by`, `chores.created_by`, `chore_schedules.created_by`) migrate to `NULL` + `ON DELETE SET NULL` via the PG_ONLY ALTER pattern. Authored content survives account deletion as "Deleted user". Belt-and-braces confirmation: current password + typed confirm-email. See DOCS.md decision #53.

**v0.6.11** — Two-step email change with old-mailbox security alert. `POST /me/email` sends a confirmation link to the NEW address + a masked security-alert to the OLD (compromised-mailbox threat model). Atomic swap on redemption. Rate-limited via `email_send_attempts.kind='change_email_request'`. See DOCS.md decision #52.

**v0.6.10** — SW-version discipline correction. `test_sw_version_matches_release` asserts `SW_VERSION` == README `## Status` unconditionally; the release checklist wording was looser ("if any precached asset changed"). Fixed the checklist to "always bump" and this release re-syncs the SW_VERSION. See DOCS.md decision #51.

**v0.6.9** — Stuck-job recovery via `jobs:unstick` cron. New CLI resets rows stuck in `status='processing'` beyond a 300s threshold. karhu-queue v0.2.0 adds status-guarded `complete()` / `fail()` so a live worker never silently races the unstick. Ansible cron on Ruxa every 10 min. See DOCS.md decision #50.

**v0.6.8** — Auto-refresh CSRF token on every page load. New `GET /csrf-token` JSON endpoint + inline IIFE in `layout.twig` freshens the in-page `<meta name="csrf-token">` on `DOMContentLoaded` + bfcache restore. Closes the cross-tab session-rotation gap. See DOCS.md decision #49.

**v0.6.7** — PWA-grade service worker. Versioned precache (icons + manifest + push script + `/offline` shell) + cache-first statics + network-first HTML with 3s timeout + silent updates via `skipWaiting` + `clients.claim`. `SW_VERSION` gated by `test_sw_version_matches_release`. See DOCS.md decision #48.

**v0.6.6** — Creation-time push categories + first schema `ALTER`. `push_subscriptions.category` column added via a canonical PG_ONLY ALTER block in `db/schema.sql`. Per-category subscribe / unsubscribe UI at `/me/notifications`. See DOCS.md decision #47.

**v0.6.4** — Progressive-enhancement hamburger nav. First explicit ARIA disclosure pattern in mishka: `.js-nav` set pre-paint on `<html>`, hamburger toggles `aria-expanded`, Escape closes, viewport-cross reset. Zero-JS clients get the full-width nav. See DOCS.md decision #46.

**v0.6.3** — PWA manifest as the iOS Web Push unblocker. `public/manifest.webmanifest` + 4 icon sizes; Safari on iOS requires a proper manifest before it will register a service worker. Adds Apple-specific meta tags (theme-color, apple-touch-icon, apple-mobile-web-app-*). See DOCS.md decision #45.

**v0.6.0** — Web push reminders + mobile polish. Family members get phone nudges when events are about to start or chores slip past due. Web Push Protocol via VAPID keys; dedicated `mishka-worker` container drains the karhu-queue; at-most-once delivery via `notification_dispatches` ledger. Plus five `@media` rules in `layout.twig` for 375px-viewport polish.

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

- **v0.7+ candidates:** subscribe-to-external-calendar, Google sign-in (OAuth/OIDC).

## Docs

- [docs/USERGUIDE.md](docs/USERGUIDE.md) — the family-facing walkthrough (also served in-product at `/help`)
- [DOCS.md](DOCS.md) — full architecture, schema, routes, design decisions
- [karhu](https://github.com/bjornbasar/karhu) — the microframework this is built on

## License

MIT
