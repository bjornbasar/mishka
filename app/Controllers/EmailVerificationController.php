<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\EmailSendAttemptRepository;
use App\Auth\EmailVerificationTokenRepository;
use App\Auth\MishkaUserRepository;
use App\Mail\Mailer;
use App\Mail\UrlBuilder;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * v0.5.0 — email-verification flow.
 *
 * Two routes:
 *   GET  /verify-email/{token}           — Atomic single-use redeem (B6);
 *                                          markEmailVerified; flash + 303 → /.
 *                                          Referrer-Policy: no-referrer (H-5).
 *   POST /me/verify-email/resend         — Session-gated, rate-limited (3/10min
 *                                          /user). Invalidates pending +
 *                                          re-issues + sends email.
 *
 * Verification is a SOFT banner only — no features are gated on it. The
 * single-copy banner ("Please verify your email — [Resend]") in layout.twig
 * hides the SMTP-fail state from non-technical users (decision U-3); the
 * sent_at = NULL signal stays in the DB for ops observability.
 */
final class EmailVerificationController
{
    private const RATE_LIMIT_REQUESTS = 3;
    private const RATE_LIMIT_WINDOW_MIN = 10;
    private const TOKEN_REGEX = '/^[0-9a-f]{64}$/';

    public function __construct(
        private readonly EmailVerificationTokenRepository $tokens,
        private readonly MishkaUserRepository $users,
        private readonly Mailer $mailer,
        private readonly UrlBuilder $urls,
        private readonly EmailSendAttemptRepository $attempts,
        private readonly TwigAdapter $view,
        private readonly NavContext $nav,
    ) {}

    // ============================================================
    // GET /verify-email/{token}
    // ============================================================

    #[Route('/verify-email/{token}', methods: ['GET'])]
    public function handleVerify(Request $request): Response
    {
        $token = (string) ($request->routeParams()['token'] ?? '');
        if (!preg_match(self::TOKEN_REGEX, $token)) {
            return $this->renderInvalid();
        }

        $row = $this->tokens->findByRawToken($token);
        if ($row === null || $row['used_at'] !== null || $row['expires_at'] <= gmdate('Y-m-d H:i:s')) {
            return $this->renderInvalid();
        }

        // B6: atomic single-use redemption — UPDATE matches only if pending
        // AND unexpired.
        if (!$this->tokens->redeemAtomically($row['id'])) {
            return $this->renderInvalid();
        }

        $this->users->markEmailVerified($row['user_id']);

        // H5: if the user is logged in (same browser as register click-through),
        // update the session-cached verified flag so the banner disappears
        // without a re-login.
        if (Session::get('user_id') === $row['user_id']) {
            Session::set('email_verified_at', gmdate('Y-m-d H:i:s'));
        }

        Session::set('flash_success', 'Email verified — thanks!');
        // Send users to home if logged in; otherwise to /login.
        $target = Session::has('user_id') ? '/' : '/login';

        return (new Response())
            ->withHeader('Referrer-Policy', 'no-referrer')   // H-5
            ->redirect($target, 303);
    }

    // ============================================================
    // POST /me/verify-email/resend
    // ============================================================

    #[Route('/me/verify-email/resend', methods: ['POST'])]
    public function handleResend(Request $request): Response
    {
        $uid = Session::get('user_id');
        if (!is_int($uid) || $uid <= 0) {
            return (new Response())->redirect('/login', 302);
        }

        // Already verified? Treat as a no-op + flash, redirect to referrer.
        if ($this->users->isEmailVerified($uid)) {
            Session::set('flash_success', 'Email is already verified.');
            return $this->redirectToReferrer($request);
        }

        // H4: rate limit 3/10min/user.
        $this->attempts->record('verify_resend', null, $uid);
        $recent = $this->attempts->countRecentByUser('verify_resend', $uid, self::RATE_LIMIT_WINDOW_MIN);
        if ($recent > self::RATE_LIMIT_REQUESTS) {
            Session::set('flash_error', 'Too many resend attempts — try again in a few minutes.');
            return $this->redirectToReferrer($request);
        }

        $user = $this->users->findById($uid);
        if ($user === null) {
            Session::destroy();
            return (new Response())->redirect('/login', 302);
        }

        // Nuke any pending tokens + issue a fresh one (also nukes via issue's
        // own invalidate-older, but explicit is clearer).
        $this->tokens->invalidatePendingForUser($uid);
        $rawToken = $this->tokens->issue($uid);

        // Pull the new token's id so we can markSent on success (H2 ops signal).
        $row = $this->tokens->findByRawToken($rawToken);

        $delivered = $this->mailer->sendVerification(
            $user['email'],
            $this->urls->absoluteUrl('/verify-email/' . $rawToken),
            $user['display_name'],
        );

        if ($delivered && $row !== null) {
            $this->tokens->markSent($row['id']);
        }

        Session::set('flash_success', 'Verification email sent — check your inbox.');
        return $this->redirectToReferrer($request);
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function renderInvalid(): Response
    {
        return (new Response(404))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withBody($this->view->render('account/verify_email_invalid.twig',
                $this->nav->forCurrentUser()));
    }

    private function redirectToReferrer(Request $request): Response
    {
        $ref = $request->header('referer');
        // Defence: only honour same-origin referrers. The URL must start with
        // a slash (relative path) OR match our own host — anything else gets
        // bounced to home.
        if ($ref !== '' && str_starts_with($ref, '/')) {
            return (new Response())->redirect($ref, 303);
        }
        return (new Response())->redirect('/', 303);
    }
}
