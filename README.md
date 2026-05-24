# Mishka Den — the den mother for your family

A family hub web app: one place for the household calendar, chores, lists, and the everyday coordination a family needs. Built on the [karhu](https://github.com/bjornbasar/karhu) PHP microframework.

> *Mishka* is Russian for "little bear / cub". A **den** is where cubs live. A **den mother** (Cub Scouts) is the adult who keeps a troop of cubs organised. Mishka the app is the den mother for your family.

## Status

**v0.1.0** — user registration + login + logout. Household create/join is next.

## Quick start

```bash
git clone https://github.com/bjornbasar/mishka
cd mishka
composer install
cp .env.example .env
$EDITOR .env                    # set DB_USER / DB_PASS for your PostgreSQL
./vendor/bin/karhu migrate       # apply schema
composer serve                   # http://localhost:8080
```

Then open `http://localhost:8080/register` and create your account. The first user becomes the household admin.

## Stack

- **Language:** PHP 8.3+
- **Framework:** karhu (HTTP, DI, middleware, auth primitives)
- **Database:** PostgreSQL via karhu-db
- **Views:** Twig via karhu-view
- **Passwords:** argon2id (via `Karhu\Auth\PasswordHasher`)
- **Tests:** PHPUnit 11
- **Static analysis:** PHPStan level 6

## What works in v0.1

- Open registration with email + display name + password
- Login with email + password (timing-attack-safe verification)
- Logout (clears session + cookie)
- CSRF-protected forms (auto-handled by middleware)
- First registered user atomically claims the `admin` role

## Roadmap

- Household create/join with invite codes (mirrors hartza's pattern)
- Profile editing
- Email verification
- Password change / reset
- Calendar, chores, shopping lists, meal plans (the actual family hub features)

## Docs

- [DOCS.md](DOCS.md) — full architecture, schema, routes, design decisions
- [karhu](https://github.com/bjornbasar/karhu) — the microframework this is built on

## License

MIT
