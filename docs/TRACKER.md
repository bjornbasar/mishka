# Mishka — Tracker (Health)

> Living design doc for the Tracker feature module. Grows across the 5-phase v0.8.x train.
> UI-labelled **Health** in the nav; internal namespace `Tracker` (matches the plain-functional
> naming of Chores/Calendar/Mail/etc.).
> Historical handoff — see `docs/TRACKER-PLAN.md` (frozen at plan-time).

## 1. Ethos

Private food + exercise tracker for **two people (Bjorn + wife)**, run as a **mishka module**.
Motivation: dissatisfaction with off-the-shelf apps — feature bloat, ads, and their food
databases don't know **common Filipino-household food (in an NZ context)**. Logging in
existing apps forces you to itemise ingredients just to get a kcal number.

Every scope decision defers to: **the two of us log what we actually ate/did in <5 seconds.**
If a feature doesn't serve that, it doesn't ship in this train.

## 2. Locked decisions

| Decision | Choice | Where documented |
|---|---|---|
| Home | mishka **module** (not standalone, not kuma) | TRACKER-PLAN.md §2 |
| Calorie model | **TDEE / BMR baseline** (net = intake − total expenditure) | TRACKER-PLAN.md §5, lands v0.8.2 |
| Food search | **serving-first, no ingredient drilling** | v0.8.0 §3 below |
| Leaderboard | **effort + consistency, intake private** | v0.8.3 candidate (weighting decided during that release) |
| Offline logging | later bonus (v0.8.4) | ROADMAP.md |
| Client stack | server-rendered Twig + vanilla JS (no htmx/Alpine) | v0.8.0 §5 below |
| Internal namespace | `Tracker` (plain functional English, matches mishka's convention) | ROADMAP.md § locked decisions |
| UI label | `Health` | ROADMAP.md § locked decisions |
| Seed data licensing | derived per-serving kcal + `source` attribution only | v0.8.0 §4 below |
| Bowl calibration | fixed household default grams, editable per dish | v0.8.0 §3 below |

## 3. Data model (v0.8.0 landed)

Three greenfield tables in `db/schema.sql`, all `CREATE TABLE IF NOT EXISTS`. No PG_ONLY block —
all new tables.

- **`foods`** — dish library. `household_id NULL` = global seed row shared by every household;
  `household_id NOT NULL + source='custom'` = household's own dish. `name_lc` is repo-owned
  (`mb_strtolower(name)`, written by FoodRepository on every create/update; never callable) and
  drives `idx_foods_name_lc` for case-insensitive LIKE search. `source CHECK IN (philfct, nzfcd,
  usda, custom)` for provenance. Partial `UNIQUE(name, source) WHERE household_id IS NULL`
  dedups the global seed but permits household-scoped duplicates.

- **`food_servings`** — serving units per dish, kcal baked in (no ingredient composition at
  log time — per §1 ethos). Grams for portion realism (household bowl calibration). Partial
  `UNIQUE(food_id) WHERE is_default = TRUE` enforces at-most-one default; FoodServingRepository
  demote-then-promotes inside a nested txn to guarantee exactly-one when a caller sets
  `is_default` via create/update. Cascade-deletes with the parent food.

- **`food_log`** — one row per "I ate this". `kcal_snapshot` captures the value at log time so
  future dish edits/deletes don't rewrite history (mirrors `chore_points_ledger` from
  DOCS #31). `food_id` + `serving_id` use `ON DELETE SET NULL`; deleted-dish log rows render
  as `(deleted dish)` via LEFT JOIN COALESCE. `logged_on DATE` is the **household-local
  calendar date**, computed in PHP via `App\Tracker\LocalDay::today($tz)`. Never `CURRENT_DATE`
  or `NOW()` — DB session TZ is the wrong clock. Per-user + per-household scoping enforced
  in `FoodLogRepository::listForUserDay(int $userId, int $householdId, string $loggedOn)`.

**FK semantic recap** (matches DOCS #31 + #53 conventions):
- `foods.household_id → households CASCADE` — household delete drops custom dishes.
- `foods.created_by → users SET NULL` — user delete leaves author as "Deleted user".
- `food_servings.food_id → foods CASCADE` — dish delete drops its servings.
- `food_log.household_id / user_id → CASCADE` — hard-delete on household/user removal.
- `food_log.food_id / serving_id → SET NULL` — historical rows survive dish removal.

## 4. Seed data (v0.8.0)

`db/seed/tracker_foods.json` — bundled 41-dish seed. Envelope shape `{"version": 1, "foods":
[…]}` for forward-compat with v0.8.1's `tracker_exercises.json`. Import via
`bin/karhu tracker:seed-foods`; idempotent (driver-portable `INSERT ON CONFLICT ... DO NOTHING`
PG / `INSERT OR IGNORE` SQLite against the partial UNIQUE `idx_foods_seed_unique`).

**Coverage**:
- **Filipino** (~15): adobo variants, kare-kare, sinigang na baboy, tinolang manok, chicken
  menudo, afritada, sinangag, tapsilog, pancit canton, lumpiang shanghai, lechon kawali,
  longganisa, tocino, champorado, arroz caldo.
- **NZ** (~10): kūmara roasted, hoki baked, lamb chop, Weet-Bix, mince pie, flat white,
  Marmite/Vegemite on toast, pavlova, hokey pokey ice cream.
- **Universals** (~15): white/brown rice, chicken breast, beef mince, boiled/scrambled eggs,
  whole milk, sourdough, banana, apple, black coffee, water, roasted vegetables, green
  salad, plain yogurt.

kcal values are **starting-point estimates** from PhilFCT (FNRI/DOST) + NZ Food Composition
Database (Plant & Food Research / MoH) + USDA FoodData Central. Household-specific bowl
sizes should be edited in the Library UI after first-run. **Editing does NOT clear the
source column** — kept as provenance.

Wired into CI's deploy job as an AFTER-migrate step; non-zero exit fails the deploy per
DOCS #63.

## 5. Module layout

- `app/Tracker/`
  - `LocalDay.php` — household-local calendar-date helper (see §3).
  - `FoodRepository.php` — CRUD + search. `name_lc` write-through, `updated_at` bump on every
    UPDATE, LIKE-escape corner cases handled. Search uses INNER JOIN `food_servings ON ... AND
    is_default = TRUE` so default-less dishes are dropped from results (server-side null-guard).
  - `FoodServingRepository.php` — CRUD + demote-then-promote invariant. Boolean portability
    via SQL `TRUE`/`FALSE` literals (PG BOOLEAN column rejects integer implicit cast;
    PHP-bool→PDO binding is driver-inconsistent).
  - `FoodLogRepository.php` — write + read for the Today view. LEFT JOIN foods + food_servings
    so deleted-dish rows survive with COALESCE fallback strings. `MEAL_ORDER` const drives
    portable `CASE meal WHEN ...` ORDER BY.
- `app/Controllers/`
  - `TrackerController.php` — `GET /health` (Today, meal-grouped).
  - `FoodLogController.php` — form + POST + delete + JSON search. **Intra-class route order**:
    literal segments (`/health/log/food/search`) declared as methods BEFORE `{id}` routes
    (`/health/log/food/{id}/delete`) or karhu's sequential matcher would 404.
  - `FoodLibraryController.php` — browse + CRUD. Same intra-class order rule for
    `/health/foods/new` vs `/health/foods/{id}`.
- `app/Commands/TrackerSeedFoodsCommand.php` — the import command (see §4).
- `templates/tracker/` — `today.twig`, `log_food.twig`, `foods_index.twig`, `food_form.twig`.
- `db/seed/tracker_foods.json` — the bundled seed.
- Live-search IIFE in `templates/layout.twig` — activates on `[data-live-search]` markers,
  debounces 250ms, XSS-safe via `textContent`.
- Nav item `<a href="/health">Health</a>` in `layout.twig:439`.

## 6. Screens (v0.8.0 shipped subset)

1. **Today** (`/health`) — meal-grouped log of what you've eaten today. Empty state:
   "Nothing logged yet today. Tap **+ Add** on any meal below to start." Per-user view;
   per-user tabs (you / wife) are v0.8.2.
2. **Log food** (`/health/log/food?meal=X`) — live-search input → tap result → set qty →
   Log it.
3. **Library** (`/health/foods`) — browse global seed + household-added dishes; edit any;
   add new. Only place ingredients would appear if v0.8.x+ adds ingredient composition;
   v0.8.0 skips ingredients entirely.

Not yet shipped:
- Log exercise (v0.8.1)
- Today energy-balance widget (v0.8.2)
- Profile screen (sex / birth year / height / base-activity / weight entry) (v0.8.2)
- Household leaderboard (v0.8.3)
- Offline logging (v0.8.4)

## 7. Routes (v0.8.0)

| Method | Path | Handler | Purpose |
|--------|------|---------|---------|
| GET | `/health` | TrackerController::today | Today dashboard |
| GET | `/health/log/food` | FoodLogController::form | Log-food form (query param `meal`) |
| GET | `/health/log/food/search` | FoodLogController::search | JSON search endpoint (`Cache-Control: no-store`) |
| POST | `/health/log/food` | FoodLogController::store | Create food_log row → 303 to `/health` |
| POST | `/health/log/food/{id}/delete` | FoodLogController::delete | Owner-scoped delete |
| GET | `/health/foods` | FoodLibraryController::index | Library browse |
| GET | `/health/foods/new` | FoodLibraryController::createForm | New-dish form |
| POST | `/health/foods` | FoodLibraryController::store | Create food + first serving in one txn |
| GET | `/health/foods/{id}` | FoodLibraryController::edit | Edit form (dish name/aliases/tag) |
| POST | `/health/foods/{id}` | FoodLibraryController::update | Update dish (servings edit not yet UI-exposed in v0.8.0) |
| POST | `/health/foods/{id}/delete` | FoodLibraryController::delete | Hard delete (food_log rows survive via SET NULL) |

All routes gate on `Session::has('user_id')` + `active_household_id` + `HouseholdAuthorizer::requireMember`.
Search endpoint is GET-safelisted by karhu Csrf middleware (no CSRF token needed).

## 8. Tests

- `tests/Tracker/LocalDayTest.php` — timezone-rollover, format shape.
- `tests/Tracker/FoodRepositoryTest.php` — CRUD, name_lc write-through, LIKE-escape corner
  cases, INNER JOIN excludes default-less dishes, seed uniqueness partial-index behaviour.
- `tests/Tracker/FoodServingRepositoryTest.php` — default-swap invariant, cascade delete,
  partial UNIQUE catches direct-INSERT bypass.
- `tests/Tracker/FoodLogRepositoryTest.php` — meal + qty validation, meal-ordered list,
  LEFT JOIN survives food deletion, per-user + per-household scoping, deleteOwnedById auth,
  daily-totals aggregation.
- `tests/Commands/TrackerSeedFoodsCommandTest.php` — first-run seeds, idempotency,
  missing/malformed/unknown-version JSON, empty foods array.
- `tests/Controllers/TrackerControllerTest.php` — auth redirect, empty-state render, nav link.
- `tests/Controllers/FoodLogControllerTest.php` — form render, live-search data-attrs
  contract (catches template drift), JSON search shape + no-store header, POST create +
  rejection paths (invalid meal, foreign-household food), delete own entry.

## 9. What lands next (Tracker train complete)

All 5 Tracker phases shipped: v0.8.0 foods · v0.8.1 exercise + weight · v0.8.2 profile
+ BMR + widget · v0.8.3 leaderboard + badges + streaks · v0.8.4 offline logging + PWA
shortcuts. Future tracker work (if any) will be plan-mode-driven per its own ROADMAP
entries. See `docs/ROADMAP.md`.

## 10. v0.8.1 — Exercise + weight_log (shipped)

Second Tracker release. `weight_log` was **brought forward from v0.8.2** at plan-time so
kcal computation lands from day one — v0.8.2 correspondingly shrinks to profiles + BMR.

### Data model (v0.8.1)

Three greenfield tables inserted after v0.8.0's `food_log` block:

- **`exercises`** — MET catalog. `household_id NULL` = global seed row (Compendium 2024).
  `type` enum: `duration` vs `strength`. `met NUMERIC(5,2)` repo-bounded to `(0, 25]` on
  BOTH create and update (Compendium max ~23; 25 is safety ceiling). `default_rom_m NULL`
  for exercises without documented range-of-motion (bodyweight, everyday activities).
  Partial `UNIQUE(name, source) WHERE household_id IS NULL` dedups global seed rows.

- **`weight_log`** — per-user time series. Deliberately NOT scoped to household — weight is
  inherently personal. `weight_kg NUMERIC(5,2)` repo-bounded to `[20.0, 300.0]` to catch
  typos (20 kg typo of 70 kg case). No upsert — measurements are historical facts.
  `latestForUser(userId)` drives ExerciseLogController's kcal + future v0.8.2 BMR calc.

- **`exercise_log`** — discriminated union by `exercise_type_snapshot`:
  - Duration: `minutes` populated; `sets`/`reps`/`load_kg` NULL. `met_minutes = met × minutes`
    (leaderboard currency, weight-independent). `kcal_snapshot` from `MET × 3.5 × weight_kg
    ÷ 200 × minutes` when weight known, else NULL.
  - Strength: `sets`/`reps`/`load_kg` populated; `minutes` NULL. `met_minutes = NULL` (no
    set-rep → minutes conversion — user-locked at plan-time; rationale below).
    `kcal_snapshot` from mechanical-work `0.011723 × load_kg × default_rom_m × reps` when
    both load and ROM known, else NULL.

**`exercise_name_snapshot` + `exercise_type_snapshot`** columns preserve context after
exercise rename or delete — deliberate divergence from food_log's LEFT JOIN + COALESCE
pattern. Rationale: type materially changes render (duration "42 min" vs strength "3×10 @
20 kg"). Documented in DOCS #71.

### Design decision — no set-rep → minutes conversion (user-locked)

TRACKER-PLAN.md §4 originally proposed deriving strength session minutes from `sets × reps
× 3s + sets × 30s rest`. This was rejected at v0.8.1 plan time:

> "exercises are either sets-reps OR minutes (a person can do set-rep exercise with lax
> in-betweens, and there's no 'reps' on a treadmill only minutes) — set-rep field OR minute
> field, not 'consolidated into one field', converting set-rep to minutes"

Storage + presentation keep the branches distinct. Duration entries produce `met_minutes`
(the v0.8.3 leaderboard currency, weight-independent). Strength entries have
`met_minutes = NULL` — strength contribution to the v0.8.3 leaderboard is a scope-open
question, likely a separate signal (count of sessions? sum of mechanical-work kcal? blended
score?).

### Kcal formulas (`App\Tracker\ExerciseKcalCalculator`)

Static-only, no state. Split by branch:

- **Duration branch**:
  - `metMinutes(met, minutes) = met × minutes` — weight-independent, always populated.
  - `durationKcal(met, minutes, weightKg) = round(met × 3.5 × weightKg / 200 × minutes)` —
    NULL when `weightKg` is null (honest snapshot: "we didn't know weight at write time";
    later weight entries do NOT retro-populate historical NULLs — matches
    chore_points_ledger snapshot semantics from DOCS #31).

- **Strength branch**:
  - `mechanicalWorkKcal(loadKg, romM, reps) = round(0.011723 × loadKg × romM × reps)` —
    weight-independent, NULL when ROM undocumented OR reps ≤ 0 OR load ≤ 0.

### Module layout (v0.8.1 additions)

- `app/Tracker/` — `LocalDay.php` (v0.8.0), `ExerciseRepository.php`, `WeightLogRepository.php`,
  `ExerciseLogRepository.php`, `ExerciseKcalCalculator.php`.
- `app/Controllers/` — `ExerciseLogController.php`, `ExerciseCatalogController.php`,
  `WeightController.php`. Intra-class route order: literal segments (e.g. `/health/log/exercise/search`)
  declared as methods BEFORE any `{id}` routes in the same class (v0.8.0 discipline).
- `app/Commands/TrackerSeedExercisesCommand.php` — same shape as v0.8.0's foods seed;
  ctor takes ONLY `Connection` (exercises have no children analogous to food_servings).
- `templates/tracker/` — `today.twig` extended (Exercise section + `[Foods] [Exercises]
  [Weight]` header links), `log_exercise.twig` (NEW; with bespoke IIFE — see below),
  `exercises_index.twig` (NEW), `exercise_form.twig` (NEW), `weight_form.twig` (NEW).
- `db/seed/tracker_exercises.json` — 36 exercises across duration + strength categories,
  Compendium 2024 sourced with `{"version": 1, "exercises": [...]}` envelope.

### Bespoke live-search IIFE for exercises

Layout.twig's food-shaped IIFE (lines 618-670) hard-skips rows without `default_serving` —
would drop every exercise row from search results. Fix: `log_exercise.twig` ships its own
IIFE using a distinct `[data-exercise-search]` marker (NOT `[data-live-search]`). Layout.twig
untouched. Bespoke IIFE:
- Fetches `/health/log/exercise/search?q=X`
- Renders as `textContent` (XSS-safe)
- Populates hidden `exercise_id`
- **Toggles** the visible form branch based on `item.type` from the picked result — hides
  the sets/reps/load block when duration; hides the minutes input when strength

### Routes (v0.8.1)

| Method | Path | Handler | Purpose |
|--------|------|---------|---------|
| GET  | `/health/log/exercise` | ExerciseLogController::form | Log-exercise form |
| GET  | `/health/log/exercise/search` | ExerciseLogController::search | JSON search endpoint (`no-store`) |
| POST | `/health/log/exercise` | ExerciseLogController::store | Create exercise_log entry → 303 to `/health` |
| POST | `/health/log/exercise/{id}/delete` | ExerciseLogController::delete | Owner-scoped delete |
| GET  | `/health/exercises` | ExerciseCatalogController::index | Catalog browse |
| GET  | `/health/exercises/new` | ExerciseCatalogController::createForm | New-exercise form |
| POST | `/health/exercises` | ExerciseCatalogController::store | Create custom exercise |
| GET  | `/health/exercises/{id}` | ExerciseCatalogController::edit | Edit form |
| POST | `/health/exercises/{id}` | ExerciseCatalogController::update | Update exercise |
| POST | `/health/exercises/{id}/delete` | ExerciseCatalogController::delete | Hard delete (log rows survive via SET NULL) |
| GET  | `/health/weight` | WeightController::form | Weight entry form + history |
| POST | `/health/weight` | WeightController::store | Create weight_log entry |
| POST | `/health/weight/{id}/delete` | WeightController::delete | Owner-scoped delete |

All routes gate on `Session::has('user_id')` + `active_household_id` + `HouseholdAuthorizer::requireMember`
(WeightController skips requireMember since weight is per-user, but still requires active
household for the timezone).

### Tests (v0.8.1 additions)

- `tests/Tracker/ExerciseKcalCalculatorTest.php` — formulas + null branches.
- `tests/Tracker/ExerciseRepositoryTest.php` — CRUD + name_lc + MET bounds on create AND update.
- `tests/Tracker/WeightLogRepositoryTest.php` — bounds + latest + ordering + ownership.
- `tests/Tracker/ExerciseLogRepositoryTest.php` — both branches happy + reject paths,
  snapshot survives exercise deletion, daily totals aggregation, delete-owned.
- `tests/Commands/TrackerSeedExercisesCommandTest.php` — first-run + idempotency + missing
  file / malformed JSON / unknown version / empty exercises.
- `tests/Controllers/ExerciseLogControllerTest.php` — form + search-attrs contract + JSON
  no-store header + duration branch with/without weight + strength branch mechanical-work
  kcal + foreign-household rejection + delete-owned.
- `tests/Controllers/WeightControllerTest.php` — auth redirect + empty state + POST create +
  bounds rejection + delete-owned.

ExerciseCatalogController pure CRUD tests deferred to v0.8.1.1+ if bugs surface (mirrors
v0.8.0 FoodLibraryController pattern).

### What's next in the Tracker train

- **v0.8.2** — `tracker_profiles` (sex / birth year / height / base-activity) + Mifflin-St
  Jeor BMR + Today energy-balance widget. Base-activity factor MUST represent "daily life
  excluding exercise" (double-count trap per TRACKER-PLAN.md §5).
- **v0.8.3** — Household leaderboard from duration-branch met_minutes. Strength contribution
  design open. Effort/consistency badges + streaks reusing Chores' `BadgeAwardRepository` +
  `WeekWindow`/`DayWindow` (DOCS #46 pattern).
- **v0.8.4** — Offline logging + PWA `shortcuts` array (deferred at v0.8.1 plan-time).

## 11. v0.8.2 — `tracker_profiles` + BMR + Today energy-balance widget (shipped)

Third Tracker release. `weight_log` already landed in v0.8.1 so v0.8.2's scope is just the
profile side (sex / birth-year / height / base-activity) + Mifflin-St Jeor + a Today
widget that renders intake vs total expenditure.

### Data model (v0.8.2)

One greenfield table inserted after v0.8.1's `exercise_log` block, before the PG_ONLY EOF
fence:

- **`tracker_profiles`** — one row per user (`PRIMARY KEY(user_id) REFERENCES users(id) ON
  DELETE CASCADE`). Fields: `sex VARCHAR(10)` CHECK `('male','female')`; `birth_year INTEGER`
  repo-bounded to `[1900, currentYear − 5]` (Plan-agent finding #5 — family-scale, no
  toddlers logging, and rejects a fat-fingered current-year birth year that would give age=0
  and BMR≈1748); `height_cm NUMERIC(5,1)` bounded `[50.0, 250.0]`; `base_activity
  NUMERIC(4,3)` bounded `[1.0, 2.5]`. `created_at + updated_at TIMESTAMPTZ NOT NULL DEFAULT
  NOW()`.

No BOOLEAN columns — no `TRUE`/`FALSE` portability trap this time. No auxiliary indexes —
PK on user_id covers `WHERE user_id = :uid`. FK CASCADE: profile follows the user's
lifecycle.

### Design decision — the double-count trap (UI-copy mitigation)

Textbook `TDEE = BMR × activity_factor`. The classical Mifflin-St Jeor factors (1.2
sedentary → 1.725 very active) BAKE EXERCISE INTO the number. Mishka already logs workouts
separately in v0.8.1's `exercise_log`. If a user picks 1.725 base_activity AND logs their
gym time, the widget double-counts.

Mitigation is UI copy only (un-unit-testable — UX mitigation). Base-activity SELECT carries
`EXCLUDING WORKOUTS` in the label + per-option semantics on each dropdown row (`Sedentary —
desk job, little walking (1.20)` through `Very active — physical job / lots of walking
(1.725)`) + a paragraph reinforcer: "Deliberate workouts are logged separately in the
Exercise section. Choosing a higher number here would double-count them." Dropdown chosen
over free-numeric input — smaller footgun surface. Widget also renders `Activity: 308 kcal
(base_activity 1.20 — excluding workouts)` inline + `title=` tooltip as a reinforcer for
returning users tweaking their profile 6 months later.

### BMR + expenditure formulas (`App\Tracker\BmrCalculator`, static-only)

- `calculate(sex, birthYear, heightCm, weightKg, nowYear=null)` — Mifflin-St Jeor (1990):
  `10·kg + 6.25·cm − 5·age + (male +5 / female −161)`. Returns null when any input is
  missing OR `age < 5` (defence-in-depth against fat-fingered birth year).
- `expenditure(bmr, baseActivity, exerciseKcalToday)` — `round(bmr × baseActivity +
  exerciseKcalToday)`. Nullable to preserve the null cascade when profile or weight is
  missing.

Age drift accepted trade-off: `year − birthYear` overestimates by up to 1 year for
pre-birthday users. BMR error < 1%. Matches TRACKER-PLAN.md §7's `birth_year INTEGER` (not
DOB) privacy decision.

### Repository — `App\Tracker\TrackerProfileRepository`

- `findByUserId(userId): ?array` — normalised row or null.
- `upsert(userId, data): void` — `INSERT ... ON CONFLICT (user_id) DO UPDATE SET ...,
  updated_at = CURRENT_TIMESTAMP`. Driver-portable pattern already in use at three sites
  (`UserPasswordChangeRepository:57-68`, `UserNotificationPrefsRepository:110`,
  `PushSubscriptionRepository:44`). INSERT clause OMITS `updated_at` (schema `DEFAULT NOW()`
  covers first-write); DO UPDATE writes `CURRENT_TIMESTAMP`. Mirrors
  `UserPasswordChangeRepository::stamp` idiom.
- `delete(userId): void` — provided for completeness; CASCADE already handles this at the
  DB level.
- All four fields validated at repo layer against `InvalidArgumentException` on
  out-of-range.

### Per-user daily aggregation (existing repos, new methods)

Plan-agent finding #1 — existing `dailyTotalsForHousehold` reads the WHOLE household.
Widget needs per-user scoping both for correctness AND for privacy (avoid loading other
users' rows into PHP memory even if never rendered). Added:

- `FoodLogRepository::intakeKcalForUserDay(uid, hid, day): int` — `COALESCE(SUM(kcal_snapshot),
  0)` scoped to `user_id + household_id + logged_on`. `kcal_snapshot` is NOT NULL on
  food_log, so COALESCE is defensive.
- `ExerciseLogRepository::exerciseKcalForUserDay(uid, hid, day): int` — same shape.
  `kcal_snapshot` IS nullable on exercise_log (strength w/o ROM; duration w/o weight);
  COALESCE(NULL, 0) treats them as 0 contribution — matches `dailyTotalsForHousehold`
  semantic.

Both extend their existing repo tests — no new test files.

### Controller — `App\Controllers\TrackerProfileController`

- `GET /health/profile` — renders `profile_form.twig` with the current profile (if any) +
  latest weight (for BMR preview) + entry form.
- `POST /health/profile` — upserts. 422 re-render on bounds fail (with `old` values for
  form repopulation). 303 redirect to `/health` with flash on success.
- Gates on `active_household_id` — profile is per-user but consistency with every other
  `/health/*` route wins (household provides TZ for `updated_at` display formatting).

### Today energy-balance widget

Extends `TrackerController::today` (v0.8.0/v0.8.1). Widget renders at the TOP of Today
(before meal sections) — it IS the primary Today number per TRACKER-PLAN.md §8 ethos.

State precedence (Plan-agent finding #3 — fresh user has neither profile nor weight;
profile CTA must win to avoid a redirect-to-weight-then-back CTA loop):

1. Profile missing (regardless of weight) → `state = 'needs_profile'` — CTA to
   `/health/profile`.
2. Profile present + weight missing → `state = 'needs_weight'` — CTA to `/health/weight`.
3. Both present → `state = 'complete'` — intake / expenditure / net breakdown.

State-complete math (Plan-agent finding #4 — draft's example numbers didn't add up). The
correct display split is `BMR + activity_delta + exercise` where `activity_delta = round(BMR
× (base_activity − 1.0))` — the "not-workouts everyday movement" component only, NOT `BMR ×
base_activity` (which would double-count BMR). Sum: `total_expenditure = BMR + activity_delta
+ exercise = BMR × base_activity + exercise`.

Example (male, 1985, 175 cm, 72.5 kg, base_activity 1.20, 380 kcal logged workouts):

```
Today's balance
Intake: 1,850 kcal                Expenditure: 2,228 kcal
─────────────────────────────────────────────────────────
Net: −378 kcal
  BMR:      1,540 kcal   (Mifflin-St Jeor)
  Activity:   308 kcal   (base_activity 1.20 — excluding workouts)
  Exercise:   380 kcal   (from your logged workouts)
```

### Privacy invariant

Per TRACKER-PLAN.md §5: intake / weight / expenditure / net are PRIVATE per user. Only
effort will be shared with the household (v0.8.3's leaderboard MET-minutes). The widget
renders ONLY the current user's own numbers.

Regression test: `TrackerControllerTest::test_today_does_not_leak_other_users_intake_or_weight`
— response-body integration test with distinct-value fingerprinting. User B is set up with
three distinct values (9876 kcal intake, 88.8 kg weight, 5432 kcal exercise) and user A's
Today body is asserted to NOT contain any of those strings. Plan-agent finding #2: leak
surface is the twig template, not the repos — the test operates on the full response body.

### Module layout (v0.8.2 additions)

- `app/Tracker/` — `BmrCalculator.php`, `TrackerProfileRepository.php`.
- `app/Controllers/` — `TrackerProfileController.php`. `TrackerController` ctor grows from 6
  to 8 params (adds `TrackerProfileRepository` + `WeightLogRepository`); new private
  `computeBalance(userId, householdId, today): array` method returns the state-fork.
- `templates/tracker/` — `profile_form.twig` (NEW); `today.twig` extended (balance widget
  block + `[Profile]` header link alongside `[Foods] [Exercises] [Weight]`).
- `config/controllers.php` — `TrackerProfileController` registered after v0.8.1's Tracker
  Phase 2 controllers.
- `public/bootstrap.php` — `\App\Tracker\TrackerProfileRepository::class` container binding
  (leading backslash per DOCS #55 Karhu\App aliasing rule).
- `tests/AppTestCase.php` — `$profileRepo` field + instantiation + container binding +
  `TrackerProfileController::class` in the router scan list; `TrackerController` factory
  reflects the new 8-param ctor.

BmrCalculator is static-only — no container binding needed (mirrors how
`ExerciseKcalCalculator` is used in v0.8.1).

### Tests (v0.8.2 additions)

- `tests/Tracker/BmrCalculatorTest.php` — male/female worked examples, null-input branches,
  `nowYear` override, `age<5` defence, expenditure aggregation.
- `tests/Tracker/TrackerProfileRepositoryTest.php` — CRUD, upsert insert-then-update path,
  bounds validation on all four fields, cascade-delete via user delete.
- `tests/Smoke/TrackerProfileRepositoryPgSmokeTest.php` — `ON CONFLICT (user_id) DO UPDATE`
  against real PG16 + NUMERIC(4,3) `base_activity = 1.375` INSERT + SELECT roundtrip
  assertion (Plan-agent finding #8 — turned a "should work but let's prove it" unknown into
  a regression guard).
- Existing `FoodLogRepositoryTest` + `ExerciseLogRepositoryTest` extended with
  `intakeKcalForUserDay` + `exerciseKcalForUserDay` cases.
- `tests/Controllers/TrackerProfileControllerTest.php` — GET form (empty + populated), POST
  create, POST update, height + birth_year bounds rejection, unauth redirect,
  no-active-household redirect.
- `tests/Controllers/TrackerControllerTest.php` — extended with 3 widget state fork tests
  (needs_profile / needs_weight / complete) + the privacy regression test above.

Full suite green at 909 / 2273 / 0 (was 871 / 2196 / 0 pre-v0.8.2 — +38 tests / +77
assertions). PHPStan L6 clean per commit.

## 12. v0.8.3 — household effort leaderboard + effort/consistency badges + streaks (shipped)

Fourth Tracker release. Reuses the Chores machinery to add a household-shared **effort**
leaderboard + badges + streaks, without ever leaking intake / weight / net (v0.8.2's
privacy invariant preserved).

### Reused Chores machinery

- `App\Chores\BadgeAwardRepository` — v0.6.13 household-scoped `badge_awards` table, UNIQUE
  dedup on `(household_id, user_id, badge_code)`. No schema change; new tracker codes just
  populate the same table.
- `App\Chores\Achievements::computeStreak` / `computeDailyStreak` — reusable streak walk
  helpers. v0.8.3 adds `computeDailyStreakLocal` — sibling walking household-local `Y-m-d`
  DATE strings instead of UTC instants (tracker's `logged_on` axis).
- `App\Chores\WeekWindow` + `DayWindow` — v0.8.3 adds local-DATE sibling helpers
  (`weekStartLocal / weekEndLocal / lookbackStartLocal` on `WeekWindow`; parallel siblings
  on `DayWindow`) returning `Y-m-d` strings for `WHERE logged_on ...` predicates.
- `config/badges.php` — extended with 9 new codes. Grid grows from 8 → 17.
- `/badges` template posture — new `/health/leaderboard` mirrors auth triad + roster JOIN
  idiom.

### Filter axis — `logged_on` DATE household-local

`exercise_log.logged_on` is a household-local DATE (via `LocalDay::today($tz)` at write).
Every v0.8.3 aggregate uses the DATE axis via `WHERE logged_on >= :ws AND logged_on < :we`
(half-open). Sister UTC helpers remain the axis for chore-side TIMESTAMPTZ columns
(`chore_points_ledger.completed_at`). Docblocks name the two-axes contract to prevent
future maintainers substituting `weekStartUtc` for `weekStartLocal` in a DATE predicate.

### Leaderboard shape

Ranking column: **weekly MET-minutes** (from `exercise_log.met_minutes`, populated only on
duration entries). Weight-independent by design in v0.8.1's `ExerciseKcalCalculator::
metMinutes = MET × minutes`.

Strength contribution — **session-count sidecar** (user-locked). Rank is MET-minutes only;
each row shows `(+N strength sessions)` as a secondary label. Does NOT convert reps →
minutes (honours v0.8.1's user-lock, DOCS #71).

Streaks: (a) **weekly-effort streak** — consecutive ISO weeks each ≥ 150 MET-minutes (WHO
moderate-activity baseline); (b) **daily-activity streak** — consecutive days with any log
entry. Both walk 52-week lookback.

Zero-effort household → single centred "log a workout to break the seal" muted paragraph
above the table. Members with zero cells render "—" in muted colour (matches today.twig
L60-72 empty-state convention). Viewer's row bolded + `(you)` marker.

### Badge codes (v0.8.3 — 9 new)

Threshold table below. All codes granted by `TrackerBadgeAwarder::evaluateAndGrant` eager;
count + MET-minute badges backfilled by `bin/karhu tracker:badges-backfill`; streak badges
are eager-only (matches chore precedent DOCS #54/#55).

| Code | Type | Criterion |
|---|---|---|
| `first_workout` | count | ≥ 1 log entries |
| `ten_workouts` | count | ≥ 10 |
| `fifty_workouts` | count | ≥ 50 |
| `five_hundred_met_minutes` | lifetime MET-min | ≥ 500 |
| `five_thousand_met_minutes` | lifetime MET-min | ≥ 5000 |
| `active_week` | week milestone | first week with ≥ 150 MET-min |
| `four_week_effort_streak` | weekly streak | 4 consecutive ISO weeks ≥ 150 MET-min |
| `seven_day_activity_streak` | daily streak | 7 consecutive days with any entry |
| `thirty_day_activity_streak` | daily streak | 30 consecutive days with any entry |

### Privacy invariant preserved

Per TRACKER-PLAN.md §5 (unchanged from v0.8.2): intake / weight / expenditure / net PRIVATE
per user. Only EFFORT (MET-min + strength session count + streaks + badges) is shared with
the household.

Regression-tested by `TrackerLeaderboardControllerTest::test_leaderboard_does_not_leak_
intake_or_weight_or_net`: user B's distinct fingerprints (9876 kcal / 88.8 kg / 5432 kcal)
MUST NOT appear in user A's leaderboard response. PLUS shape-based negative assertions on
`Intake:` / `Expenditure:` / `BMR:` / `Net:` (unique widget-only markers absent from
leaderboard) — defends against a future maintainer copy-pasting the Today balance widget
into `leaderboard.twig`.

### Module layout (v0.8.3 additions)

- `app/Chores/WeekWindow.php` + `DayWindow.php` — local-DATE sibling helpers.
- `app/Chores/Achievements.php` — `computeDailyStreakLocal` sibling.
- `app/Tracker/ExerciseLogRepository.php` — 5 new methods (leaderboard SQL + cumulative +
  streak feeds + daily MET bucketing).
- `app/Tracker/TrackerBadgeAwarder.php` (NEW) — 9-threshold awarder + pure `computeWeekly
  MetStreak` + ISO-week helpers.
- `app/Commands/TrackerBadgesBackfillCommand.php` (NEW) — `bin/karhu tracker:badges-backfill`.
- `app/Controllers/TrackerLeaderboardController.php` (NEW) — `GET /health/leaderboard`.
- `app/Controllers/ExerciseLogController.php` — ctor grows 7→8 params (adds
  `TrackerBadgeAwarder`); `store()` fires awarder best-effort in try/catch.
- `templates/tracker/leaderboard.twig` (NEW) — ranking table + badge strip.
- `templates/tracker/today.twig` — `[Leaderboard]` header link added.
- `config/badges.php` — 9 new tracker codes (grid 8→17).
- `config/controllers.php` — `TrackerLeaderboardController` registered.
- `config/commands.php` — `TrackerBadgesBackfillCommand` registered.
- `public/bootstrap.php` — `TrackerBadgeAwarder` + backfilled `TrackerProfileRepository`
  container bindings.
- `.github/workflows/ci.yml` — deploy step `Tracker badges backfill (idempotent)`
  AFTER `Seed tracker exercises`.
- `tests/AppTestCase.php` — `$trackerBadgeAwarder` field + binding + router scan entry.

### Tests (v0.8.3 additions)

- `tests/Chores/WeekWindowTest.php` / `DayWindowTest.php` — 5 new cases each covering local
  siblings + DST-crossing wall-clock arithmetic + malformed input rejection.
- `tests/Chores/AchievementsTest.php` — 4 new cases for `computeDailyStreakLocal`.
- `tests/Tracker/ExerciseLogRepositoryTest.php` — 11 new cases: leaderboard shapes +
  cumulative stats + streak feeds + per-day bucketing.
- `tests/Tracker/TrackerBadgeAwarderTest.php` (NEW) — 17 cases: guards + all 9 threshold
  paths + idempotency + `computeWeeklyMetStreak` pure-function unit tests + ISO year-
  boundary assertion.
- `tests/Controllers/TrackerLeaderboardControllerTest.php` (NEW) — 8 cases including the
  load-bearing privacy regression.
- `tests/Controllers/ExerciseLogControllerTest.php` — +1 case: POST fires awarder +
  `first_workout` badge granted eagerly.
- `tests/Controllers/BadgesControllerTest.php` — bumped "N of 8" → "N of 17".
- `tests/Commands/TrackerBadgesBackfillCommandTest.php` (NEW) — 4 cases: empty db,
  10-entry earns first + ten, idempotency, bad-tz household skipped.
- `tests/Smoke/ExerciseLogRepositoryLeaderboardPgSmokeTest.php` (NEW) — 4 cases against
  PG16: NUMERIC(8,2) SUM roundtrip via PDO, COALESCE(SUM(NULL), 0) on strength-only user,
  departed-member drop, `u.id > 0` sentinel guard (skipped in SQLite — sequence rejects
  id=0 insert, expected).

Full suite green at **971 / 2381 / 0**, 1 skipped (was 909 / 2273 / 0 pre-v0.8.3 — +62
tests / +108 assertions). PHPStan L6 clean per commit.

## 13. v0.8.4 — offline logging + PWA shortcuts (shipped)

Fifth and final Tracker release. Roadmap-marked bonus — user asked. Two deliverables:
(a) offline logging (queue food/exercise/weight writes in IndexedDB when offline,
replay via client-side IIFE on `window.online` or next page load with fresh CSRF);
(b) PWA `shortcuts` array (three home-screen quick actions on Android/Chromium).

### User-locked design choices (AskUserQuestion at plan-time)

- **Q1 — `logged_on` axis**: client stamps at queue-time; server validates via shared
  `LoggedOnValidator`. Preserves "the workout I logged Tuesday appears on Tuesday"
  across an overnight offline gap.
- **Q2 — Sync mechanism**: client-side IIFE fallback only. No Background Sync API —
  universal browser support (Chromium + Safari + Firefox all fire `window.online`),
  matches how `push-subscribe.js` already runs.

### `App\Tracker\LoggedOnValidator` (NEW, static-only)

Shared parser consumed by all three tracker POST controllers
(`FoodLogController::store`, `ExerciseLogController::store`,
`WeightController::store`). WeightController migrates its ad-hoc `measured_on` regex
to the shared validator for symmetric behaviour across every user-suppliable-date
endpoint (Plan-agent SHOULD-FIX #7 fold).

Contract:
- Blank / null → `LocalDay::today` fallback (unchanged pre-v0.8.4 behaviour).
- Shape `^\d{4}-\d{2}-\d{2}$` required.
- Format-round-trip check rejects non-existent dates (`2026-02-30` silently coerced to
  `2026-03-02` by `createFromFormat` alone).
- Must be `<= household-local today` (future-reject).
- Must be `>= today − 7 days` INCLUSIVE (past-cutoff — exactly 7 days ago accepted,
  8 rejected). 7-day cutoff is arbitrary but reasonable at family scale.

DST-safe: `->modify('-7 days')` on a wall-clock midnight in `$tz` steps calendar
days, NOT 7·86400s. Docblock warns against seconds-based refactor.

### JSON path on tracker POST controllers

`Accept: application/json` triggers a distinct response shape:
- 200 `{status:'ok'}` on success.
- 400 `{status:'error', code:'validation', message}` on reject.
- 401 `{status:'error', code:'auth', message}` on session gone.

Load-bearing: `fetch()` follows redirects by default → 302→`/login` returns 200
after auto-follow → without the JSON path, replayed POSTs against anonymous sessions
would silently succeed (row silently disappears from queue). 303-success + 303-
validation-reject also share status post-follow. JSON path gives four distinct
outcomes (200 / 400 / 401 / 403) so replay client can dispatch correctly.

Plan-agent BLOCKER #1 fold — this is the load-bearing correctness fix.

### `CsrfTokenController::show` extended contract

Response now carries `authenticated: bool` + `user_id: ?int` +
`active_household_id: ?int` alongside the existing `token`. Powers the offline IIFE's
session-scoping pre-check (Plan-agent BLOCKER #2/#3 fold):

- Flush loop probes `/csrf-token` FIRST.
- Holds queue if `authenticated: false` (session gone).
- Holds queue if `user_id !== metaUserId` (wrong user signed in on shared device).

Preserves invariants from DOCS #49: `Cache-Control: no-store`, GET-safelisted by
Csrf middleware, anonymous callers still get non-empty token.

### `public/mishka-idb.js` (NEW — thin IDB wrapper)

Zero-dep IndexedDB wrapper. DB name `mishka-offline`, version 1.

**`queued_writes`** — auto-inc key + compound index `by_session` on
`[user_id, household_id]`. Every queued row is session-scoped so shared iPads with
mum→dad session swaps never leak queued POSTs across users. Plan-agent BLOCKER #2
fold.

**`library_cache`** — key `${household_id}:${endpoint}:${q}`. Household-scoped so
mum's custom recipes don't render for dad on the same iPad. 30-min TTL at read
time. Plan-agent SHOULD-FIX #12 fold.

`crypto.randomUUID()` with RFC-4122 v4 fallback (`getRandomValues(new Uint8Array(16))`
+ version/variant bits) for dev-mode over HTTP where `randomUUID` is undefined.

### `public/mishka-offline.js` (NEW — offline logging IIFE)

~200 lines, no external deps. Reads three session meta tags from layout.twig; the
`HAS_SESSION_CONTEXT` gate skips queue + flush on anonymous pages (Plan-agent
BLOCKER #3 fold — `DOMContentLoaded` fires on `/login` / `/register` / `/offline`
too; without this gate the IIFE would silently drain rows against anonymous
sessions).

**Form-submit interceptor** on `[data-offline-queue]` marker fires CAPTURE-PHASE
PRIORITY-FIRST — before v0.7.4's double-submit-prevention IIFE. When offline,
`preventDefault()` + `stopPropagation()` cancels the event; v0.7.4's disable-buttons
+ dataset-flag path never runs. Plan-agent SHOULD-FIX #6 fold.

**`stampLoggedOnToday()`** — client stamps `logged_on` via
`new Date().toLocaleDateString('en-CA', {timeZone: hh_tz})`. Intl produces
`YYYY-MM-DD` in the household TZ (not device TZ). RangeError on invalid meta →
omit `logged_on` from payload, let server default via LoggedOnValidator.
Plan-agent SHOULD-FIX #11 fold.

**`flushQueue()`** — runs on `DOMContentLoaded` + `window.online`, guarded by
module-scoped `flushInFlight` mutex to prevent concurrent double-drain.

Per-row replay response-status matrix:

- 200 → remove row (log retry_count on success for DevTools reviewers).
- 400 → hard-fail remove + console.warn (row is bad, don't retry forever).
- 401 → hold row for the next authenticated flush.
- 403 → one-shot re-freshen + retry (guarded, no infinite loop).
- 429 / ≥ 500 → `incrementRetry`, cap at 5 then hard-fail.
- Network error → `incrementRetry`.

Exposes `window.MishkaOffline = {searchLibrary, cacheLibraryResponse}` for the
layout.twig live-search IIFE's offline-fallback + write-through-cache hooks.

### PWA shortcuts (`public/manifest.webmanifest`)

Top-level `shortcuts` array with 3 entries: Log Food → `/health/log/food`;
Log Exercise → `/health/log/exercise`; Today → `/health`. Chromium/Android show
them on long-press of installed PWA icon; Safari + Firefox silently ignore.

All three reuse `/icon-192.png` (in PRECACHE) — preserves the
manifest-icons-must-be-precached invariant asserted by
`ServiceWorkerStructureTest::test_manifest_icons_match_precache`. If distinct
per-shortcut icons become desirable later, v0.8.4.1 can add them + extend PRECACHE.

### SW precache + wiring

- `PRECACHE_URLS` 7 → 9 (adds `/mishka-idb.js` + `/mishka-offline.js`).
- `EXPECTED_PRECACHE` in `ServiceWorkerStructureTest.php` + count assertion at L154
  bumped.
- `SW_VERSION → mishka-v0.8.4` per decision #51 always-bump.
- `NavContext::forCurrentUser` extended to expose `session_user_id: ?int` so
  layout.twig can render the `mishka-user-id` meta tag.
- Three meta tags added inside `{% if session_email %}` head guard:
  `mishka-user-id`, `mishka-household-id`, `mishka-household-tz`. Absent on anonymous
  pages by construction. Corrected to `active_household.timezone` (not
  `household.timezone`) per Plan-agent BLOCKER #4 fold.
- Body-end `<script src="/mishka-idb.js" defer>` + `<script src="/mishka-offline.js" defer>`
  — `defer` guarantees document-order execution.
- `<span data-offline-badge>` in the nav row (populated by `mishka-offline.js`).
- layout.twig live-search IIFE gains `.catch → MishkaOffline.searchLibrary` +
  write-through `cacheLibraryResponse`.
- Three tracker form templates gain the `data-offline-queue` marker.

### Tests (v0.8.4 additions)

- `tests/Tracker/LoggedOnValidatorTest.php` (NEW) — 10 cases: blank fallback, valid
  today, valid yesterday, exactly-7-days-ago (inclusive-accept), 8-days-ago rejected,
  future rejected, malformed shape rejected, non-existent `2026-02-30` rejected,
  DST-crossing boundary honours calendar days, whitespace stripped.
- `tests/Controllers/CsrfTokenControllerTest.php` — 3 new: anonymous returns
  authenticated:false + null ids; authed no-active-hh returns user_id + null hh;
  authed with active hh returns both.
- `tests/Controllers/FoodLogControllerTest.php` — 4 new: logged_on accepted;
  malformed rejected; JSON success 200 `{status:'ok'}`; JSON validation reject 400
  `{code:'validation'}`.
- `tests/Controllers/ExerciseLogControllerTest.php` — 4 new (same shape).
- `tests/Controllers/WeightControllerTest.php` — 4 new (same shape; measured_on
  migrated to shared validator).
- `tests/Controllers/TrackerControllerTest.php` — v0.8.2 privacy regression test's
  latent-bug fix (was using `date('Y-m-d')` UTC which diverged from widget's
  `LocalDay::today(Auckland)` during the ~10-hour daily UTC/NZ mismatch window).
- `tests/View/ServiceWorkerStructureTest.php` — `EXPECTED_PRECACHE` 7 → 9 + count
  assertion bumped.
- `tests/View/ManifestTest.php` — 2 new: exactly 3 shortcuts with correct URLs;
  every `shortcuts[].icons[].src` in the precached-icons set (Plan-agent
  SHOULD-FIX #10 fold — extends the invariant coverage to nested icons).

**Client-side JS tests**: none. mishka has no JS test runner; matches how
`push-subscribe.js` v0.6.0 shipped. Manual Chrome DevTools smoke covers the client
path — real-user smoke requires HTTPS context (`mishka.minified.work`), not
`192.168.*` dev where the SW's `IS_DEV` escape hatch skips fetch interception.

Full suite green at **996 / 2433 / 0**, 1 skipped (was 971 / 2381 / 0 pre-v0.8.4 —
+25 tests / +52 assertions). PHPStan L6 clean per commit.

### What's NOT in v0.8.4

- Schema-based idempotency (`client_request_id UUID UNIQUE` on food_log /
  exercise_log / weight_log) — accepted risk at family scale, escape hatch to
  v0.8.4.1+ if prod reveals doubles.
- Per-shortcut distinct icons — deferrable, all three reuse `/icon-192.png` today.
- Background Sync API — client-side IIFE fallback only (user-locked Q2 at
  plan-time). Universal browser support beats Chromium-only offline-while-closed.
- Multi-tab cross-tab dedup — accepted at-least-once semantic at family scale (R2
  in the plan file).
