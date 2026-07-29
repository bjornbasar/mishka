<?php

declare(strict_types=1);

namespace App\Http;

use App\View\NavContext;
use Karhu\Http\ErrorHandler;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * v0.8.6 — mishka's branded implementation of the karhu ErrorHandler hook.
 *
 * Content-negotiates JSON vs HTML on Request::prefersJson() and further
 * branches HTML between a full layout.twig-extending template (signed-in)
 * and a stripped standalone template (anonymous). The anonymous branch
 * is deliberate: layout.twig emits `<meta name="csrf-token"
 * content="{{ csrf_token() }}">` which triggers Csrf::regenerate()
 * → $_SESSION[_csrf_token] write → session-file write per request.
 * On a bot-spam 404 storm that would churn /var/lib/mishka/sessions/
 * needlessly; the stripped template keeps anonymous 404s session-free.
 *
 * Called by Karhu\App on two paths (see karhu/src/App.php:handleNotFound):
 *   1. Unmatched-route: dispatch found no route, $error is null.
 *   2. Controller-throw: a matched controller threw NotFoundException,
 *      $error is the caught NFE instance. Message surfaces in the
 *      "Details" block on HTML (prod-safe — messages are non-sensitive)
 *      and in the JSON `message` field.
 *
 * If ANY code path in here throws (missing template, DB error in
 * NavContext, Twig config bug, etc.) karhu's App::resolveErrorHandler
 * outer try/catch falls back to DefaultErrorHandler so a 404 is ALWAYS
 * served — never a 500 to the user.
 */
final class MishkaErrorHandler implements ErrorHandler
{
    public function __construct(
        private readonly TwigAdapter $view,
        private readonly NavContext $nav,
    ) {
    }

    public function handle(Request $request, ?\Throwable $error, array $context): Response
    {
        $status = is_int($context['status'] ?? null) ? $context['status'] : 404;
        $message = $error !== null && $error->getMessage() !== '' ? $error->getMessage() : null;

        if ($request->prefersJson()) {
            return $this->jsonResponse($status, $message);
        }

        // Signed-in vs anonymous branch. Detecting via Session::get('user_id')
        // matches how NavContext::forCurrentUser decides its own anonymous
        // short-circuit at NavContext.php:44 — same source of truth.
        $userId = Session::get('user_id');
        $authenticated = is_int($userId) && $userId > 0;

        if ($authenticated) {
            return $this->htmlAuthenticated($status, $message);
        }
        return $this->htmlAnonymous($status, $message);
    }

    private function jsonResponse(int $status, ?string $message): Response
    {
        // Shape matches FoodLogController::jsonError at L225-230 (mishka's
        // internal JSON error convention). Do NOT switch to RFC 7807 here —
        // JSON consumers on /health/log/food/search and similar endpoints
        // key on this shape.
        return (new Response($status))
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody(json_encode([
                'status' => 'error',
                'code' => 'not_found',
                'message' => $message ?? 'Not Found',
            ], JSON_THROW_ON_ERROR));
    }

    private function htmlAuthenticated(int $status, ?string $message): Response
    {
        // consumeFlash: false — an accidental 404 render (user typo)
        // should NOT eat the flash message queued by the previous action
        // (e.g., "Password updated" after a change). See NavContext.php
        // docblock + round-2 S4 fold.
        $nav = $this->nav->forCurrentUser(consumeFlash: false);

        return (new Response($status))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->view->render('errors/404.twig', [
                'status' => $status,
                'error_message' => $message,
            ] + $nav));
    }

    private function htmlAnonymous(int $status, ?string $message): Response
    {
        // Standalone template — does NOT extend layout.twig. Zero session
        // writes on bot-spam 404s. No CSRF meta, no nav, no household
        // switcher. Just brand + friendly copy + "Back to home" link.
        return (new Response($status))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->view->render('errors/404_anon.twig', [
                'status' => $status,
                'error_message' => $message,
            ]));
    }
}
