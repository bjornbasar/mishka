# Mishka — Food & Exercise Tracker (design + implementation plan)

> **Status:** PLAN / not started. This is a handoff document for a fresh mishka-repo session
> to pick up and implement. It captures the full product design, the locked decisions and
> *why*, the data model, the module layout, and a release-train breakdown. Nothing here is
> built yet. Working module name is **`Tracker`** (brand/display name TBD — see Open Decisions).

## 1. Why this exists

A private food + exercise tracker for **two people (Bjorn + wife)**, run as a **mishka module**.
The motivation is dissatisfaction with off-the-shelf apps:

- too much feature bloat and ads,
- their food database doesn't know **common Filipino-household food (in an NZ context)**,
- logging forces you to itemise ingredients to get a calorie count.

So the product's whole reason to exist is: **lean, ad-free, knows our food, logs a dish in seconds.**
Every scope decision defers to that. If a feature doesn't serve "the two of us log what we actually
ate/did in <5 seconds," it doesn't ship.

## 2. Locked decisions (and why)

| Decision | Choice | Why |
|---|---|---|
| **Home** | mishka **module** (not standalone, not kuma) | The wanted **leaderboard** needs a community — mishka already has the family as users, auth, layout, deploy, and a proven leaderboard stack. Offline was only a "bonus," which removed the main argument for a standalone PWA. Bonus: a 2nd real karhu dogfood. |
| **Rejected: standalone Next.js PWA** | no | Best mobile/offline UX and was the first instinct, but a leaderboard of two people with bespoke identity/social is thin; new repo/CI/deploy to maintain; no family context. |
| **Rejected: fold into kuma** | no | kuma answers "what should we cook?"; this answers "what did we eat + how much did we move?". Different intent — merging bloats kuma against the very ethos that started this. A thin one-way "kuma recipe → dish" import may be added *later*, without coupling the codebases. |
| **Calorie model** | **TDEE / BMR baseline** | User chose true energy balance (intake vs total expenditure), not just intake vs workouts. |
| **Food search** | **serving-first, no ingredient drilling** | Hard requirement: type `kare-kare` → pick "1 bowl" → done. Ingredients exist only behind an admin "edit dish" screen, never at log time. |
| **Leaderboard** | **effort + consistency, intake private** | Rank on weight-independent effort (MET-minutes) + streaks — NOT kcal burned (scales with bodyweight = unfair) and NOT deficit/weight (sensitive). Intake, weight, and net stay private per user. |
| **Offline logging** | **later bonus** | mishka already ships a PWA shell + service worker; installability is inherited. True offline *write-and-sync* is a deferred enhancement, not a v1 blocker. |
| **Client stack** | server-rendered Twig + vanilla JS | Match mishka's existing grain (no htmx/Alpine in the repo today). The only interactive piece is a live dish-search box (fetch → render list). Introducing htmx is explicitly *out of scope* unless a later session decides otherwise. |

## 3. The serving-first food library (the differentiating feature)

The library stores **composed dishes with calories baked into serving units** — you never touch
ingredients at log time.

- Each dish (`foods`) has one or more **serving units** (`food_servings`), each carrying its own kcal:
  `kare-kare → "1 bowl" (≈350 g) → ~480 kcal` *(illustrative — real values from the sources below)*,
  optionally `"½ bowl"`, `"1 cup"`. One serving is flagged default.
- **Log flow:** search `kare-kare` → tap result → defaults to **1 bowl** → confirm (nudge qty 0.5/2). Zero ingredient interaction.
- **New-dish flow (admin, rare):** name it, pick a serving label, type the kcal (from the pack / a source table) — *or* optionally compose from ingredients **once** to derive the number. After that it's in the library forever. Ingredients are provenance, never part of logging.
- **"Our food" = a curated, editable library that grows.** Seeded, then the couple adds their own household dishes as they eat them. That curated list is the soul of the product.

### Seeding the library

Ship a bundled seed of **~60–100 dishes** as a repo data file (JSON/CSV under `db/seed/` or `app/Tracker/seed/`),
imported by a **console command** (mishka already has `app/Commands/` + `config/commands.php`; model it on
`MailTestCommand`). Idempotent (`INSERT ... WHERE NOT EXISTS`, like the chore ledger backfill).

