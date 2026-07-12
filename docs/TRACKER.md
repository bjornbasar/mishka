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

## 9. What lands next (v0.8.1)

Exercise catalog + logging. Two branches — duration (run/walk/cycle) via `kcal = MET × 3.5 ×
weight_kg ÷ 200 × minutes`; strength (reps/sets/load) via a resistance MET + optional mechanical-
work `kcal ≈ 0.011723 × load_kg × ROM_m × reps`. `met_minutes = MET × minutes` weight-
independent — this becomes the v0.8.3 leaderboard currency. Curated MET catalog subset from
the Compendium of Physical Activities (2024). Seed via `tracker_exercises.json` (same `{"version":
1, ...}` envelope shape as this release's foods seed).

See `docs/TRACKER-PLAN.md` §4 + §9 for the design; ROADMAP.md for the effort estimate.
