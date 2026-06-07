<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

/**
 * v0.6.0 — /me/notifications + push subscribe/revoke/test flow.
 */
final class NotificationsControllerTest extends AppTestCase
{
    public function test_get_renders_form_with_vapid_public_key_data_attr(): void
    {
        $uid = $this->createUserWithHash('me@example.com', 'pw-correct-horse-staple');
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('GET', '/me/notifications');

        self::assertSame(200, $response->status());
        // The wrapper carries the VAPID public key so push-subscribe.js can
        // read it without a second fetch.
        self::assertStringContainsString('data-vapid-public-key="BHkj3Stq', $response->body());
        self::assertStringContainsString('Event reminder', $response->body());
        self::assertStringContainsString('Daily 7:30', $response->body());
        // v0.6.6 — two new creation-time push category checkboxes.
        self::assertStringContainsString('assigns me a new chore', $response->body());
        self::assertStringContainsString('new event is added', $response->body());
    }

    public function test_get_redirects_anonymous_to_login(): void
    {
        $response = $this->request('GET', '/me/notifications');
        self::assertSame(302, $response->status());
        self::assertStringContainsString('/login', (string) $response->header('location'));
    }

    public function test_post_prefs_updates_when_valid(): void
    {
        $uid = $this->createUserWithHash('me@example.com', 'pw-correct-horse-staple');
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/notifications', [
            'event_reminder_minutes' => '30',
            'overdue_chore_digest' => 'on',
            'new_chore_assigned_enabled' => 'on',
            'new_event_enabled' => 'on',
        ]);

        self::assertSame(303, $response->status());
        $stored = $this->notifyPrefsRepo->getFor($uid);
        self::assertSame(30, $stored['event_reminder_minutes']);
        self::assertTrue($stored['overdue_chore_digest']);
        self::assertTrue($stored['new_chore_assigned_enabled']);
        self::assertTrue($stored['new_event_enabled']);
    }

    public function test_post_prefs_unchecked_checkbox_stores_false(): void
    {
        // Browsers omit unchecked checkboxes from the POST body, so absent
        // checkbox keys mean "off." v0.6.6 added two new checkboxes; all four
        // boolean prefs follow the same convention.
        $uid = $this->createUserWithHash('me@example.com', 'pw-correct-horse-staple');
        $this->loginAs($uid, 'me@example.com');

        $this->request('POST', '/me/notifications', [
            'event_reminder_minutes' => '15',
            // no overdue_chore_digest, new_chore_assigned_enabled, new_event_enabled
        ]);

        $stored = $this->notifyPrefsRepo->getFor($uid);
        self::assertFalse($stored['overdue_chore_digest']);
        self::assertFalse($stored['new_chore_assigned_enabled']);
        self::assertFalse($stored['new_event_enabled']);
    }

    public function test_get_form_reflects_persisted_new_v066_prefs(): void
    {
        // v0.6.6 — pre-set mixed state and assert the form rendering reflects it.
        $uid = $this->createUserWithHash('me@example.com', 'pw-correct-horse-staple');
        $this->loginAs($uid, 'me@example.com');
        $this->notifyPrefsRepo->setFor($uid, [
            'new_chore_assigned_enabled' => true,
            'new_event_enabled' => false,
        ]);

        $response = $this->request('GET', '/me/notifications');
        $body = $response->body();

        self::assertSame(200, $response->status());
        // Checked = the substring `name="new_chore_assigned_enabled" checked` appears.
        self::assertStringContainsString(
            '<input type="checkbox" name="new_chore_assigned_enabled" checked',
            $body,
        );
        // Unchecked = the same name appears but WITHOUT the `checked` attr.
        self::assertStringContainsString(
            '<input type="checkbox" name="new_event_enabled" >',
            $body,
        );
    }

    public function test_post_prefs_rejects_out_of_range_minutes_with_422(): void
    {
        $uid = $this->createUserWithHash('me@example.com', 'pw-correct-horse-staple');
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/notifications', [
            'event_reminder_minutes' => '99999',
            'overdue_chore_digest' => 'on',
        ]);

        self::assertSame(422, $response->status());
    }

    public function test_post_prefs_rejects_non_numeric_minutes(): void
    {
        $uid = $this->createUserWithHash('me@example.com', 'pw-correct-horse-staple');
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/notifications', [
            'event_reminder_minutes' => 'thirty',
            'overdue_chore_digest' => 'on',
        ]);

        self::assertSame(422, $response->status());
    }

    public function test_subscribe_registers_subscription(): void
    {
        $uid = $this->createUserWithHash('me@example.com', 'pw-correct-horse-staple');
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/push/subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
            'p256dh' => 'pk-base64url',
            'auth' => 'auth-base64url',
        ]);

        self::assertSame(200, $response->status());
        self::assertCount(1, $this->pushSubRepo->listActiveForUser($uid));
    }

    public function test_subscribe_re_register_wakes_a_revoked_row(): void
    {
        $uid = $this->createUserWithHash('me@example.com', 'pw-correct-horse-staple');
        $this->loginAs($uid, 'me@example.com');

        $first = $this->pushSubRepo->register($uid, 'https://fcm.example/x', 'pk', 'auth', null);
        $this->pushSubRepo->revoke($uid, $first);

        $response = $this->request('POST', '/me/push/subscribe', [
            'endpoint' => 'https://fcm.example/x',
            'p256dh' => 'pk-new',
            'auth' => 'auth-new',
        ]);

        self::assertSame(200, $response->status());
        $active = $this->pushSubRepo->listActiveForUser($uid);
        self::assertCount(1, $active);
        self::assertSame('pk-new', $active[0]['p256dh']);
    }

    public function test_subscribe_rejects_http_endpoint(): void
    {
        $uid = $this->createUserWithHash('me@example.com', 'pw-correct-horse-staple');
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/push/subscribe', [
            'endpoint' => 'http://insecure.example/foo',
            'p256dh' => 'pk',
            'auth' => 'auth',
        ]);

        self::assertSame(422, $response->status());
    }

    public function test_subscribe_rejects_missing_fields(): void
    {
        $uid = $this->createUserWithHash('me@example.com', 'pw-correct-horse-staple');
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/push/subscribe', [
            'endpoint' => 'https://fcm.example/x',
            // no p256dh / auth
        ]);

        self::assertSame(422, $response->status());
    }

    public function test_delete_revokes_when_owned(): void
    {
        $uid = $this->createUserWithHash('me@example.com', 'pw-correct-horse-staple');
        $subId = $this->pushSubRepo->register($uid, 'https://fcm.example/a', 'pk', 'auth', null);
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', "/me/push/subscriptions/{$subId}/delete");

        self::assertSame(303, $response->status());
        self::assertCount(0, $this->pushSubRepo->listActiveForUser($uid));
    }

    public function test_delete_foreign_subscription_returns_403(): void
    {
        $owner = $this->createUserWithHash('owner@example.com', 'pw-correct-horse-staple');
        $subId = $this->pushSubRepo->register($owner, 'https://fcm.example/a', 'pk', 'auth', null);

        $stranger = $this->createUserWithHash('stranger@example.com', 'pw-correct-horse-staple');
        $this->loginAs($stranger, 'stranger@example.com');

        $response = $this->request('POST', "/me/push/subscriptions/{$subId}/delete");

        self::assertSame(403, $response->status());
        // Subscription must still be active.
        self::assertCount(1, $this->pushSubRepo->listActiveForUser($owner));
    }

    public function test_test_push_enqueues_a_job_when_subscription_exists(): void
    {
        $uid = $this->createUserWithHash('me@example.com', 'pw-correct-horse-staple');
        $this->pushSubRepo->register($uid, 'https://fcm.example/a', 'pk', 'auth', null);
        $this->loginAs($uid, 'me@example.com');

        $this->request('POST', '/me/push/test');

        $job = $this->queue->pop();
        self::assertNotNull($job);
        self::assertSame('SendPushNotification', $job['job']);
        self::assertSame($uid, $job['data']['user_id']);
        self::assertStringContainsString('Mishka push works', $job['data']['title']);
    }

    public function test_test_push_with_no_subscriptions_flashes_and_skips_enqueue(): void
    {
        $uid = $this->createUserWithHash('me@example.com', 'pw-correct-horse-staple');
        $this->loginAs($uid, 'me@example.com');
        // No subs registered.

        $this->request('POST', '/me/push/test');

        $job = $this->queue->pop();
        self::assertNull($job, 'No job should be enqueued without an active subscription.');
        self::assertStringContainsString('Enable notifications', (string) ($_SESSION['flash_error'] ?? ''));
    }

    public function test_test_push_rate_limited_within_10s(): void
    {
        $uid = $this->createUserWithHash('me@example.com', 'pw-correct-horse-staple');
        $this->pushSubRepo->register($uid, 'https://fcm.example/a', 'pk', 'auth', null);
        $this->loginAs($uid, 'me@example.com');

        // First test push: enqueued.
        $this->request('POST', '/me/push/test');
        $first = $this->queue->pop();
        self::assertNotNull($first);

        // Second test push immediately after: rate-limited, NOT enqueued.
        $this->request('POST', '/me/push/test');
        $second = $this->queue->pop();
        self::assertNull($second);
    }
}
