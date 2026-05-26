-- mishka schema — applied idempotently by `bin/karhu migrate`.
-- v0.1: users + system_roles
-- v0.2: households + household_members + user_preferences
-- v0.3.0: events
-- v0.3.1: event_exceptions
-- v0.3.2: ical_feed_tokens
-- Email policy: lowercased on write, queried as-is. UNIQUE constraint is sufficient.

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

CREATE INDEX IF NOT EXISTS idx_system_roles_user_id ON system_roles(user_id);

-- Race-free first-user-admin sentinel.
-- Email '__system__' is intentionally non-RFC so registration validation
-- (FILTER_VALIDATE_EMAIL) cannot ever produce this user via the form.
-- First user to register atomically claims the admin slot via:
--   UPDATE system_roles SET user_id = :new_id WHERE role = 'admin' AND user_id = 0
INSERT INTO users (id, email, password_hash, display_name)
VALUES (0, '__system__', '*disabled*', 'System')
ON CONFLICT (id) DO NOTHING;

INSERT INTO system_roles (user_id, role) VALUES (0, 'admin')
ON CONFLICT (user_id, role) DO NOTHING;

-- ============================================================
-- v0.2: households (N:M membership) + user_preferences
-- ============================================================
--
-- N:M user → household: a `household_members` join table, NOT a 1:1 FK on users.
-- Family-hub edge cases (divorced parents, foster carers, live-in nannies) are real
-- 10-15% of users — modeling them now is cheap; migrating after events/chores have
-- FK'd to a 1:1 column is ruinous.
--
-- Active-household persistence lives in `user_preferences.last_household_id` rather
-- than `users.last_household_id` because `ALTER TABLE ADD COLUMN IF NOT EXISTS` is
-- not supported on SQLite (the test harness). Keeping schema.sql as pure CREATE TABLE
-- statements lets it run idempotently on both PG (prod) and SQLite (tests) without
-- a migrations framework. Future per-user prefs land in this same table.

CREATE TABLE IF NOT EXISTS households (
    id         SERIAL PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    join_code  CHAR(8) NOT NULL UNIQUE,
    timezone   VARCHAR(64) NOT NULL DEFAULT 'Pacific/Auckland',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_households_join_code ON households(join_code);

CREATE TABLE IF NOT EXISTS household_members (
    household_id INTEGER NOT NULL REFERENCES households(id) ON DELETE CASCADE,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role         VARCHAR(32) NOT NULL CHECK (role IN ('owner', 'member')),
    joined_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (household_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_household_members_user_id ON household_members(user_id);

-- Partial index for owner-only lookups (`Who owns this household?` and
-- the v0.3+ "block last-owner removal" check).
CREATE INDEX IF NOT EXISTS idx_household_members_role
    ON household_members(household_id) WHERE role = 'owner';

-- Per-user preferences. v0.2 stores just `last_household_id` (for restoring the
-- active household on login). Future prefs (theme, default calendar view, etc.)
-- get more columns here without touching users.
CREATE TABLE IF NOT EXISTS user_preferences (
    user_id           INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    last_household_id INTEGER NULL REFERENCES households(id) ON DELETE SET NULL,
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ============================================================
-- v0.3.0: events (household calendar — one-off events only at this layer)
-- ============================================================
--
-- All times stored as local time + IANA timezone. UTC-as-TIMESTAMPTZ would drift
-- under DST for recurring events ("9am every Tuesday in NZ" would land at 8am
-- half the year), so we store the wall-clock value and the zone separately and
-- let the recurrence engine expand in that zone.
--
-- `timezone` is copied from the household at create-time. In v0.3 the column ships
-- but the event-create form has no input — every event in a household uses that
-- household's timezone. v0.4+ unlocks per-event editing with the requisite per-tz
-- range-query partitioning.
--
-- `rrule` and `series_event_id` ship in v0.3.0 (inert) so v0.3.1's
-- recurrence + single-occurrence-override feature doesn't need an ALTER TABLE.
-- The schema stays append-only, matching v0.1/v0.2 idempotent migration semantics.

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
    rrule           TEXT NULL,                                  -- v0.3.1+
    series_event_id INTEGER NULL REFERENCES events(id) ON DELETE CASCADE,  -- v0.3.1+; override back-ref
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_events_household_starts
    ON events(household_id, starts_at_local);

CREATE INDEX IF NOT EXISTS idx_events_series_event_id
    ON events(series_event_id) WHERE series_event_id IS NOT NULL;

-- ============================================================
-- v0.3.1: event_exceptions — cancellations + overrides for recurring events
-- ============================================================
--
-- Each row marks a single occurrence of a recurring series as either:
--   - CANCELLED (override_event_id IS NULL) — the occurrence is removed
--                                              from the series' expansion
--   - OVERRIDDEN (override_event_id → events) — the occurrence is replaced
--                                              by a standalone event with
--                                              its own time/title/etc.
--
-- The override Event row has events.series_event_id pointing back at the
-- series (the back-ref shipped in v0.3.0 schema). On series delete, ON
-- DELETE CASCADE on events.series_event_id wipes the override events
-- automatically; the exception rows then CASCADE via event_exceptions.event_id
-- FK. Note: dropping just an event_exceptions row does NOT cascade the
-- override Event — that's an application-layer concern (EventService::dropAllForEvent
-- does the two-step DELETE explicitly).
--
-- UNIQUE (event_id, original_starts_at) makes cancel idempotent and prevents
-- two overrides on the same occurrence.

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

-- ============================================================
-- v0.3.2: ical_feed_tokens — per-user signed URLs for calendar subscription
-- ============================================================
--
-- Each row backs a `/ical/{raw_token}.ics` URL the user pastes into their
-- phone calendar app. The raw 64-char hex token is shown to the user ONCE
-- post-generate; only the SHA-256 hash is stored, looked up via the UNIQUE
-- index on token_hash. The route is unauthenticated — the token IS the auth.
--
-- Token cap policy (v0.3.2): max 3 active tokens per user. The 4th generate
-- auto-revokes the oldest active token (sorted by created_at ASC) so users
-- can rotate devices without manually cleaning up.
--
-- scope_household_id is always NULL in v0.3.2 (all-households-merged feed
-- per the locked design). v0.4+ can issue per-household feeds by setting it.
--
-- `last_used_at` is updated on every hit. Surface this in the settings UI
-- as a leak-detection signal (a feed URL being scraped silently shows a
-- recent timestamp the user didn't expect).

CREATE TABLE IF NOT EXISTS ical_feed_tokens (
    id                 SERIAL PRIMARY KEY,
    user_id            INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    scope_household_id INTEGER NULL REFERENCES households(id) ON DELETE CASCADE,
    token_hash         CHAR(64) NOT NULL UNIQUE,    -- SHA-256 of the raw hex token
    last_used_at       TIMESTAMPTZ NULL,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    revoked_at         TIMESTAMPTZ NULL
);

-- Partial index for the cap-at-3 query (lookup of active tokens per user)
CREATE INDEX IF NOT EXISTS idx_ical_feed_tokens_user_active
    ON ical_feed_tokens(user_id) WHERE revoked_at IS NULL;
