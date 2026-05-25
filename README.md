# Mishka Den — the den mother for your family

A family hub web app: one place for the household calendar, chores, lists, and the everyday coordination a family needs. Built on the [karhu](https://github.com/bjornbasar/karhu) PHP microframework.

> *Mishka* is Russian for "little bear / cub". A **den** is where cubs live. A **den mother** (Cub Scouts) is the adult who keeps a troop of cubs organised. Mishka the app is the den mother for your family.

## Status

**v0.3.0** — calendar lands: month grid + agenda + create/edit/delete one-off events. Recurrence + iCal feed are next (v0.3.1 → v0.3.2 release train).

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

## What works in v0.3.0

Carried forward from v0.1/v0.2: registration + login (timing-safe), logout (cookie-clearing), CSRF (multi-tab friendly), households (N:M membership, 8-char invite codes, owner-managed, switcher dropdown), stale-session self-heal.

**New in v0.3.0:**
- Household calendar at `/calendar` — month grid + agenda fallback
- Create / edit / delete one-off events (title, datetime, location, description, all-day flag)
- DST-safe time storage (local + IANA timezone)
- Optimistic-concurrency on event edits — two-tab edits land cleanly on a "View current event" page instead of overwriting each other

## Roadmap

- **v0.3.1** — RRULE recurrence + single-occurrence editing (cancel + override an individual occurrence of a recurring event)
- **v0.3.2** — Per-user signed iCal feed for phone-calendar subscription
- **v0.4** — Chores: per-household list, round-robin assignment, kid-friendly points, in-app overdue badges
- Later: leave/transfer/delete household, regenerate invite code, profile editing, email verification, password change/reset

## Docs

- [DOCS.md](DOCS.md) — full architecture, schema, routes, design decisions
- [karhu](https://github.com/bjornbasar/karhu) — the microframework this is built on

## License

MIT
