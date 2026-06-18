<?php

declare(strict_types=1);

namespace App\Tests;

use App\Account\AccountEmailFlow;
use App\Account\UserPreferenceRepository;
use App\Auth\EmailSendAttemptRepository;
use App\Auth\EmailChangeTokenRepository;
use App\Auth\EmailVerificationTokenRepository;
use App\Auth\HouseholdAuthorizer;
use App\Auth\MishkaUserRepository;
use App\Auth\PasswordResetTokenRepository;
use App\Auth\UserPasswordChangeRepository;
use App\Calendar\EventExceptionRepository;
use App\Calendar\EventRepository;
use App\Calendar\EventService;
use App\Calendar\IcalFeedBuilder;
use App\Calendar\IcalFeedTokenRepository;
use App\Calendar\MonthGridBuilder;
use App\Calendar\RangeExpander;
use App\Calendar\RruleTranslator;
use App\Chores\BadgeAwardRepository;
use App\Chores\BadgeAwarder;
use App\Chores\ChoreRepository;
use App\Chores\ChoreScheduleGenerator;
use App\Chores\ChoreScheduleRepository;
use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\CalendarController;
use App\Controllers\ChoresController;
use App\Controllers\ChoreSchedulesController;
use App\Controllers\CsrfTokenController;
use App\Controllers\HomeController;
use App\Controllers\HouseholdController;
use App\Controllers\IcalFeedController;
use App\Controllers\EmailVerificationController;
use App\Controllers\HelpController;
use App\Controllers\NotificationsController;
use App\Controllers\OfflineController;
use App\Controllers\PasswordResetController;
use App\Mail\Mailer;
use App\Mail\UrlBuilder;
use App\Push\NotificationDispatchRepository;
use App\Push\PushSender;
use App\Push\PushSubscriptionRepository;
use App\Push\UserNotificationPrefsRepository;
use App\Push\VapidConfig;
use App\Tests\Fixtures\RecordingMailer;
use App\Tests\Fixtures\RecordingPushSender;
use Karhu\Queue\DatabaseQueue;
use Karhu\Queue\QueueInterface;
use App\Household\HouseholdRepository;
use App\View\CsrfTwigExtension;
use App\View\NavContext;
use Karhu\App;
use Karhu\Auth\PasswordHasher;
use Karhu\Auth\Rbac;
use Karhu\Auth\UserRepositoryInterface;
use Karhu\Db\Connection;
use Karhu\Error\ExceptionHandler;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\View\TwigAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Base test case for mishka integration tests.
 *
 * Boots a karhu App wired to the in-memory SQLite test DB, with Twig
 * configured for `templates/`, and a pre-computed dummy hash for the
 * AuthController's timing-attack defense.
 *
 * Skips Session + Csrf middleware so tests can manipulate $_SESSION
 * directly and post forms without round-tripping a CSRF token.
 */
