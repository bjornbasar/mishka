<?php

declare(strict_types=1);

/**
 * Mishka Den — front controller.
 *
 * Bootstrap order: error handler, env, DB, Twig (with brand global + CSRF
 * extension), DI container wiring, middleware (Session before CSRF), route
 * scan, run.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Account\UserPreferenceRepository;
use App\Auth\HouseholdAuthorizer;
use App\Auth\MishkaUserRepository;
use App\Calendar\EventExceptionRepository;
use App\Calendar\EventRepository;
use App\Calendar\EventService;
use App\Calendar\MonthGridBuilder;
use App\Calendar\RangeExpander;
use App\Calendar\RruleTranslator;
use App\Controllers\AuthController;
use App\Household\HouseholdRepository;
use App\View\CsrfTwigExtension;
use App\View\NavContext;
use Dotenv\Dotenv;
use Karhu\App;
use Karhu\Auth\PasswordHasher;
use Karhu\Auth\Rbac;
use Karhu\Auth\UserRepositoryInterface;
use Karhu\Db\Connection;
use Karhu\Error\ExceptionHandler;
use Karhu\Middleware\Csrf;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

// Install karhu's content-negotiated exception handler.
// karhu v0.1.1+ also handles ForbiddenException — with redirectTo it
// returns a 302, without it returns a 403. Used by HouseholdAuthorizer.
(new ExceptionHandler())->register();

// Load .env from the project root. safeLoad() returns silently if the file
// is missing — production .env is provisioned by the deploy environment.
Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

$dsn = $_ENV['DB_DSN'] ?? null;
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('DB_DSN missing — copy .env.example to .env and configure.');
}

$db = new Connection(
    $dsn,
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
);

// Twig — cache disabled because karhu-view's TwigAdapter writes the cache
// into the templates directory (anti-pattern). Revisit when karhu-view
// learns to accept an explicit cache path.
$twig = new TwigAdapter(__DIR__ . '/../templates', cache: false);
$twig->twig()->addExtension(new CsrfTwigExtension());
$twig->twig()->addGlobal('brand', require __DIR__ . '/../config/brand.php');

$app = new App();

// DI wiring — every concrete dependency a controller's constructor types
// against must be either pre-registered or auto-resolvable. Connection takes
// scalars so it MUST be pre-built and set; everything else autowires.
$userRepo = new MishkaUserRepository($db);
$householdRepo = new HouseholdRepository($db);
$prefsRepo = new UserPreferenceRepository($db);
$eventRepo = new EventRepository($db);
$exceptionRepo = new EventExceptionRepository($db);
$monthGrid = new MonthGridBuilder();
$rangeExpander = new RangeExpander($eventRepo, $exceptionRepo);
$rruleTranslator = new RruleTranslator();
$eventService = new EventService($eventRepo, $exceptionRepo);
$hasher = new PasswordHasher();
$rbac = new Rbac($userRepo);
$authz = new HouseholdAuthorizer($householdRepo);
$nav = new NavContext($householdRepo);

$app->container()->set(Connection::class, $db);
$app->container()->set(UserRepositoryInterface::class, $userRepo);
$app->container()->set(MishkaUserRepository::class, $userRepo);
$app->container()->set(HouseholdRepository::class, $householdRepo);
$app->container()->set(UserPreferenceRepository::class, $prefsRepo);
$app->container()->set(EventRepository::class, $eventRepo);
$app->container()->set(EventExceptionRepository::class, $exceptionRepo);
$app->container()->set(MonthGridBuilder::class, $monthGrid);
$app->container()->set(RangeExpander::class, $rangeExpander);
$app->container()->set(RruleTranslator::class, $rruleTranslator);
$app->container()->set(EventService::class, $eventService);
$app->container()->set(HouseholdAuthorizer::class, $authz);
$app->container()->set(NavContext::class, $nav);
$app->container()->set(PasswordHasher::class, $hasher);
$app->container()->set(Rbac::class, $rbac);
$app->container()->set(TwigAdapter::class, $twig);

// AuthController takes a scalar $dummyHash (timing-attack defense). The
// auto-wirer can't inject scalars, so register via factory. Compute the
// dummy hash once at boot — argon2id is expensive enough that doing it
// per-request would be a real cost.
$dummyHash = $hasher->hash(bin2hex(random_bytes(16)));
$app->container()->factory(
    AuthController::class,
    fn(): AuthController => new AuthController(
        $userRepo, $hasher, $twig, $dummyHash,
        $householdRepo, $prefsRepo, $nav,
    ),
);

// Middleware order matters: Session must precede CSRF (CSRF reads $_SESSION).
// karhu v0.1.1's CSRF middleware no longer rotates the token after each POST,
// so multi-tab workflows (open /household + logout form in two tabs) work
// without stale-token 403s.
$app->pipe(new Session());
$app->pipe(new Csrf());

$app->router()->scanControllers(require __DIR__ . '/../config/controllers.php');
$app->run();
