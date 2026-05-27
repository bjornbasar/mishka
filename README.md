# Mishka Den — the den mother for your family

A family hub web app: one place for the household calendar, chores, lists, and the everyday coordination a family needs. Built on the [karhu](https://github.com/bjornbasar/karhu) PHP microframework.

> *Mishka* is Russian for "little bear / cub". A **den** is where cubs live. A **den mother** (Cub Scouts) is the adult who keeps a troop of cubs organised. Mishka the app is the den mother for your family.

## Status

**v0.4.2** — chores polish. A durable points ledger (history survives edits/deletes), a weekly + all-time leaderboard, pause/resume for recurring chores, and per-chore rotation pools.

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

## What works in v0.4.2

Carried forward from v0.1–v0.4.1: registration + login, households (N:M membership + invite codes), the full household calendar (month grid, agenda, RRULE recurrence, single-occurrence editing, cascade dialogs), per-user signed iCal feed, chores (one-off + recurring with round-robin/fixed assignment, overdue badges, Done section).

**New in v0.4.2:**
- **Durable points ledger** — completing a chore writes an immutable points record. Editing a completed chore's points, or deleting it, no longer rewrites history; the ledger row survives. (Reopen still un-credits — that's a real undo.)
- **Weekly + all-time leaderboard** — the board (on /chores and the home page) ranks members by points earned **this week** (resets Monday, household time) with all-time alongside.
- **Pause / resume** — pause a recurring chore to stop generating new occurrences without deleting it; resume picks up from now (no backlog).
- **Per-chore rotation pools** — a recurring chore can rotate across a chosen subset of members (e.g. just the older kids) instead of everyone.

## Roadmap

- **Chores polish (later):** penalty/negative points, badges/streaks.
- Later: leave/transfer/delete household, regenerate invite code, profile editing, email verification, password change/reset, per-household feeds, subscribe-to-external-calendar.

## Docs

- [DOCS.md](DOCS.md) — full architecture, schema, routes, design decisions
- [karhu](https://github.com/bjornbasar/karhu) — the microframework this is built on

## License

MIT
