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

## v0.3.2 — iCal feed

```sql
CREATE TABLE IF NOT EXISTS ical_feed_tokens (
    id                 SERIAL PRIMARY KEY,
    user_id            INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    scope_household_id INTEGER NULL REFERENCES households(id) ON DELETE CASCADE,
    token_hash         CHAR(64) NOT NULL UNIQUE,
    last_used_at       TIMESTAMPTZ NULL,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    revoked_at         TIMESTAMPTZ NULL
);
CREATE INDEX IF NOT EXISTS idx_ical_feed_tokens_user_active
    ON ical_feed_tokens(user_id) WHERE revoked_at IS NULL;
```

**`token_hash` stores SHA-256(raw)**, not the raw token. The 64-char hex raw token is shown to the user once on the post-generate page and is irrecoverable thereafter. UNIQUE on the hash means lookups are O(log n) via the index and brute-force on 256 bits of entropy is computationally infeasible.

**`scope_household_id NULL` = all-households-merged feed.** v0.3.2 always sets this to NULL; the column ships now so v0.4+ can add a "feed for this household only" UI without an ALTER.

**`last_used_at`** is updated on every successful `findByRawToken` hit and surfaced in the settings page as a leak-detection signal — a feed URL being scraped silently shows a recent timestamp the owner didn't expect.

**Soft-delete via `revoked_at`** rather than DELETE: lets the user see "this URL got 240 fetches before I revoked it" in a future audit view (not in v0.3.2). All lookups filter `WHERE revoked_at IS NULL`.

**Cap-at-3 active tokens per user** enforced in `IcalFeedTokenRepository::generate` — loop-and-revoke the oldest active row (by `created_at ASC, id ASC`) before inserting the new row whenever the active count is ≥3.

## v0.4.0 — chores

```sql
CREATE TABLE IF NOT EXISTS chores (
    id              SERIAL PRIMARY KEY,
    household_id    INTEGER NOT NULL REFERENCES households(id) ON DELETE CASCADE,
    created_by      INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    assigned_to     INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    completed_by    INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    title           VARCHAR(200) NOT NULL,
    description     TEXT NOT NULL DEFAULT '',
    points          INTEGER NOT NULL DEFAULT 0 CHECK (points >= 0),
    due_at_local    TIMESTAMP NULL,
    timezone        VARCHAR(64) NOT NULL,
    completed_at    TIMESTAMPTZ NULL,
    schedule_id     INTEGER NULL,              -- v0.4.1 inert; NO DB FK (app-enforced)
    occurrence_date TIMESTAMP NULL,            -- v0.4.1 inert
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_chores_household_due ON chores(household_id, due_at_local);
CREATE INDEX IF NOT EXISTS idx_chores_assigned_to   ON chores(assigned_to) WHERE assigned_to IS NOT NULL;
```

**Time model mirrors `events`** — wall-clock `due_at_local TIMESTAMP` interpreted in `timezone` (IANA, copied from the household at create-time), NOT UTC. NULL due = no deadline = never overdue. Overdue is computed in PHP against the chore's own `timezone`, never in SQL.

**FK matrix** — `household_id` CASCADE (scope root); `created_by` RESTRICT (matches `events.created_by`; blocks an account delete that would orphan authorship); `assigned_to` / `completed_by` SET NULL (member/account gone → the chore survives as unassigned and the delete is never blocked).

**`completed_at` is the sole done-indicator** (NULL = open). `completed_by` records who clicked Done.

**Points** credit the doer (`COALESCE(completed_by, assigned_to)`), driven off `household_members` so only current members appear on the board. v0.4.0 computed this as a live aggregate over `chores`; **v0.4.2 moved it to the durable `chore_points_ledger`** (below) — editing/deleting a completed chore no longer mutates history. `ORDER BY MIN(joined_at)` keeps the grouped query valid under PostgreSQL's strict GROUP BY rule (SQLite is permissive).

**`schedule_id` + `occurrence_date` ship inert for v0.4.1** (a generated recurring instance is a `chores` row back-linking its `chore_schedules` template + the occurrence it fills). `schedule_id` is a **bare `INTEGER` with no DB FK**: its target table ships in v0.4.1 and the no-ALTER convention forbids adding the constraint after the fact, so referential integrity is app-enforced (the repo only ever writes a `schedule_id` it just created). This is a deliberate, documented integrity downgrade vs `events.series_event_id` (which could be a real FK because it self-references an existing table).

