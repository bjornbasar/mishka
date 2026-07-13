-- mishka schema — applied idempotently by `bin/karhu migrate`.
-- v0.1: users + system_roles
-- v0.2: households + household_members + user_preferences
-- v0.3.0: events
-- v0.3.1: event_exceptions
-- v0.3.2: ical_feed_tokens
-- v0.4.0: chores
-- v0.4.1: chore_schedules
-- v0.4.2: chore_points_ledger + chore_schedule_pauses + chore_schedule_participants
-- v0.5.0: email_verification_tokens + password_reset_tokens + user_password_changes + email_send_attempts
-- v0.6.0: push_subscriptions + user_notification_prefs + notification_dispatches
-- v0.6.11: email_change_tokens; email_send_attempts.kind CHECK extended for change_email_request
-- v0.6.12: events/chores/chore_schedules.created_by → NULLABLE + SET NULL (account-delete support)
-- v0.6.13: badge_awards (persistent badge history; reverses #35)
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

-- v0.6.12: created_by is NULLABLE + ON DELETE SET NULL — authored content
-- survives the author's account deletion as "Deleted user" (mirrors decision
-- #31 chore_points_ledger.credited_user_id pattern). PG_ONLY ALTER at EOF
-- handles the migration on existing prod data.
CREATE TABLE IF NOT EXISTS events (
    id              SERIAL PRIMARY KEY,
    household_id    INTEGER NOT NULL REFERENCES households(id) ON DELETE CASCADE,
    created_by      INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
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
-- FK matrix: household_id CASCADE (scope root); created_by SET NULL as of
-- v0.6.12 (matches events + chore_schedules — authored content survives the
-- author's account deletion as "Deleted user"); assigned_to / completed_by
-- SET NULL (member/account gone → chore survives as unassigned).
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
    created_by      INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
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
-- FK matrix mirrors chores: household_id CASCADE; created_by SET NULL as of
-- v0.6.12 (authored content survives the author's account deletion); the user
-- pointers (fixed_user_id, last_assigned_user_id) already SET NULL (self-heal
-- when an account is deleted). `chores.schedule_id` stays a bare INTEGER with
-- NO DB FK (no-ALTER convention) — so deleting a schedule does NOT cascade;
-- ChoreSchedulesController coordinates that.

CREATE TABLE IF NOT EXISTS chore_schedules (
    id                    SERIAL PRIMARY KEY,
    household_id          INTEGER NOT NULL REFERENCES households(id) ON DELETE CASCADE,
    created_by            INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
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

-- ============================================================
-- v0.5.0: email_verification_tokens — soft-banner email verification
-- ============================================================
--
-- A row backs a `/verify-email/{raw_token}` URL emailed to a user after register
-- or resend. Raw hex token shown ONCE in the email; only the SHA-256 hash is
-- stored, looked up via the UNIQUE index on token_hash. Identical pattern to
-- ical_feed_tokens — the route is unauthenticated (token IS the auth) and
-- redeemed atomically against `used_at IS NULL AND expires_at > :now` to prevent
-- a concurrent-redemption race (B6).
--
-- `sent_at NULL` records the SMTP-failure state: registration always completes
-- even when MailHog/Postmark is down, and the resend flow uses `sent_at` for
-- ops-side observability. The user-facing banner copy is identical regardless
-- (single-copy decision U-3) — sent_at is for logs, not for users.
--
-- TTL is 24h, single-use. Issue invalidates older pending rows for the same
-- user in the same txn (prevents a stockpile of valid tokens). All expiry math
-- is done in PHP via gmdate('Y-m-d H:i:s') — SQLite's NOW() lacks INTERVAL
-- arithmetic and PG's behaviour with TIMESTAMPTZ differs across timezones (B3).

CREATE TABLE IF NOT EXISTS email_verification_tokens (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  CHAR(64) NOT NULL UNIQUE,            -- SHA-256 of the raw hex token
    expires_at  TIMESTAMPTZ NOT NULL,                -- TTL = 24h, stamped in PHP
    used_at     TIMESTAMPTZ NULL,                    -- NULL = pending; set = redeemed (single-use)
    sent_at     TIMESTAMPTZ NULL,                    -- NULL = SMTP send failed (ops-only signal)
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_email_verification_tokens_user_pending
    ON email_verification_tokens(user_id) WHERE used_at IS NULL;

-- ============================================================
-- v0.5.0: password_reset_tokens — 1h forgot-password flow
-- ============================================================
--
-- Same pattern as email_verification_tokens (SHA-256-hashed raw token, atomic
-- single-use redeem, all expiry math in PHP), but TTL = 1h (industry standard
-- for password reset). No `sent_at` column — `/password-reset` is always-200
-- + 1500ms timing-floored (B4), so the user sees no signal whether a hit ever
-- attempted SMTP. Issue invalidates older pending rows; success also calls
-- invalidatePendingForUser to nuke any racing tokens.

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  CHAR(64) NOT NULL UNIQUE,            -- SHA-256 of the raw hex token
    expires_at  TIMESTAMPTZ NOT NULL,                -- TTL = 1h, stamped in PHP
    used_at     TIMESTAMPTZ NULL,                    -- NULL = pending; set = redeemed (single-use)
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_password_reset_tokens_user_pending
    ON password_reset_tokens(user_id) WHERE used_at IS NULL;

-- ============================================================
-- v0.6.11: email_change_tokens — confirm new-email ownership
-- ============================================================
--
-- Same shape as email_verification_tokens (decision #37): 64-hex raw token
-- shown once via email link; SHA-256 hashed in token_hash (UNIQUE). Single-
-- use atomic redeem via guarded UPDATE on (used_at IS NULL AND expires_at > :now).
--
-- The NEW email lives in the token row; on POST /me/email-change/{token},
-- the controller atomically UPDATEs users.email + email_verified_at = NOW
-- and invalidates pending password_reset_tokens + email_verification_tokens
-- (mailbox-compromise hardening). UNIQUE conflict on the users UPDATE
-- (another user took the email mid-flow) is caught and surfaced as 422.
--
-- `sent_at NULL` is the ops signal that SMTP failed for this issuance.
-- The user-facing flash IS surfaced asymmetric to /password-reset (decision
-- #52 — change-email send-failure is loud since user explicitly initiated),
-- but the column persists SMTP-success state for ops observability — mirrors
-- email_verification_tokens.sent_at.

CREATE TABLE IF NOT EXISTS email_change_tokens (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  CHAR(64) NOT NULL UNIQUE,
    new_email   VARCHAR(320) NOT NULL,
    expires_at  TIMESTAMPTZ NOT NULL,
    used_at     TIMESTAMPTZ NULL,
    sent_at     TIMESTAMPTZ NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_email_change_tokens_user_pending
    ON email_change_tokens(user_id) WHERE used_at IS NULL;

-- ============================================================
-- v0.5.0: user_password_changes — session revocation stamp (H1)
-- ============================================================
--
-- One row per user; presence + value of `password_changed_at` is the predicate
-- the SessionRevocationGuard middleware checks against `Session::get('auth_time')`.
-- Lives in a separate table (not as users.password_changed_at) because the
-- additive-only schema convention forbids ALTER on the existing users table —
-- every prior release reserved the column it needed in advance (email_verified_at
-- in v0.1, schedule_id in v0.4.0), but no slot was reserved for this.
--
-- Predicate matrix (legacy-session grandfather, user decision U-1):
--   - No row + no auth_time         → PASS (pre-v0.5 baseline; security promise
--                                            activates on next login)
--   - Row + no auth_time            → REVOKE (legacy session post-pw-change)
--   - No row + auth_time set        → PASS (modern session, never changed pw)
--   - Both set                      → COMPARE: revoke if auth_time < changed_at
--
-- Upsert pattern: ON CONFLICT (user_id) DO UPDATE on PG, INSERT OR REPLACE on
-- SQLite — repo translates per dialect via the existing isUniqueViolation
-- pattern from EventExceptionRepository. The pinned-$now invariant (BL-2) keeps
-- the self-changing user from self-revoking on a slow request.

CREATE TABLE IF NOT EXISTS user_password_changes (
    user_id              INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    password_changed_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ============================================================
-- v0.5.0: email_send_attempts — app-layer rate limit (H4)
-- ============================================================
--
-- Records each `/password-reset` request (keyed by IP) and `/me/verify-email/
-- resend` (keyed by user) so the controllers can `countRecent(kind, key, 10min)`
-- before issuing a new token. Closes abuse at the app layer regardless of
-- what's in front (no Cloudflare/nginx rate-limit assumed).
--
-- Buckets:
--   - password_reset_request : 5 / 10min / IP   (anonymous, IP-keyed)
--   - verify_resend          : 3 / 10min / user (authed, user-keyed)
--
-- Unbounded growth note: at ~5-10 attempts/day in the family-scale baseline
-- this table stays tiny (< 4k rows/year). A future maintenance job can prune
-- WHERE attempted_at < NOW() - '90 days'; for now the SELECT WHERE attempted_at
-- >= now - 10min always hits the partial-ish (kind, attempted_at) index.

CREATE TABLE IF NOT EXISTS email_send_attempts (
    id            SERIAL PRIMARY KEY,
    ip_address    VARCHAR(45) NULL,                  -- NULL for user-keyed; IPv4 + IPv6 both fit 45
    user_id       INTEGER NULL REFERENCES users(id) ON DELETE CASCADE,
    kind          VARCHAR(32) NOT NULL CHECK (kind IN ('password_reset_request', 'verify_resend', 'change_email_request')),
    attempted_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_email_send_attempts_recent
    ON email_send_attempts(kind, attempted_at);

-- ============================================================
-- v0.6.0: push_subscriptions — Web Push Protocol / VAPID subscription state
-- ============================================================
--
-- Each row backs one browser/device installation's push subscription. The
-- browser returns three values from pushManager.subscribe(): endpoint (the
-- push-service URL — can be FCM, Mozilla, Apple, Microsoft), p256dh (a
-- base64url-encoded P-256 ECDH public key), and auth (a base64url-encoded
-- shared secret). The worker uses all three when calling minishlink/web-push.
--
-- endpoint is TEXT (not VARCHAR) because push URLs can exceed 255 chars in
-- practice (FCM endpoints in 2026 are ~250 chars; Apple's push endpoints can
-- be longer). user_agent is a hint for the /me/notifications UI ("which
-- device is this?"); it's never authoritative.
--
-- Soft-delete via revoked_at (not DROP): a user revoking on /me/notifications
-- sets the flag; a push svc HTTP 410 from the worker also sets it. Revoked
-- rows stay for the audit trail.
--
-- UNIQUE(user_id, endpoint) makes register() idempotent: if the same user re-
-- subscribes from the same browser, we wake the revoked row (clear revoked_at)
-- rather than create a duplicate. The matching active partial index speeds
-- per-user listing.

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id            SERIAL PRIMARY KEY,
    user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    endpoint      TEXT NOT NULL,                       -- push-service URL
    p256dh        VARCHAR(256) NOT NULL,               -- base64url ECDH public key
    auth          VARCHAR(128) NOT NULL,               -- base64url auth secret
    user_agent    VARCHAR(500) NULL,                   -- ops hint; never authoritative
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_used_at  TIMESTAMPTZ NULL,                    -- worker touches on success
    revoked_at    TIMESTAMPTZ NULL                     -- user-revoke OR HTTP 410 from push svc
);

CREATE INDEX IF NOT EXISTS idx_push_subscriptions_user_active
    ON push_subscriptions(user_id) WHERE revoked_at IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_push_subscriptions_user_endpoint
    ON push_subscriptions(user_id, endpoint);

-- ============================================================
-- v0.6.0: user_notification_prefs — per-user push preferences
-- ============================================================
--
-- Two settings:
--   event_reminder_minutes — fire a push N min before each event in the
--     user's active households. 0 disables. Capped at 1440 (24h).
--   overdue_chore_digest — toggle the daily 07:30–08:30 household-tz digest
--     of overdue chores assigned to this user.
--
-- One row per user (PK = user_id). PushScanCommand reads this per scan tick;
-- per-user customisation is the natural ergonomic level (per-household would
-- require N rows + a query that the UI can't easily explain). The digest
-- timing window is GLOBAL (07:30–08:30 hh-tz, locked) — v0.7 candidate.

CREATE TABLE IF NOT EXISTS user_notification_prefs (
    user_id                    INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    event_reminder_minutes     INTEGER NOT NULL DEFAULT 15
                                CHECK (event_reminder_minutes >= 0 AND event_reminder_minutes <= 1440),
    overdue_chore_digest       BOOLEAN NOT NULL DEFAULT TRUE,
    new_chore_assigned_enabled BOOLEAN NOT NULL DEFAULT TRUE,   -- v0.6.6
    new_event_enabled          BOOLEAN NOT NULL DEFAULT TRUE,   -- v0.6.6
    updated_at                 TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ============================================================
-- v0.6.0: notification_dispatches — at-most-once dispatch ledger
-- ============================================================
--
-- One row per (user, kind, ref_id) the scanner has decided to fire.
-- Written via INSERT … ON CONFLICT DO NOTHING; the boolean return tells the
-- caller whether THIS run wins. Multiple cron ticks racing on the same event
-- can't double-fire because only one INSERT survives.
--
-- ref_id semantics by kind:
--   'event_reminder' → events.id (the upcoming event)
--   'overdue_digest' → YYYYMMDD as int in household tz (e.g., 20260601)
-- Deliberately NO FK on ref_id (events get deleted, dates don't exist as a
-- table). Dangling ref_ids stay as audit; SERIAL doesn't reuse PG ids so
-- there's no collision risk.
--
-- PushScanCommand prunes rows older than 90 days at the top of each run.
-- Family-scale (5 users × 5 events/day = 25/day = ~9k/year before prune).
-- The kind CHECK guards forward-compat — adding a new kind needs a schema
-- bump and an explicit migration.

CREATE TABLE IF NOT EXISTS notification_dispatches (
    id            SERIAL PRIMARY KEY,
    user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    kind          VARCHAR(32) NOT NULL CHECK (kind IN (
                    'event_reminder', 'overdue_digest',
                    'new_chore_assigned', 'new_event'  -- v0.6.6
                  )),
    ref_id        INTEGER NOT NULL,
    dispatched_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_notification_dispatches_unique
    ON notification_dispatches(user_id, kind, ref_id);

CREATE INDEX IF NOT EXISTS idx_notification_dispatches_pruning
    ON notification_dispatches(dispatched_at);

-- ============================================================
-- v0.6.0: jobs — karhu-queue's database-backed queue table
-- ============================================================
--
-- Owned by the karhu-queue package; the table shape is what
-- Karhu\Queue\DatabaseQueue expects. Inlined here so a single
-- `bin/karhu migrate` covers all mishka's persistent state.
--
-- status lifecycle: pending → processing → completed | failed.
-- The worker pops one pending → flips to processing → handles → completes
-- (or fails on exception). Jobs stuck in 'processing' after a worker SIGKILL
-- stay stuck (at-most-once by design; v0.6.1 candidate: `karhu jobs:unstick`
-- to sweep `processing` rows older than 5 min back to `pending`).

CREATE TABLE IF NOT EXISTS jobs (
    id         SERIAL PRIMARY KEY,
    queue      VARCHAR(50) NOT NULL DEFAULT 'default',
    job        VARCHAR(255) NOT NULL,
    data       TEXT NOT NULL DEFAULT '{}',
    status     VARCHAR(20) NOT NULL DEFAULT 'pending'
                CHECK (status IN ('pending', 'processing', 'completed', 'failed')),
    error      TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_jobs_queue_status_id
    ON jobs(queue, status, id);

-- ============================================================
-- v0.6.6: forward-migration block for existing PG databases
-- ============================================================
--
-- This is mishka's FIRST explicit ALTER TABLE block. Pre-v0.6.6 the project
-- followed an "additive-only via CREATE TABLE IF NOT EXISTS" convention (per
-- DOCS.md #14 — adding new inert columns to fresh CREATEs covers all cases
-- when no existing rows need backfill). v0.6.6 needed to add two boolean
-- columns to a table that v0.6.0 already created in prod, and to extend the
-- CHECK predicate on `notification_dispatches.kind` — both impossible via
-- CREATE TABLE IF NOT EXISTS once the table exists.
--
-- The whole block is wrapped in BEGIN/COMMIT so a partial failure (e.g. a
-- network drop between the DROP and ADD CONSTRAINT) rolls back atomically.
-- Never leaves the table with no CHECK on `kind`.
--
-- Idempotency:
--   - ALTER TABLE ... ADD COLUMN IF NOT EXISTS is a no-op on subsequent runs.
--   - The CHECK extension is idempotent via DROP-then-ADD inside the txn:
--     every run lands in the same final state regardless of prior CHECK.
--
-- Driver compat: verified against PHP 8.4 + libpq + PG 16. Older PHP-PG
-- drivers with PDO::ATTR_EMULATE_PREPARES=false (which is mishka's setting)
-- may need each ALTER as a separate exec() call — verify on prod libpq
-- before relying on this pattern in v0.7+.
--
-- Convention: ONE PG_ONLY block per schema.sql, at end of file. The strip
-- regex in tests/bootstrap.php is global but the documented pattern is
-- single-block-at-EOF.
--
-- SQLite (tests/bootstrap.php) strips this entire block via preg_replace
-- because SQLite doesn't support ALTER TABLE ADD COLUMN IF NOT EXISTS nor
-- ALTER TABLE DROP/ADD CONSTRAINT. Fresh SQLite test runs get the new
-- columns + CHECK directly from the CREATE TABLE statements above.

-- ============================================================
-- v0.6.13: badge_awards — persistent badge-earn history
-- ============================================================
--
-- Reverses decision #35 (stateless badges, v0.4.3). The 6 badges that
-- Achievements::badges() returned via runtime compute now persist with
-- the moment the threshold was crossed (pinned by the controller as the
-- triggering ledger row's completed_at).
--
-- Idempotency: UNIQUE(household_id, user_id, badge_code) — "earned once
-- forever". The eager-award path INSERTs with ON CONFLICT DO NOTHING (PG)
-- / INSERT OR IGNORE (SQLite) so a double-complete-then-reopen-then-
-- complete sequence never produces a second row (and never re-stamps
-- earned_at backwards).
--
-- user_id is ON DELETE SET NULL (mirrors decision #31 chore_points_ledger
-- + decision #53 events/chores.created_by). Award history per-household
-- survives the author's account deletion as a "Deleted user" badge.
-- household_id CASCADEs (scope root).

CREATE TABLE IF NOT EXISTS badge_awards (
    id           SERIAL PRIMARY KEY,
    household_id INTEGER NOT NULL REFERENCES households(id) ON DELETE CASCADE,
    user_id      INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    badge_code   VARCHAR(64) NOT NULL,
    earned_at    TIMESTAMPTZ NOT NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_badge_awards_unique_per_user_badge
    ON badge_awards(household_id, user_id, badge_code);

CREATE INDEX IF NOT EXISTS idx_badge_awards_user_earned
    ON badge_awards(user_id, earned_at);

-- v0.6.19: user_deletions — account-delete audit (DOCS #60).
-- Written INSIDE the delete txn BEFORE the user DELETE so the audit row
-- and the destruction are atomic. No FK to users(id) — the user row is
-- destroyed in the same txn; FK would either fail at INSERT or CASCADE
-- the audit row away on the DELETE (both wrong). Same no-FK posture as
-- chore_points_ledger.credited_user_id (decision #31).
CREATE TABLE IF NOT EXISTS user_deletions (
    id            SERIAL PRIMARY KEY,
    user_id       INTEGER NOT NULL,
    deleted_at    TIMESTAMPTZ NOT NULL,
    household_ids TEXT NOT NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_user_deletions_deleted_at
    ON user_deletions(deleted_at);

-- v0.7.0: user_sessions — per-device session tracking (DOCS #62).
-- One row per active web session, INSERTed at login (AuthController) or
-- lazy-backfilled by SessionRevocationGuard on first post-deploy request
-- from a pre-v0.7.0 session. session_uuid is an APP-LEVEL identifier
-- (NOT PHP session_id()) so it survives Session::regenerate() on login
-- + password change. revoked_at IS NULL = active. CASCADE on users
-- delete handles account-delete cleanup (decision #53 chain).
CREATE TABLE IF NOT EXISTS user_sessions (
    id           SERIAL PRIMARY KEY,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    session_uuid CHAR(32) NOT NULL UNIQUE,
    user_agent   VARCHAR(500) NOT NULL DEFAULT '',
    ip           VARCHAR(45) NOT NULL DEFAULT '',
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_used_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    revoked_at   TIMESTAMPTZ NULL
);

CREATE INDEX IF NOT EXISTS idx_user_sessions_user_active
    ON user_sessions(user_id, last_used_at DESC)
    WHERE revoked_at IS NULL;

-- v0.7.1: schema_versions — audit log of each migrate apply (DOCS #63).
-- Append-only history. id SERIAL is the implicit "order applied".
-- schema_hash is SHA-256(db/schema.sql) at apply time. applied_by lets
-- the operator distinguish CI-driven (ci-deploy) vs manual runs.
-- Closes the v0.7.0 ops gap where the manual `bin/karhu migrate` step
-- was missed at deploy → prod 500'd until run by hand.
CREATE TABLE IF NOT EXISTS schema_versions (
    id          SERIAL PRIMARY KEY,
    applied_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    schema_hash CHAR(64) NOT NULL,
    applied_by  VARCHAR(64) NOT NULL DEFAULT 'manual'
);

CREATE INDEX IF NOT EXISTS idx_schema_versions_applied_at
    ON schema_versions(applied_at DESC);

-- v0.8.0: Tracker Phase 1 — dish library + serving-first food logging.
-- Three greenfield tables (no PG_ONLY migration needed). Serves the food
-- side of the tracker; exercise + profile + weight tables land in v0.8.1+.
-- See DOCS.md #70 + docs/TRACKER.md.

-- foods — composed dishes with kcal baked into servings (§7 of TRACKER-PLAN).
-- household_id NULL = global seed row shared by every household. Seeds ship
-- via db/seed/tracker_foods.json + `bin/karhu tracker:seed-foods`. Custom
-- household dishes carry household_id NOT NULL + source='custom'.
CREATE TABLE IF NOT EXISTS foods (
    id           SERIAL PRIMARY KEY,
    household_id INTEGER NULL REFERENCES households(id) ON DELETE CASCADE,
    name         VARCHAR(200) NOT NULL,
    -- name_lc: mb_strtolower(name), written by FoodRepository on every
    -- create/update. Case-insensitive LIKE search hits idx_foods_name_lc.
    -- Never populated by the caller; the repo owns this column.
    name_lc      VARCHAR(200) NOT NULL,
    aliases      VARCHAR(500) NULL,
    cuisine_tag  VARCHAR(50) NULL,
    source       VARCHAR(20) NOT NULL CHECK (source IN ('philfct','nzfcd','usda','custom')),
    created_by   INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_foods_household ON foods(household_id) WHERE household_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_foods_name_lc   ON foods(name_lc);
-- Seed dedup: (name, source) unique for global rows only. Household-scoped
-- rows can freely duplicate the seed name (e.g., a household's own kare-kare).
CREATE UNIQUE INDEX IF NOT EXISTS idx_foods_seed_unique ON foods(name, source) WHERE household_id IS NULL;

-- food_servings — serving units per dish. kcal baked in; no ingredient
-- composition at log time. Exactly one is_default = TRUE per food_id
-- (partial unique index). Repo enforces the invariant via
-- demote-then-promote transaction.
CREATE TABLE IF NOT EXISTS food_servings (
    id         SERIAL PRIMARY KEY,
    food_id    INTEGER NOT NULL REFERENCES foods(id) ON DELETE CASCADE,
    label      VARCHAR(100) NOT NULL,
    grams      NUMERIC(10,2) NOT NULL,
    kcal       INTEGER NOT NULL,
    protein_g  NUMERIC(6,2) NULL,
    carb_g     NUMERIC(6,2) NULL,
    fat_g      NUMERIC(6,2) NULL,
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_food_servings_food ON food_servings(food_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_food_servings_default_unique
    ON food_servings(food_id) WHERE is_default = TRUE;

-- food_log — one row per "I ate this". kcal_snapshot captures the value at
-- log time so subsequent dish edits/deletes don't rewrite history (mirrors
-- chore_points_ledger append-only pattern from decision #31).
-- food_id / serving_id SET NULL on delete so deleted-dish rows render via
-- COALESCE(foods.name, '(deleted dish)') without breaking the log view.
-- logged_on is the HOUSEHOLD-LOCAL calendar date, computed in PHP via
-- App\Tracker\LocalDay::today($householdTz). Never CURRENT_DATE/NOW().
CREATE TABLE IF NOT EXISTS food_log (
    id             SERIAL PRIMARY KEY,
    household_id   INTEGER NOT NULL REFERENCES households(id) ON DELETE CASCADE,
    user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    food_id        INTEGER NULL REFERENCES foods(id) ON DELETE SET NULL,
    serving_id     INTEGER NULL REFERENCES food_servings(id) ON DELETE SET NULL,
    qty            NUMERIC(6,2) NOT NULL,
    logged_on      DATE NOT NULL,
    logged_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    meal           VARCHAR(20) NOT NULL CHECK (meal IN ('breakfast','lunch','dinner','snack')),
    kcal_snapshot  INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_food_log_household_day ON food_log(household_id, logged_on);
CREATE INDEX IF NOT EXISTS idx_food_log_user_day      ON food_log(user_id, logged_on);

-- v0.8.1: Tracker Phase 2 — exercise catalog + logging + weight_log.
-- Three greenfield tables (no PG_ONLY migration needed). Seeded via
-- db/seed/tracker_exercises.json + bin/karhu tracker:seed-exercises.
-- See DOCS.md #71 + docs/TRACKER.md §10.
-- Design: exercise_log is a discriminated union by type ('duration' vs
-- 'strength'). NO derivation between the two branches — user-locked at
-- plan-time: sets/reps entries are NOT converted to minutes. Strength
-- entries have met_minutes=NULL; kcal computed via mechanical-work
-- formula (0.011723 × load_kg × default_rom_m × reps) when ROM known.

-- Exercise catalog. household_id NULL = global seed row shared by all.
CREATE TABLE IF NOT EXISTS exercises (
    id             SERIAL PRIMARY KEY,
    household_id   INTEGER NULL REFERENCES households(id) ON DELETE CASCADE,
    name           VARCHAR(200) NOT NULL,
    name_lc        VARCHAR(200) NOT NULL,               -- repo-owned mb_strtolower(name)
    type           VARCHAR(20) NOT NULL CHECK (type IN ('duration','strength')),
    met            NUMERIC(5,2) NOT NULL,               -- Compendium MET (0, 25] repo-bounded
    default_rom_m  NUMERIC(4,2) NULL,                   -- range-of-motion for strength mechanical-work
    source         VARCHAR(20) NOT NULL CHECK (source IN ('compendium','custom')),
    created_by     INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_exercises_household ON exercises(household_id) WHERE household_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_exercises_name_lc   ON exercises(name_lc);
CREATE UNIQUE INDEX IF NOT EXISTS idx_exercises_seed_unique ON exercises(name, source) WHERE household_id IS NULL;

-- weight_log — time series, no upsert (measurements are historical facts).
-- Latest row per user (ORDER BY measured_on DESC, id DESC LIMIT 1) drives
-- ExerciseLogController's kcal computation AND v0.8.2's BMR/TDEE calc.
CREATE TABLE IF NOT EXISTS weight_log (
    id           SERIAL PRIMARY KEY,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    weight_kg    NUMERIC(5,2) NOT NULL,                 -- [20.00, 300.00] repo-bounded
    measured_on  DATE NOT NULL,                         -- household-local via LocalDay::today
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_weight_log_user_measured ON weight_log(user_id, measured_on DESC);

-- Exercise log — one row per "I did this". Discriminated union by
-- exercise_type_snapshot: duration branch populates minutes; strength
-- populates sets/reps/load_kg. Snapshots preserve name+type so deletion
-- of the parent exercise doesn't break the log-view render.
CREATE TABLE IF NOT EXISTS exercise_log (
    id                     SERIAL PRIMARY KEY,
    household_id           INTEGER NOT NULL REFERENCES households(id) ON DELETE CASCADE,
    user_id                INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    exercise_id            INTEGER NULL REFERENCES exercises(id) ON DELETE SET NULL,
    logged_on              DATE NOT NULL,               -- household-local via LocalDay::today
    logged_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    -- Discriminated union: exactly one branch's fields are non-null.
    minutes                NUMERIC(6,2) NULL,           -- duration branch
    sets                   INTEGER NULL,                -- strength branch
    reps                   INTEGER NULL,                -- strength branch
    load_kg                NUMERIC(6,2) NULL,           -- strength branch (optional even within branch)
    -- Snapshots — immutable at log time (mirrors chore_points_ledger #31).
    exercise_name_snapshot VARCHAR(200) NOT NULL,
    exercise_type_snapshot VARCHAR(20) NOT NULL CHECK (exercise_type_snapshot IN ('duration','strength')),
    -- met_minutes: DURATION branch only. NULL for strength (no set-rep→minutes conversion).
    -- v0.8.3 leaderboard primary currency; strength contribution TBD in v0.8.3.
    met_minutes            NUMERIC(8,2) NULL,
    -- kcal_snapshot: computed branch-dependently at write time.
    --   Duration:  MET × 3.5 × weight_kg ÷ 200 × minutes. NULL when user has no weight_log yet.
    --   Strength:  0.011723 × load_kg × default_rom_m × reps. NULL when default_rom_m is NULL.
    kcal_snapshot          INTEGER NULL
);
CREATE INDEX IF NOT EXISTS idx_exercise_log_household_day ON exercise_log(household_id, logged_on);
CREATE INDEX IF NOT EXISTS idx_exercise_log_user_day     ON exercise_log(user_id, logged_on);

-- v0.8.2: Tracker Phase 3 — per-user body profile for BMR/TDEE calc.
-- One row per user (PK on user_id — upsert semantics via ON CONFLICT).
-- Feeds the Today energy-balance widget alongside food_log +
-- exercise_log + weight_log (all v0.8.0-v0.8.1). See DOCS.md #72 +
-- docs/TRACKER.md §11.
--
-- base_activity CRITICAL semantic: "your normal day EXCLUDING deliberate
-- workouts". UI must spell this out so users don't double-count
-- exercise_log against the activity factor. Mifflin-St Jeor literature
-- values: 1.2 sedentary / 1.375 lightly active / 1.55 moderately active /
-- 1.725 very active. See docs/TRACKER.md §11 + DOCS #72 double-count-trap.
CREATE TABLE IF NOT EXISTS tracker_profiles (
    user_id       INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    sex           VARCHAR(10) NOT NULL CHECK (sex IN ('male','female')),
    birth_year    INTEGER NOT NULL,                 -- [1900, currentYear-5] repo-bounded
    height_cm     NUMERIC(5,1) NOT NULL,            -- [50.0, 250.0] repo-bounded
    base_activity NUMERIC(4,3) NOT NULL,            -- [1.0, 2.5] repo-bounded
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
-- No auxiliary indexes — PK on user_id is enough for the read pattern.

-- BEGIN PG_ONLY
BEGIN;
ALTER TABLE user_notification_prefs ADD COLUMN IF NOT EXISTS new_chore_assigned_enabled BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE user_notification_prefs ADD COLUMN IF NOT EXISTS new_event_enabled BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE notification_dispatches DROP CONSTRAINT IF EXISTS notification_dispatches_kind_check;
ALTER TABLE notification_dispatches ADD CONSTRAINT notification_dispatches_kind_check
    CHECK (kind IN ('event_reminder', 'overdue_digest', 'new_chore_assigned', 'new_event'));
-- v0.6.11: extend email_send_attempts.kind for change_email_request.
-- Same DROP+ADD pattern as #47 — idempotent on rerun.
ALTER TABLE email_send_attempts DROP CONSTRAINT IF EXISTS email_send_attempts_kind_check;
ALTER TABLE email_send_attempts ADD CONSTRAINT email_send_attempts_kind_check
    CHECK (kind IN ('password_reset_request', 'verify_resend', 'change_email_request'));
-- v0.6.12: relax events/chores/chore_schedules.created_by from NOT NULL + ON
-- DELETE RESTRICT to NULL + ON DELETE SET NULL so account delete can fire the
-- cascade chain cleanly. See DOCS.md decision #53. All three blocks use
-- DROP CONSTRAINT IF EXISTS + DROP NOT NULL + ADD CONSTRAINT — idempotent on
-- rerun. DROP NOT NULL is a no-op when already nullable; ADD after DROP lands
-- the same final state every time.
ALTER TABLE events DROP CONSTRAINT IF EXISTS events_created_by_fkey;
ALTER TABLE events ALTER COLUMN created_by DROP NOT NULL;
ALTER TABLE events ADD CONSTRAINT events_created_by_fkey
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE chores DROP CONSTRAINT IF EXISTS chores_created_by_fkey;
ALTER TABLE chores ALTER COLUMN created_by DROP NOT NULL;
ALTER TABLE chores ADD CONSTRAINT chores_created_by_fkey
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE chore_schedules DROP CONSTRAINT IF EXISTS chore_schedules_created_by_fkey;
ALTER TABLE chore_schedules ALTER COLUMN created_by DROP NOT NULL;
ALTER TABLE chore_schedules ADD CONSTRAINT chore_schedules_created_by_fkey
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
COMMIT;
-- END PG_ONLY