- **Filipino household staples:** adobo, sinigang, kare-kare, tinola, menudo, afritada, sinangág,
  tapsilog, pancit, lumpia, lechon kawali, longganisa, tocino, champorado, arroz caldo, laing,
  pinakbet, kaldereta, dinuguan, ginataang…
- **NZ everyday:** kūmara, hoki, lamb, Weet-Bix, mince pie, flat white, Marmite…
- **Per-serving kcal derived from authoritative composition tables:**
  - **PhilFCT** (FNRI/DOST) — Filipino composed dishes, ~1,600 foods, per-100 g → per-serving.
    <https://i.fnri.dost.gov.ph/fct/library>
  - **NZ Food Composition Database / FOODfiles** (Plant & Food Research / MoH, 2024, 2,857 foods) —
    NZ + Pacific foods. <https://www.foodcomposition.co.nz/foodfiles/index.html>
  - **USDA FoodData Central** — ingredient atoms for composing anything not pre-listed. Free API + key.
- ⚠️ **Licensing check before bundling raw data:** PhilFCT is free online; FOODfiles is a free download
  but has licence/attribution terms. Store *derived per-serving kcal with source attribution* and confirm
  redistribution terms before committing any bulk dataset. (Open Decision.)

### Portion realism

"1 bowl" is fuzzy — pin *their* household bowl once (e.g. their actual bowl ≈ 350 g). Day-to-day
**consistency beats lab accuracy** for tracking trends.

## 4. Exercise — two modalities

Exercise logging is a discriminated union, not a single "minutes" field.

- **Duration** (run/walk/cycle): `kcal = MET × 3.5 × weight_kg ÷ 200 × minutes`.
- **Strength** (reps/sets/load): derive session minutes (~3 s/rep + rest) → apply a resistance MET;
  optionally also the mechanical-work estimate since load is logged:
  `kcal ≈ 0.011723 × load_kg × ROM_m × reps`. Ship MET-via-duration as the headline number; show
  work-based as a secondary "nerd stat."
- **MET-minutes** (`MET × minutes`, weight-independent) is computed for **every** entry — it is the
  **leaderboard currency** (see §6), stored on the row so the board never recomputes.

MET values come from the **Compendium of Physical Activities** (2024 update) — bundle a curated subset
in the `exercises` catalog, not the full 1,300 rows.

## 5. TDEE / BMR — the daily net (and the double-count trap)

Net becomes **intake − total expenditure**. Each user gets a body profile.

- **BMR via Mifflin-St Jeor:** `BMR = 10·kg + 6.25·cm − 5·age + (male +5 / female −161)`.
- **⚠️ Do NOT double-count exercise.** Textbook `TDEE = BMR × activity_factor`, but that factor already
  contains exercise. Since we log workouts separately, the base factor must represent **daily life
  excluding deliberate exercise**:

  ```
  expenditure(day) = BMR × BASE_factor        // ≈1.2–1.375, "your normal day minus workouts"
                   + Σ exercise_log.kcal(day)  // logged workouts add on top
  net(day)         = Σ food_log.kcal(day) − expenditure(day)
  ```

  The base-activity question must be **worded in the UI as "excluding workouts."**
- **Weight is a time series** (`weight_log`) feeding both BMR and exercise MET — latest measurement = current.
- **All of intake / weight / net is PRIVATE** to each user. Only effort (§6) is shared.

## 6. Leaderboard — reuse the Chores machinery

mishka already has a complete leaderboard stack in the **Chores** module. The tracker's board is the
**same pattern with a different currency** — study these before building anything new:

- `app/Chores/` + `App\Chores\Achievements` — badge registry (pure functions over a per-member stats array;
  emoji/titles live in `config/badges.php` as a Twig global — the service never sees emoji).
- `ChoresRepository::leaderboardForHousehold($hid, $weekStartUtc)` — weekly + all-time in one query,
  driven off `household_members`, ranked, week boundary = **Monday 00:00 in household tz** computed in PHP
  → UTC string (portable across PG `TIMESTAMPTZ` and SQLite `TEXT`).
