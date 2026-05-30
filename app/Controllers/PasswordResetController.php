<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\EmailSendAttemptRepository;
use App\Auth\MishkaUserRepository;
use App\Auth\PasswordResetTokenRepository;
use App\Mail\Mailer;
use App\Mail\UrlBuilder;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Auth\PasswordHasher;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Csrf;
use Karhu\View\TwigAdapter;

/**
 * v0.5.0 — anonymous "forgot password" flow.
 *
 * Four routes:
 *   GET  /password-reset             — Request form (email input)
 *   POST /password-reset             — ALWAYS 200 + generic copy + 1500ms floor (B4)
 *                                       + dummy argon2id verify on miss path (H-4)
 *                                       + rate limit 5/10min/IP (H4)
 *   GET  /password-reset/{64-hex}    — Reset form (token in hidden field)
 *                                       + Referrer-Policy: no-referrer (H-5)
 *   POST /password-reset/{64-hex}    — Atomic single-use redeem (B6); updates hash
 *                                       + stamps revocation; invalidates other pending;
 *                                       redirects to /login (no auto-login)
 *
 * Threat model:
 *   - B1 (host-header injection in email link) — UrlBuilder reads only $_ENV['APP_URL'],
 *     boot-validated at public/index.php startup. Never reads $request->header('host').
 *   - B3 (timestamp drift) — all token TTL math in PHP gmdate (PasswordResetTokenRepository
 *     handles this).
 *   - B4 (timing enumeration) — always-200 + identical body for hit/miss + 1500ms floor.
 *     Round-4 H-4 also runs a throwaway argon2id verify on the miss path so SMTP-fail
 *     edge case (which can blow past 1500ms) still doesn't leak a hit signal.
 *   - B6 (single-use race) — atomic guarded UPDATE in the repo.
 *   - H-5 (Referer leak) — both GET routes set Referrer-Policy: no-referrer.
 */
final class PasswordResetController
{
    private const PASSWORD_MIN = 12;
    private const PASSWORD_MAX = 128;
    private const RATE_LIMIT_REQUESTS = 5;
    private const RATE_LIMIT_WINDOW_MIN = 10;
    /** sha256 hex token shape — matches the issue() return format. */
    private const TOKEN_REGEX = '/^[0-9a-f]{64}$/';

    public function __construct(
        private readonly PasswordResetTokenRepository $tokens,
        private readonly MishkaUserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly Mailer $mailer,
        private readonly UrlBuilder $urls,
        private readonly EmailSendAttemptRepository $attempts,
        private readonly TwigAdapter $view,
        private readonly NavContext $nav,
        /**
         * Floor for the always-200 hit/miss timing equalisation (B4 + H-4).
         * Configurable so tests can override to a tiny value (~50ms) without
         * destroying suite runtime. Production uses 1_500_000μs = 1.5s.
         */
        private readonly int $timingFloorMicros = 1_500_000,
        /**
         * Pre-computed argon2id dummy hash. Used on the miss path so a passive
         * observer can't distinguish "unknown email" from "known email" by
         * the absence of a PasswordHasher::verify call (H-4 defence in depth).
         * Tests pass an empty string to skip the verify (it'd just slow the
         * test suite without changing what's being asserted).
         */
        private readonly string $dummyHash = '',
    ) {}

    // ============================================================
    // /password-reset — anonymous email-input form
    // ============================================================

