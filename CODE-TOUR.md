# mishka — Code Tour

> A **reading-guide map**, companion to [karhu's CODE-TOUR](../karhu/CODE-TOUR.md). **Read that one first** — mishka is the *car*; karhu is the *engine*. This tour spends its energy on the **seam** (where the app plugs into the framework) and on the app-level patterns karhu deliberately doesn't have opinions about. Every `file:line` link is clickable in your IDE and on GitHub.
>
> **How to use it:** §1 recaps the spine and shows where mishka plugs in; §2 walks `bootstrap.php`, the one file that *is* the app's wiring; §3 answers the question the karhu tour left open ("what must a real app supply?"); §4–§6 are the app's own cross-cutting patterns; §7 is the sync/async payoff; §8 walks one whole feature (the health tracker) end to end; §9 is the pattern index; §10 the exercises; §11 bridges to istrbuddy.

---

## 0. Orientation — mishka in one breath

**Mishka Den** is a family-hub web app: auth + households, a calendar (recurrence + iCal feed), chores (points, badges, rotation), account lifecycle (email verify, password reset), web-push reminders, and a **health tracker** (food/exercise/weight logging with BMR targets, a private energy-balance dashboard, and a household effort leaderboard + badges). **v0.8.5.1, PHP 8.4+, PostgreSQL, Twig.** It's the *first real dogfood* of karhu — so the interesting thing isn't "how does a calendar work," it's **"how does a real app consume a from-scratch microframework, and where does it have to fill the gaps?"**

The single most important file in the whole repo is [public/bootstrap.php](public/bootstrap.php). If you understand that file, you understand how mishka is assembled. Everything else is domain code hanging off it.

---

## Vocabulary check — terms this tour leans on

Same convention as the karhu tour — no-fluff, anchored to a PHP/Node frame. Skip what you own.

- **the "seam"** — my word for the boundary where *your app* meets *the framework*: the interfaces the framework declares and the app implements, plus the wiring that hands app objects to the framework. `bootstrap.php` is the seam made concrete.
- **repository pattern** — one class per data aggregate (`HouseholdRepository`, `ChoreRepository`, `FoodLogRepository`) that owns all SQL for that thing. Controllers/services never write SQL directly — they call repo methods. No ORM here; it's hand-written parameterised SQL over a thin PDO wrapper.
- **PDO / DSN** — PDO is PHP's built-in database abstraction (drivers for PostgreSQL, SQLite, MySQL…). A **DSN** ("Data Source Name") is the connection string, e.g. `pgsql:host=…;dbname=…`. karhu-db's `Connection` wraps PDO.
- **PRG (Post/Redirect/Get)** — after a successful POST, respond with a `303` redirect to a GET page instead of rendering HTML directly. Stops the browser's "resubmit form?" on refresh. You'll see `redirect('/', 303)` everywhere.
- **flash message** — a one-shot message ("Household deleted") stored in the session, shown on the *next* page, then cleared. Survives exactly one redirect.
- **optimistic concurrency** — instead of locking a row, you let two edits race and detect the clash at write time (via a version/updated-at check), then ask the loser to retry. The calendar uses it.
- **sentinel row / upsert** — a *sentinel* is a placeholder row with a special id (here `user_id=0`, `'__system__'`) used as a claimable marker. An *upsert* is "insert or update if it already exists" in one statement.
- **argon2id** — the current best-practice password-hashing algorithm (deliberately slow/memory-hard). karhu's `PasswordHasher` uses it; note the "dummy hash" timing-defence trick in §2.
- **queue + worker** — a way to defer slow work: the web request writes a *job* row to a table (the queue) and returns immediately; a separate long-lived *worker* process reads that table in a loop and does the work later. This is how a **synchronous** app fakes "background" work (§7).
- **service worker / web push** — a browser background script + the Web Push protocol that lets a server send a notification to a device even when the site isn't open. mishka is the *sender*.
- **discriminated union** — one logical record with two (or more) structurally different shapes chosen by a `type` field, where the branches share no meaningful conversion. `exercise_log` (duration vs. strength) is the case study in §8.

---

## 1. The spine — where mishka plugs into karhu

The karhu lifecycle (index → App → pipeline → dispatch → router → container → controller → emit) runs **underneath mishka unchanged**. mishka only supplies the *wiring* and the *handlers*:

```
public/index.php            ← 2 lines: require bootstrap → $app->run()
   │
public/bootstrap.php        ← THE SEAM (everything below is set up here)
   │  ExceptionHandler::register()          — karhu error handling
   │  env validation (fail-fast)            — app policy
   │  new Connection(dsn,…)                 — karhu-db (PDO)
   │  new TwigAdapter(templates/)           — karhu-view
   │  new App()                             — karhu front controller
   │  container()->set(...) × 49            — hand-wired DI graph
   │  container()->factory(AuthController…) — for scalar-arg ctors
   │  pipe(Session) pipe(Guard) pipe(Csrf)  — middleware order matters
   │  router()->scanControllers(config)     — karhu route scan
   │  return $app
   ▼
$app->run()  → [karhu takes over — see karhu/CODE-TOUR.md §1]
   ▼
App\Controllers\*  ← your #[Route] handlers (§4)
```

The entry point is deliberately trivial — [public/index.php:14-15](public/index.php#L14-L15) is `$app = require bootstrap.php; $app->run();`. The split exists so a **smoke test** can `require` bootstrap.php and assert "boot completes without throwing" without dispatching a request (see the file's own docblock, [bootstrap.php:5-23](public/bootstrap.php#L5-L23)). That's a nice testability move worth noticing.

---

## 2. The seam — reading `bootstrap.php` top to bottom

Open [public/bootstrap.php](public/bootstrap.php) and read it as the guided walk below. This *is* the tour. (Line numbers are current as of v0.8.5.1 — the file grew with the tracker DI wiring; the *structure* is unchanged.)

**a. Install karhu's error handler** — [bootstrap.php:86](public/bootstrap.php#L86). `(new ExceptionHandler())->register()`. This is the wire-up the karhu tour flagged as "you must do this yourself" (karhu §8). From here, any thrown exception (including `ForbiddenException` from §3) becomes a content-negotiated response.

**b. Fail-fast env validation** — [bootstrap.php:93-137](public/bootstrap.php#L93-L137). `DB_DSN`, `APP_URL`, `MAIL_FROM_ADDRESS`, and the VAPID keypair are all *required at boot* — a missing/invalid value throws before the app serves a single request. Read the comment at [:100-108](public/bootstrap.php#L100-L108): requiring `APP_URL` isn't pedantry, it's a **host-header-injection defence** — email links are built only from `APP_URL`, never from the incoming request's `Host`. This is a recurring mishka theme: security decisions are annotated inline with a threat model.

**c. Build the framework-provided services** — the karhu-db `Connection` [:139-143](public/bootstrap.php#L139-L143) and the karhu-view `TwigAdapter` [:148-151](public/bootstrap.php#L148-L151) (plus a `brand` global and the `CsrfTwigExtension` — see §5).

**d. The DI graph — hand-wired** — [:158-297](public/bootstrap.php#L158-L297). The app's ~49 services are `new`'d and then bound with **49** `container()->set(Class::class, $instance)` calls (the bind block itself is [:238-297](public/bootstrap.php#L238-L297)). This is worth pausing on, because it looks like a lot of boilerplate for a framework that *has* auto-wiring:

> **Why hand-wire when karhu can auto-wire?** Two reasons, both in the comment at [:155-157](public/bootstrap.php#L155-L157). (1) `Connection` takes **scalar** args (dsn/user/pass from env) — the auto-wirer can't invent those, so it *must* be pre-built. (2) Every repo depends on that one `Connection` instance; pre-building and `set()`-ing guarantees a **single shared** connection rather than the auto-wirer trying to construct one per class. Once `Connection` is set, most repos *could* auto-wire — but mishka wires them explicitly for a legible, greppable startup graph. Compare this against `config/container.php` (§7) where the CLI leans *harder* on auto-wiring.

**e. Factories for scalar-arg controllers** — [:304-335](public/bootstrap.php#L304-L335). `AuthController` and `PasswordResetController` can't fully auto-wire because their constructors take a scalar `$dummyHash` / `timingFloorMicros`, so they're registered with `container()->factory(...)` ([:304](public/bootstrap.php#L304), [:322](public/bootstrap.php#L322)) — **this is the direct payoff of karhu's `factory(Container)` feature** from the karhu tour (karhu §6). Read the comment at [:299-302](public/bootstrap.php#L299-L302): the `$dummyHash` is a pre-computed argon2id hash used so the "email not found" path of a password reset still burns the *same* CPU as a real one — a **timing-attack defence** (you can't tell a real account from a fake one by response time).

**f. Middleware order** — [:344-346](public/bootstrap.php#L344-L346). `Session → SessionRevocationGuard → Csrf`, and the comment says exactly why: `Session` must run first so `$_SESSION` exists; the guard reads it to kill stale sessions *before* CSRF would let them act. **This is the concrete version of the karhu §12 exercise #2** — ordering is load-bearing, and it's declared here in FIFO `pipe()` calls. Note the `Session(['lifetime' => 2592000])` — a **30-day cookie** (v0.7.7 "family stay logged in", paired with `gc_maxlifetime`; comment at [:338-343](public/bootstrap.php#L338-L343)).

**g. Scan routes + return** — [:348](public/bootstrap.php#L348). `scanControllers(require config/controllers.php)` then `return $app`. Note: mishka scans via reflection **on every boot** — no `route:cache` is wired (karhu §3). *Exercise: would the cache help here, and what would it cost you in the dev loop?*

---

## 3. What the app must SUPPLY (the answer to karhu §13)

The karhu tour ended with: *"what does a real app have to supply that the framework deliberately left out?"* Here's mishka's answer, made concrete:

| Framework leaves abstract | mishka supplies | Where |
|---------------------------|-----------------|-------|
| `UserRepositoryInterface` (auth queries) | `MishkaUserRepository` | [app/Auth/MishkaUserRepository.php](app/Auth/MishkaUserRepository.php) |
| A view layer | karhu-view `TwigAdapter` + `templates/` | [bootstrap.php:148](public/bootstrap.php#L148) |
| DB access | karhu-db `Connection` (PDO) + repositories | [app/**/*Repository.php](app/) |
| The DI graph | `bootstrap.php` (HTTP) + `config/container.php` (CLI) | [public/bootstrap.php](public/bootstrap.php) |
| All domain logic + routes | `app/Controllers`, `app/Calendar`, `app/Chores`, `app/Tracker`, … | [app/](app/) |

**The keystone example — `MishkaUserRepository`** ([app/Auth/MishkaUserRepository.php:21](app/Auth/MishkaUserRepository.php#L21)): karhu declares `UserRepositoryInterface` keyed on an opaque `username` string ([ADR-0006](../karhu/docs/adr/0006-rbac-via-repository-interface.md)); mishka's schema keys on an **integer PK + email**. The repo *adapts* one to the other — `findByUsername()` [:47](app/Auth/MishkaUserRepository.php#L47) treats "username" as the email, and returns an extra `id` key beyond the interface contract (documented at [:36-46](app/Auth/MishkaUserRepository.php#L36-L46)). This is the **inversion** the whole framework is built around: karhu says *what* it needs (an interface), the app decides *how* (the schema, the SQL). It even detects the PDO driver at construction ([:30-34](app/Auth/MishkaUserRepository.php#L30-L34)) to stay portable between PG (prod) and SQLite (tests).

---

## 4. Controllers — the app's request handlers

Open [app/Controllers/HouseholdController.php](app/Controllers/HouseholdController.php) alongside [HomeController.php](app/Controllers/HomeController.php). Every controller follows the same recipe — once you see it in two, you've seen all 24 (the tracker set — `TrackerController`, `FoodLogController`, `ExerciseLogController`, … — added the same way in v0.8.x; §8):

1. **Constructor DI** of repos/services/view/nav ([HouseholdController.php:39-45](app/Controllers/HouseholdController.php#L39-L45)). These are the exact instances `bootstrap.php` `set()` — karhu's container hands them over when it auto-resolves the controller (karhu §5). (Controllers themselves are *not* explicitly bound — the container reflects their constructor type-hints and resolves each against the already-registered services.)
2. **Auth/session gate at the top** — `if (!Session::has('user_id')) return redirect('/login')` ([:50-52](app/Controllers/HouseholdController.php#L50-L52)). `Session` is a **static facade** (karhu's static-facade-alongside-DI pattern — karhu §4). The tracker controllers factor this into a `requireContext()` triad (anon → `/login`, no active household → `/household/setup`, non-member → self-heal).
3. **Authorization via a thrown exception, no try/catch** — `$this->auth->requireOwner($userId, $hid)` ([:156](app/Controllers/HouseholdController.php#L156)). If it fails it *throws* (§3.5 below); the controller doesn't handle it — the framework's `ExceptionHandler` does. Read the class docblock at [:33-36](app/Controllers/HouseholdController.php#L33-L36).
4. **Read the body defensively** — `readBody()` [:378-398](app/Controllers/HouseholdController.php#L378-L398) reads JSON (test harness) *or* form-urlencoded (browser), through an explicit key **allowlist**. Note: it uses karhu's `Request::body()` (auto-JSON-decode) with a `Request::post()` fallback. The tracker POSTs lean on this hard — they answer JSON *or* HTML depending on the client (offline-replay; §8).
5. **Inline validation, not karhu's attribute validator.** mishka validates *in the controller* ([:82-86](app/Controllers/HouseholdController.php#L82-L86)) rather than using karhu's `#[Required]`/`Validation` DTO system (karhu §9). ⟵ **A real divergence worth noticing:** the framework offers a validation mechanism the flagship app chose not to use here. *Exercise: why might that be? (Hint: server-rendered forms that re-display errors + old input vs. JSON-API validation.)*
6. **PRG on success, re-render with `422` on failure** — success returns `redirect(..., 303)` ([:90](public/../app/Controllers/HouseholdController.php#L90)); validation failure re-renders the form with a `422` and the `old` input ([:110-116](app/Controllers/HouseholdController.php#L110-L116)).
7. **Flash + session mutation** — `Session::set('flash_success', …)` before a redirect ([:233](app/Controllers/HouseholdController.php#L233)); note privilege-changing actions also **rotate the CSRF token** (`Csrf::regenerate()`, [:319](app/Controllers/HouseholdController.php#L319)) — defence in depth, and the exact "session rotates → token rotates" case the karhu CSRF comment described.

> **Router gotcha the whole app obeys:** literal-segment routes (`/health/log/food/search`) must be declared as *methods before* any `{id}` route in the same controller — karhu's router matches by **declaration order, not specificity**.

---

## 5. The view layer — shared context + Twig

- **`NavContext`** ([app/View/NavContext.php:40](app/View/NavContext.php#L40)) is the single source of truth for what `layout.twig` needs (logged-in email, household list, active household, verify banner, flash). Every controller merges it into its own data with the PHP array-union operator: `$data + $this->nav->forCurrentUser()` (see the docblock at [:10-26](app/View/NavContext.php#L10-L26)). **Mind the `+` idiom:** with `+`, *left-hand keys win* — so the controller's own data overrides nav defaults on key collision (this is different from `array_merge`; it bit v0.6.14, see the comment at [HomeController.php:118-122](app/Controllers/HomeController.php#L118-L122)).
- **`active_household` is fetched FRESH** every render against the session id, and cross-checked against live membership ([NavContext.php:56-67](app/View/NavContext.php#L56-L67)) — this is the data half of the kicked-user self-heal (the redirect half lives in HomeController/HouseholdAuthorizer).
- **`CsrfTwigExtension`** ([app/View/CsrfTwigExtension.php](app/View/CsrfTwigExtension.php)) bridges karhu's `Csrf::field()` static into a Twig function so templates can drop a CSRF hidden input — the template-side of karhu's static-facade decision.

---

## 6. The data layer — karhu-db repositories

No ORM. Each aggregate has a repository of hand-written parameterised SQL over `Connection` (`fetchOne` / `fetchAll` / `fetchScalar` / `run` / `insert` / `pdo()`). Read `MishkaUserRepository` as the exemplar:

- **Driver-portable SQL** — `json_agg` (PG) vs `json_group_array` (SQLite) chosen at construction ([:30-34](app/Auth/MishkaUserRepository.php#L30-L34)); `INSERT … RETURNING` is used because it works on both PG and SQLite 3.35+ ([:173-179](app/Auth/MishkaUserRepository.php#L173-L179)). The tracker repos add more of these idioms — `TRUE`/`FALSE` literals over parameterised booleans, `ESCAPE '\'` on `LIKE`, `ON CONFLICT` vs `INSERT OR IGNORE` branches in the seed commands.
- **Nested-transaction guard** — `create()` and `updatePassword()` only `beginTransaction()` if one isn't already open ([:166-170](app/Auth/MishkaUserRepository.php#L166-L170)), because the **test harness wraps every test in an outer transaction**. This pattern recurs; recognise it.
- **Race-free sentinel claim** — the *first ever* registration atomically claims the seeded `admin` sentinel row via a conditional `UPDATE … WHERE user_id = 0` ([:181-191](app/Auth/MishkaUserRepository.php#L181-L191)); everyone after sees 0 rows affected and gets `member`. No app-level "is this the first user?" check — the SQL is the lock.
- **Idempotent writes** — `markEmailVerified()` guards `WHERE email_verified_at IS NULL` ([:296-301](app/Auth/MishkaUserRepository.php#L296-L301)) so a re-submitted token can't overwrite the original timestamp (there's your **idempotent** term from the karhu tour, in the wild).

---

## 7. Background work in a *synchronous* world — the sync/async payoff

This is the section to read slowly, because it's the concrete answer to the sync/async conversation.

**The problem:** a push notification send is slow (network round-trips to Apple/Google/Mozilla push services) and can fail. But PHP is **synchronous and share-nothing per request** (karhu Vocabulary check): there's no event loop to hand the work to, and the request *must finish and tear down*. You cannot "fire and forget" inside a PHP request the way Node can.

**mishka's solution — a queue + a worker, i.e. async-via-infrastructure, not async-via-language:**

```
[web request]  POST /chores/{id}/done   ──►  enqueue a job row     ──►  returns 303 immediately
   (synchronous, ends here)                  (karhu-queue: jobs table in Postgres)

           ... time passes, request is long gone ...

[separate process]  push:worker  ──►  Worker.run() loops forever  ──►  pop job ──►  handlePush ──►  PushSender → device
   (its own container, restart: unless-stopped)
```

- The **producer** side runs inside a normal request: write a `SendPushNotificationJob` row to the `jobs` table (`DatabaseQueue`, bound in [bootstrap.php:296-297](public/bootstrap.php#L296-L297)) and return. Fast, synchronous, done.
- The **consumer** side is [app/Commands/PushWorkerCommand.php](app/Commands/PushWorkerCommand.php) — a karhu CLI command (`#[Command('push:worker')]`, [:57](app/Commands/PushWorkerCommand.php#L57)) that `Worker::run()`s an **infinite loop** ([:61](app/Commands/PushWorkerCommand.php#L61)) in its **own long-lived container** (`mishka-worker`, `restart: unless-stopped`). `handlePush()` ([:81](app/Commands/PushWorkerCommand.php#L81)) fans out to each device subscription and reacts to the result (410 → revoke, transient → log-and-leave).

**The contrast to internalise:** wojtek (Node) would defer this *in-process* — `await`/background it on the event loop, one long-lived process. mishka can't, so it externalises the "long-lived process" into a **separate container** and the "later" into a **database table**. Same goal (don't make the user wait), opposite mechanism — dictated entirely by the synchronous execution model. The CLI command mechanism this rides on is *exactly* the `#[Command]` + reflection + container path from the karhu tour (karhu §10); the worker is `push:worker`, `push:scan` (the cron producer) is its companion.

**Honest sharp edge:** a SIGKILL'd worker leaves a job stuck in `processing` forever — documented as "at-most-once by design" ([:28-31](app/Commands/PushWorkerCommand.php#L28-L31)). Note it; it's the kind of trade-off a from-scratch queue makes that a mature one (Sidekiq/BullMQ) would handle for you.

---

## 8. Tracker — one feature, end to end

Everything so far has been *cross-cutting* (the seam, the controller recipe, the data layer). This section is the **vertical slice**: the **Tracker** domain (UI label "Health") — food/exercise/weight logging with BMR targets, a private energy-balance dashboard, and a household leaderboard + badges. It's the largest thing built since this tour was first written (v0.8.0–v0.8.4; v0.8.5 was Docker/CI hardening, not tracker), and it's worth reading precisely *because* it reuses every pattern above on fresh code — then adds a few new "why"s. For exhaustive schema/route/seed detail, read [docs/TRACKER.md](docs/TRACKER.md) (§10–13, one per release); this section is the map, not the manual.

**The shape** — profile → target → log → balance → shared surfaces:

```
TrackerProfile (sex / birth-year / height / base-activity) ─┐
WeightLog (latest kg) ──────────────────────────────────────┴─► BmrCalculator → daily expenditure target
FoodLog     (dish → serving → qty → kcal snapshot) ─┐
ExerciseLog (duration │ strength → kcal + MET-min) ─┴─► /health "Today" = PRIVATE energy balance (in vs out vs net)
                                                     ├─► /health/leaderboard = HOUSEHOLD, MET-minutes only
                                                     └─► TrackerBadgeAwarder → badge wall (household-visible)
```

**The 12 classes map straight onto the patterns you already know:** seven **repositories** (`FoodRepository`, `FoodServingRepository`, `FoodLogRepository`, `ExerciseRepository`, `ExerciseLogRepository`, `WeightLogRepository`, `TrackerProfileRepository`) — the same `final`, constructor-`Connection`, hand-SQL, nested-txn-guarded, PG/SQLite-portable shape as §6; two pure **calculators** (`BmrCalculator`, `ExerciseKcalCalculator` — no DB, no state); and three **services** (`LocalDay`, `LoggedOnValidator`, `TrackerBadgeAwarder`). All wired the §2 way — `bootstrap.php` `new`s each and `set()`s it ([:178-190](public/bootstrap.php#L178-L190) build, [:253-264](public/bootstrap.php#L253-L264) bind); the 9 controllers auto-wire off those and register through the same `#[Route]` scan ([config/controllers.php:36-49](config/controllers.php#L36-L49) → [bootstrap.php:348](public/bootstrap.php#L348)). No new framework mechanics — the point is that the patterns *hold*.

Four "why"s worth the read:

1. **Calendar dates are computed in PHP, never in SQL.** `logged_on` is a *date*, not an instant — two households in different timezones "log lunch on the 12th" at different UTC moments, so the DB session clock is the wrong authority. `LocalDay::today()` derives the date against the household's IANA timezone ([app/Tracker/LocalDay.php:36-41](app/Tracker/LocalDay.php#L36-L41)). The v0.8.4 offline-replay `LoggedOnValidator` builds on it and flags a real **DST trap** ([:44-75, esp. :70](app/Tracker/LoggedOnValidator.php#L70)): the "≤ 7 days old" cutoff must use `->modify('-7 days')` (calendar arithmetic), not a 168-hour interval, or it drifts an hour across Auckland's NZDT/NZST flip. *karhu's "correct/portable over clever" ethos, applied to time.*
2. **The BMR formula guards against its own inputs.** Mifflin-St Jeor from `birth_year` (an integer — full DOB withheld for privacy, accepting a < 1% pre-birthday drift), with an `age < 5` reject that catches the "typed the current year into birth_year" fat-finger before it yields a plausible-but-wrong target ([app/Tracker/BmrCalculator.php:30-49](app/Tracker/BmrCalculator.php#L30-L49)). The controller separately prevents a **double-count**: `base_activity` must exclude deliberate workouts, or logged exercise gets subtracted from expenditure twice.
3. **Exercise is a genuine discriminated union.** Duration (minutes → MET-minutes, weight-dependent kcal) and strength (sets/reps/load → mechanical-work kcal, weight-*in*dependent) are two disjoint branches with a user-locked "no conversion" rule ([ExerciseKcalCalculator.php:38-58](app/Tracker/ExerciseKcalCalculator.php#L38-L58)). Unlike `food_log` (which LEFT-JOINs the catalog and `COALESCE`s), `exercise_log` **snapshots the name *and type*** — because type materially changes how a historical row even renders. *Exercise: why can food degrade gracefully to nullable columns but exercise can't?*
4. **The badge awarder is a best-effort side-effect.** It's called synchronously after each exercise write, but the call site **must swallow exceptions** — a badge-eval bug can never fail the log write ([ExerciseLogController.php:161-171](app/Controllers/ExerciseLogController.php#L161-L171)); idempotency is `ON CONFLICT DO NOTHING` at the grant, which is what makes the `tracker:badges-backfill` CLI safe to re-run. The honest edge: backfill can rebuild *cumulative* badges (counts, lifetime MET-minutes) but **not streaks** (eager-only) — documented, not engineered around ([TrackerBadgeAwarder.php:88-176](app/Tracker/TrackerBadgeAwarder.php#L88-L176)).

**One real divergence to notice:** `TrackerBadgeAwarder` is a **separate class, not a subclass** of Chores' `BadgeAwarder`, though they play the same role — because the two grant off different SQL feeds (SRP over shared inheritance, argued in DOCS #73). It still reaches across namespaces for one *static* call, `Chores\Achievements::computeDailyStreakLocal` — an accepted hygiene compromise, not a clean hoist. *Exercise: when is "two similar classes" better than "one base + two subclasses"?*

**Privacy is structural, not incidental:** the private balance ([TrackerController.php:96-144](app/Controllers/TrackerController.php#L96-L144)) and the shared leaderboard ([ExerciseLogRepository.php:233-266](app/Tracker/ExerciseLogRepository.php#L233-L266)) query **different column sets** — intake/weight/net never reach a household-visible surface — and a *shape*-based regression test guards it (a copy-pasted widget would leak by structure even with mangled values).

---

## 9. Pattern catalog — the app-level "why"

| Pattern | Where | Why |
|---------|-------|-----|
| **The seam / dependency inversion** | `MishkaUserRepository impl UserRepositoryInterface` | Framework declares the need; app owns the schema + SQL. The whole karhu bet. |
| **Explicit DI graph in bootstrap** | `bootstrap.php` `set()` × 49 | Legible, greppable startup; guarantees one shared `Connection`; scalars can't auto-wire |
| **Factory for scalar-arg services** | `factory(AuthController…)` | Auto-wire can't supply `$dummyHash`/timing scalars — karhu's `factory()` feature earns its keep |
| **Fail-fast boot validation** | env checks in bootstrap | Turn "misconfigured in prod" into "won't start"; also a security control (host-header injection) |
| **Repository pattern, no ORM** | `app/**/*Repository.php` | Full control over portable SQL; the DB *is* the model |
| **PRG (Post/Redirect/Get)** | `redirect(…, 303)` after POST | Kills double-submit; clean browser history |
| **Shared view context** | `NavContext` merged via `+` | One source of truth for layout state; left-key-wins union |
| **Static session/CSRF facade** | `Session::`, `Csrf::` | Ergonomics in controllers + templates without threading the container everywhere |
| **Queue + worker for deferral** | karhu-queue + `push:worker` | The synchronous-PHP answer to "background work" (§7) |
| **Nested-txn guard** | `create()`, `updatePassword()` | Same repo code works under the test harness's outer transaction and in prod |
| **Race-free SQL over app locks** | sentinel admin claim | Let the database be the concurrency authority, not PHP |
| **Timezone-local calendar dates** | `LocalDay::today()` (PHP, not SQL `CURRENT_DATE`) | A logged day is a date, not an instant; the household's TZ is the authority, DST-safe (§8) |
| **Discriminated union over one shape** | `exercise_log` duration vs. strength | Two genuinely different things resist a shared schema; snapshot name+type for honest history |
| **Best-effort side-effect** | `TrackerBadgeAwarder` call swallows exceptions | A secondary reward must never fail the primary write; idempotent grant makes backfill safe |
| **Security-in-depth, annotated** | dummy hash, timing floor, CSRF rotation | Threat models live *in the code comments* — read them |

---

## 10. Active-recall exercises

Trace in the source before concluding.

1. **A non-owner POSTs `/household/rename`.** Name every hop from request to response, and explain *why there is no `try/catch`* in `handleRename`. Where exactly is the 403 produced, and by whom? (Follow [HouseholdController.php:156](app/Controllers/HouseholdController.php#L156) → `HouseholdAuthorizer::requireOwner` → `throw` → karhu `ExceptionHandler`.)
2. **A user is kicked from their household while logged in.** Trace how their *next* `GET /` self-heals to `/household/setup`. Which piece supplies the null (`NavContext`), and which piece acts on it (`HomeController`)? Now trace the *other* path where the same kick produces a `302` instead of a `403` — what's different? ([HouseholdAuthorizer.php:41-48](app/Auth/HouseholdAuthorizer.php#L41-L48).)
3. **Why does `AuthController` need a `factory()` in bootstrap but `HomeController` doesn't?** Answer using the difference in their constructors. (Tie to karhu §6's scalar-resolution problem.)
4. **A chore is marked done and should fire a push reminder.** Trace enqueue → device. State which OS *process* each step runs in, and pinpoint the exact line where the web request stops caring. (§7.)
5. **The very first registration becomes admin; the second doesn't.** Find the race-free SQL and explain how two simultaneous first-registrations can't both become admin. ([MishkaUserRepository.php:181-191](app/Auth/MishkaUserRepository.php#L181-L191).)
6. **`$data + $nav` vs `array_merge($nav, $data)`** — would swapping to `array_merge` change behaviour? Construct the exact key collision that breaks. ([NavContext](app/View/NavContext.php) + [HomeController.php:118-122](app/Controllers/HomeController.php#L118-L122).)
7. **A user logs a run at 11 pm on the day NZDT ends.** Trace how `logged_on` is chosen and prove it lands on the right calendar day; then explain why the offline "≤ 7 days old" check uses `->modify('-7 days')` and not a 168-hour interval. (§8; [LocalDay.php:36-41](app/Tracker/LocalDay.php#L36-L41) + [LoggedOnValidator.php:44-75](app/Tracker/LoggedOnValidator.php#L44-L75).)
8. **The `/health` "Today" dashboard is private; the `/health/leaderboard` is household-visible.** Explain how the code *structurally* prevents intake/weight from leaking into the leaderboard — not "it just doesn't select them," but what makes that hard to break by accident. (§8; compare [TrackerController.php:96-144](app/Controllers/TrackerController.php#L96-L144) vs [ExerciseLogRepository.php:233-266](app/Tracker/ExerciseLogRepository.php#L233-L266).)

---

## 11. Bridge to istrbuddy

istrbuddy is the *other* karhu dogfood — an issue tracker, smaller, SQLite-backed, and it's the app karhu ships as its own reference (`examples/istrbuddy/`, [docs/recipes/istrbuddy.md](../karhu/docs/recipes/istrbuddy.md)). Carry these questions in:
- **How does istrbuddy's seam differ?** It has no households/push/email — so its `bootstrap` should be dramatically smaller. What's the *minimum* a karhu app must wire?
- **SQLite vs PostgreSQL** — istrbuddy is SQLite-only, so no driver-portability dance. Does it still use the repository pattern?
- **Does it lean on auto-wiring more** (fewer scalar deps → fewer `set()`/`factory()` calls)?

The comparison *is* the lesson: two apps, one framework, different wiring pressures.

---

*Tour covers mishka @ `f261fd4` (v0.8.5.1). Companion docs: [DOCS.md](DOCS.md) + [docs/](docs/) (deep design per feature — [docs/TRACKER.md](docs/TRACKER.md) for the health tracker's schema/routes/seed), [karhu/CODE-TOUR.md](../karhu/CODE-TOUR.md) (the engine). Next tour: istrbuddy → ansible.*
