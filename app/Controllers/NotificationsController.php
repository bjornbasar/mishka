<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Push\PushSubscriptionRepository;
use App\Push\UserNotificationPrefsRepository;
use App\Push\VapidConfig;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\Queue\QueueInterface;
use Karhu\View\TwigAdapter;

/**
 * v0.6.0 — /me/notifications + the push subscribe/revoke/test endpoints.
 *
 * The controller is the user-facing surface for Web Push. Backend repos do
 * the persistence; this just wires HTTP to them.
 *
 * Endpoints:
 *   GET  /me/notifications                        Render prefs + sub list
 *                                                  + expose VAPID public key
 *   POST /me/notifications                        Upsert prefs
 *   POST /me/push/subscribe                       JS-driven; register a sub
 *   POST /me/push/subscriptions/{id}/delete       Revoke a sub
 *   POST /me/push/test                            Enqueue a test push (rate-
 *                                                  limited to 1 per 10s)
 *
 * The JS subscribe POST is form-urlencoded (decision in the plan — three short
 * scalar fields, no need to JSON-parse) and carries X-CSRF-Token (read from the
 * `<meta>` in layout.twig).
 */
final class NotificationsController
{
    private const REMINDER_MIN = 0;
    private const REMINDER_MAX = 1440;
    /** Allowed quick-select values; arbitrary inputs are clamped to the range above. */
    private const TEST_PUSH_COOLDOWN_SECONDS = 10;

    public function __construct(
        private readonly PushSubscriptionRepository $subs,
        private readonly UserNotificationPrefsRepository $prefs,
        private readonly VapidConfig $vapid,
        private readonly QueueInterface $queue,
        private readonly TwigAdapter $view,
        private readonly NavContext $nav,
    ) {}

    #[Route('/me/notifications', methods: ['GET'], name: 'me.notifications')]
    public function show(Request $request): Response
    {
        $uid = $this->requireSessionUserId();
        if ($uid === null) {
            return $this->redirectToLogin();
        }

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('account/notifications.twig', [
                'errors' => [],
                'prefs' => $this->prefs->getFor($uid),
                'subscriptions' => $this->subs->listActiveForUser($uid),
                'vapid_public_key' => $this->vapid->publicKey,
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/me/notifications', methods: ['POST'])]
    public function update(Request $request): Response
    {
        $uid = $this->requireSessionUserId();
        if ($uid === null) {
            return $this->redirectToLogin();
        }

        // Clamp minutes to the schema CHECK range; out-of-range becomes 422.
        $minutesRaw = $this->str($request, 'event_reminder_minutes');
        $errors = [];
        if (!preg_match('/^\d+$/', $minutesRaw)) {
            $errors[] = 'Event reminder minutes must be a whole number.';
        } else {
            $minutes = (int) $minutesRaw;
            if ($minutes < self::REMINDER_MIN || $minutes > self::REMINDER_MAX) {
                $errors[] = 'Event reminder minutes must be between '
                    . self::REMINDER_MIN . ' and ' . self::REMINDER_MAX . '.';
            }
        }

        if ($errors !== []) {
            return (new Response(422))
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody($this->view->render('account/notifications.twig', [
                    'errors' => $errors,
                    'prefs' => $this->prefs->getFor($uid),
                    'subscriptions' => $this->subs->listActiveForUser($uid),
                    'vapid_public_key' => $this->vapid->publicKey,
                ] + $this->nav->forCurrentUser()));
        }

        // checkbox sends 'on' when checked, absent when unchecked
        $digest = $this->str($request, 'overdue_chore_digest') !== '';
        $this->prefs->setFor($uid, [
            'event_reminder_minutes' => (int) $minutesRaw,
            'overdue_chore_digest' => $digest,
        ]);

        Session::set('flash_success', 'Notification preferences saved.');
        return (new Response())->redirect('/me/notifications', 303);
    }

    #[Route('/me/push/subscribe', methods: ['POST'])]
    public function subscribe(Request $request): Response
    {
        $uid = $this->requireSessionUserId();
        if ($uid === null) {
            return $this->redirectToLogin();
        }

        $endpoint = trim($this->str($request, 'endpoint'));
        $p256dh = trim($this->str($request, 'p256dh'));
        $auth = trim($this->str($request, 'auth'));
        $userAgent = $request->header('user-agent');
        if ($userAgent === '') {
            $userAgent = null;
        }

        // H3 — basic shape validation; reject http:// + empty host outright.
        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return (new Response(422))
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withBody('endpoint, p256dh, auth are all required');
        }
        $parts = parse_url($endpoint);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            return (new Response(422))
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withBody('endpoint must be a valid https:// URL');
        }

        $this->subs->register($uid, $endpoint, $p256dh, $auth, $userAgent);

        return (new Response())
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withBody('subscribed');
    }

    #[Route('/me/push/subscriptions/{id}/delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $uid = $this->requireSessionUserId();
        if ($uid === null) {
            return $this->redirectToLogin();
        }

        $subId = (int) ($request->routeParams()['id'] ?? 0);
        try {
            $this->subs->revoke($uid, $subId);
        } catch (\RuntimeException $e) {
            // Foreign or missing subscription → 403; never reveal whether it
            // exists for someone else.
            return (new Response(403))
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withBody('Subscription not found or not yours.');
        }

        Session::set('flash_success', 'Notification subscription removed.');
        return (new Response())->redirect('/me/notifications', 303);
    }

    #[Route('/me/push/test', methods: ['POST'])]
    public function test(Request $request): Response
    {
        $uid = $this->requireSessionUserId();
        if ($uid === null) {
            return $this->redirectToLogin();
        }

        // H2 — 10-second session cooldown to stop accidental spam.
        $last = Session::get('last_test_push_at');
        $now = time();
        if (is_int($last) && ($now - $last) < self::TEST_PUSH_COOLDOWN_SECONDS) {
            Session::set('flash_error', 'Hold on — test pushes are rate-limited. Try again in a few seconds.');
            return (new Response())->redirect('/me/notifications', 303);
        }

        // H6 — flash if there's no active subscription to push to.
        if (count($this->subs->listActiveForUser($uid)) === 0) {
            Session::set('flash_error', 'Enable notifications on a device first, then send a test push.');
            return (new Response())->redirect('/me/notifications', 303);
        }

        Session::set('last_test_push_at', $now);
        $this->queue->push('SendPushNotification', [
            'user_id' => $uid,
            'title' => '🐻 Mishka push works',
            'body' => 'This is a test notification. Your Mishka pushes are wired up.',
            'url' => '/',
        ]);

        Session::set('flash_success', 'Test push enqueued. Should arrive within a few seconds.');
        return (new Response())->redirect('/me/notifications', 303);
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function requireSessionUserId(): ?int
    {
        $uid = Session::get('user_id');
        if (!is_int($uid) || $uid <= 0) {
            return null;
        }
        return $uid;
    }

    private function redirectToLogin(): Response
    {
        return (new Response())->redirect('/login', 302);
    }

    private function str(Request $request, string $key): string
    {
        $body = $request->body();
        if (is_array($body) && isset($body[$key]) && is_scalar($body[$key])) {
            return (string) $body[$key];
        }
        return $request->post($key);
    }
}