    #[Route('/password-reset', methods: ['GET'], name: 'password-reset.request')]
    public function showRequest(Request $request): Response
    {
        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('account/password_reset_request.twig', [
                'errors' => [],
                'old' => [],
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/password-reset', methods: ['POST'])]
    public function handleRequest(Request $request): Response
    {
        // Mark the start so we can sleep to the timing floor at the end.
        // hrtime is monotonic, immune to wall-clock adjustments — the right
        // primitive for measuring elapsed durations (microtime() is wrong).
        $startNs = hrtime(true);

        $email = strtolower(trim((string) $this->readField($request, 'email')));
        // If we can't resolve the client IP (test SAPI, proxy misconfig,
        // CLI invocation), default to a sentinel so the rate limiter still
        // groups attempts together. Better to occasionally over-limit than
        // open a bypass channel.
        $ip = $this->clientIp($request) ?? '0.0.0.0';

        // App-layer rate limit (H4). Stops abuse regardless of upstream WAF.
        $this->attempts->record('password_reset_request', $ip, null);
        $recentCount = $this->attempts->countRecentByIp(
            'password_reset_request',
            $ip,
            self::RATE_LIMIT_WINDOW_MIN,
        );

        // Always-200 + generic body — applies to over-limit too. We DO skip
        // the SMTP send + dummy-verify if over-limit because by that point
        // the user is already being throttled.
        if ($recentCount <= self::RATE_LIMIT_REQUESTS) {
            // Validate email shape — silently skip on malformed (no enumeration).
            $isShapeValid = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            $userId = $isShapeValid ? $this->users->findIdByEmail($email) : null;

            if ($userId !== null) {
                // Hit path: issue + email.
                $rawToken = $this->tokens->issue($userId);
                $user = $this->users->findById($userId);
                $displayName = $user['display_name'] ?? 'there';
                $this->mailer->sendPasswordReset(
                    $email,
                    $this->urls->absoluteUrl('/password-reset/' . $rawToken),
                    $displayName,
                );
            } else {
                // Miss path: throwaway argon2id verify so PasswordHasher CPU
                // cost lands here too (H-4 defence in depth). Skipped if no
                // dummy hash configured (tests pass '').
                if ($this->dummyHash !== '') {
                    $this->hasher->verify($email, $this->dummyHash);
                }
            }
        }

        // Always render the same generic "if-exists-we-sent-it" page (B4 body).
        $body = $this->view->render('account/password_reset_sent.twig',
            $this->nav->forCurrentUser());

        // Sleep to the floor (B4 + H-4 timing equalisation).
        $elapsedUs = (int) ((hrtime(true) - $startNs) / 1000);
        $remaining = $this->timingFloorMicros - $elapsedUs;
        if ($remaining > 0) {
            usleep($remaining);
        }

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($body);
    }

    // ============================================================
    // /password-reset/{token} — the link in the email
    // ============================================================

    #[Route('/password-reset/{token}', methods: ['GET'])]
    public function showForm(Request $request): Response
    {
        $token = (string) ($request->routeParams()['token'] ?? '');
        if (!preg_match(self::TOKEN_REGEX, $token)) {
            return $this->renderInvalid();
        }

        $row = $this->tokens->findByRawToken($token);
        if (!$this->isRedeemable($row)) {
            return $this->renderInvalid();
        }

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Referrer-Policy', 'no-referrer')   // H-5: don't leak raw token
            ->withBody($this->view->render('account/password_reset_form.twig', [
                'errors' => [],
                'token' => $token,
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/password-reset/{token}', methods: ['POST'])]
    public function handleSubmit(Request $request): Response
    {
        $token = (string) ($request->routeParams()['token'] ?? '');
        if (!preg_match(self::TOKEN_REGEX, $token)) {
            return $this->renderInvalid();
        }

        $row = $this->tokens->findByRawToken($token);
        if (!$this->isRedeemable($row)) {
            return $this->renderInvalid();
        }

        $new = (string) $this->readField($request, 'new_password');
        $confirm = (string) $this->readField($request, 'new_password_confirm');
        $errors = $this->validateNewPassword($new, $confirm);
        if ($errors !== []) {
            return (new Response(422))
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('Referrer-Policy', 'no-referrer')
                ->withBody($this->view->render('account/password_reset_form.twig', [
                    'errors' => $errors,
                    'token' => $token,
                ] + $this->nav->forCurrentUser()));
        }

        // B6: atomic single-use redemption — UPDATE matches only if pending
        // AND unexpired. Returns false if another request beat us to it.
        if (!$this->tokens->redeemAtomically($row['id'])) {
            return $this->renderInvalid();
        }

        // BL-2: pin $now for both updatePassword + the credential-change stamp.
        $now = gmdate('Y-m-d H:i:s');
        $newHash = $this->hasher->hash($new);
        $this->users->updatePassword($row['user_id'], $newHash, $now);

        // Nuke any racing pending tokens for this user (defence vs. parallel
        // reset link the user might still have in their inbox).
        $this->tokens->invalidatePendingForUser($row['user_id']);

        // M4: rotate CSRF token after a credential-change-equivalent event.
        Csrf::regenerate();

        // No auto-login — security best-practice forces re-auth. The flash
        // is set into a query-string param the login template can surface.
        return (new Response())->redirect('/login?reset=ok', 303);
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * @param array{id: int, user_id: int, expires_at: string, used_at: ?string}|null $row
     */
    private function isRedeemable(?array $row): bool
    {
        if ($row === null) {
            return false;
        }
        if ($row['used_at'] !== null) {
            return false;
        }
        // Expired? gmdate compare matches the storage format (B3).
        return $row['expires_at'] > gmdate('Y-m-d H:i:s');
    }

    private function renderInvalid(): Response
    {
        return (new Response(404))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withBody($this->view->render('account/password_reset_invalid.twig',
                $this->nav->forCurrentUser()));
    }

    /** @return list<string> */
    private function validateNewPassword(string $new, string $confirm): array
    {
        $errors = [];
        $len = strlen($new);
        if ($new === '') {
            $errors[] = 'New password is required.';
        } elseif ($len < self::PASSWORD_MIN) {
            $errors[] = 'New password must be at least ' . self::PASSWORD_MIN . ' characters.';
        } elseif ($len > self::PASSWORD_MAX) {
            $errors[] = 'New password must be at most ' . self::PASSWORD_MAX . ' characters.';
        }
        if ($new !== '' && $new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        }
        return $errors;
    }

    private function readField(Request $request, string $field): string
    {
        $body = $request->body();
        if (is_array($body) && isset($body[$field]) && is_string($body[$field])) {
            return $body[$field];
        }
        return $request->post($field);
    }

    /** Resolve client IP for rate-limit keying. */
    private function clientIp(Request $request): ?string
    {
        // X-Forwarded-For (when behind a reverse proxy) trumps REMOTE_ADDR.
        // The first value in XFF is the client; subsequent are proxies.
        // Request::header returns '' when missing (not null) — no is_string guard.
        $xff = $request->header('x-forwarded-for');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if ($first !== '') {
                return $first;
            }
        }
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $remote !== '' ? $remote : null;
    }
}
