<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\MishkaUserRepository;
use Karhu\Attributes\Route;
use Karhu\Auth\PasswordHasher;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * Registration / login / logout flows for mishka.
 *
 * All POST handlers read input from both JSON body (test harness) and
 * form-urlencoded POST (browser forms) — see readInput().
 *
 * Constructed via $container->factory() in public/index.php because the
 * $dummyHash scalar parameter can't be auto-wired.
 */
final class AuthController
{
    public function __construct(
        private readonly MishkaUserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly TwigAdapter $view,
        private readonly string $dummyHash,
    ) {}

    #[Route('/register', methods: ['GET'], name: 'register')]
    public function showRegister(Request $request): Response
    {
        if ($this->isLoggedIn()) {
            return (new Response())->redirect('/');
        }

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('auth/register.twig', [
                'errors' => [],
                'old' => [],
                'session_email' => null,
            ]));
    }

    #[Route('/register', methods: ['POST'])]
    public function handleRegister(Request $request): Response
    {
        $input = $this->readInput($request);
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $passwordConfirm = (string) ($input['password_confirm'] ?? '');
        $displayName = trim((string) ($input['display_name'] ?? ''));

        // Accumulate validation errors into a single list re-rendered
        // alongside the form. Password is never echoed back to the user.
        $errors = $this->validateRegistration($email, $password, $passwordConfirm, $displayName);

        if ($errors !== []) {
            return (new Response(422))
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody($this->view->render('auth/register.twig', [
                    'errors' => $errors,
                    'old' => ['email' => $email, 'display_name' => $displayName],
                    'session_email' => null,
                ]));
        }

        // Default display name to the email's local part if blank.
        if ($displayName === '') {
            $displayName = strstr($email, '@', true) ?: $email;
        }

        $hash = $this->hasher->hash($password);
        $id = $this->users->create($email, $hash, $displayName);
        $roles = $this->users->rolesFor($email);

        $this->establishSession($id, strtolower($email), $roles);

        return (new Response())->redirect('/', 303);
    }

    #[Route('/login', methods: ['GET'], name: 'login')]
    public function showLogin(Request $request): Response
    {
        if ($this->isLoggedIn()) {
            return (new Response())->redirect('/');
        }

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('auth/login.twig', [
                'errors' => [],
                'old' => [],
                'session_email' => null,
            ]));
    }

    #[Route('/login', methods: ['POST'])]
    public function handleLogin(Request $request): Response
    {
        $input = $this->readInput($request);
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        // Timing-safe authentication. Karhu's Rbac::authenticate skips
        // password_verify when the user is missing, which leaks a timing
        // oracle for user enumeration. Both branches below run exactly
        // one password_verify call so the unknown-email and wrong-password
        // paths consume the same time budget.
        $user = $this->users->findByUsername($email);

        if ($user === null) {
            $this->hasher->verify($password, $this->dummyHash);
            return $this->renderLoginError($email);
        }

        if (!$this->hasher->verify($password, $user['password_hash'])) {
            return $this->renderLoginError($email);
        }

        // findByUsername carries `id` so we don't need a second round-trip.
        $this->users->recordLogin($user['id']);
        $this->establishSession($user['id'], $user['username'], $user['roles']);

        return (new Response())->redirect('/', 303);
    }

    private function renderLoginError(string $email): Response
    {
        return (new Response(401))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('auth/login.twig', [
                'errors' => ['Invalid email or password.'],
                'old' => ['email' => $email],
                'session_email' => null,
            ]));
    }

    #[Route('/logout', methods: ['POST'], name: 'logout')]
    public function logout(Request $request): Response
    {
        Session::destroy();

        // Karhu's Session::destroy doesn't delete the cookie — the browser
        // would otherwise re-present the (now empty) session ID on the next
        // request. Explicitly expire the cookie. setcookie() is a no-op in
        // the test CLI SAPI but does what's needed in production.
        $secure = ($_SERVER['HTTPS'] ?? '') === 'on'
               || $request->header('x-forwarded-proto') === 'https';

        if (PHP_SAPI !== 'cli') {
            setcookie(session_name() ?: 'PHPSESSID', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        return (new Response())->redirect('/login', 303);
    }

    /**
     * Validate registration input. Returns a list of human-readable error
     * messages — empty list means OK.
     *
     * @return list<string>
     */
    private function validateRegistration(
        string $email,
        string $password,
        string $passwordConfirm,
        string $displayName,
    ): array {
        $errors = [];

        if ($email === '') {
            $errors[] = 'Email is required.';
        } elseif (strlen($email) > 320) {
            $errors[] = 'Email is too long (max 320 characters).';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Please enter a valid email address.';
        }

        $pwLen = strlen($password);
        if ($password === '') {
            $errors[] = 'Password is required.';
        } elseif ($pwLen < 12) {
            $errors[] = 'Password must be at least 12 characters.';
        } elseif ($pwLen > 128) {
            $errors[] = 'Password must be at most 128 characters.';
        }

        if ($password !== '' && $password !== $passwordConfirm) {
            $errors[] = 'Passwords do not match.';
        }

        if ($displayName !== '' && strlen($displayName) > 120) {
            $errors[] = 'Display name is too long (max 120 characters).';
        }

        // Only check email uniqueness if the email is otherwise valid —
        // saves a DB round-trip on malformed submissions.
        if ($errors === [] && $this->users->emailExists($email)) {
            $errors[] = 'That email is already registered.';
        }

        return $errors;
    }

    /**
     * Read body input regardless of content-type — JSON test bodies AND
     * browser form-urlencoded both flow through this helper. Matches the
     * istrbuddy convention.
     *
     * @return array<string, string>
     */
    private function readInput(Request $request): array
    {
        $body = $request->body();
        $bodyArr = is_array($body) ? $body : [];

        $keys = ['email', 'password', 'password_confirm', 'display_name'];
        $out = [];
        foreach ($keys as $key) {
            $jsonVal = $bodyArr[$key] ?? null;
            $out[$key] = is_string($jsonVal) ? $jsonVal : $request->post($key);
        }
        return $out;
    }

    /**
     * Set the post-auth session keys in the correct order to defeat
     * session fixation (regenerate ID, then clear, then write new identity).
     *
     * @param list<string> $roles
     */
    private function establishSession(int $userId, string $email, array $roles): void
    {
        Session::regenerate();
        // Wipe any pre-existing keys before writing the new identity —
        // belt-and-braces against fixation pre-poisoning.
        $_SESSION = [];
        Session::set('user_id', $userId);
        Session::set('username', $email);
        Session::set('roles', $roles);
    }

    private function isLoggedIn(): bool
    {
        return Session::has('user_id');
    }
}
