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

## Future work (post-v0.4.0)

- **v0.4.1** — recurring chores (`chore_schedules` + RRULE, reusing `App\Calendar\RruleTranslator`) + round-robin rotation across all members in join order, with skip/reassign of a single occurrence. Generation is lazy-on-view (no cron), bounded by a horizon, idempotent via a `UNIQUE(schedule_id, occurrence_date)` partial index.
- Durable points ledger / immutable history / weekly leaderboards / badges / streaks.
- Per-chore participant pools (round-robin currently cycles all members).
- Penalty (negative) points.
- Notifications / reminders (the household already gets these via the v0.3.2 iCal feed).
