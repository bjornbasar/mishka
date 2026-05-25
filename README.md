# Mishka Den — the den mother for your family

A family hub web app: one place for the household calendar, chores, lists, and the everyday coordination a family needs. Built on the [karhu](https://github.com/bjornbasar/karhu) PHP microframework.

> *Mishka* is Russian for "little bear / cub". A **den** is where cubs live. A **den mother** (Cub Scouts) is the adult who keeps a troop of cubs organised. Mishka the app is the den mother for your family.

## Status

**v0.2.0** — user accounts + households (N:M membership, 8-char invite codes, owner-managed). Calendar is next (v0.3).

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

## What works in v0.2

- Open registration with email + display name + password
- Login with email + password (timing-attack-safe verification)
- Logout (clears session + cookie)
- CSRF-protected forms with multi-tab-friendly token rotation (karhu v0.1.1 fix)
- **Households (v0.2):**
  - Create or join via 8-character invite code from a no-lookalike alphabet
  - N:M membership — a user can belong to multiple households (divorced parents, foster carers, live-in nannies); switch between them via nav dropdown
  - Owner-managed: rename, kick member, view invite code
  - "Active household" persists across sessions via `user_preferences.last_household_id`
  - Stale-session self-heal: if you've been kicked since login, your next request bounces you to `/household/setup`

## Roadmap

- **v0.3 — Calendar.** Events with RRULE recurrence, iCal feed for phone-calendar sync, per-event assignments.
- **v0.4 — Chores.** Per-household chore list with round-robin assignment, kid-friendly points, in-app overdue badges.
- Later: leave/transfer/delete household, regenerate invite code, profile editing, email verification, password change/reset.

## Docs

- [DOCS.md](DOCS.md) — full architecture, schema, routes, design decisions
- [karhu](https://github.com/bjornbasar/karhu) — the microframework this is built on

## License

MIT
