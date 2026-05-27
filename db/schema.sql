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

-- ============================================================
-- v0.4.0: chores — per-household task list with assignment + points
-- ============================================================
--
-- A household chore: a one-off task with an optional due date, an optional
-- assignee, a point value, and a done/reopen toggle. Recurrence + round-robin
-- land in v0.4.1.
--
-- Time model mirrors events: `due_at_local` is wall-clock TIMESTAMP interpreted
-- in `timezone` (IANA, copied from the household at create-time). NULL due =
-- no deadline = never overdue. Overdue is computed in PHP against the chore's
-- own `timezone`, never in SQL (CURRENT_TIMESTAMP/NOW() is the wrong clock).
--
-- `completed_at` is the SOLE done-indicator (NULL = open). `completed_by`
-- records who clicked Done. The points tally credits COALESCE(completed_by,
-- assigned_to) — the doer — summed live over completed chores (no ledger;
-- editing/deleting a completed chore or removing a member adjusts the tally,
-- a documented v0.4.0 limitation).
--
-- FK matrix: household_id CASCADE (scope root); created_by RESTRICT (matches
-- events — block account-delete that orphans authorship); assigned_to /
-- completed_by SET NULL (member/account gone → chore survives as unassigned,
-- never blocks a delete).
--
-- `schedule_id` + `occurrence_date` ship INERT for v0.4.1 (a generated
-- recurring instance is a chores row back-linking its chore_schedules template
-- + the occurrence it fills). schedule_id is a BARE INTEGER with NO DB FK: its
-- target table ships in v0.4.1 and the no-ALTER convention means the constraint
-- can't be added later, so referential integrity is app-enforced (the repo
-- only ever writes a schedule_id it just created).