abstract class AppTestCase extends TestCase
{
    protected App $app;
    protected Connection $db;
    protected MishkaUserRepository $userRepo;
    protected HouseholdRepository $householdRepo;
    protected UserPreferenceRepository $prefsRepo;
    protected EventRepository $eventRepo;
    protected IcalFeedTokenRepository $tokenRepo;
    protected ChoreRepository $choreRepo;
    // v0.6.13
    protected BadgeAwardRepository $badgeAwardRepo;
    protected BadgeAwarder $badgeAwarder;
    protected ChoreScheduleRepository $scheduleRepo;
    protected PasswordHasher $hasher;
    // v0.5.0
    protected EmailVerificationTokenRepository $verifyTokenRepo;
    protected PasswordResetTokenRepository $resetTokenRepo;
    protected UserPasswordChangeRepository $pwChangeRepo;
    protected EmailSendAttemptRepository $sendAttemptRepo;
    protected RecordingMailer $mailer;
    // v0.6.11
    protected EmailChangeTokenRepository $changeTokenRepo;
    // v0.6.0
    protected PushSubscriptionRepository $pushSubRepo;
    protected UserNotificationPrefsRepository $notifyPrefsRepo;
    protected NotificationDispatchRepository $notifyDispatchRepo;
    protected RecordingPushSender $pushSender;
    protected DatabaseQueue $queue;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->app = $this->createApp();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        $_SESSION = [];
    }

    /** Boot the app with the same wiring as public/index.php (minus Session/Csrf). */
    protected function createApp(): App
    {
        $app = new App();

        $this->userRepo = new MishkaUserRepository($this->db);
        $this->householdRepo = new HouseholdRepository($this->db);
        $this->prefsRepo = new UserPreferenceRepository($this->db);
        $this->eventRepo = new EventRepository($this->db);
        $exceptionRepo = new EventExceptionRepository($this->db);
        $monthGrid = new MonthGridBuilder();
        $rangeExpander = new RangeExpander($this->eventRepo, $exceptionRepo);
        $rruleTranslator = new RruleTranslator();
        $eventService = new EventService($this->eventRepo, $exceptionRepo);
        $this->tokenRepo = new IcalFeedTokenRepository($this->db);
        $icalBuilder = new IcalFeedBuilder($this->eventRepo, $exceptionRepo, $this->householdRepo);
        $this->choreRepo = new ChoreRepository($this->db);
        $this->badgeAwardRepo = new BadgeAwardRepository($this->db);
        $this->badgeAwarder = new BadgeAwarder($this->badgeAwardRepo, $this->choreRepo);
        $this->scheduleRepo = new ChoreScheduleRepository($this->db);
        $choreScheduleGenerator = new ChoreScheduleGenerator($this->scheduleRepo, $this->choreRepo, $this->householdRepo);
        $this->hasher = new PasswordHasher();
        $rbac = new Rbac($this->userRepo);
        $authz = new HouseholdAuthorizer($this->householdRepo);
        $nav = new NavContext($this->householdRepo);

        // v0.5.0 — account lifecycle + email-dependent flows
        $this->verifyTokenRepo = new EmailVerificationTokenRepository($this->db);
        $this->resetTokenRepo = new PasswordResetTokenRepository($this->db);
        $this->pwChangeRepo = new UserPasswordChangeRepository($this->db);
        $this->sendAttemptRepo = new EmailSendAttemptRepository($this->db);
        $this->mailer = new RecordingMailer();
        // v0.6.11 — email-change flow
        $this->changeTokenRepo = new EmailChangeTokenRepository($this->db);
        $urlBuilder = new UrlBuilder('http://localhost:8080');
        // v0.6.20 — bundle the 5 email-change-specific deps for AccountController
        $accountEmailFlow = new AccountEmailFlow(
            $this->changeTokenRepo,
            $this->sendAttemptRepo,
            $this->resetTokenRepo,
            $this->verifyTokenRepo,
            $urlBuilder,
        );

        // v0.6.0 — push notifications
        $this->pushSubRepo = new PushSubscriptionRepository($this->db);
        $this->notifyPrefsRepo = new UserNotificationPrefsRepository($this->db);
        $this->notifyDispatchRepo = new NotificationDispatchRepository($this->db);
        $this->pushSender = new RecordingPushSender();
        $this->queue = new DatabaseQueue($this->db);
        // Stub VAPID — the keys are arbitrary base64url-shape values; tests
        // never invoke the real WebPush transport (RecordingPushSender does
        // the substitution).
        $vapid = new VapidConfig(
            publicKey: 'BHkj3Stq_test_public_key_base64url_only_for_assertions',
            privateKey: 'priv_test_only',
            subject: 'mailto:test@example.com',
        );

        $twig = new TwigAdapter(__DIR__ . '/../templates', cache: false);
        $twig->twig()->addExtension(new CsrfTwigExtension());
        $twig->twig()->addGlobal('brand', require __DIR__ . '/../config/brand.php');
        $twig->twig()->addGlobal('badge_meta', require __DIR__ . '/../config/badges.php');

        $app->container()->set(Connection::class, $this->db);
        $app->container()->set(UserRepositoryInterface::class, $this->userRepo);
        $app->container()->set(MishkaUserRepository::class, $this->userRepo);
        $app->container()->set(HouseholdRepository::class, $this->householdRepo);
        $app->container()->set(UserPreferenceRepository::class, $this->prefsRepo);
        $app->container()->set(HouseholdAuthorizer::class, $authz);
        $app->container()->set(NavContext::class, $nav);
        $app->container()->set(EventRepository::class, $this->eventRepo);
        $app->container()->set(EventExceptionRepository::class, $exceptionRepo);
        $app->container()->set(MonthGridBuilder::class, $monthGrid);
        $app->container()->set(RangeExpander::class, $rangeExpander);
        $app->container()->set(RruleTranslator::class, $rruleTranslator);
        $app->container()->set(EventService::class, $eventService);
        $app->container()->set(IcalFeedTokenRepository::class, $this->tokenRepo);
        $app->container()->set(IcalFeedBuilder::class, $icalBuilder);
        $app->container()->set(ChoreRepository::class, $this->choreRepo);
        $app->container()->set(BadgeAwardRepository::class, $this->badgeAwardRepo);
        $app->container()->set(BadgeAwarder::class, $this->badgeAwarder);
        $app->container()->set(ChoreScheduleRepository::class, $this->scheduleRepo);
        $app->container()->set(ChoreScheduleGenerator::class, $choreScheduleGenerator);
        $app->container()->set(PasswordHasher::class, $this->hasher);
        $app->container()->set(Rbac::class, $rbac);
        $app->container()->set(TwigAdapter::class, $twig);
        // v0.5.0 container bindings
        $app->container()->set(EmailVerificationTokenRepository::class, $this->verifyTokenRepo);
        $app->container()->set(PasswordResetTokenRepository::class, $this->resetTokenRepo);
        $app->container()->set(UserPasswordChangeRepository::class, $this->pwChangeRepo);
        $app->container()->set(EmailSendAttemptRepository::class, $this->sendAttemptRepo);
        $app->container()->set(Mailer::class, $this->mailer);
        $app->container()->set(UrlBuilder::class, $urlBuilder);
        // v0.6.11 container bindings
        $app->container()->set(EmailChangeTokenRepository::class, $this->changeTokenRepo);
        // v0.6.20 — the bundle used by AccountController
        $app->container()->set(AccountEmailFlow::class, $accountEmailFlow);
        // v0.6.0 container bindings
        $app->container()->set(PushSubscriptionRepository::class, $this->pushSubRepo);
        $app->container()->set(UserNotificationPrefsRepository::class, $this->notifyPrefsRepo);
        $app->container()->set(NotificationDispatchRepository::class, $this->notifyDispatchRepo);
        $app->container()->set(PushSender::class, $this->pushSender);
        $app->container()->set(VapidConfig::class, $vapid);
        $app->container()->set(QueueInterface::class, $this->queue);

        $dummyHash = $this->hasher->hash('test-dummy-' . bin2hex(random_bytes(8)));
        // v0.7.0 — SessionRepository for per-device session tracking.
        $sessionRepo = new \App\Auth\SessionRepository($this->db);
        $app->container()->set(\App\Auth\SessionRepository::class, $sessionRepo);

        $app->container()->factory(AuthController::class, fn() => new AuthController(
            $this->userRepo, $this->hasher, $twig, $dummyHash,
            $this->householdRepo, $this->prefsRepo, $nav,
            // v0.5.0 register-hook deps
            $this->verifyTokenRepo, $this->mailer, $urlBuilder,
            // v0.7.0 per-device session tracking
            $sessionRepo,
        ));

        // PasswordResetController takes scalar ctor params (timing floor +
        // dummy hash) so it can't auto-wire — register a factory.
        // Tests use a 50ms floor + empty dummy hash to keep the suite fast;
        // PasswordResetTimingTest constructs its own with the production 1.5s floor.
        $app->container()->factory(PasswordResetController::class, fn() => new PasswordResetController(
            $this->resetTokenRepo, $this->userRepo, $this->hasher, $this->mailer,
            $urlBuilder, $this->sendAttemptRepo, $twig, $nav,
            timingFloorMicros: 50_000,
            dummyHash: '',
        ));

        $app->router()->scanControllers([
            HomeController::class,
            AuthController::class,
            HouseholdController::class,
            CalendarController::class,
            IcalFeedController::class,
            // ChoreSchedulesController MUST precede ChoresController: the router
            // matches sequentially and ChoresController's `/chores/{id}` would
            // otherwise greedily capture the static `/chores/schedules` paths.
            ChoreSchedulesController::class,
            ChoresController::class,
            // v0.5.0 — account + email-dependent flows
            AccountController::class,
            PasswordResetController::class,
            EmailVerificationController::class,
            // v0.5.2 — in-product user guide
            HelpController::class,
            // v0.6.0 — push notifications
            NotificationsController::class,
            // v0.6.7 — /offline shell precached by service worker
            OfflineController::class,
            // v0.6.8 — /csrf-token JSON endpoint
            CsrfTokenController::class,
            // v0.6.13 — /badges page
            \App\Controllers\BadgesController::class,
            // v0.7.0 — /me/sessions UI
            \App\Controllers\SessionsController::class,
        ]);

        return $app;
    }

    /**
     * Make a request through the full app stack.
     *
     * Sends JSON bodies by default (matches istrbuddy convention).
     * Pass `headers['content-type'] => 'application/x-www-form-urlencoded'`
     * to test the form-urlencoded code path.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    protected function request(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
    ): Response {
        $queryString = '';
        $get = [];
        if (str_contains($path, '?')) {
            [$path, $queryString] = explode('?', $path, 2);
            parse_str($queryString, $get);
        }

        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path . ($queryString !== '' ? "?{$queryString}" : ''),
        ];

        $headers = array_merge(['accept' => 'text/html'], $headers);

        $jsonBody = '';
        $post = [];
        if ($body !== [] && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $contentType = $headers['content-type'] ?? 'application/json';
            if (str_contains($contentType, 'json')) {
                $jsonBody = json_encode($body, JSON_THROW_ON_ERROR);
                $headers['content-type'] = 'application/json';
            } else {
                $post = $this->castBodyToStringMap($body);
                $headers['content-type'] = 'application/x-www-form-urlencoded';
            }
        }

        /** @var array<string, string> $get */
        /** @var array<string, string> $post */
        $request = new Request(
            server: $server,
            get: $get,
            post: $post,
            body: $jsonBody,
            headers: $headers,
        );

        // Production routes uncaught exceptions through PHP's global handler
        // (installed by ExceptionHandler::register()). Tests don't go through
        // emit(), so we catch + route through the handler directly to mirror
        // the same content-negotiated response semantics.
        try {
            return $this->app->handle($request);
        } catch (\Throwable $e) {
            return (new ExceptionHandler())->handle($e, $request);
        }
    }

    /** Simulate a logged-in user by setting session data directly. */
    protected function loginAs(int $userId, string $email, array $roles = ['member']): void
    {
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $email;
        $_SESSION['roles'] = $roles;
    }

    /**
     * Test helper — insert a user directly and return its id.
     */
    protected function createUserWithHash(string $email, string $password, string $displayName = 'Test'): int
    {
        return $this->userRepo->create($email, $this->hasher->hash($password), $displayName);
    }

    /**
     * v0.6.19 — directly seed a system_roles admin grant for tests that need
     * a multi-admin or non-first-user-admin state. The first user gets admin
     * via the sentinel claim in MishkaUserRepository::create; this helper is
     * for adding a SECOND admin (e.g. testing "two admins exist, either may
     * self-delete") without depending on commit ordering between the
     * SystemRoleRepository commit and the delete-flow tests.
     */
    protected function grantSystemAdmin(int $userId): void
    {
        $this->db->run(
            'INSERT OR IGNORE INTO system_roles (user_id, role) VALUES (:uid, :role)',
            ['uid' => $userId, 'role' => 'admin'],
        );
    }

    /**
     * Test helper — set the user's active household session keys + last_household_id pref.
     */
    protected function activateHouseholdInSession(int $userId, int $householdId, string $role = 'member'): void
    {
        $_SESSION['active_household_id'] = $householdId;
        $_SESSION['active_household_role'] = $role;
        $this->prefsRepo->setLastHouseholdId($userId, $householdId);
    }

    /**
     * Coerce arbitrary body values to a string-only map for $_POST simulation.
     *
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    private function castBodyToStringMap(array $body): array
    {
        $out = [];
        foreach ($body as $k => $v) {
            if (is_scalar($v)) {
                $out[(string) $k] = (string) $v;
            } else {
                $encoded = json_encode($v);
                $out[(string) $k] = is_string($encoded) ? $encoded : '';
            }
        }
        return $out;
    }
}
