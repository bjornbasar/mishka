# Mishka Den — Project Documentation

**Version:** 0.3.1 | **License:** MIT | **PHP:** >=8.4

A family hub web app — the den mother for your family. First real-world dogfood of the [karhu](https://github.com/bjornbasar/karhu) PHP microframework.

This file is the top-level overview. Detail lives in `docs/`:

- **[docs/SCHEMA.md](docs/SCHEMA.md)** — full database schema, every table, design notes per release
- **[docs/ROUTES.md](docs/ROUTES.md)** — full route table grouped by feature
- **[docs/CALENDAR.md](docs/CALENDAR.md)** — v0.3 calendar design (time model, month grid, optimistic concurrency, planned v0.3.1/v0.3.2 sections)

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
│   ├── Auth/
│   │   ├── HouseholdAuthorizer.php             requireMember + requireOwner; throws ForbiddenException
│   │   └── MishkaUserRepository.php
│   ├── Calendar/                                v0.3.0+
│   │   ├── ConcurrentUpdateException.php       optimistic-concurrency signal
│   │   ├── EventRepository.php                  events CRUD; defensive series_event_id IS NULL filter
│   │   └── MonthGridBuilder.php                 6×7 grid + slot assignment for multi-day pills
│   ├── Commands/MigrateCommand.php
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── CalendarController.php               v0.3.0+
│   │   ├── HomeController.php
│   │   └── HouseholdController.php
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
│   ├── ROUTES.md
│   └── SCHEMA.md
├── public/
│   ├── index.php                                Front controller — DI, middleware, run
│   └── .htaccess
├── templates/
│   ├── auth/{register,login}.twig
│   ├── calendar/                                v0.3.0+
│   │   ├── _stale_data.twig                    409 partial for optimistic-concurrency conflicts
│   │   ├── agenda.twig
│   │   ├── event_form.twig                      shared by new/edit
│   │   └── month.twig
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
    ├── Controllers/{Auth,Calendar,Home,Household}ControllerTest.php
    ├── Household/HouseholdRepositoryTest.php
    ├── Smoke/{HouseholdRepositoryPgSmoke,EventRepositoryPgSmoke}Test.php
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

---

## Session keys

| Key | Type | Set when | Purpose |
|---|---|---|---|
| `user_id` (v0.1) | int | login, register | Canonical identity |
| `username` (v0.1) | string (email) | login, register | Display + satisfies Karhu `RequireRole` |
| `roles` (v0.1) | list&lt;string&gt; | login, register | Global roles from `system_roles` |
| `active_household_id` (v0.2) | int / absent | setup, switch, login (from `user_preferences`) | Scope for household-scoped queries |
| `active_household_role` (v0.2) | 'owner' / 'member' | same as above | UI gates (show invite code, show rename form) |
| `_csrf_token` (v0.1) | string | first request | Set + verified by `Karhu\Middleware\Csrf` |

---

## Environment

| Variable | Required | Description |
|---|---|---|
| `DB_DSN` | yes | PDO DSN, e.g. `pgsql:host=192.168.4.9;port=5433;dbname=mishka` |
| `DB_USER` | yes | DB user |
| `DB_PASS` | yes | DB password |
| `APP_ENV` | no | `dev` (default) or `prod` |
| `APP_URL` | no | Base URL, used in absolute links |

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

- **v0.3.1 calendar:** RRULE recurrence + single-occurrence editing (cancel + override). See `docs/CALENDAR.md`.
- **v0.3.2 calendar:** Per-user signed iCal feed URL via `sabre/vobject`. See `docs/CALENDAR.md`.
- **v0.4 chores:** Per-household chore list, RRULE recurrence (reuses v0.3.1's translator), round-robin assignment, kid-friendly points, in-app overdue badge. No notifications.
- **Household lifecycle gaps:** leave/transfer/delete household, regenerate invite code, invite via email, household timezone editor.
- **Email verification, password change/reset.**
- **Profile editing.**
- **Real migrations framework** — keep deferring. Schema stays additive across v0.3.
- **"Subscribe to external calendar"** — sabre/vobject parses iCal; v0.5+.

---

## Related Repos

- [karhu](https://github.com/bjornbasar/karhu) — the microframework
- [karhu-db](https://github.com/bjornbasar/karhu-db) — PDO wrapper + active-record
- [karhu-view](https://github.com/bjornbasar/karhu-view) — view-engine bridge (Twig adapter)
- [karhu-skeleton](https://github.com/bjornbasar/karhu-skeleton) — starter template
- [istrbuddy](https://github.com/bjornbasar/istrbuddy) — the other karhu dogfood (issue tracker)
- [hartza](https://github.com/bjornbasar/hartza) — sibling personal app (household budget); inspired mishka's registration + household-join pattern
