<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth\MishkaUserRepository;
use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\View\CsrfTwigExtension;
use Karhu\App;
use Karhu\Auth\PasswordHasher;
use Karhu\Auth\Rbac;
use Karhu\Auth\UserRepositoryInterface;
use Karhu\Db\Connection;
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
    protected PasswordHasher $hasher;

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
        $this->hasher = new PasswordHasher();
        $rbac = new Rbac($this->userRepo);

        $twig = new TwigAdapter(__DIR__ . '/../templates', cache: false);
        $twig->twig()->addExtension(new CsrfTwigExtension());
        $twig->twig()->addGlobal('brand', require __DIR__ . '/../config/brand.php');

        $app->container()->set(Connection::class, $this->db);
        $app->container()->set(UserRepositoryInterface::class, $this->userRepo);
        $app->container()->set(MishkaUserRepository::class, $this->userRepo);
        $app->container()->set(PasswordHasher::class, $this->hasher);
        $app->container()->set(Rbac::class, $rbac);
        $app->container()->set(TwigAdapter::class, $twig);

        $dummyHash = $this->hasher->hash('test-dummy-' . bin2hex(random_bytes(8)));
        $app->container()->factory(AuthController::class, fn() => new AuthController(
            $this->userRepo, $this->hasher, $twig, $dummyHash,
        ));

        $app->router()->scanControllers([
            HomeController::class,
            AuthController::class,
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

        return $this->app->handle($request);
    }

    /** Simulate a logged-in user by setting session data directly. */
    protected function loginAs(int $userId, string $email, array $roles = ['member']): void
    {
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $email;
        $_SESSION['roles'] = $roles;
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
