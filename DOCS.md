# Mishka Den — Project Documentation

**Version:** 0.6.0 | **License:** MIT | **PHP:** >=8.4

A family hub web app — the den mother for your family. First real-world dogfood of the [karhu](https://github.com/bjornbasar/karhu) PHP microframework.

This file is the top-level overview. Detail lives in `docs/`:

- **[docs/SCHEMA.md](docs/SCHEMA.md)** — full database schema, every table, design notes per release
- **[docs/ROUTES.md](docs/ROUTES.md)** — full route table grouped by feature
- **[docs/CALENDAR.md](docs/CALENDAR.md)** — v0.3 calendar design (time model, month grid, optimistic concurrency, recurrence, single-occurrence editing, iCal feed)
- **[docs/CHORES.md](docs/CHORES.md)** — v0.4 chores design (overdue/time model, live points tally + credit rule, recurring-chore generation + round-robin rotation)
- **[docs/ACCOUNT.md](docs/ACCOUNT.md)** — v0.5 account lifecycle (profile, password change, reset, verify, household lifecycle, session revocation, rate limit, threat model)

---

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Language | PHP 8.4+ |
| Framework | karhu ^0.1.1 (HTTP, DI, middleware, attribute routing) |
| Database | PostgreSQL via karhu-db (PDO) |
| Views | Twig via karhu-view |
| Auth | karhu's argon2id PasswordHasher + Rbac + Session/CSRF middleware + `Karhu\Error\ForbiddenException` (v0.1.1) |
| Recurrence | `simshaun/recurr ^6.0` (v0.3.1+) |
| iCal (v0.3.2+) | `sabre/vobject ^4.5` |
| Email (v0.5.0+) | `symfony/mailer ^7.2` + `symfony/mime ^7.2`; dev SMTP capture via MailHog in `/data/personal/docker-compose.yml` (profile `mishka`); prod recommendation Postmark |
| Env loader | `vlucas/phpdotenv ^5.6` |
| Testing | PHPUnit 11 — SQLite in-memory for unit/integration; PostgreSQL smoke job in CI |
| Static analysis | PHPStan level 6 |
| CI | GitHub Actions (`ubuntu-latest`, free for public repos); two jobs (SQLite test + PG smoke) |

---

## Directory Structure

```
mishka/
├── app/
│   ├── Account/UserPreferenceRepository.php
│   ├── Auth/                                    v0.5.0 — bulk additions: token repos + session revocation
│   │   ├── EmailSendAttemptRepository.php       v0.5.0; app-layer rate-limit accounting (H4)
│   │   ├── EmailVerificationTokenRepository.php v0.5.0; SHA-256-hashed, 24h TTL, atomic single-use, markSent for H2
│   │   ├── HouseholdAuthorizer.php              requireMember + requireOwner; throws ForbiddenException
│   │   ├── MishkaUserRepository.php             v0.5.0 ext: updateDisplayName/updatePassword/markEmailVerified/isEmailVerified
│   │   ├── PasswordResetTokenRepository.php     v0.5.0; SHA-256-hashed, 1h TTL, atomic single-use
│   │   ├── SessionRevocationGuard.php           v0.5.0; middleware with 4-permutation BL-1 predicate; legacy-grandfather
│   │   └── UserPasswordChangeRepository.php     v0.5.0; one-row-per-user upsert with caller-pinned $now (BL-2)
│   ├── Mail/                                    v0.5.0
│   │   ├── Mailer.php                           symfony/mailer wrapper; non-final (RecordingMailer extends)
│   │   └── UrlBuilder.php                       reads $_ENV['APP_URL'] only — B1 host-header-injection guard
│   ├── Calendar/                                v0.3.0+
│   │   ├── ConcurrentUpdateException.php       optimistic-concurrency signal
│   │   ├── EventExceptionRepository.php         v0.3.1; two-step DELETE in dropAllForEvent
│   │   ├── EventRepository.php                  events CRUD; defensive series_event_id IS NULL filter
│   │   ├── EventService.php                     v0.3.1; cascade coordinator
│   │   ├── IcalFeedBuilder.php                  v0.3.2; sabre/vobject VCALENDAR builder
│   │   ├── IcalFeedTokenRepository.php          v0.3.2; SHA-256 hashed, cap-at-3
│   │   ├── MonthGridBuilder.php                 6×7 grid + slot assignment for multi-day pills
│   │   ├── RangeExpander.php                    v0.3.1; recurr-driven expansion + override de-dup
│   │   └── RruleTranslator.php                  v0.3.1; preset form ↔ RRULE round-trip
│   ├── Chores/                                   v0.4.0+
│   │   ├── Achievements.php                      v0.4.3; pure-PHP — badges + weekly streaks from the ledger
│   │   ├── ChoreRepository.php                   chores CRUD + markDone/reopen (ledger-coupled v0.4.2) + leaderboard + recent completions (v0.4.3) + createGenerated
│   │   ├── ChoreScheduleGenerator.php            v0.4.1; clamped horizon + pure-fn rotation; skips paused + pool-aware (v0.4.2)
│   │   ├── ChoreScheduleRepository.php           v0.4.1 templates; + pause/resume + participant-pool methods (v0.4.2)
│   │   └── WeekWindow.php                        v0.4.3; DST-safe week arithmetic (single home for the household-tz/UTC dance)
│   ├── Commands/MigrateCommand.php
│   ├── Controllers/
│   │   ├── AccountController.php                v0.5.0; /me/profile + /me/password (BL-2 pinned-$now)
│   │   ├── AuthController.php                   v0.5.0 ext: register-hook (verify-email send) + establishSession writes auth_time/email_verified_at/Csrf::regenerate
│   │   ├── CalendarController.php               v0.3.0+ (single-occurrence routes v0.3.1)
│   │   ├── ChoresController.php                  v0.4.0; chores CRUD + done/reopen + points board (+ lazy-gen hook v0.4.1)
│   │   ├── ChoreSchedulesController.php          v0.4.1; recurring-chore CRUD (scanned before ChoresController)
│   │   ├── EmailVerificationController.php      v0.5.0; GET /verify-email/{token} + POST /me/verify-email/resend (rate-limited)
│   │   ├── HomeController.php                    v0.4.0: points board + open/overdue counts
│   │   ├── HouseholdController.php              v0.5.0 ext: regenerate-code / leave / transfer (BL-3 atomic) / delete
│   │   ├── IcalFeedController.php               v0.3.2
│   │   └── PasswordResetController.php          v0.5.0; always-200 + 1.5s timing floor + dummy argon2id verify on miss (B4 + H-4); atomic single-use redeem (B6); Referrer-Policy on token routes (H-5)
│   ├── Household/HouseholdRepository.php
│   └── View/
│       ├── CsrfTwigExtension.php
│       └── NavContext.php
├── config/
│   ├── brand.php
│   ├── controllers.php
│   └── commands.php
├── db/schema.sql                                see docs/SCHEMA.md for the full version-tagged content
├── docs/
│   ├── CALENDAR.md
│   ├── CHORES.md                                v0.4.0
│   ├── ROUTES.md
│   └── SCHEMA.md
├── public/
│   ├── index.php                                Front controller — DI, middleware, run
│   └── .htaccess
├── templates/
│   ├── auth/{register,login}.twig
│   ├── calendar/                                v0.3.0+
│   │   ├── _cascade_confirm.twig                v0.3.1; time-shift dialog with affected-list
│   │   ├── _drop_confirm.twig                   v0.3.1; structural-change dialog with affected-list
│   │   ├── _stale_data.twig                    409 partial for optimistic-concurrency conflicts
│   │   ├── agenda.twig
│   │   ├── event_form.twig                      shared by new/edit
│   │   ├── month.twig
│   │   └── occurrence_edit.twig                 v0.3.1; single-occurrence override form
│   ├── chores/                                  v0.4.0
│   │   ├── chore_form.twig                       shared create/edit; member dropdown + confirm-delete
│   │   ├── index.twig                            points board + open list + Done section + Recurring section (v0.4.1)
│   │   └── schedule_form.twig                    v0.4.1; recurring-chore create/edit (recurrence fieldset + rotate/fixed)
│   ├── feed/                                    v0.3.2
│   │   ├── generated.twig                       raw token shown ONCE + referrer-no-referrer meta
│   │   └── settings.twig                        active-tokens roster + Generate + Revoke
│   ├── household/{setup,index}.twig
│   ├── _partials/household_switcher.twig
│   ├── home.twig
│   └── layout.twig                              brand + nav + flash slot + inline CSS
└── tests/
    ├── AppTestCase.php                          integration harness + exception → response routing
    ├── bootstrap.php                            SQLite :memory: + regex-translated production schema
    ├── Account/UserPreferenceRepositoryTest.php
    ├── Auth/{HouseholdAuthorizerTest,MishkaUserRepositoryTest}.php
    ├── Calendar/{EventRepositoryTest,MonthGridBuilderTest}.php
    ├── Chores/{ChoreRepositoryTest,ChoreScheduleRepositoryTest,ChoreScheduleGeneratorTest}.php
    ├── Controllers/{Auth,Calendar,Chores,ChoreSchedules,Home,Household}ControllerTest.php
    ├── Household/HouseholdRepositoryTest.php
    ├── Smoke/{HouseholdRepositoryPgSmoke,EventRepositoryPgSmoke,ChoreRepositoryPgSmoke,ChoreScheduleRepositoryPgSmoke,ChorePointsLedgerPgSmoke}Test.php
    └── View/NavContextTest.php
```

---

## Top-level design decisions

(Detail in `docs/SCHEMA.md`, `docs/CALENDAR.md`, and the per-component class docstrings.)

1. **Email as identifier, integer PK for FK targets** (v0.1). karhu's `UserRepositoryInterface` is keyed on an opaque string; mishka puts the email in that slot, and `users.id` is the canonical FK target.
2. **Lowercase-on-write email policy** (v0.1). Single UNIQUE constraint, no functional index.
3. **Race-free first-user-admin via sentinel row** (v0.1). Atomic `UPDATE` claims the seeded admin slot.
4. **Timing-attack-safe login** (v0.1). One `password_verify` call per branch, including a dummy hash for the unknown-email path.
5. **Logout clears the session cookie** (v0.1). `setcookie()` with expired timestamp after `Session::destroy()`.
6. **Controllers read both JSON and form-urlencoded bodies** (v0.1). Test harness sends JSON; browsers send urlencoded.
7. **N:M user → household** (v0.2). Divorced parents, foster carers, live-in nannies are 10-15% of real users.
8. **Race-free creator-as-owner; no auto-promote-first-joiner** (v0.2). Single-transaction create + owner-membership insert.
9. **Controller-level no-household guards** (v0.2). Karhu's middleware runs before route matching; path-string exemptions are fragile.
10. **Last-selected active household in `user_preferences`** (v0.2). Restored on login.
11. **Stale-session self-heal via ForbiddenException(redirectTo)** (v0.2). Kicked users land on /household/setup, not a 403.
12. **Multi-tab CSRF "just works"** (karhu v0.1.1). Token rotates only on session rotation, not every POST.
13. **Local-time + IANA timezone storage for events** (v0.3.0). UTC TIMESTAMPTZ drifts under DST. Recurr expands in the event's tz.
14. **Schema additive-only** (v0.3.0 includes inert `rrule` + `series_event_id` columns) so v0.3.1 doesn't need an ALTER.
15. **Optimistic concurrency on event edits** (v0.3.0). `_expected_updated_at` hidden field; 409 + stale-data partial on mismatch.
16. **POST whitelist** (v0.3.0). Calendar controller never accepts `series_event_id`, `timezone`, `created_by`, or system columns from form input.
17. **Pattern B for single-occurrence editing** (v0.3.1). `event_exceptions(event_id, original_starts_at, override_event_id NULL)` with RFC 5545 RECURRENCE-ID semantics in mind for the future iCal feed.
18. **Two-step DELETE for dropping overrides** (v0.3.1). FK CASCADE points `event_exceptions → events`, so deleting an exception row does NOT delete the override Event. `EventExceptionRepository::dropAllForEvent` deletes the override events first (CASCADE wipes the exception rows), then deletes the remaining cancellation rows.
19. **Cascade-on-series-edit with confirmation dialogs** (v0.3.1). Clean time-shifts cascade override `original_starts_at` by the same delta; structural rrule/all_day changes drop overrides with a list of what's affected. `_expected_exception_count` hidden field protects the dialog flow against another tab adding/removing exceptions mid-dialog.
20. **Defensive `series_event_id IS NULL` + `rrule IS NULL` filter** (v0.3.1) in `EventRepository::findInRangeForHousehold`. Override events would otherwise double-render through the one-off branch; recurring series would otherwise leak through the same branch alongside the RangeExpander.
21. **SHA-256 hashed iCal tokens with cap at 3** (v0.3.2). Raw 64-hex token shown to the user once; only the hash persists. Cap-at-3 auto-revokes the oldest active row on the 4th generate — bounded leak surface without forcing manual cleanup. `last_used_at` exposed in settings as a leak-detection signal.
22. **sabre/vobject not eluceo/ical for iCal serialisation** (v0.3.2). eluceo/ical 2.x cannot emit `RECURRENCE-ID`; overrides need it. sabre/vobject also parses iCal — door open for v0.5+ "subscribe to external calendar".
23. **Layered token-leak defences** (v0.3.2). `Referrer-Policy: no-referrer` on feed responses + `<meta name="referrer">` on the post-generate page + Caddy log-path redaction documented in INFRASTRUCTURE.md.
24. **Live points tally, no ledger** (v0.4.0). `pointsTallyForHousehold` sums completed chores by `COALESCE(completed_by, assigned_to)` — the doer — driven off current `household_members`. `ORDER BY MIN(joined_at)` so PostgreSQL's GROUP BY rule holds. Documented limitation: editing/deleting a completed chore or removing a member shifts the tally; a durable ledger is the v0.4.2+ path.
25. **Chores inert columns ship without a DB FK** (v0.4.0). `chores.schedule_id` is a bare `INTEGER` (no `REFERENCES`) because its v0.4.1 target table doesn't exist yet and the no-ALTER convention can't add the constraint later — integrity is app-enforced. No defensive `schedule_id IS NULL` list filter (templates live in a separate table, so v0.4.1 instances are first-class list rows, unlike the calendar's double-render risk).
26. **Overdue computed in PHP against the chore's own timezone** (v0.4.0), never SQL `NOW()`. NULL due = never overdue. Same wall-clock + IANA model as events; the predicate is duplicated in `ChoresController` and `HomeController` (no shared base).
27. **Recurring chores = template + generated instances** (v0.4.1). `chore_schedules` is the template; occurrences are generated as ordinary `chores` rows (Tody-style) so completion/points/overdue reuse v0.4.0. Generated lazily on view, bounded to a 14-day rolling horizon via a `generated_through` watermark + a 60-row cap — because recurr always expands from the anchor, an unclamped limit would either silently generate zero rows or explode.
28. **Rotation cursor is a durable id, not an index** (v0.4.1). `last_assigned_user_id` + a pure-function next-assignee survive member renumbering and concurrent lazy generation; the cursor advances only alongside a successful insert. `assignment_mode='fixed'` pins instead of rotating.
29. **`ChoreSchedulesController` scanned before `ChoresController`** (v0.4.1). The router matches sequentially, so the static `/chores/schedules` routes must precede `/chores/{id}`.
30. **Schedule edit refreshes upcoming; delete is app-coordinated** (v0.4.1). Edit deletes future-open occurrences + rewinds the watermark; delete drops open + detaches completed (`schedule_id` → NULL) because `chores.schedule_id` has no FK cascade.
31. **Durable points ledger replaces the live tally** (v0.4.2). `markDone` writes a `chore_points_ledger` row iff the guarded UPDATE transitioned the chore (`run()===1`), one UTC timestamp to both rows; `reopen` deletes it; no `UNIQUE(chore_id)` (reopen→recomplete). Editing/deleting a completed chore no longer mutates history (chore_id/credited_user_id SET NULL). Idempotent `NOT EXISTS` backfill in `schema.sql`.
32. **Weekly leaderboard windowed in PHP, compared in UTC** (v0.4.2). Monday 00:00 household tz → UTC string bound vs the ledger's UTC `completed_at`, so PG (TIMESTAMPTZ) and SQLite (TEXT) agree. Ranked `week_points DESC, MIN(joined_at)`.
33. **Pause via flag-table; participant pools via subset-table** (v0.4.2). `chore_schedules` can't gain columns (no ALTER), so pause = presence in `chore_schedule_pauses` (skipped in `generateForHousehold`, never inside `generateForSchedule`, so the watermark can't drift); a pool = rows in `chore_schedule_participants` (rotation cycles `listMembers ∩ pool`, empty intersection → unassigned). Both new tables carry real FKs.
34. **DST-safe week arithmetic centralised in `WeekWindow`** (v0.4.3). Monday 00:00 NZDT and Monday 00:00 NZST are NOT 168 UTC-hours apart (169 at the end of DST, 167 at the start), so naive `−7d` on a UTC string drifts by an hour across every transition and silently breaks streak walks. `WeekWindow` does every step in household tz via `->modify('-1 week')->setTime(0, 0, 0)`, converting to UTC only for the string representation. Both controllers + `Achievements` route through it.
35. **Badges + streaks are stateless** (v0.4.3). Derived per-render from the ledger; no `member_badges` table, no `earned_at` history. Badge criteria are pure functions over a stats array, returned from a method (not `const` — PHP rejects closures in constant expressions). Presentation (emoji + title) registered as the `badge_meta` Twig global, mirroring `brand`; the service never sees emoji.
36. **Host-header-injection guard via boot-required `APP_URL`** (v0.5.0). The unauthenticated `/password-reset` endpoint would otherwise be a phishing factory: forge `Host: evil.com`, request a reset for a victim, and SMTP-deliver `https://evil.com/password-reset/<token>`. Fix: `App\Mail\UrlBuilder` reads only `$_ENV['APP_URL']`; `public/index.php` validates the env var at boot (must match `^https?://`). No code path reads `$request->header('host')` for emailed URLs (B1).
37. **Token pattern reused** (v0.5.0). `email_verification_tokens` and `password_reset_tokens` mirror `ical_feed_tokens` byte-for-byte: 32 random bytes → 64-char hex shown ONCE → SHA-256 stored as `token_hash` with a UNIQUE index. One canonical token shape across the app reduces the bug surface.
38. **Atomic single-use redeem via guarded UPDATE** (v0.5.0). Both `password_reset_tokens` and `email_verification_tokens` redeem via `UPDATE … SET used_at = :now WHERE id AND used_at IS NULL AND expires_at > :now` and gate the post-redeem write on `rowCount === 1`. Two concurrent POSTs of the same token cannot both succeed (B6). Expiry math is in PHP via `gmdate('Y-m-d H:i:s')` because SQLite doesn't translate `NOW() + INTERVAL` (B3).
39. **Owner-transfer atomicity with FOR UPDATE on PG** (v0.5.0). The non-unique partial index `idx_household_members_role` can't enforce single-owner. `transferOwnership` opens a txn, runs `SELECT user_id, role FROM household_members WHERE household_id=:h AND user_id IN (:old,:new) FOR UPDATE` (no-op on SQLite — single-writer makes the lock implicit; PG-only syntax detected via PDO driver name), re-verifies inside the lock, then promote-first / demote-second with guarded UPDATEs. Two-owner intermediate state is degenerate-OK; zero-owner would be degenerate-bad and the rowCount check blocks it (B5 + round-4 BL-3).
40. **Session revocation via separate stamp table** (v0.5.0). `users` can't gain a `password_changed_at` column (additive-only schema), so `user_password_changes (user_id PK, password_changed_at)` is the stamp. `SessionRevocationGuard` middleware compares `Session::get('auth_time')` to the stamp; 4-permutation predicate handles missing-state combinations (legacy session pre-v0.5 = grandfather pass per user decision U-1, BL-1). The pinned-`$now` invariant (BL-2) — caller pins one `gmdate()` call and passes the SAME string to `updatePassword`'s stamp and `Session::set('auth_time')` — prevents the user from self-revoking on their own password change.
41. **App-layer rate limit via simple counter table** (v0.5.0). `email_send_attempts (kind CHECK IN ('password_reset_request','verify_resend'), ip_address NULL, user_id NULL, attempted_at)` records every issuance attempt; the controller does `countRecentByIp/ByUser(kind, key, 10min)` before issuing. Two independent buckets (5/10min/IP for reset, 3/10min/user for resend) so one bucket overflowing doesn't lock the other. Unbounded growth is fine at family-scale; a future prune job can DELETE old rows (H4).
42. **Soft-banner email verification with always-quiet copy** (v0.5.0). No app features are gated on `email_verified_at`. The layout banner reads "Please verify your email — [Resend]" regardless of SMTP outcome (user decision U-3); `email_verification_tokens.sent_at` distinguishes for ops, never for users. Family-friendly tone, no scary "we couldn't send" copy.
43. **Always-200 + 1.5s timing floor + dummy argon2id verify on miss** (v0.5.0). The hit path takes ~100–500ms (issue + Twig render + SMTP); the miss path was ~5ms. Three layers close enumeration: (a) identical body for hit/miss; (b) `usleep(max(0, 1_500_000 - elapsed_us))` floor; (c) on miss, run a throwaway `PasswordHasher::verify` against a precomputed dummy hash so the argon2id baseline cost lands on miss too (round-4 H-4 — defence in depth for SMTP-timeout edge cases that blow past the floor on hit). Tests use a 50ms floor for speed; `PasswordResetTimingTest` exercises the real 1.5s floor.
44. **`Referrer-Policy: no-referrer` on token-bearing routes** (v0.5.0, round-4 H-5). Both `/password-reset/{token}` GET responses (form + invalid page) and `/verify-email/{token}` GET set the header, preventing the raw token from leaking via `Referer` if the user clicks an external link from those pages.
45. **PWA manifest as the iOS Web Push unblocker** (v0.6.3). iOS Safari only delivers Web Push when the site is installed as a real PWA (`display: standalone`) — `<link rel="manifest">` + an `apple-touch-icon` + the `apple-mobile-web-app-*` meta tags are what flip "Add to Home Screen" from bookmark to PWA. Manifest `id: "/"` is locked forever (changing it orphans every existing install). Status bar style is `black` not `black-translucent`, because the latter renders content under the iPhone notch and we don't carry `viewport-fit=cover` + `env(safe-area-inset-top)` CSS retrofits. Manifest `theme_color` / `background_color` are snapshotted at install time; a 3-way drift test (`tests/View/ManifestTest.php`) keeps CSS vars, meta tag, and manifest field aligned. Icons are Lanczos upscales from a single 192×192 source — replaceable later without a version bump because static-asset bitmaps are versionless.

---

## Session keys

| Key | Type | Set when | Purpose |
|---|---|---|---|
| `user_id` (v0.1) | int | login, register | Canonical identity |
| `username` (v0.1) | string (email) | login, register | Display + satisfies Karhu `RequireRole` |
| `roles` (v0.1) | list&lt;string&gt; | login, register | Global roles from `system_roles` |
| `active_household_id` (v0.2) | int / absent | setup, switch, login (from `user_preferences`) | Scope for household-scoped queries |
| `active_household_role` (v0.2) | 'owner' / 'member' | same as above | UI gates (show invite code, show rename form) |
| `auth_time` (v0.5.0) | string (GMT 'Y-m-d H:i:s') | login, register, password change | Compared by `SessionRevocationGuard` to `user_password_changes.password_changed_at` |
| `email_verified_at` (v0.5.0) | ?string | login, register (null), verify-email click-through | Cached so `NavContext` doesn't re-query per render; null → soft verify banner |
| `flash_success` / `flash_error` (v0.5.0) | string | post-success / -error redirects | One-shot — `NavContext::forCurrentUser()` reads + clears for the next render |
| `_csrf_token` (v0.1) | string | first request | Set + verified by `Karhu\Middleware\Csrf` |

---

## Environment

| Variable | Required | Description |
|---|---|---|
| `DB_DSN` | yes | PDO DSN, e.g. `pgsql:host=192.168.4.9;port=5433;dbname=mishka` |
| `DB_USER` | yes | DB user |
| `DB_PASS` | yes | DB password |
| `APP_ENV` | no | `dev` (default) or `prod` |
| `APP_URL` | **yes (v0.5.0)** | Required at boot — used for absolute URLs in emails (B1 fix). Must match `^https?://`. |
| `MAIL_FROM_ADDRESS` | **yes (v0.5.0)** | Required at boot — From: address on outbound emails. |
| `MAIL_FROM_NAME` | no | From: display name (default `Mishka Den`). |
| `MAILER_DSN` | no | symfony/mailer transport DSN. Dev default is MailHog (`smtp://mailhog:1025?timeout=5`). Prod: Postmark recommended (`smtp://apikey:<token>@smtp.postmarkapp.com:587?timeout=5&encryption=tls`). `?timeout=5` keeps a request from hanging when SMTP is down. |

---

## Development

```bash
composer install
cp .env.example .env
php vendor/bjornbasar/karhu/bin/karhu migrate
composer test           # PHPUnit (SQLite in-memory)
composer analyse        # PHPStan level 6
composer serve          # http://localhost:8080

# PG smoke locally against your real database:
DB_DSN='pgsql:host=…;dbname=…' DB_USER=… DB_PASS=… \
  vendor/bin/phpunit --filter 'PgSmoke'
```

CI runs two jobs: `test` (SQLite in-memory + PHPStan) and `pg-smoke` (postgres:16 service container; runs anything matching `PgSmoke`).

---

## Future work

- **Chores polish (later):** penalty/negative points; daily streaks alongside weekly; persistent badge history / pluggable registry / `/badges` page. (Ledger, leaderboard, pause, participant pools shipped in v0.4.2; badges + weekly streaks in v0.4.3.)
- **Household lifecycle gaps:** leave/transfer/delete household, regenerate invite code, invite via email, household timezone editor.
- **Email verification, password change/reset.**
- **Profile editing.**
- **Real migrations framework** — keep deferring. Schema stays additive.
- **"Subscribe to external calendar"** — sabre/vobject parses iCal; v0.5+.

---

## Related Repos

- [karhu](https://github.com/bjornbasar/karhu) — the microframework
- [karhu-db](https://github.com/bjornbasar/karhu-db) — PDO wrapper + active-record
- [karhu-view](https://github.com/bjornbasar/karhu-view) — view-engine bridge (Twig adapter)
- [karhu-skeleton](https://github.com/bjornbasar/karhu-skeleton) — starter template
- [istrbuddy](https://github.com/bjornbasar/istrbuddy) — the other karhu dogfood (issue tracker)
- [hartza](https://github.com/bjornbasar/hartza) — sibling personal app (household budget); inspired mishka's registration + household-join pattern
