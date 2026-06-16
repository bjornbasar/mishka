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
use App\Auth\EmailChangeTokenRepository;
use App\Auth\EmailSendAttemptRepository;
use App\Auth\EmailVerificationTokenRepository;
use App\Auth\HouseholdAuthorizer;
use App\Auth\MishkaUserRepository;
use App\Auth\PasswordResetTokenRepository;
use App\Auth\SessionRevocationGuard;
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
use App\Controllers\EmailVerificationController;
use App\Controllers\PasswordResetController;
use App\Household\HouseholdRepository;
use App\Mail\Mailer;
use App\Mail\UrlBuilder;
use App\View\CsrfTwigExtension;
use App\View\NavContext;
use Dotenv\Dotenv;
use Karhu\App;
use Karhu\Auth\PasswordHasher;
use Karhu\Auth\Rbac;
use Karhu\Auth\UserRepositoryInterface;
use Karhu\Db\Connection;
use Karhu\Error\ExceptionHandler;
use App\Clock\SystemClock;
use App\Push\NotificationDispatchRepository;
use App\Push\PushSender;
use App\Push\PushSubscriptionRepository;
use App\Push\UserNotificationPrefsRepository;
use App\Push\VapidConfig;
use Karhu\Middleware\Csrf;
use Karhu\Middleware\Session;
use Karhu\Queue\DatabaseQueue;
use Karhu\Queue\QueueInterface;
use Karhu\View\TwigAdapter;
use Minishlink\WebPush\WebPush;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;

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

// v0.5.0: REQUIRE APP_URL at boot (B1 — host-header injection in email links).
// UrlBuilder reads ONLY this value when constructing /password-reset and
// /verify-email URLs that go into emails. Without it, an attacker could
// forge `Host: evil.com` against the unauthenticated /password-reset endpoint
// and mint a phishing URL targeting any registered user. Fail fast.
$appUrl = $_ENV['APP_URL'] ?? '';
if (!is_string($appUrl) || !preg_match('#^https?://#', $appUrl)) {
    throw new RuntimeException('APP_URL missing or invalid (must be http(s)://...). Set it in .env.');
}

// v0.5.0: REQUIRE MAIL_FROM_ADDRESS for outbound email From: header.
$mailFromAddress = $_ENV['MAIL_FROM_ADDRESS'] ?? '';
if (!is_string($mailFromAddress) || !filter_var($mailFromAddress, FILTER_VALIDATE_EMAIL)) {
    throw new RuntimeException('MAIL_FROM_ADDRESS missing or invalid. Set it in .env.');
}
$mailFromName = (string) ($_ENV['MAIL_FROM_NAME'] ?? 'Mishka Den');
$mailerDsn = (string) ($_ENV['MAILER_DSN'] ?? 'null://null');

