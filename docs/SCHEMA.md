# Mishka — Database Schema

All tables defined in `db/schema.sql`, applied idempotently by `bin/karhu migrate`. Every table uses `CREATE TABLE IF NOT EXISTS` (no `ALTER TABLE` — matches v0.1/v0.2 additive migration pattern).

## v0.1 — users + system_roles

```sql
CREATE TABLE IF NOT EXISTS users (
    id                SERIAL PRIMARY KEY,
    email             VARCHAR(320) NOT NULL UNIQUE,   -- RFC 5321 max length
    password_hash     VARCHAR(255) NOT NULL,
    display_name      VARCHAR(120) NOT NULL DEFAULT '',
    email_verified_at TIMESTAMPTZ NULL,                -- populated by future verification feature
    last_login_at     TIMESTAMPTZ NULL,                -- populated by login handler
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS system_roles (
    user_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role     VARCHAR(32) NOT NULL,
    PRIMARY KEY (user_id, role)
);
```

**Sentinel rows** seeded at migrate time:
- `users(id=0, email='__system__', password_hash='*disabled*', display_name='System')` — system user; non-RFC email means registration validation can never produce it
- `system_roles(user_id=0, role='admin')` — admin claim sentinel; first registered user atomically claims via `UPDATE system_roles SET user_id = :new_id WHERE role = 'admin' AND user_id = 0`

All household / event queries filter `user_id > 0` to exclude the sentinel.

**Email policy:** lowercased on write, queried as-is. Single `UNIQUE` constraint — no functional index, no `CITEXT`.

## v0.2 — households + household_members + user_preferences

```sql
CREATE TABLE IF NOT EXISTS households (
    id         SERIAL PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    join_code  CHAR(8) NOT NULL UNIQUE,
    timezone   VARCHAR(64) NOT NULL DEFAULT 'Pacific/Auckland',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS household_members (
    household_id INTEGER NOT NULL REFERENCES households(id) ON DELETE CASCADE,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role         VARCHAR(32) NOT NULL CHECK (role IN ('owner', 'member')),
    joined_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (household_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_household_members_role
    ON household_members(household_id) WHERE role = 'owner';

CREATE TABLE IF NOT EXISTS user_preferences (
    user_id           INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    last_household_id INTEGER NULL REFERENCES households(id) ON DELETE SET NULL,
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

**N:M model**: `household_members` is the canonical user→household relationship. No `users.household_id` column — a user can belong to multiple households.

**Join codes**: 8 chars from `ABCDEFGHJKMNPQRSTUVWXYZ23456789` (no I/O/L/0/1 lookalikes). Generated server-side with collision-retry. Immutable in v0.2.

**Partial index** on `household_members(household_id) WHERE role = 'owner'` powers the owner-lookup path used by kick-member guards.

## v0.3.0 — events

```sql
CREATE TABLE IF NOT EXISTS events (
    id              SERIAL PRIMARY KEY,
    household_id    INTEGER NOT NULL REFERENCES households(id) ON DELETE CASCADE,
    created_by      INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    title           VARCHAR(200) NOT NULL,
    description     TEXT NOT NULL DEFAULT '',
    location        VARCHAR(200) NOT NULL DEFAULT '',
    starts_at_local TIMESTAMP NOT NULL,
    ends_at_local   TIMESTAMP NOT NULL,
    timezone        VARCHAR(64) NOT NULL,
    all_day         BOOLEAN NOT NULL DEFAULT FALSE,
    rrule           TEXT NULL,                                      -- v0.3.1+
    series_event_id INTEGER NULL REFERENCES events(id) ON DELETE CASCADE,  -- v0.3.1+
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_events_household_starts
    ON events(household_id, starts_at_local);

CREATE INDEX IF NOT EXISTS idx_events_series_event_id
    ON events(series_event_id) WHERE series_event_id IS NOT NULL;
```

**Local-time storage** (`starts_at_local`, `ends_at_local`): bare `TIMESTAMP` (wall-clock) paired with `timezone`. UTC `TIMESTAMPTZ` would drift under DST for recurring events ("9am every Tuesday in NZ" lands at 8am half the year). The recurrence engine (v0.3.1) expands in the event's timezone.

**`rrule` + `series_event_id` ship inert in v0.3.0** so v0.3.1's recurrence + override columns don't need an `ALTER TABLE`. Schema stays append-only.

**Per-event timezone column** ships but the v0.3 event-create form has no input — every event in a household uses the household's tz. v0.4+ unlocks per-event editing with per-tz range partitioning.

**`created_by ON DELETE RESTRICT`** blocks account deletion that would orphan events. v0.5+ adds a proper ownership-transfer flow.

**Partial index** on `series_event_id` powers v0.3.1's override-lookup path. The defensive `WHERE series_event_id IS NULL` filter in `EventRepository::findInRangeForHousehold` keeps v0.3.1's override events from double-rendering through this code path.

## v0.3.1 — event_exceptions

```sql
CREATE TABLE IF NOT EXISTS event_exceptions (
    id                 SERIAL PRIMARY KEY,
    event_id           INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    original_starts_at TIMESTAMP NOT NULL,
    override_event_id  INTEGER NULL REFERENCES events(id) ON DELETE CASCADE,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_event_exceptions_one_per_occurrence
    ON event_exceptions(event_id, original_starts_at);

CREATE INDEX IF NOT EXISTS idx_event_exceptions_override_event_id
    ON event_exceptions(override_event_id) WHERE override_event_id IS NOT NULL;
```

**Semantics:** each row marks a single occurrence of a recurring series (`event_id`) as either cancelled (`override_event_id IS NULL`) or overridden (`override_event_id → events`). The override Event row has `series_event_id` pointing back at the series (the back-ref column shipped inert in v0.3.0).

**CASCADE chains:**
- `events.household_id ON DELETE CASCADE → events` (the household goes, its events go)
- `events.series_event_id ON DELETE CASCADE → override events` (the series goes, its overrides go)
- `event_exceptions.event_id ON DELETE CASCADE → event_exceptions` (the series goes, its exceptions go)
- `event_exceptions.override_event_id ON DELETE CASCADE → event_exceptions` (the override Event row goes, its exception row goes — this is the *load-bearing* direction for `dropAllForEvent`'s two-step DELETE)

**Why two-step DELETE for `dropAllForEvent`:** the FK direction is `event_exceptions → events`, so deleting an exception row does NOT delete the referenced override Event. To wipe both, the service deletes the override Event rows first (CASCADE clears the matching exception rows), then deletes the remaining cancellation rows directly. Round-3 review BLOCKING-bug-fix.

## Future schema additions (v0.3.2)

- `ical_feed_tokens` table for per-user signed iCal feed URLs
