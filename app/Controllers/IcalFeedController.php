<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Calendar\IcalFeedBuilder;
use App\Calendar\IcalFeedTokenRepository;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * iCal feed: per-user signed subscription URL.
 *
 * Two surfaces:
 *   - Settings (`/me/calendar/feed*`): auth-gated; the user generates / revokes
 *     long-lived hex tokens. The Generate page is the ONLY place the raw token
 *     is ever surfaced — re-visiting the settings page just shows the metadata
 *     (created/last-used/scope).
 *   - Public feed (`/ical/{token}.ics`): UNAUTHENTICATED; hash-lookup; serves
 *     `text/calendar`. 404 for invalid or revoked tokens (no distinguishing
 *     signal — both look the same to a guesser).
 *
 * Token-leak defences (layered):
 *   - `Referrer-Policy: no-referrer` on the feed response so phone calendar
 *     apps don't leak it back in the Referer header.
 *   - `<meta name="referrer" content="no-referrer">` on the generated-token
 *     page (belt-and-braces; the user might click a webcal:// link there).
 *   - Caddy log-path redaction (documented in INFRASTRUCTURE.md).
 */
final class IcalFeedController
{
    public function __construct(
        private readonly IcalFeedTokenRepository $tokens,
        private readonly IcalFeedBuilder $builder,
        private readonly NavContext $nav,
        private readonly TwigAdapter $view,
    ) {}

    #[Route('/me/calendar/feed', methods: ['GET'], name: 'feed.settings')]
    public function showSettings(Request $request): Response
    {
        if (!Session::has('user_id')) {
            return (new Response())->redirect('/login', 302);
        }
        $userId = (int) Session::get('user_id');

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('feed/settings.twig', [
                'tokens' => $this->tokens->listActiveForUser($userId),
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/me/calendar/feed/generate', methods: ['POST'])]
    public function handleGenerate(Request $request): Response
    {
        if (!Session::has('user_id')) {
            return (new Response())->redirect('/login', 302);
        }
        $userId = (int) Session::get('user_id');

        $rawToken = $this->tokens->generate($userId);
        $feedUrl = $this->absoluteFeedUrl($request, $rawToken);

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withBody($this->view->render('feed/generated.twig', [
                'raw_token' => $rawToken,
                'feed_url' => $feedUrl,
                'webcal_url' => $this->webcalUrl($feedUrl),
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/me/calendar/feed/tokens/{id}/revoke', methods: ['POST'])]
    public function handleRevoke(Request $request): Response
    {
        if (!Session::has('user_id')) {
            return (new Response())->redirect('/login', 302);
        }
        $userId = (int) Session::get('user_id');
        $tokenId = (int) ($request->routeParams()['id'] ?? 0);

        // Repository enforces ownership and throws on stranger-revoke. Let the
        // ExceptionHandler turn that into a 500 for defensive-only paths.
        $this->tokens->revoke($tokenId, $userId);

        return (new Response())->redirect('/me/calendar/feed', 303);
    }

    #[Route('/ical/{token}.ics', methods: ['GET'], name: 'feed.public')]
    public function serveFeed(Request $request): Response
    {
        $rawToken = (string) ($request->routeParams()['token'] ?? '');
        // Shape-check before hashing — the regex is the cheapest filter and
        // means a bot guessing short paths can't even trigger a DB lookup.
        if (preg_match('/^[0-9a-f]{64}$/', $rawToken) !== 1) {
            return $this->notFound();
        }

        $row = $this->tokens->findByRawToken($rawToken);
        if ($row === null) {
            return $this->notFound();
        }

        $body = $this->builder->renderForUser($row['user_id'], $row['scope_household_id']);

        return (new Response())
            ->withHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cache-Control', 'private, max-age=300')
            ->withBody($body);
    }

    private function notFound(): Response
    {
        return (new Response(404))
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withBody('Not found');
    }

    private function absoluteFeedUrl(Request $request, string $rawToken): string
    {
        $scheme = $request->header('x-forwarded-proto');
        if ($scheme === '') {
            $scheme = ($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http';
        }
        $host = $request->header('host');
        if ($host === '') {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        return "{$scheme}://{$host}/ical/{$rawToken}.ics";
    }

    private function webcalUrl(string $httpUrl): string
    {
        // webcal:// is the universal "subscribe in calendar app" handler; iOS
        // and macOS Calendar auto-launch on click. Strip the scheme and prepend.
        return 'webcal://' . preg_replace('#^https?://#', '', $httpUrl);
    }
}
