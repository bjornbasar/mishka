# Mishka Den — the den mother for your family

A family hub web app: one place for the household calendar, chores, lists, and the everyday coordination a family needs. Built on the [karhu](https://github.com/bjornbasar/karhu) PHP microframework.

> *Mishka* is Russian for "little bear / cub". A **den** is where cubs live. A **den mother** (Cub Scouts) is the adult who keeps a troop of cubs organised. Mishka the app is the den mother for your family.

## Status

**v0.3.1** — recurrence + single-occurrence editing. Weekly chores, monthly bills, yearly birthdays all expand on the calendar grid; edit "just this Tuesday" without breaking the rest of the series. iCal feed is next (v0.3.2).

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

## What works in v0.3.1

Carried forward from v0.1/v0.2/v0.3.0: registration + login (timing-safe), logout (cookie-clearing), CSRF (multi-tab friendly), households (N:M membership, 8-char invite codes, owner-managed), stale-session self-heal, household calendar with month grid + agenda + optimistic-concurrency event edits.

**New in v0.3.1:**
- **RRULE recurrence** with preset UX (none / daily / weekly / monthly / yearly + INTERVAL). DST-safe expansion in the event's timezone — "9am every Tuesday in NZ" stays 9am wall-clock across NZDT/NZST transitions.
- **Single-occurrence editing** — cancel or override a specific occurrence of a recurring series without disturbing the rest. Edit "just this Tuesday" to 7pm; the next Tuesdays stay at 6pm.
- **Cascade-on-series-edit dialogs** — when you move a recurring event's time or change its repeat pattern with existing customisations, you see exactly which ones will shift (clean time-delta) or drop (structural change) before you confirm. Two-step concurrency check protects against another tab adding/removing an exception mid-dialog.

## Roadmap

- **v0.3.2** — Per-user signed iCal feed for phone-calendar subscription. `sabre/vobject` builds VCALENDAR with RECURRENCE-ID overrides + VTIMEZONE; cap at 3 active tokens with auto-revoke oldest.
- **v0.4** — Chores: per-household list, round-robin assignment, kid-friendly points, in-app overdue badges
- Later: leave/transfer/delete household, regenerate invite code, profile editing, email verification, password change/reset

## Docs

- [DOCS.md](DOCS.md) — full architecture, schema, routes, design decisions
- [karhu](https://github.com/bjornbasar/karhu) — the microframework this is built on

## License

MIT