## v0.4.1 — chore_schedules

```sql
CREATE TABLE IF NOT EXISTS chore_schedules (
    id                    SERIAL PRIMARY KEY,
    household_id          INTEGER NOT NULL REFERENCES households(id) ON DELETE CASCADE,
    created_by            INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    title                 VARCHAR(200) NOT NULL,
    description           TEXT NOT NULL DEFAULT '',
    points                INTEGER NOT NULL DEFAULT 0 CHECK (points >= 0),
    rrule                 TEXT NOT NULL,
    anchor_at_local       TIMESTAMP NOT NULL,
    timezone              VARCHAR(64) NOT NULL,
    assignment_mode       VARCHAR(16) NOT NULL DEFAULT 'rotate'
                            CHECK (assignment_mode IN ('rotate', 'fixed')),
    fixed_user_id         INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    last_assigned_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    generated_through     TIMESTAMP NULL,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_chore_schedules_household ON chore_schedules(household_id);

-- Generation idempotency guard on chores (partial so v0.4.0 one-off chores never enter it):
CREATE UNIQUE INDEX IF NOT EXISTS idx_chores_schedule_occurrence
    ON chores(schedule_id, occurrence_date) WHERE schedule_id IS NOT NULL;
```

**A schedule is a recurring template; occurrences are generated as `chores` rows.** `rrule` + `anchor_at_local` (carries the time-of-day) feed simshaun/recurr, expanded in `timezone` (wall-clock, DST-safe).

**`generated_through`** is the high-water mark that bounds lazy generation: the materialised window is `(generated_through ?? max(anchor, now−14d), now+14d]`. recurr always starts at the anchor, so this clamp (plus a 60-row cap) is what stops a far-past anchor from generating thousands of rows.

**`last_assigned_user_id` is the rotation cursor — a durable user id, NOT an index** — so the next assignee is a pure function of `(last_assigned_user_id, current members in join order)` that survives member removal/join. `assignment_mode='fixed'` pins to `fixed_user_id` instead. Both user pointers are `ON DELETE SET NULL` (self-heal when an account is deleted).

**FK matrix**: `household_id` CASCADE; `created_by` RESTRICT (matches chores/events); the two user pointers SET NULL. `chores.schedule_id` remains a bare INTEGER (no FK), so deleting a schedule does NOT cascade — `ChoreSchedulesController` coordinates it (drop open generated, detach completed).

## v0.4.2 — points ledger + pause + participant pools

```sql
CREATE TABLE IF NOT EXISTS chore_points_ledger (
    id               SERIAL PRIMARY KEY,
    household_id     INTEGER NOT NULL REFERENCES households(id) ON DELETE CASCADE,
    chore_id         INTEGER NULL REFERENCES chores(id) ON DELETE SET NULL,
    credited_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    points           INTEGER NOT NULL DEFAULT 0 CHECK (points >= 0),   -- snapshot at completion
    completed_at     TIMESTAMPTZ NOT NULL,                             -- UTC instant
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_chore_points_ledger_household_completed ON chore_points_ledger(household_id, completed_at);
CREATE INDEX IF NOT EXISTS idx_chore_points_ledger_credited ON chore_points_ledger(credited_user_id);

CREATE TABLE IF NOT EXISTS chore_schedule_pauses (
    schedule_id INTEGER PRIMARY KEY REFERENCES chore_schedules(id) ON DELETE CASCADE,
    paused_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS chore_schedule_participants (
    schedule_id INTEGER NOT NULL REFERENCES chore_schedules(id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    PRIMARY KEY (schedule_id, user_id)
);
CREATE INDEX IF NOT EXISTS idx_chore_schedule_participants_schedule ON chore_schedule_participants(schedule_id);
```

**`chore_points_ledger`** — append-only points history. **No `UNIQUE(chore_id)`**: reopen deletes the chore's row, so a chore can be completed → reopened → re-completed (a fresh row each time); the invariant "≤1 *live* row per chore" is maintained by delete-on-reopen, not a constraint. `markDone` writes a row only on the real open→done transition; `chore_id`/`credited_user_id` SET NULL preserve history when a chore or account is deleted; `household_id` CASCADE. `completed_at` is a UTC instant so the Monday-in-household-tz weekly boundary (computed in PHP, converted to UTC) compares correctly on both PG and SQLite. An idempotent `INSERT…SELECT … WHERE NOT EXISTS` in `schema.sql` backfills pre-v0.4.2 completions.

