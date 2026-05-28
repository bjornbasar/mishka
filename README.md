# Mishka Den — the den mother for your family

A family hub web app: one place for the household calendar, chores, lists, and the everyday coordination a family needs. Built on the [karhu](https://github.com/bjornbasar/karhu) PHP microframework.

> *Mishka* is Russian for "little bear / cub". A **den** is where cubs live. A **den mother** (Cub Scouts) is the adult who keeps a troop of cubs organised. Mishka the app is the den mother for your family.

## Status

**v0.4.3** — chores gamification. Kid-motivating badges and weekly streaks on the leaderboard, all derived from the v0.4.2 ledger — no schema changes.

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

## What works in v0.4.3

Carried forward from v0.1–v0.4.2: registration + login, households (N:M membership + invite codes), the full household calendar, per-user signed iCal feed, chores (one-off + recurring with round-robin/fixed assignment, overdue badges, durable points ledger, weekly + all-time leaderboard, pause/resume, per-chore rotation pools).

**New in v0.4.3:**
- **Kid-friendly badges** — six escalating badges on the leaderboard, earned the moment the criterion is met: first chore 🌱, 10 chores ⭐, 50 chores 🏅, 100 points 💯, 500 points 🏆, four-week streak 🔥. Hover any badge for the description.
- **Weekly streaks** — a 🔥 N counter next to each member's name shows how many consecutive weeks (Monday in the household timezone) they've completed at least one chore. Forgiving inside the current week; resets only after a fully missed week. DST-safe (NZDT↔NZST transitions don't break the count).
- **No new tables** — everything derived from the v0.4.2 ledger. Two new repo queries, one new pure-PHP service.

## Roadmap

- **Chores polish (later):** penalty/negative points, daily streaks alongside weekly, persistent badge history + dedicated /badges page, pluggable badge registry.
- Later: leave/transfer/delete household, regenerate invite code, profile editing, email verification, password change/reset, per-household feeds, subscribe-to-external-calendar.

## Docs

- [DOCS.md](DOCS.md) — full architecture, schema, routes, design decisions
- [karhu](https://github.com/bjornbasar/karhu) — the microframework this is built on

## License

MIT
