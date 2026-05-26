# Mishka Den — the den mother for your family

A family hub web app: one place for the household calendar, chores, lists, and the everyday coordination a family needs. Built on the [karhu](https://github.com/bjornbasar/karhu) PHP microframework.

> *Mishka* is Russian for "little bear / cub". A **den** is where cubs live. A **den mother** (Cub Scouts) is the adult who keeps a troop of cubs organised. Mishka the app is the den mother for your family.

## Status

**v0.4.0** — chores. A per-household task list with assignment, kid-friendly points, and an in-app overdue badge. Mark chores done to credit the doer; the points board shows on the chores page and the home landing.

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

## What works in v0.4.0

Carried forward from v0.1–v0.3.2: registration + login (timing-safe), logout, CSRF (multi-tab friendly), households (N:M membership, 8-char invite codes, owner-managed), stale-session self-heal, household calendar (month grid + agenda + optimistic-concurrency edits, RRULE recurrence, single-occurrence cancel/override, cascade dialogs), per-user signed iCal feed.

**New in v0.4.0:**
- **Per-household chore list** — create / edit / delete chores with an optional due date and an optional assignee (any household member). Any member can act on any chore (the calendar's trust model); delete asks for confirmation.
- **Done / reopen** — marking a chore done is idempotent and credits the doer; reopen un-credits.
- **Kid-friendly points** — each chore carries a point value; a per-member all-time tally shows on the chores page and the home landing. (Simple live tally — see [docs/CHORES.md](docs/CHORES.md) for the documented limitations and the v0.4.2+ ledger path.)
- **Overdue badge** — chores past their due date (computed in the household's timezone; no-due chores never go overdue) are flagged in-app.
- **Done section** — completed chores collapse into a "Done" list (most-recently-done first), keeping the active list clean.

## Roadmap

- **v0.4.1** — Recurring chores (RRULE, reusing the calendar's translator) + round-robin assignment across all household members.
- Later: durable points ledger / leaderboards, leave/transfer/delete household, regenerate invite code, profile editing, email verification, password change/reset, per-household feeds, subscribe-to-external-calendar.

## Docs

- [DOCS.md](DOCS.md) — full architecture, schema, routes, design decisions
- [karhu](https://github.com/bjornbasar/karhu) — the microframework this is built on

## License

MIT
