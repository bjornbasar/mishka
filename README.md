# Mishka Den — the den mother for your family

A family hub web app: one place for the household calendar, chores, lists, and the everyday coordination a family needs. Built on the [karhu](https://github.com/bjornbasar/karhu) PHP microframework.

> *Mishka* is Russian for "little bear / cub". A **den** is where cubs live. A **den mother** (Cub Scouts) is the adult who keeps a troop of cubs organised. Mishka the app is the den mother for your family.

## Status

**v0.6.19** — Account-delete hardening + admin-promote UI. Closes two v0.6.13-candidate gaps flagged in DOCS #53. (1) **Admin-presence pre-check on self-delete**: if the user is the only `'admin'` in `system_roles`, the FK CASCADE would leave the system admin-less post-delete; v0.6.19 blocks this with a 422 + escape-hatch link to the new endpoint. (2) **`/me/admin/promote` endpoint** lets system admins grant the role to another user (promote-only semantics — the granter keeps their own admin until they actually delete, at which point the CASCADE removes it). New `App\Auth\SystemRoleRepository` owns all `system_roles` DB access (Karhu's `Rbac` is `final` + SQL-free per round-2 review); driver-aware idempotent INSERT mirrors `BadgeAwardRepository::grant` from decision #54. (3) **`user_deletions` audit table** (strictly additive, no PG_ONLY ALTER) writes `(user_id, deleted_at, household_ids)` INSIDE the existing delete transaction BEFORE the user DELETE — atomic audit. No FK to `users(id)` (the user row is gone post-cascade), mirroring `chore_points_ledger.credited_user_id` from decision #31. (4) **403 + dedicated forbidden page** is the new convention for system-admin-gated routes — reusable by v0.7+'s session-revoke UI. Nav link from `/me/profile` is gated on `SystemRoleRepository::isSystemAdmin`. AccountController now takes 14 ctor params; v0.6.20 will land the refactor flagged in DOCS #53.

**v0.6.18** — Worker bootstrap regression guard. Closes the DOCS #57 follow-up deferred from v0.6.16 (which guarded only the web `public/bootstrap.php`). The worker (mishka-worker container, running `php vendor/bjornbasar/karhu/bin/karhu push:worker`) has no single extractable bootstrap file — its wiring is split across bin/karhu (vendor), `config/container.php` (5 DI factories), `config/commands.php` (6-class registry), and `app/Commands/PushWorkerCommand.php` (3-dep ctor). New `tests/Smoke/WorkerBootstrapSmokeTest` replays what bin/karhu does up to but not including `dispatch()` (which would invoke `$worker->run()` and block) and asserts every command class auto-wires through the container without throwing. NOT `#[RunInSeparateProcess]` — the worker path doesn't register the karhu `ExceptionHandler`, so no process-global side effect exists to isolate. Reuses the v0.6.17 .env stub touch+unlink pattern + real-shape test-only VAPID keys from `BootstrapSmokeTest`. Also calls `$dispatcher->scanCommands($commands)` (round-2 defence in depth — catches broken `#[Command(...)]` attributes).

**v0.6.17** — Hotfix: BootstrapSmokeTest stub `.env` for GitHub-hosted CI. v0.6.16 shipped the smoke test verified green locally (the docker container bind-mounts `.env`) but the GitHub-hosted runner has no `.env` file (correctly — secrets aren't committed). `Dotenv::createImmutable(...)->safeLoad()` catches `InvalidPathException` for missing files, BUT the underlying `file_get_contents` emits an E_WARNING which the karhu `ExceptionHandler` (registered at `public/bootstrap.php:84`, immediately before the Dotenv call) promotes to `ErrorException` — bypassing safeLoad's catch. The smoke test now `touch`es an empty stub `.env` before the require if none exists, and `unlink`s it in `finally` if it created it. Pre-seeded `$_ENV` values win regardless (immutable semantics). No bootstrap code change.

**v0.6.16** — Bootstrap regression guards. Closes the DOCS #56 follow-up from the v0.6.15 hotfix. Two complementary guards: (1) `public/` added to `phpstan.neon.dist` analysis paths — Phase 1 verified that with `public/` in scope, PHPStan flags the v0.6.13-class namespace-collision bug (`Karhu\App\Chores\BadgeAwardRepository not found`) at analyse-time. (2) `public/index.php` extracted to `public/bootstrap.php` (returns the configured `Karhu\App` after all wiring); new `tests/Smoke/BootstrapSmokeTest` requires bootstrap.php in an isolated process (`#[RunInSeparateProcess]` — isolates `ExceptionHandler::register`'s process-global side effect) and asserts it returns a configured App with non-null router + container-resolved Connection. Real-shape test-only VAPID keys generated 2026-06-16 (WebPush::__construct calls VAPID::validate which asserts public-key strlen===65 bytes after base64url-decode; stub strings would throw at boot). `public/index.php` is now a 5-line dispatch shim: `$app = require __DIR__ . '/bootstrap.php'; $app->run();`. Also folded a pre-existing PHPStan OOM fix: `composer analyse` script gains `--memory-limit=512M` because PHPStan exceeds the container's default 128M (level 6 + vendor crawl). No schema, no behavioural change — internal refactor of the bootstrap + new test + PHPStan config additions.

**v0.6.15** — Hotfix: namespace-collision in `public/index.php`. The v0.6.13 wiring `new App\Chores\BadgeAwardRepository($db)` was a bare relative-name reference; combined with line 45's `use Karhu\App;` (aliasing `App` → `Karhu\App`), PHP resolved the lookup to `Karhu\App\Chores\BadgeAwardRepository` and 500'd on every request. PHPUnit + PHPStan + CI never executed `public/index.php` so the bug slipped through both v0.6.13 and v0.6.14 releases. Fix: added explicit `use App\Chores\BadgeAwardRepository;` + `use App\Chores\BadgeAwarder;` imports and stripped the `App\Chores\` prefix from the four references on lines 153/154/199/200. SW_VERSION bumped per decision #51 always-bump. No schema, no behavioural change — purely a namespace-resolution fix at the bootstrap layer.

**v0.6.14** — Daily streaks alongside weekly. Adds a parallel daily-streak track to the existing v0.4.3 weekly-streak machinery: consecutive days (household-tz midnight boundaries) with ≥1 chore completion. New `DayWindow` analogue to `WeekWindow` (DST-safe day arithmetic per decision #34); new `Achievements::computeDailyStreak` public static sibling to `computeStreak`. Live counter renders `📅 N` next to the existing `🔥 N` on the leaderboard at `daily_streak >= 2` (mirrors weekly's threshold). Two new badges via the v0.6.13 `badge_awards` table: `seven_day_streak` (🗓️ "Week strong") at 7 consecutive days and `thirty_day_streak` (📅 "Habit formed") at 30. Both eager-awarded via BadgeAwarder post-`markDone()`; both surface automatically on `/badges` via `config/badges.php` + the v0.6.13 grid + roster sections. No schema migration — `badge_awards.badge_code VARCHAR(64)` already accepts the new codes. No backfill for the streak badges (decision #54's `four_week_streak skip` precedent — eager-evaluation on the next chore-complete catches live streaks; households who streaked-then-stopped miss the badge, accepted rare case). `Achievements::compute()` return shape extends to `{badges, streak, daily_streak}`; `HomeController::achievementsBoard()`'s fallback default updated to match.

**v0.6.13** — Persistent badge history + `/badges` page. Reverses decision #35 (v0.4.3 "stateless badges, derived per render"). The 6 existing badges (first_chore, ten_chores, fifty_chores, centurion, five_hundred, four_week_streak) now persist in a new `badge_awards` table — `UNIQUE(household_id, user_id, badge_code)` for once-earned-forever semantics; `ON DELETE SET NULL` on `user_id` so badges survive account-delete as "Deleted user" rows (mirrors decision #31 + #53). Eager-award runs synchronously after `POST /chores/{id}/done` via a new `BadgeAwarder` service (best-effort — a badge-eval failure NEVER rolls back the chore-complete; `error_log` + continue). Streak rendering stays live (the 🔥 N number is still computed per-render via the v0.4.3 `Achievements::computeStreak` walk, now promoted to `public static`); only badge codes shift to persistent. New `/badges` page has two sections: per-user grid (6 cards, earned-vs-locked) + household roster (other members' badge counts + emoji row). Discoverability via `/me/profile`'s new "🏆 Your badges" link + clickable badge emoji on the `/chores` leaderboard. New `php bin/karhu badges:backfill` CLI walks `chore_points_ledger` once post-deploy to populate historical earnings with accurate `earned_at` (the triggering completed_at, not the deploy time); idempotent via ON CONFLICT DO NOTHING / INSERT OR IGNORE. `four_week_streak` is NOT backfilled (decision #14 — eager-evaluation on the next chore-complete catches live streaks). `ChoreRepository::markDone()` signature changed from `bool` to `?string` so BadgeAwarder reuses the same pinned timestamp without a re-SELECT (cascaded to 3 test files). Visit `/badges` from your profile, or click any badge emoji on the `/chores` leaderboard.

**v0.6.12** — Account deletion. Closes the v0.5.0 README deferral. POST `/me/delete` requires `current_password` re-auth (M1 always-verify) + a typed `confirm_email` (`hash_equals` against the canonical lowercased email — both inputs normalised with `strtolower`+`trim`). The three RESTRICT FKs that blocked the original implementation (`events.created_by`, `chores.created_by`, `chore_schedules.created_by`) migrate to NULLABLE + ON DELETE SET NULL via the PG_ONLY ALTER pattern (decision #47); authored content survives the author's account deletion as "Deleted user" (zero template changes — `created_by` is audit-only and never rendered). Owned-household pre-check blocks self-delete until the user transfers ownership or deletes each household first (mirrors decision #40 "owners cannot leave"). The single `DELETE FROM users WHERE id = :uid` inside one transaction fires the 12-table CASCADE chain + 7 SET NULL columns automatically. Session::destroy + explicit cookie clear post-delete; redirect to `/login?deleted=1` (query-param flash because the session is gone). Courtesy notification email to the user's email-on-file is fire-and-forget (detection signal for unauthorised deletion). Account delete is NOT a credential change — no `user_password_changes` write, no `Session::regenerate()` (regression-guarded by `test_post_delete_does_NOT_write_user_password_changes_row`).

**v0.6.11** — Email change. Closes the v0.5.0 deferral. Two-step flow: POST `/me/email` (with current-password re-auth + 3/10min/user rate limit) issues a 24h confirmation token sent to the NEW address; clicking the link renders a GET confirmation page (which defends against email-client link prefetching burning the token), and POSTing that page atomically swaps `users.email`, marks the new address verified, and invalidates pending `password_reset_tokens` + `email_verification_tokens` (mailbox-compromise hardening). An immediate notification to the OLD address surfaces the request (with the new email masked, no token, no cancel link — old-mailbox compromise must go through password-change for remediation). UNIQUE conflict on the swap (another user took the email mid-flow) surfaces as a 422 conflict page, never 500. Email change is NOT a credential change — no `user_password_changes` write, no `Session::regenerate()`. New `email_change_tokens` table mirrors v0.5.0's token shape; `email_send_attempts.kind` CHECK extended via the PG_ONLY ALTER pattern (decision #47).

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

- **v0.6+ candidates:** per-device session revoke (sessions-list UI), subscribe-to-external-calendar.

## Docs

- [docs/USERGUIDE.md](docs/USERGUIDE.md) — the family-facing walkthrough (also served in-product at `/help`)
- [DOCS.md](DOCS.md) — full architecture, schema, routes, design decisions
- [karhu](https://github.com/bjornbasar/karhu) — the microframework this is built on

## License

MIT