- `App\...\WeekWindow` — DST-safe week/streak boundaries.
- `templates/_chore_leaderboard.twig`, `templates/badges/` — presentation partials to mirror.
- `chore_points_ledger` — **append-only, snapshot-at-completion** ledger. Copy this idea: snapshot values
  at log time so later edits/deletes don't rewrite history.

**Tracker specifics:**
- **Currency = weekly MET-minutes** (fair across bodyweights), optionally blended with a consistency
  score (days logged / streak). Final weighting is an Open Decision.
- **Privacy split:** the board shows effort/streaks to the household; intake/weight/net never appear.
- Badges: effort/consistency themed (first workout, N active days, weekly-streak 🔥, MET-minute milestones
  matching the 500–1000/week public-health band).

## 7. Data model (additive-tables convention)

mishka uses **`CREATE TABLE IF NOT EXISTS` additive-only** in `db/schema.sql` — **no ALTER**, so new
attributes on an existing entity go in a **new table**, never as new columns on `users`. All tables must
work on **PostgreSQL (prod) and SQLite (tests)**. Attribution FKs `SET NULL`, ownership FKs `CASCADE`
(follow the chore ledger's FK reasoning).

```
tracker_profiles                        -- per-user body profile for TDEE (NOT columns on users)
  user_id PK → users(id) CASCADE
  sex          CHECK ('male','female')  -- BMR constant selector
  birth_year   INTEGER                  -- age derived; avoids storing exact DOB
  height_cm    NUMERIC
  base_activity NUMERIC                 -- BASE factor, "daily life excluding workouts"
  created_at, updated_at

weight_log                              -- time series; latest row = current weight
  id, user_id → users CASCADE, measured_on DATE (household-local), weight_kg NUMERIC, created_at

foods                                   -- dish library (serving-first)
  id, household_id → households CASCADE (NULL = global seed shared by all households),
  name, aliases, cuisine_tag,
  source ('philfct'|'nzfcd'|'usda'|'custom'),
  created_by → users SET NULL, created_at, updated_at

food_servings                           -- 1..n serving units per dish, kcal baked in
  id, food_id → foods CASCADE, label ('1 bowl'), grams NUMERIC, kcal NUMERIC,
  protein_g/carb_g/fat_g NULL, is_default BOOL

food_log                                -- what was eaten
  id, user_id → users CASCADE, food_id → foods SET NULL, serving_id → food_servings SET NULL,
  qty NUMERIC, logged_on DATE (household-local day), logged_at TIMESTAMPTZ,
  meal ('breakfast'|'lunch'|'dinner'|'snack'),
  kcal_snapshot NUMERIC                 -- SNAPSHOT at log time (edit/delete dish later ≠ rewrite history)

exercises                               -- MET catalog (curated Compendium subset)
  id, name, type ('duration'|'strength'), met NUMERIC, default_rom_m NULL,
  household_id → households CASCADE (NULL = global)

exercise_log                            -- what was done
  id, user_id → users CASCADE, exercise_id → exercises SET NULL,
  logged_on DATE (local), logged_at TIMESTAMPTZ,
  minutes NUMERIC NULL,                 -- duration branch
  sets INT NULL, reps INT NULL, load_kg NUMERIC NULL,  -- strength branch
  met_minutes NUMERIC,                  -- weight-independent effort → leaderboard currency
  kcal_snapshot NUMERIC                 -- weight-dependent, snapshot at log time
```

**Day boundary:** `logged_on` is the **household-local** date (roll over at local midnight, DST-safe) —
reuse the calendar/chores wall-clock-in-household-tz model, never SQL `NOW()`/`CURRENT_TIMESTAMP`.

## 8. Module layout & wiring

Mirror an existing feature module (Chores is the closest analogue — leaderboard, ledger, per-household).

- `app/Tracker/` — repositories, services (BMR/TDEE calc, MET/kcal calc, leaderboard, seed importer).
- `app/Controllers/` — `TrackerController` (today/dashboard), `FoodLogController`, `ExerciseLogController`,
  `FoodLibraryController`, `TrackerProfileController` (follow existing controller placement).
- `app/Commands/` — `TrackerSeedCommand` (import bundled dish/exercise seed). Register in `config/commands.php`.
- `config/controllers.php` — register routes/controllers. `config/container.php` — DI wiring.
  `config/badges.php` — add tracker badges (Twig global). `config/brand.php` — display name if branded.
- `templates/tracker/` + partials (`_tracker_leaderboard.twig` mirroring `_chore_leaderboard.twig`).
- `db/schema.sql` — append the tables from §7 (additive, idempotent).
- **Docs to keep in sync per release:** `docs/TRACKER.md` (living design doc — replaces this plan once
  building starts), `docs/SCHEMA.md`, `docs/ROUTES.md`, `docs/TESTPLAN.md`, `docs/USERGUIDE.md`,
  root `DOCS.md`, `README.md ## Status`. Workspace-level: `/data/personal/CLAUDE.md` (mishka row),
  Airtable Projects/Services if routes/services change.

### Screens (that's the whole UI)

1. **Today** — per-user (you / wife tabs): intake vs expenditure balance (private), quick-add bar.
2. **Log food** — live dish search → serving → qty → done.
3. **Log exercise** — pick activity → minutes *or* sets/reps/load.
4. **Library** — browse/edit dishes; add-new (the *only* place ingredients appear).
5. **Leaderboard** — household effort/streaks (shared); no intake/weight.
6. **Profile** — sex / birth year / height / base-activity ("excluding workouts") / weight entry.

## 9. Release-train breakdown

mishka ships features as monotonic versioned increments (see the Chores train v0.4.0→v0.4.3). mishka is
at **v0.7.5** today, so this is a new train — proposed **v0.8.x** (confirm the number when starting):

| Release | Scope |
|---|---|
| **v0.8.0** | Dish library (`foods`/`food_servings`) + serving-first food logging + seed command + bundled Filipino/NZ seed. Live dish-search box. |
| **v0.8.1** | Exercise catalog + logging (duration **and** strength branches) + kcal + met_minutes. |
| **v0.8.2** | `tracker_profiles` + `weight_log` + BMR/TDEE + **Today** energy-balance screen (no double-count). |
| **v0.8.3** | Household **leaderboard** (MET-minutes) + badges + streaks — reuse Chores machinery. |
| **v0.8.4** *(bonus)* | Offline logging — extend `service-worker.js` to cache the dish library + queue writes. |
| *later* | Optional one-way **kuma recipe → dish** import. |

## 10. Definition of done (per release) — house rules

- **TDD**, RED→GREEN commits (see git log: `test: … (RED)` then `feat: … (GREEN)`). PHPUnit.
- Tests pass on **both PG and SQLite** (the leaderboard/date logic is the risk area).
- **Commit per issue**; **never auto-commit/push** — wait for explicit "commit"/"push"/"ship it".
- **No `Co-Authored-By`** lines.
- Update every doc surface in §8 in the same change.
- A **"release" includes a GitHub Release** (`gh release create` with notes), per workspace convention —
  not just a tag. Follow `docs/RELEASE.md` (bump `SW_VERSION` — `tests/View/ServiceWorkerStructureTest`
  asserts it matches the release).

## 11. Open decisions (surface to Bjorn before/while building)

1. **Module/brand name.** Internal `Tracker` is a placeholder. Brand/display candidates: **Oso**
   (bear, PH/ES — fits the bear-naming streak + Filipino angle), **Kai** (Te Reo for "food", NZ nod),
   or plain "Health". User's call.
2. **Leaderboard weighting** — pure MET-minutes, or blended with a consistency/streak score?
3. **Seed data licensing** — confirm FOODfiles/PhilFCT redistribution/attribution terms before bundling
   any bulk dataset; otherwise ship only derived per-serving kcal with source attribution.
4. **Household bowl calibration** — fixed household default grams per serving, or per-user override?
5. **Version number** for the train (v0.8.x assumed).

## 12. Start here (fresh session)

Read, in order: this file → `docs/SCHEMA.md` (additive convention + FK patterns) → `docs/CHORES.md`
(leaderboard/ledger/streak design) → `app/Chores/Achievements.php` + `ChoresRepository::leaderboardForHousehold`
→ `docs/ROUTES.md` + `config/controllers.php` (how a module is wired) → `db/schema.sql` (existing tables,
`users`/`households`/`household_members`) → `templates/_chore_leaderboard.twig` + `templates/layout.twig`
(PWA registration). Then plan v0.8.0 in plan mode and confirm the Open Decisions.