CREATE TABLE IF NOT EXISTS chores (
    id              SERIAL PRIMARY KEY,
    household_id    INTEGER NOT NULL REFERENCES households(id) ON DELETE CASCADE,
    created_by      INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    assigned_to     INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    completed_by    INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    title           VARCHAR(200) NOT NULL,
    description     TEXT NOT NULL DEFAULT '',
    points          INTEGER NOT NULL DEFAULT 0 CHECK (points >= 0),
    due_at_local    TIMESTAMP NULL,             -- wall-clock; NULL = no due date (never overdue)
    timezone        VARCHAR(64) NOT NULL,       -- IANA, copied from household at create-time
    completed_at    TIMESTAMPTZ NULL,           -- NULL = open; set = done
    schedule_id     INTEGER NULL,               -- v0.4.1 inert; FK to chore_schedules enforced in app (no-ALTER)
    occurrence_date TIMESTAMP NULL,             -- v0.4.1 inert; which schedule occurrence this instance fills
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_chores_household_due
    ON chores(household_id, due_at_local);

-- v0.4.1-anticipatory (no v0.4.0 query filters on assigned_to alone; ships now
-- to avoid a later index migration when the "my chores" view lands).
CREATE INDEX IF NOT EXISTS idx_chores_assigned_to
    ON chores(assigned_to) WHERE assigned_to IS NOT NULL;

-- ============================================================
-- v0.4.1: chore_schedules — recurring-chore templates + round-robin
-- ============================================================
--
-- A recurring chore is a TEMPLATE. Concrete occurrences are GENERATED as
-- ordinary `chores` rows (schedule_id + occurrence_date set) by
-- ChoreScheduleGenerator, lazily on page view, on a bounded rolling horizon.
-- Generated instances are first-class chores: complete/reopen/points/overdue
-- all work from v0.4.0.
--
-- rrule + anchor_at_local feed simshaun/recurr (expanded in `timezone`, wall-
-- clock DST-safe like events). recurr ALWAYS iterates from the anchor, so the
-- generator clamps the materialised window to [max(anchor, now-14d), now+14d]
-- and records `generated_through` so re-views are cheap and an old anchor
-- catches up over a few views instead of one giant batch.
--
-- Round-robin: `last_assigned_user_id` is a DURABLE id (NOT an index), so the
-- next assignee is a pure function of (last_assigned_user_id, current members
-- in join order). It survives member removal/join and the concurrent-generation
-- race because it's advanced only inside the same txn as a successful insert.
-- `assignment_mode='fixed'` pins every occurrence to `fixed_user_id` instead.
--
-- FK matrix mirrors chores: household_id CASCADE; created_by RESTRICT; the user
-- pointers SET NULL (self-heal when an account is deleted). `chores.schedule_id`
-- stays a bare INTEGER with NO DB FK (no-ALTER convention) — so deleting a
-- schedule does NOT cascade; ChoreSchedulesController coordinates that.

CREATE TABLE IF NOT EXISTS chore_schedules (
    id                    SERIAL PRIMARY KEY,
    household_id          INTEGER NOT NULL REFERENCES households(id) ON DELETE CASCADE,
    created_by            INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    title                 VARCHAR(200) NOT NULL,
    description           TEXT NOT NULL DEFAULT '',
    points                INTEGER NOT NULL DEFAULT 0 CHECK (points >= 0),
    rrule                 TEXT NOT NULL,              -- a schedule recurs by definition
    anchor_at_local       TIMESTAMP NOT NULL,         -- DTSTART; carries the time-of-day, wall-clock
    timezone              VARCHAR(64) NOT NULL,       -- IANA, copied from household at create-time
    assignment_mode       VARCHAR(16) NOT NULL DEFAULT 'rotate'
                            CHECK (assignment_mode IN ('rotate', 'fixed')),
    fixed_user_id         INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    last_assigned_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    generated_through     TIMESTAMP NULL,             -- watermark: latest occurrence considered; NULL = never generated
    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_chore_schedules_household
    ON chore_schedules(household_id);

-- Generation idempotency guard. Partial so v0.4.0 one-off chores
-- (schedule_id NULL) never enter it.
CREATE UNIQUE INDEX IF NOT EXISTS idx_chores_schedule_occurrence
    ON chores(schedule_id, occurrence_date) WHERE schedule_id IS NOT NULL;

-- ============================================================
-- v0.4.2: chore_points_ledger — append-only points history
-- ============================================================
--
-- Replaces the v0.4.0 live-aggregate points tally. A row is written when a chore
-- is completed (ChoreRepository::markDone, only on the real open→done transition)
-- crediting the doer with the chore's points captured AT completion. Editing the
-- chore's points/assignee afterward never touches this row (immutable history);
-- deleting the chore SET-NULLs chore_id but keeps the row (points survive). reopen
-- DELETEs the chore's row (un-credit). There is deliberately NO UNIQUE(chore_id):
-- a chore can be completed → reopened → re-completed, and delete-on-reopen keeps
-- at most one live row per chore without a constraint that would block re-credit.
--
-- completed_at is stored as a UTC instant; the weekly-leaderboard boundary
-- (Monday 00:00 household tz) is computed in PHP and converted to UTC for an
-- apples-to-apples comparison on both PG (TIMESTAMPTZ) and SQLite (TEXT).

CREATE TABLE IF NOT EXISTS chore_points_ledger (
    id               SERIAL PRIMARY KEY,
    household_id     INTEGER NOT NULL REFERENCES households(id) ON DELETE CASCADE,
    chore_id         INTEGER NULL REFERENCES chores(id) ON DELETE SET NULL,
    credited_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    points           INTEGER NOT NULL DEFAULT 0 CHECK (points >= 0),  -- snapshot at completion
    completed_at     TIMESTAMPTZ NOT NULL,                            -- UTC instant
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_chore_points_ledger_household_completed
    ON chore_points_ledger(household_id, completed_at);
CREATE INDEX IF NOT EXISTS idx_chore_points_ledger_credited
    ON chore_points_ledger(credited_user_id);

-- One-time, idempotent backfill of completions that predate the ledger so the
-- leaderboard doesn't read zero on ship day. Runs on every `karhu migrate` and
-- in the SQLite test load (against an empty chores table = harmless no-op). The
-- NOT EXISTS guard (not a UNIQUE constraint — see above) makes re-runs safe.
INSERT INTO chore_points_ledger (household_id, chore_id, credited_user_id, points, completed_at)
SELECT c.household_id, c.id, COALESCE(c.completed_by, c.assigned_to), c.points, c.completed_at
FROM chores c
WHERE c.completed_at IS NOT NULL
  AND COALESCE(c.completed_by, c.assigned_to) IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM chore_points_ledger l WHERE l.chore_id = c.id);

-- ============================================================
-- v0.4.2: chore_schedule_pauses — presence of a row = the schedule is paused
-- ============================================================
--
-- chore_schedules can't gain an `active` column (additive-only, no ALTER), so a
-- flag table represents pause state. New table → a real FK to chore_schedules is
-- legal (free cleanup on schedule delete). The generator skips paused schedules;
-- pause = INSERT, resume = DELETE (resume also rewinds the schedule's
-- generated_through to now so a long pause doesn't spawn a backlog).

CREATE TABLE IF NOT EXISTS chore_schedule_pauses (
    schedule_id INTEGER PRIMARY KEY REFERENCES chore_schedules(id) ON DELETE CASCADE,
    paused_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ============================================================
-- v0.4.2: chore_schedule_participants — rotation pool subset
-- ============================================================
--
-- Presence of rows for a schedule = rotation cycles that subset (in member join
-- order) instead of all members. No rows = rotate across all members (v0.4.1
-- behaviour). Composite PK dedups; both FKs CASCADE (a removed account or deleted
-- schedule drops its pool rows).

CREATE TABLE IF NOT EXISTS chore_schedule_participants (
    schedule_id INTEGER NOT NULL REFERENCES chore_schedules(id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    PRIMARY KEY (schedule_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_chore_schedule_participants_schedule
    ON chore_schedule_participants(schedule_id);
