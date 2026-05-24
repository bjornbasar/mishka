# Mishka Den — Project Documentation

**Version:** 0.1.0 | **License:** MIT | **PHP:** >=8.3

A family hub web app — the den mother for your family. First real-world dogfood of the [karhu](https://github.com/bjornbasar/karhu) PHP microframework.

---

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Language | PHP 8.3+ |
| Framework | karhu ^0.1 (HTTP, DI, middleware, attribute routing) |
| Database | PostgreSQL via karhu-db (PDO) |
| Views | Twig via karhu-view |
| Auth | karhu's argon2id PasswordHasher + Rbac + Session/CSRF middleware |
| Env loader | vlucas/phpdotenv ^5.6 |
| Testing | PHPUnit 11 (SQLite in-memory for repo tests) |
| Static analysis | PHPStan level 6 |
| CI | GitHub Actions (`ubuntu-latest`, free for public repos) |

---

## Directory Structure

```
mishka/
├── app/
│   ├── Auth/MishkaUserRepository.php       Email-PK adapter to karhu's UserRepositoryInterface
│   ├── Commands/MigrateCommand.php          bin/karhu migrate
│   ├── Controllers/{Home,Auth}Controller.php
│   └── View/CsrfTwigExtension.php           {{ csrf_field() }}, {{ csrf_token() }}
├── config/
│   ├── brand.php                            Name + tagline (Twig global)
│   ├── controllers.php                      Controller registry
│   └── commands.php                         CLI command registry
├── db/
│   └── schema.sql                           v0.1 schema (users + system_roles + sentinel)
├── public/
│   ├── index.php                            Front controller (DI, middleware, run)
│   └── .htaccess                            Apache rewrite rules
├── templates/
│   ├── layout.twig                          Base layout (brand + nav + flash + logout form)
│   ├── home.twig                            Landing
│   └── auth/{register,login}.twig
└── tests/
    ├── bootstrap.php                        SQLite :memory: + portable schema
    ├── AppTestCase.php                      Integration harness (mirrors istrbuddy)
    ├── Auth/MishkaUserRepositoryTest.php
    └── Controllers/AuthControllerTest.php
```

---

## Key Design Decisions

### 1. Email as identifier, integer PK for FK targets

karhu's `UserRepositoryInterface` is keyed on an opaque `username` string. Mishka puts the email in that slot, and adds `users.id SERIAL PRIMARY KEY` for future FK relationships (households, posts, etc.). `MishkaUserRepository` adapts the karhu contract — `findByUsername(string $email)` returns `{username: email, password_hash, roles}` while `findById(int)` is the mishka-internal way to look up by PK.

### 2. Lowercase-on-write email policy

`MishkaUserRepository::create()` and `findByUsername()` both lowercase the input. The column has a plain `UNIQUE` constraint — no functional index, no `CITEXT`. One policy, enforced in code. `VARCHAR(320)` matches RFC 5321 max.

### 3. Race-free first-user-admin via sentinel row

Schema seeds a system user `(id=0, email='__system__', …)` and a sentinel `system_roles` row `(user_id=0, role='admin')`. `__system__` is intentionally non-RFC so registration validation can never produce it. `create()` does an atomic UPDATE: `UPDATE system_roles SET user_id = :new_id WHERE role = 'admin' AND user_id = 0`. If 1 row affected → admin claimed; otherwise → member. No race, works the same on PG and SQLite.

### 4. Timing-attack-safe login

`Karhu\Auth\Rbac::authenticate()` short-circuits when the user is not found, leaking a timing oracle. `AuthController::handleLogin` bypasses this by calling `findByUsername` directly and running a dummy `password_verify` against a known throwaway hash when the user is missing. The dummy hash is computed once at bootstrap and injected via container factory (auto-wiring can't inject scalars).

### 5. Logout clears the session cookie

`Karhu\Middleware\Session::destroy()` empties `$_SESSION` but doesn't delete the browser cookie. `AuthController::logout` explicitly calls `setcookie(session_name(), '', time()-3600, …)` after `Session::destroy()`. A regression test asserts the `Set-Cookie` expiry header.

### 6. Controllers read both JSON and form-urlencoded bodies

Browsers POST form-urlencoded; integration tests POST JSON (lifted from istrbuddy's harness). Every POST handler uses the dual pattern: `$body = is_array($request->body()) ? $request->body() : []; $email = (string) ($body['email'] ?? $request->post('email'));`.

### 7. `system_roles` instead of `user_roles`

When households arrive, household-scoped roles will need their own table. Naming the global-roles table `system_roles` from day one prevents future ambiguity.

### 8. Open registration in v0.1; household gating later

The first feature is just user auth so we don't conflate scopes. The schema has the future-proofing columns (`email_verified_at`, `last_login_at`) ready.

---

## Schema (v0.1)

```sql
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

CREATE INDEX IF NOT EXISTS idx_system_roles_user_id ON system_roles(user_id);

INSERT INTO users (id, email, password_hash, display_name)
VALUES (0, '__system__', '*disabled*', 'System')
ON CONFLICT (id) DO NOTHING;

INSERT INTO system_roles (user_id, role) VALUES (0, 'admin')
ON CONFLICT (user_id, role) DO NOTHING;
```

---

## Routes

| Method | Path | Behaviour |
|---|---|---|
| GET  | / | Landing page (Twig) |
| GET  | /register | Show registration form (redirect / if already logged in) |
| POST | /register | Validate, create user, claim admin sentinel atomically, auto-login, redirect / |
| GET  | /login | Show login form (redirect / if already logged in) |
| POST | /login | Timing-safe verify, set session, record last_login_at, redirect / |
| POST | /logout | Destroy session, clear cookie, redirect /login |

---

## Session keys

| Key | Type | Purpose |
|---|---|---|
| `user_id` | int | Canonical identity for FK joins and internal lookups |
| `username` | string (email) | Display + satisfies `Karhu\Middleware\RequireRole` (reads from `username` key) |
| `roles` | list<string> | Global roles from `system_roles` |
| `_csrf_token` | string | Set + verified by `Karhu\Middleware\Csrf` |

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
./vendor/bin/karhu migrate       # apply schema
composer test                     # PHPUnit
composer analyse                  # PHPStan level 6
composer serve                    # http://localhost:8080
```

Tests run against SQLite in-memory; production runs against PostgreSQL. The portable subset of SQL in the repo (no `array_agg`, no PG-only types — `json_agg` + `json_group_array`) works on both.

---

## Future work

- **Households:** create/join with 8-char invite codes (mirrors hartza). Adds `households` + `household_members` tables; `MishkaUserRepository` grows household-scoped role lookups.
- **Email verification:** populates `email_verified_at`; gates household creation.
- **Password change + reset:** authenticated change in profile; reset via email token.
- **Profile editing:** display name, email change with re-verification.
- **Admin UI:** role management once roles are non-trivial.
- **Production deploy:** Dockerfile + compose entry + CI deploy job. SOPS-encrypted `.env`.
- **PG-side integration smoke test:** spin up `postgres:16` service in CI to catch SQLite-vs-PG divergences.

---

## Related Repos

- [karhu](https://github.com/bjornbasar/karhu) — the microframework
- [karhu-db](https://github.com/bjornbasar/karhu-db) — PDO wrapper + active-record
- [karhu-view](https://github.com/bjornbasar/karhu-view) — view-engine bridge (Twig adapter)
- [karhu-skeleton](https://github.com/bjornbasar/karhu-skeleton) — starter template
- [istrbuddy](https://github.com/bjornbasar/istrbuddy) — the other karhu dogfood (issue tracker)
- [hartza](https://github.com/bjornbasar/hartza) — sibling personal app (household budget); inspired mishka's open-registration + household-join pattern