// v0.6.0: REQUIRE VAPID keypair at boot for Web Push Protocol.
// Run `vendor/bjornbasar/karhu/bin/karhu push:generate-keys` once and paste the values into
// `.env`. The private key signs outbound pushes; the public key gets served
// to browsers via /me/notifications so they can `pushManager.subscribe()`.
// VAPID_SUBJECT is the operator-contact-on-abuse field (RFC 8292 §2.1) — must
// be a real mailto: or https:// URL or push services may rate-limit.
$vapidPublicKey = (string) ($_ENV['VAPID_PUBLIC_KEY'] ?? '');
$vapidPrivateKey = (string) ($_ENV['VAPID_PRIVATE_KEY'] ?? '');
$vapidSubject = (string) ($_ENV['VAPID_SUBJECT'] ?? '');
if ($vapidPublicKey === '' || $vapidPrivateKey === '') {
    throw new RuntimeException(
        'VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY missing in .env. Generate with: '
        . 'php vendor/bjornbasar/karhu/bin/karhu push:generate-keys'
    );
}
if (!preg_match('#^(mailto:[^@\s]+@[^@\s]+|https?://)#', $vapidSubject)) {
    throw new RuntimeException(
        'VAPID_SUBJECT must be a real mailto: or https:// URL (RFC 8292 §2.1).'
    );
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
$twig->twig()->addGlobal('badge_meta', require __DIR__ . '/../config/badges.php');

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
$tokenRepo = new IcalFeedTokenRepository($db);
$icalBuilder = new IcalFeedBuilder($eventRepo, $exceptionRepo, $householdRepo);
$choreRepo = new ChoreRepository($db);
$choreScheduleRepo = new ChoreScheduleRepository($db);
$choreScheduleGenerator = new ChoreScheduleGenerator($choreScheduleRepo, $choreRepo, $householdRepo);
// v0.6.13 — persistent badges
$badgeAwardRepo = new BadgeAwardRepository($db);
$badgeAwarder = new BadgeAwarder($badgeAwardRepo, $choreRepo);
$hasher = new PasswordHasher();
$rbac = new Rbac($userRepo);
$authz = new HouseholdAuthorizer($householdRepo);
$nav = new NavContext($householdRepo);

// v0.5.0 — account / email lifecycle + revocation
$verifyTokenRepo = new EmailVerificationTokenRepository($db);
$resetTokenRepo = new PasswordResetTokenRepository($db);
$pwChangeRepo = new UserPasswordChangeRepository($db);
$sendAttemptRepo = new EmailSendAttemptRepository($db);
$urlBuilder = new UrlBuilder($appUrl);
// v0.6.11 — email-change flow
$changeTokenRepo = new EmailChangeTokenRepository($db);

// Symfony Mailer transport. `?timeout=5` in the DSN keeps a request from
// hanging 60s when MailHog/Postmark/SMTP is unreachable. Mailer catches
// TransportExceptionInterface internally and returns false — never throws.
$mailerTransport = Transport::fromDsn($mailerDsn);
$mailer = new Mailer(new SymfonyMailer($mailerTransport), $twig, $mailFromAddress, $mailFromName);

// v0.6.0 — web push reminders + clock + queue
$vapid = new VapidConfig($vapidPublicKey, $vapidPrivateKey, $vapidSubject);
$pushSubRepo = new PushSubscriptionRepository($db);
$notifyPrefsRepo = new UserNotificationPrefsRepository($db);
$notifyDispatchRepo = new NotificationDispatchRepository($db);
$webPush = new WebPush(['VAPID' => $vapid->forWebPush()], timeout: 5);
$pushSender = new PushSender($webPush);
$systemClock = new SystemClock();
$queue = new DatabaseQueue($db);

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
$app->container()->set(IcalFeedTokenRepository::class, $tokenRepo);
$app->container()->set(IcalFeedBuilder::class, $icalBuilder);
$app->container()->set(ChoreRepository::class, $choreRepo);
$app->container()->set(BadgeAwardRepository::class, $badgeAwardRepo);
$app->container()->set(BadgeAwarder::class, $badgeAwarder);
$app->container()->set(ChoreScheduleRepository::class, $choreScheduleRepo);
$app->container()->set(ChoreScheduleGenerator::class, $choreScheduleGenerator);
$app->container()->set(HouseholdAuthorizer::class, $authz);
$app->container()->set(NavContext::class, $nav);
$app->container()->set(PasswordHasher::class, $hasher);
$app->container()->set(Rbac::class, $rbac);
$app->container()->set(TwigAdapter::class, $twig);

// v0.5.0 bindings
$app->container()->set(EmailVerificationTokenRepository::class, $verifyTokenRepo);
$app->container()->set(PasswordResetTokenRepository::class, $resetTokenRepo);
$app->container()->set(UserPasswordChangeRepository::class, $pwChangeRepo);
$app->container()->set(EmailSendAttemptRepository::class, $sendAttemptRepo);
$app->container()->set(Mailer::class, $mailer);
$app->container()->set(UrlBuilder::class, $urlBuilder);

// v0.6.11 bindings — email change
$app->container()->set(EmailChangeTokenRepository::class, $changeTokenRepo);

// v0.6.0 bindings — web push + clock + queue
$app->container()->set(VapidConfig::class, $vapid);
$app->container()->set(PushSubscriptionRepository::class, $pushSubRepo);
$app->container()->set(UserNotificationPrefsRepository::class, $notifyPrefsRepo);
$app->container()->set(NotificationDispatchRepository::class, $notifyDispatchRepo);
$app->container()->set(PushSender::class, $pushSender);
$app->container()->set(\App\Clock\ClockInterface::class, $systemClock);
$app->container()->set(QueueInterface::class, $queue);
$app->container()->set(DatabaseQueue::class, $queue);

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
        // v0.5.0 register-hook: issue + email verify token after a successful
        // /register POST. Mailer.sendVerification returns false on SMTP fail —
        // the user-facing banner is "Please verify your email" regardless.
        $verifyTokenRepo, $mailer, $urlBuilder,
    ),
);

// v0.5.0: PasswordResetController takes scalar `timingFloorMicros` + the
// pre-computed argon2id `dummyHash`. Production uses 1.5s + the same dummy
// hash as AuthController so the always-200 miss path still consumes
// PasswordHasher CPU — defence in depth on top of the timing floor (H-4).
$app->container()->factory(
    PasswordResetController::class,
    fn(): PasswordResetController => new PasswordResetController(
        $resetTokenRepo, $userRepo, $hasher, $mailer,
        $urlBuilder, $sendAttemptRepo, $twig, $nav,
        timingFloorMicros: 1_500_000,
        dummyHash: $dummyHash,
    ),
);

// Middleware order (round-4 BL-1):
//   Session → SessionRevocationGuard → Csrf
// Session must be first so $_SESSION is available; the guard reads
// $_SESSION['user_id'] + ['auth_time'] and revokes stale sessions before
// CSRF would let them act on the request.
$app->pipe(new Session());
$app->pipe(new SessionRevocationGuard($pwChangeRepo));
$app->pipe(new Csrf());

$app->router()->scanControllers(require __DIR__ . '/../config/controllers.php');
$app->run();