**`chore_schedule_pauses`** — presence of a row = the schedule is paused; the generator skips it. Real FK CASCADE (new table) cleans up on schedule delete.

**`chore_schedule_participants`** — the rotation pool subset; presence of rows restricts rotation to `listMembers ∩ pool` (join order), else all members. Composite PK dedups; both FKs CASCADE.

## v0.4.3 — badges + weekly streaks (no schema changes)

Pure-derivation feature on top of v0.4.2. Two query additions on `ChoreRepository`:
- `leaderboardForHousehold` gains a `COUNT(l.id) AS total_completions` aggregate. The existing LEFT JOIN keeps zero-completion members at 0.
- `recentCompletionsForHousehold(hid, sinceUtc): user_id → list<completed_at>` — for the streak walk; INNER JOIN to `household_members` so departed members and SET-NULL'd credits drop out automatically. No new index — `idx_chore_points_ledger_household_completed` covers the WHERE and `household_members` is tiny per household.

## v0.5.0 — account lifecycle + email-dependent flows

Four NEW tables, no ALTER (additive-only schema convention). `users.email_verified_at` already shipped in v0.1.

```sql
CREATE TABLE IF NOT EXISTS email_verification_tokens (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  CHAR(64) NOT NULL UNIQUE,            -- SHA-256 of the raw hex token
    expires_at  TIMESTAMPTZ NOT NULL,                -- TTL = 24h, stamped in PHP
    used_at     TIMESTAMPTZ NULL,                    -- NULL = pending; set = redeemed
    sent_at     TIMESTAMPTZ NULL,                    -- NULL = SMTP send failed (ops-only)
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_email_verification_tokens_user_pending
    ON email_verification_tokens(user_id) WHERE used_at IS NULL;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  CHAR(64) NOT NULL UNIQUE,
    expires_at  TIMESTAMPTZ NOT NULL,                -- TTL = 1h
    used_at     TIMESTAMPTZ NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_password_reset_tokens_user_pending
    ON password_reset_tokens(user_id) WHERE used_at IS NULL;

CREATE TABLE IF NOT EXISTS user_password_changes (
    user_id              INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    password_changed_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS email_send_attempts (
    id            SERIAL PRIMARY KEY,
    ip_address    VARCHAR(45) NULL,                  -- NULL for user-keyed buckets
    user_id       INTEGER NULL REFERENCES users(id) ON DELETE CASCADE,
    kind          VARCHAR(32) NOT NULL CHECK (kind IN ('password_reset_request', 'verify_resend')),
    attempted_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_email_send_attempts_recent
    ON email_send_attempts(kind, attempted_at);
```

**Token pattern** mirrors `ical_feed_tokens` exactly: 32 random bytes → 64-char hex shown ONCE in the email → SHA-256 stored as `token_hash` with a UNIQUE index. Lookups are constant-time-enough (256-bit entropy ⇒ brute force is infeasible).

**`email_verification_tokens.sent_at`** records SMTP delivery for ops observability (H2). The user-facing banner is the single-copy "Please verify your email — [Resend]" regardless (decision U-3). `password_reset_tokens` has no `sent_at` column because the always-200 + 1.5s timing-floor pattern hides SMTP-fail from the user entirely.

**`user_password_changes`** is the session-revocation stamp (H1). One row per user, upserted. The `SessionRevocationGuard` middleware compares `Session::get('auth_time')` to `password_changed_at` and bounces stale sessions. Lives in a separate table (not as `users.password_changed_at`) because the schema convention forbids ALTER; v0.1 didn't reserve a slot.

**`email_send_attempts`** is the app-layer rate limit (H4). Two independent buckets keyed by IP (`password_reset_request`, 5/10min) or user (`verify_resend`, 3/10min). Unbounded growth is acceptable at family-scale (~4k rows/year); a future pruning job can DELETE WHERE attempted_at < NOW() - '90 days'.

**FK CASCADE chain on `users.id` delete**: all four new tables cascade. The PG smoke test in `tests/Smoke/AccountLifecyclePgSmokeTest.php` verifies this end-to-end.

## v0.6.0 — web push subscriptions + notification prefs + dispatch ledger + jobs queue

Four new tables — three for push, one for karhu-queue's job board.

