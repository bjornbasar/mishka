# Mishka Den — the den mother for your family

A family hub web app: one place for the household calendar, chores, lists, and the everyday coordination a family needs. Built on the [karhu](https://github.com/bjornbasar/karhu) PHP microframework.

> *Mishka* is Russian for "little bear / cub". A **den** is where cubs live. A **den mother** (Cub Scouts) is the adult who keeps a troop of cubs organised. Mishka the app is the den mother for your family.

## Status

**v0.3.2** — per-user signed iCal feed. Subscribe to your household calendar from any phone or desktop calendar app; recurrence, single-occurrence overrides, and cancellations all surface as RFC 5545 VEVENTs.

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

## What works in v0.3.2

Carried forward from v0.1/v0.2/v0.3.0/v0.3.1: registration + login (timing-safe), logout (cookie-clearing), CSRF (multi-tab friendly), households (N:M membership, 8-char invite codes, owner-managed), stale-session self-heal, household calendar with month grid + agenda + optimistic-concurrency event edits, RRULE recurrence with preset UX, single-occurrence cancel/override, cascade-on-series-edit dialogs.

**New in v0.3.2:**
- **Per-user signed iCal feed.** Visit `/me/calendar/feed`, generate a URL, and subscribe in iOS Calendar / Google Calendar / Outlook / Thunderbird. The feed merges every household you're a member of; recurring events emit raw RRULEs so the client expands client-side (DST-correct without us pre-expanding).
- **RECURRENCE-ID overrides + EXDATE cancellations** — single-occurrence edits and cancels from v0.3.1 surface natively in subscribed calendars via `sabre/vobject` (eluceo/ical 2.x can't emit RECURRENCE-ID; that's why we picked sabre/vobject).
- **VTIMEZONE block per event timezone** — Apple Calendar / Google Calendar / Outlook render the wall-clock time correctly across NZDT / NZST.
- **Cap at 3 active tokens** with auto-revoke oldest on the 4th generate; `last_used_at` surfaces in the settings page as a leak-detection signal.
- **Token-leak defences**: `Referrer-Policy: no-referrer` on feed responses + `<meta name="referrer">` on the post-generate page + Caddy log-path redaction documented in [INFRASTRUCTURE.md](../INFRASTRUCTURE.md#mishka-ical-feed-log-redaction).

## Roadmap

- **v0.4** — Chores: per-household list, round-robin assignment, kid-friendly points, in-app overdue badges
- Later: leave/transfer/delete household, regenerate invite code, profile editing, email verification, password change/reset, per-household feeds (column already nullable), subscribe-to-external-calendar (sabre/vobject parses iCal)

## Docs

- [DOCS.md](DOCS.md) — full architecture, schema, routes, design decisions
- [karhu](https://github.com/bjornbasar/karhu) — the microframework this is built on

## License

MIT
