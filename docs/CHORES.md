# Mishka — Chores design

The household chores feature. Built as a release train: v0.4.0 one-off chores (list, assign, done/reopen, points, overdue) → v0.4.1 recurring chores + round-robin rotation.

This doc grows monotonically per release. v0.4.0 is the only section populated today; v0.4.1 appends its section when it lands.

## v0.4.0 — one-off chores

### Time & overdue model

`due_at_local` is a wall-clock `TIMESTAMP` interpreted in the chore's `timezone` (IANA, copied from the household at create-time) — the same DST-safe model as calendar events, not UTC. A chore with **no due date** (`due_at_local IS NULL`) is never overdue.

Overdue is computed **in PHP, against the chore's own `timezone`**, in both `ChoresController` and `HomeController`:

```
overdue ⟺ due_at_local !== null
        AND not done
        AND new DateTimeImmutable(due_at_local, choreTz) < new DateTimeImmutable('now', choreTz)
```

SQL `NOW()` / `CURRENT_TIMESTAMP` is deliberately NOT used — it's UTC/server-clock, which would flip a NZ household's "overdue" boundary ~13 hours early. The two controllers have no shared base class, so the tiny predicate is intentionally duplicated.

### Completion & points

`completed_at` is the **sole done-indicator** (NULL = open; set = done). `markDone` is idempotent (`WHERE completed_at IS NULL`), so two people clicking Done at once is harmless and the first completer keeps the credit. `completed_by` records who clicked Done. Reopen clears both fields.

