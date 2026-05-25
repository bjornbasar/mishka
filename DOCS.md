# Mishka Den — Project Documentation

**Version:** 0.2.0 | **License:** MIT | **PHP:** >=8.4

A family hub web app — the den mother for your family. First real-world dogfood of the [karhu](https://github.com/bjornbasar/karhu) PHP microframework.

---

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Language | PHP 8.4+ |
| Framework | karhu ^0.1.1 (HTTP, DI, middleware, attribute routing) |
| Database | PostgreSQL via karhu-db (PDO) |
| Views | Twig via karhu-view |
| Auth | karhu's argon2id PasswordHasher + Rbac + Session/CSRF middleware + `Karhu\Error\ForbiddenException` (v0.1.1) |
| Env loader | vlucas/phpdotenv ^5.6 |
| Testing | PHPUnit 11 — SQLite in-memory for unit/integration, plus a PostgreSQL smoke job in CI |
| Static analysis | PHPStan level 6 |
| CI | GitHub Actions (`ubuntu-latest`, free for public repos); two jobs (SQLite test + PG smoke) |

---

## Directory Structure

```
mishka/
├── app/
│   ├── Account/UserPreferenceRepository.php   user_preferences upsert (last_household_id, future prefs)
│   ├── Auth/
│   │   ├── HouseholdAuthorizer.php            requireMember + requireOwner; throws Karhu ForbiddenException
│   │   └── MishkaUserRepository.php           Email-PK adapter to karhu's UserRepositoryInterface
│   ├── Commands/MigrateCommand.php            bin/karhu migrate
│   ├── Controllers/
│   │   ├── AuthController.php                 /register, /login, /logout + active-household restoration
│   │   ├── HomeController.php                 / (anonymous pitch / logged-in home / redirect)
│   │   └── HouseholdController.php            /household/setup, /household, /household/rename, /household/members/{id}/remove, /household/switch
│   ├── Household/HouseholdRepository.php      Households + N:M membership + join-code generation
│   └── View/
│       ├── CsrfTwigExtension.php              {{ csrf_field() }}, {{ csrf_token() }}
│       └── NavContext.php                     Shared layout context (session_email, households, active_household)
├── config/
│   ├── brand.php                              Name + tagline (Twig global)
│   ├── controllers.php                        Controller registry
│   └── commands.php                           CLI command registry
├── db/
│   └── schema.sql                             v0.1 + v0.2 schema (idempotent, no ALTER TABLE)
├── public/
│   ├── index.php                              Front controller (DI, middleware, run)
│   └── .htaccess                              Apache rewrite rules
├── templates/
│   ├── layout.twig                            Brand + nav (with switcher partial) + flash slot
│   ├── home.twig                              Anonymous landing / logged-in home with household name
│   ├── auth/{register,login}.twig
│   ├── household/{setup,index}.twig
│   └── _partials/household_switcher.twig      Renders only when households|length > 1
└── tests/
    ├── bootstrap.php                          SQLite :memory: + regex-translated production schema
    ├── AppTestCase.php                        Integration harness + exception → response routing
    ├── Account/UserPreferenceRepositoryTest.php
    ├── Auth/{HouseholdAuthorizerTest,MishkaUserRepositoryTest}.php
    ├── Controllers/{Auth,Home,Household}ControllerTest.php
    ├── Household/HouseholdRepositoryTest.php
    ├── Smoke/HouseholdRepositoryPgSmokeTest.php   PG-only tests; skipped when DB_DSN is not pgsql
    └── View/NavContextTest.php
```

---

## Key Design Decisions

### 1. Email as identifier, integer PK for FK targets (v0.1)

karhu's `UserRepositoryInterface` is keyed on an opaque `username` string. Mishka puts the email in that slot, and adds `users.id SERIAL PRIMARY KEY` for FK relationships.

### 2. Lowercase-on-write email policy (v0.1)

`MishkaUserRepository::create()` and `findByUsername()` both lowercase the input. Plain `UNIQUE` constraint — no functional index, no `CITEXT`. `VARCHAR(320)` matches RFC 5321 max.

### 3. Race-free first-user-admin via sentinel row (v0.1)

Schema seeds a system user `(id=0, email='__system__')` and a sentinel `system_roles` row `(user_id=0, role='admin')`. First registration atomically claims via `UPDATE … WHERE user_id = 0`. Excluded from all household queries via `user_id > 0`.

### 4. Timing-attack-safe login (v0.1)

`AuthController::handleLogin` runs exactly one `password_verify` call in every branch — including against a throwaway dummy hash on the unknown-email path — so timing can't be used to enumerate registered emails.

### 5. Logout clears the session cookie (v0.1)

`Karhu\Middleware\Session::destroy()` empties `$_SESSION` but not the browser cookie. `AuthController::logout` explicitly calls `setcookie()` with an expired timestamp.

### 6. Controllers read both JSON and form-urlencoded bodies (v0.1)

Browsers POST form-urlencoded; integration tests POST JSON. Every POST handler reads both via the dual pattern.

### 7. N:M user → household, not 1:1 (v0.2)

A family hub must model divorced parents (kids in two households), foster carers, live-in nannies, adult children helping aging parents. These are ~10-15% of real users — not edge cases. Migrating to N:M after events/chores have FK'd to a 1:1 column is ruinous; doing it now is one extra table.

### 8. Race-free creator-as-owner; no auto-promote-first-joiner (v0.2)

`action=create` runs INSERT household + INSERT owner membership in one transaction. `action=join` always assigns `role='member'`, regardless of current membership count. This drops the hartza "first joiner of empty household = owner" rule, which has a TOCTOU race when two users join simultaneously.

### 9. Controller-level no-household guards (not middleware) (v0.2)

karhu's middleware pipeline runs *before* route matching, so middleware can't see route params. Path-string exemption lists are fragile (trailing slashes, query strings). Each handler that needs an active household calls `if (!Session::has('active_household_id')) return redirect('/household/setup')` at the top. Three lines per handler, explicit, greppable.

### 10. Last-selected active household persisted in `user_preferences` (v0.2)

`user_preferences.last_household_id` is bumped on every `/household/switch` and on each setup completion. Login restores it (with fallback to first membership if the preference points at a household the user is no longer in). Persisted in a separate table — not a column on `users` — because SQLite doesn't support `ALTER TABLE ADD COLUMN IF NOT EXISTS` and the production `schema.sql` is run idempotently in test mode.

### 11. Stale-session self-heal in HouseholdAuthorizer (v0.2)

When a user's session `active_household_id` points at a household they're no longer in (they got kicked since login), `HouseholdAuthorizer::requireMember` clears the session keys and throws karhu v0.1.1's `Karhu\Error\ForbiddenException(redirectTo: '/household/setup')`. The framework's `ExceptionHandler` renders this as a 302 redirect — clean recovery from a state that would otherwise 403 forever.

### 12. Multi-tab CSRF "just works" (v0.2 + karhu v0.1.1)

karhu v0.1.0 regenerated the CSRF token after every successful POST verification, which broke multi-tab workflows. karhu v0.1.1 dropped that behavior — the token now rotates only on session rotation (login, logout) or explicit `Csrf::regenerate()` calls. mishka v0.2 has five POST surfaces (rename, kick, switch, setup, logout); without this fix, normal two-tab use would 403 frequently.

---

## Schema (v0.1 + v0.2)

```sql
-- v0.1
CREATE TABLE IF NOT EXISTS users (
    id                SERIAL PRIMARY KEY,
    email             VARCHAR(320) NOT NULL UNIQUE,
    password_hash     VARCHAR(255) NOT NULL,
    display_name      VARCHAR(120) NOT NULL DEFAULT '',
    email_verified_at TIMESTAMPTZ NULL,
    last_login_at     TIMESTAMPTZ NULL,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS system_roles (
    user_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role     VARCHAR(32) NOT NULL,
    PRIMARY KEY (user_id, role)
);

-- v0.1 sentinel rows (admin claim race-free pattern; system user excluded from household queries)
INSERT INTO users (id, email, password_hash, display_name)
VALUES (0, '__system__', '*disabled*', 'System')
ON CONFLICT (id) DO NOTHING;
INSERT INTO system_roles (user_id, role) VALUES (0, 'admin')
ON CONFLICT (user_id, role) DO NOTHING;

-- v0.2
CREATE TABLE IF NOT EXISTS households (
    id         SERIAL PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    join_code  CHAR(8) NOT NULL UNIQUE,
    timezone   VARCHAR(64) NOT NULL DEFAULT 'Pacific/Auckland',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS household_members (
    household_id INTEGER NOT NULL REFERENCES households(id) ON DELETE CASCADE,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role         VARCHAR(32) NOT NULL CHECK (role IN ('owner', 'member')),
    joined_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (household_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_household_members_role
    ON household_members(household_id) WHERE role = 'owner';  -- partial index

CREATE TABLE IF NOT EXISTS user_preferences (
    user_id           INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    last_household_id INTEGER NULL REFERENCES households(id) ON DELETE SET NULL,
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

---

## Routes

| Method | Path | Behaviour |
|---|---|---|
| GET  | / | Anonymous: pitch + CTAs. Logged in w/ household: home with name + Manage link. Logged in w/o household: redirect /household/setup. |
| GET  | /register | Show form (redirect / if logged in) |
| POST | /register | Validate, create user, auto-login, redirect /household/setup |
| GET  | /login | Show form (redirect / if logged in) |
| POST | /login | Timing-safe verify, set session, restore active_household from user_preferences, redirect / |
| POST | /logout | Destroy session, clear cookie, redirect /login |
| GET  | /household/setup | Create/Join form (redirect /household if already has active) |
| POST | /household/setup | action=create OR action=join; sets session + writes user_preferences; redirect / |
| GET  | /household | Settings; owner sees invite code + rename + kick buttons |
| POST | /household/rename | Owner-only (requireOwner) |
| POST | /household/members/{userId}/remove | Owner-only; blocks owner + self |
| POST | /household/switch | Switch active household (requireMember on target); persists in user_preferences |

---

## Session keys

| Key | Type | Set when | Purpose |
|---|---|---|---|
| `user_id` | int | login, register | Canonical identity |
| `username` | string (email) | login, register | Display + satisfies Karhu `RequireRole` |
| `roles` | list&lt;string&gt; | login, register | Global roles from `system_roles` |
| `active_household_id` | int / absent | setup, switch, login (restored) | Scope for household-scoped queries |
| `active_household_role` | 'owner' / 'member' | same as above | UI gates (show invite code, show rename form) |
| `_csrf_token` | string | first request | Set + verified by `Karhu\Middleware\Csrf` |

`user_preferences.last_household_id` (DB) persists the active household across sessions.

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
php vendor/bjornbasar/karhu/bin/karhu migrate       # apply schema
composer test                                       # PHPUnit (SQLite in-memory)
composer analyse                                    # PHPStan level 6
composer serve                                      # http://localhost:8080

# PG smoke locally (against your real database):
DB_DSN='pgsql:host=…;dbname=…' DB_USER=… DB_PASS=… \
  vendor/bin/phpunit --filter HouseholdRepositoryPgSmoke
```

CI runs two jobs:
- **test** (SQLite in-memory + PHPStan) — fast, always runs
- **pg-smoke** (postgres:16 service container) — verifies PG-specific behaviour (SERIAL, TIMESTAMPTZ, CHECK constraints, partial indexes, ON DELETE CASCADE, UNIQUE violation)

---

## Future work

- **v0.3 Calendar:** events with RRULE recurrence, iCal feed export, per-event assignments. Phone calendar apps handle reminders via the feed.
- **v0.4 Chores:** chores + RRULE recurrence + round-robin assignment ("oldest most-recent-completion" picker), kid-friendly points snapshotted at completion time, in-app overdue badge. No notifications.
- **Leave/transfer/delete household, regenerate invite code, invite via email, household timezone editor** — household lifecycle gaps in v0.2.
- **Email verification + password change/reset** — schema's `email_verified_at` is ready; no flow yet.
- **Profile editing** — display name, email change with re-verification.
- **Real migrations framework** (Phinx or hand-rolled) — deferred until the first ALTER TABLE is genuinely needed.

---

## Related Repos

- [karhu](https://github.com/bjornbasar/karhu) — the microframework
- [karhu-db](https://github.com/bjornbasar/karhu-db) — PDO wrapper + active-record
- [karhu-view](https://github.com/bjornbasar/karhu-view) — view-engine bridge (Twig adapter)
- [karhu-skeleton](https://github.com/bjornbasar/karhu-skeleton) — starter template
- [istrbuddy](https://github.com/bjornbasar/istrbuddy) — the other karhu dogfood (issue tracker)
- [hartza](https://github.com/bjornbasar/hartza) — sibling personal app (household budget); inspired mishka's open-registration + household-join pattern
