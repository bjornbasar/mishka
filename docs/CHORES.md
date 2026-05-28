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

Points credit the **doer** (`COALESCE(completed_by, assigned_to)`). v0.4.0 shipped this as a live aggregate over the `chores` table; **v0.4.2 replaced it with a durable ledger** (see the v0.4.2 section) — the live-aggregate limitations below are now RESOLVED and kept only as history of the design path:
- ~~Editing the points/assignee of an already-completed chore changed the historical total.~~ → ledger snapshots points at completion, immutable.
- ~~Deleting a completed chore removed its points.~~ → the ledger row survives (chore_id SET NULL).
- ~~A completer's account deletion fell back to crediting the assignee.~~ → the ledger froze the doer; account deletion orphans the row (unattributed) rather than re-crediting someone who didn't do it.

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

## v0.4.2 — points ledger + leaderboard + pause + pools

### Durable points ledger

`chore_points_ledger` is an append-only history. `markDone` writes one row **iff** its guarded UPDATE actually transitioned the chore (`Connection::run() === 1`), inside one nested-txn-guarded block, crediting the doer with the chore's points captured at completion via a single UTC timestamp written to both the chore and the ledger row — so a double-click yields exactly one row. `reopen` **deletes** the chore's ledger row (un-credit), keeping the invariant "≤1 live row per chore" without a `UNIQUE(chore_id)` (which would block reopen→recomplete). FKs: `household_id` CASCADE; `chore_id` SET NULL (deleting a completed chore keeps its points history); `credited_user_id` SET NULL (a deleted account orphans the row rather than losing or mis-crediting the points). Existing completions are **backfilled** idempotently (`NOT EXISTS`) by `schema.sql` so the board isn't empty on ship day; backfilled points are pre-this-week, so they land in all-time only.

### Weekly + all-time leaderboard

`leaderboardForHousehold(hid, weekStartUtc)` returns per-member `total_points` + `week_points` in one query, driven off `household_members` (current members at 0; departed drop off but their ledger history persists), ranked `week_points DESC, MIN(joined_at) ASC`. The week boundary is **Monday 00:00 in the household tz**, computed in PHP and converted to a **UTC** string so it compares correctly against the ledger's UTC `completed_at` on both PG (TIMESTAMPTZ) and SQLite (TEXT). The board (on /chores + home) shows "N this wk · M all-time".

### Pause / resume

`chore_schedule_pauses` (presence of a row = paused; real FK CASCADE). `generateForHousehold` skips paused schedule ids **before** calling `generateForSchedule` — never inside it, which unconditionally advances the watermark; skipping there would drift `generated_through` forward while paused and lose occurrences. Resume rewinds `generated_through` to now (forward-only — a long pause doesn't spawn a backlog). Already-generated occurrences of a paused schedule are kept (pause ≠ delete).

### Per-chore participant pools

`chore_schedule_participants` (composite PK; both FKs CASCADE). Rotation cycles `listMembers ∩ pool` in join order when the pool has rows, else all members (v0.4.1 behaviour). A pool whose members have all left the household → the occurrence is **unassigned** (NULL), never a silent fall-back to people the user didn't pick. The generator computes the candidate list once per schedule (no N+1); the schedule form has a member-checkbox picker under "Rotate" (leave all unchecked = everyone); fixed mode clears the pool.

## v0.4.3 — badges + weekly streaks

All derived from the v0.4.2 `chore_points_ledger` — **no schema changes**. The leaderboard rows on both `/chores` and the home page gain per-member badges (small emoji + `title=` for the description) and a weekly streak (🔥 N, shown only when N ≥ 2).

### Badges (stateless, re-derived per render)

Six escalating badges as a single registry on `App\Chores\Achievements` (returned from a method, not a `const` — PHP rejects closures in constant expressions). Criteria are pure functions over a per-member stats array (`total_completions`, `total_points`, `week_points`, `streak`). Presentation (emoji + title) lives in `config/badges.php` and is registered as a Twig global (`badge_meta`) alongside `brand` — the service never sees emoji.

| Code | Emoji | Criterion |
|---|---|---|
| `first_chore` | 🌱 | total_completions ≥ 1 |
| `ten_chores` | ⭐ | total_completions ≥ 10 |
| `fifty_chores` | 🏅 | total_completions ≥ 50 |
| `centurion` | 💯 | total_points ≥ 100 |
| `five_hundred` | 🏆 | total_points ≥ 500 |
| `four_week_streak` | 🔥 | streak ≥ 4 |

### Weekly streaks — DST-safe via `WeekWindow`

A streak is the count of consecutive weeks (Monday in household tz) with ≥ 1 ledger row crediting the member, walked back from the most recent activity week. Broken if the latest activity is older than (this week − 1) — i.e. a *full* missed week.

The walk is the bit that needs care. Monday 00:00 NZDT (UTC+13) and Monday 00:00 NZST (UTC+12) are **not** 168 UTC-hours apart — adjacent Monday-NZ markers are 169 UTC-hours apart at the end of DST and 167 at the start. A naive `cursor − 7 days` step on a UTC string drifts by exactly one hour across every transition and silently breaks every streak that spans one. **`App\Chores\WeekWindow` is the single home of the DST fix**: every `−1 week` step runs `->modify('-1 week')->setTime(0, 0, 0)` *in household tz* and only converts to UTC for the string representation, so each step always lands on the correct Monday-household-midnight marker regardless of DST. `WeekWindow` is exercised by `tests/Chores/WeekWindowTest.php` (NZ end-of-DST + NZ start-of-DST + non-DST control); the Achievements unit tests pin both transition directions end-to-end.

### Query / wiring

`ChoreRepository::leaderboardForHousehold` gains a `COUNT(l.id) AS total_completions` aggregate (still one query; LEFT JOIN means zero-completion members still appear at 0). `ChoreRepository::recentCompletionsForHousehold(hid, sinceUtc)` returns `user_id → list<completed_at>` for the streak walk, scoped to current `household_members` (departed members drop off automatically; accounts whose credit was SET-NULL'd never join because NULL ≠ int).

Both controllers (`ChoresController` + `HomeController`) call the same `achievementsBoard()` helper (mirrors the `isOverdue` duplication precedent), feeding the leaderboard + recent-completions through `Achievements::compute()` keyed by `user_id` and merging `badges` + `streak` into the board rows. The shared Twig macro `templates/_chore_leaderboard.twig` renders each row identically on both pages — no markup drift.

## Future work (post-v0.4.3)

- Penalty (negative) points (needs a separate `chore_penalties` table since the ledger has `CHECK(points >= 0)`).
- Daily streaks alongside weekly.
- Persistent `earned_at` badge history / chronological badge feed; pluggable badge registry.
- Dedicated `/badges` page (today the inline display is enough).
- Notifications / reminders (the household already gets these via the v0.3.2 iCal feed).