Points are a **live aggregate query**, not a ledger. `pointsTallyForHousehold` sums `points` over completed chores, credited to `COALESCE(completed_by, assigned_to)` — the **doer** — and is driven off `household_members` so:
- every current member appears (0 if they've earned nothing);
- a departed member silently drops off the board;
- a chore assigned to a since-removed member but completed by a current one credits the **doer** (no points lost to a "ghost").

`ORDER BY MIN(joined_at)` keeps the grouped query valid under PostgreSQL's strict GROUP BY rule (SQLite is permissive — only the PG smoke test catches a regression here).

**Documented live-tally limitations** (accepted for a personal family app; the user chose "simple tally, easy to extend later"):
- Editing the `points` or `assigned_to` of an *already-completed* chore changes the historical total.
- Deleting a completed chore removes its points. (The "Done" section — rather than a delete-to-declutter habit — is the mitigation: completed chores stay visible without being deleted.)
- If the completer's *account* is deleted, `completed_by` is SET NULL and credit falls back to `assigned_to`.

A durable append-only ledger (immune to all of the above) is the v0.4.2+ extension path if history ever needs to be permanent.

### Assignment

`assigned_to` is nullable (`NULL` = unassigned). On create/update the controller validates the posted id against `HouseholdRepository::isMember` for the active household — a non-member or non-numeric value is silently coerced to NULL (matching the calendar's trust model, not a 422). There's no DB-level "assignee must be a member of this chore's household" constraint (it's a 3-way relationship), so this is an application invariant.

The chore list resolves the assignee's display name against the *current* member list; a chore still pointing at a since-kicked member shows "Unassigned" without any DB mutation.

### Permissions

Any household member may create, edit, delete, complete, or reopen any chore — the same high-trust model as the calendar. Delete carries a `confirm()` dialog (the only irreversible action); done/reopen are instant because they're reversible.

### Display

The `/chores` page partitions chores into an **open list** (ordered by due date, NULL-due last) and a collapsible **"Done" section** ordered most-recently-completed first. The home page surfaces the points board + an "N open, M overdue" count. Both are presentation-only — `listForHousehold` returns all chores in one query and the controller splits them.

### Input whitelist

POST create/update honour only `[title, description, points, due_at_local, assigned_to]`. `household_id`, `created_by`, `timezone` (forced from the household), `completed_*`, `schedule_id`, `occurrence_date`, and system columns are never accepted from form input. `points` is shape-checked (`/^\d+$/`, ≤ 1000; blank → 0) before casting; a blind `(int)` cast is avoided so `"ten"` is a 422, not a silent 0.

### Forward-compat (inert columns for v0.4.1)

`schedule_id` + `occurrence_date` ship NULL/inert. Under v0.4.1's model, a recurring chore is a `chore_schedules` *template* and each generated occurrence is a `chores` row carrying `schedule_id` (back-link) + `occurrence_date` (which occurrence it fills) — everything else a generated instance needs already exists on the table. `schedule_id` is a bare `INTEGER` with **no DB FK** because the no-ALTER schema convention can't add the constraint once `chore_schedules` ships; integrity is app-enforced. There is deliberately **no** defensive `schedule_id IS NULL` filter on `listForHousehold` (unlike the calendar's anti-double-render filter): chore templates live in a separate table, so v0.4.1's generated instances are first-class list items that should appear.

### Concurrency

No optimistic-concurrency token (`_expected_updated_at`) in v0.4.0 — unlike event edits. A chore is a small record with no cascade; the done-toggle is idempotent; last-writer-wins on a title/points edit is acceptable for a family hub.

## v0.4.1 — recurring chores + round-robin

A recurring chore is a **template** (`chore_schedules`); concrete occurrences are **generated as ordinary `chores` rows** (Tody-style), so completion / points / overdue all reuse the v0.4.0 machinery. RRULE construction reuses `App\Calendar\RruleTranslator` (injected — the anchor date is passed as the rule's "start" so weekly/monthly phase correctly).

### Generation (ChoreScheduleGenerator) — lazy, bounded, idempotent

Occurrences materialise **lazily on view** (no cron) in both `ChoresController::index` and `HomeController` — best-effort (wrapped so a hiccup never 500s the page) and idempotent.

The critical subtlety: **`recurr` always iterates from the schedule's anchor (DTSTART)** — there's no "expand from date X". So the generator expands from the anchor with a `virtualLimit` sized to *reach* the horizon, but **clamps the materialised window** to `(genFrom, genTo]` where `genFrom = generated_through ?? max(anchor, now − 14d)` and `genTo = now + 14d`, with a hard `MAX_GENERATE_PER_RUN = 60` circuit-breaker. A `chore_schedules.generated_through` watermark records progress so re-views are near-free and a far-past anchor "catches up" over a few views instead of generating thousands of rows in one batch. Without the clamp, a small limit would generate **zero** rows (all ancient occurrences clipped) and a large one would explode — the round-2 skeptic's B1.

Each occurrence's `occurrence_date` (the UNIQUE key) and `due_at_local` are formatted with a single pinned `Y-m-d H:i:00` so the `UNIQUE(schedule_id, occurrence_date)` index dedupes deterministically; concurrent page loads that race to insert the same occurrence swallow the unique violation. Occurrences are expanded in the schedule timezone and formatted as wall-clock, so a "daily 9am" schedule stays 9am local across DST.

### Round-robin (rotate) vs fixed

`assignment_mode = 'fixed'` pins every occurrence to `fixed_user_id` (or NULL if that member has left). `assignment_mode = 'rotate'` cycles assignees across current members in join order. The cursor is **`last_assigned_user_id`** — a *durable user id*, not an index — and the next assignee is a **pure function** of `(last_assigned_user_id, current members in join order)`: the member after `last` if still present, else the head of the roster. This survives member removal/join (no index renumbering) and concurrent generation, because the cursor is advanced **only inside the same step as a successful insert**, generating occurrences oldest-first. A 3-member roster yields A, B, C, A, B…

### Edit / delete semantics

- **Edit refreshes upcoming**: updating a schedule deletes its not-yet-done future occurrences and rewinds `generated_through` to now, so the next view regenerates them from the new rule. Completed occurrences are immutable history. A manual per-occurrence tweak to an upcoming instance is reset (accepted trade-off).
- **Delete**: `chores.schedule_id` has **no DB FK**, so deleting a schedule is app-coordinated — open generated instances are dropped, completed ones are **detached** (`schedule_id` set NULL) so their points history survives and a future reused schedule id can't collide on the UNIQUE index.

### Skip / reassign a single occurrence

No new machinery: a generated occurrence is a real chore. **Skip** = delete it (`POST /chores/{id}/delete`); the watermark means it won't regenerate. **Reassign just this one** = edit its `assigned_to` (`POST /chores/{id}`); the schedule's rotation cursor is untouched.

## Future work (post-v0.4.1)

- Pause/deactivate a schedule (currently delete-only).
- Durable points ledger / immutable history / weekly leaderboards / badges / streaks.
- Per-chore participant pools (rotation currently cycles all members).
- Penalty (negative) points.
- Notifications / reminders (the household already gets these via the v0.3.2 iCal feed).
