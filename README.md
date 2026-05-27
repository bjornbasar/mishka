# Mishka Den — the den mother for your family

A family hub web app: one place for the household calendar, chores, lists, and the everyday coordination a family needs. Built on the [karhu](https://github.com/bjornbasar/karhu) PHP microframework.

> *Mishka* is Russian for "little bear / cub". A **den** is where cubs live. A **den mother** (Cub Scouts) is the adult who keeps a troop of cubs organised. Mishka the app is the den mother for your family.

## Status

**v0.4.1** — recurring chores + round-robin. Set a chore to repeat (daily/weekly/monthly/yearly) and either rotate it across the household or pin it to one person; occurrences materialise automatically on a rolling horizon, complete with points and overdue badges.

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

## What works in v0.4.1

Carried forward from v0.1–v0.4.0: registration + login, households (N:M membership + invite codes), the full household calendar (month grid, agenda, RRULE recurrence, single-occurrence editing, cascade dialogs), per-user signed iCal feed, one-off chores (assign, done/reopen, kid-friendly points + board, overdue badge, Done section).

**New in v0.4.1:**
- **Recurring chores** — set a chore to repeat (daily / weekly / monthly / yearly, with INTERVAL + day-of-week), reusing the calendar's RRULE engine. Occurrences materialise automatically on a 14-day rolling horizon when you open the chores or home page (no cron); a far-past start "catches up" over a few views rather than flooding the list.
- **Round-robin or fixed assignment** — a recurring chore either rotates its assignee across all household members (in join order) or is pinned to one person. The rotation survives members joining/leaving.
- **Refresh-on-edit** — editing a recurring chore regenerates its upcoming not-yet-done occurrences; completed ones stay as history. Deleting it drops the upcoming ones and keeps the completed history (and its points).
- **Skip / reassign one** — a generated occurrence is an ordinary chore: delete to skip (it won't come back), or edit it to reassign just that one.

## Roadmap

- **v0.4.2+** — Durable points ledger / leaderboards; pause a recurring chore; per-chore participant pools.
- Later: leave/transfer/delete household, regenerate invite code, profile editing, email verification, password change/reset, per-household feeds, subscribe-to-external-calendar.

## Docs

- [DOCS.md](DOCS.md) — full architecture, schema, routes, design decisions
- [karhu](https://github.com/bjornbasar/karhu) — the microframework this is built on

## License

MIT