```sql
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id            SERIAL PRIMARY KEY,
    user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    endpoint      TEXT NOT NULL,
    p256dh        VARCHAR(256) NOT NULL,
    auth          VARCHAR(128) NOT NULL,
    user_agent    VARCHAR(500) NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_used_at  TIMESTAMPTZ NULL,
    revoked_at    TIMESTAMPTZ NULL
);
CREATE INDEX IF NOT EXISTS idx_push_subscriptions_user_active
    ON push_subscriptions(user_id) WHERE revoked_at IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_push_subscriptions_user_endpoint
    ON push_subscriptions(user_id, endpoint);

CREATE TABLE IF NOT EXISTS user_notification_prefs (
    user_id                    INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    event_reminder_minutes     INTEGER NOT NULL DEFAULT 15
                                CHECK (event_reminder_minutes >= 0 AND event_reminder_minutes <= 1440),
    overdue_chore_digest       BOOLEAN NOT NULL DEFAULT TRUE,
    new_chore_assigned_enabled BOOLEAN NOT NULL DEFAULT TRUE,   -- v0.6.6
    new_event_enabled          BOOLEAN NOT NULL DEFAULT TRUE,   -- v0.6.6
    updated_at                 TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

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

-- karhu-queue's jobs table; the package owns the contract but mishka inlines
-- the table here so a single `karhu migrate` covers all persistent state.
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
CREATE INDEX IF NOT EXISTS idx_jobs_queue_status_id ON jobs(queue, status, id);
```

**`push_subscriptions`** stores the three values the browser hands back from `pushManager.subscribe()` — endpoint, p256dh, auth. Soft-delete via `revoked_at`; the worker marks revoked on HTTP 410 (subscription expired) and the UI marks revoked on user click. UNIQUE(user_id, endpoint) makes re-register idempotent — the same browser subscribing twice wakes the revoked row instead of creating a duplicate.

**`user_notification_prefs`** is one row per user. v0.6.6 widened it from 2 to 4 prefs; defaults are `event_reminder_minutes=15`, `overdue_chore_digest=true`, `new_chore_assigned_enabled=true`, `new_event_enabled=true`. The CHECK rejects out-of-range minutes. `UserNotificationPrefsRepository::setFor` is a **partial update** as of v0.6.6 — only keys present in the input array are written; absent keys preserve their current value (or fall to default if no row exists). This makes the v0.6.5→v0.6.6 deploy window safe for stale browser tabs. Per-user; per-household customisation deferred to v0.7+ if anyone asks.

**`notification_dispatches`** is the at-most-once ledger. `claim(user_id, kind, ref_id)` returns true iff the INSERT survived the UNIQUE constraint; the caller proceeds to enqueue only on true. ref_id semantics: `event_reminder` → `events.id`; `overdue_digest` → YYYYMMDD-in-hh-tz int; `new_chore_assigned` → `chores.id` (v0.6.6); `new_event` → `events.id` (v0.6.6). Deliberately NO FK on ref_id (events/chores get deleted, dates aren't a table). Pruned to 90 days at the top of each `push:scan` run.

**v0.6.6 schema migration** is mishka's first explicit ALTER. Pre-v0.6.6 the project followed an "additive-only via `CREATE TABLE IF NOT EXISTS`" convention (DOCS.md #14). Adding 2 columns to an existing table + extending a CHECK constraint can't be expressed that way. The fix lives at end-of-`db/schema.sql` in a fenced `-- BEGIN PG_ONLY ... -- END PG_ONLY` block wrapped in `BEGIN; ... COMMIT;` for atomic rollback. `tests/bootstrap.php` strips the block via regex because SQLite recreates the schema per test run and doesn't support `ALTER TABLE ADD COLUMN IF NOT EXISTS` or `DROP/ADD CONSTRAINT`. See DOCS.md decision #47 for the full rationale.

**`jobs`** is karhu-queue's table — the worker pops `pending` rows, flips to `processing`, runs the handler, flips to `completed` or `failed`. Stuck `processing` rows after a SIGKILL are accepted as at-most-once-by-design (v0.6.1 candidate: `karhu jobs:unstick`).

**FK CASCADE chain on `users.id` delete**: push_subscriptions, user_notification_prefs, notification_dispatches all cascade. `jobs` has no FK — a deleted user's pending jobs stay until the worker drains them (the handler then sees `user_id` doesn't resolve and skips).
