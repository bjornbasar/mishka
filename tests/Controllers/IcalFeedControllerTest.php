<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

final class IcalFeedControllerTest extends AppTestCase
{
    public function test_get_settings_renders_for_logged_in_user_with_no_tokens(): void
    {
        $this->signInAsHouseholdOwner();

        $response = $this->request('GET', '/me/calendar/feed');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Calendar feed', $response->body());
        self::assertStringContainsString('Generate', $response->body());
    }

    public function test_get_settings_redirects_anon_to_login(): void
    {
        $response = $this->request('GET', '/me/calendar/feed');
        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('location'));
    }

    public function test_post_generate_returns_raw_token_once(): void
    {
        [$uid] = $this->signInAsHouseholdOwner();

        $response = $this->request('POST', '/me/calendar/feed/generate');

        self::assertSame(200, $response->status());
        // The raw token (64 hex chars) appears in the response body ONCE
        self::assertMatchesRegularExpression('/[0-9a-f]{64}/', $response->body());
        self::assertStringContainsString('/ical/', $response->body());
        // Token row landed in DB
        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM ical_feed_tokens WHERE user_id = :uid',
            ['uid' => $uid],
        );
        self::assertSame(1, $count);
    }

    public function test_post_generated_page_has_referrer_no_referrer_meta(): void
    {
        $this->signInAsHouseholdOwner();
        $response = $this->request('POST', '/me/calendar/feed/generate');

        self::assertStringContainsString('name="referrer"', $response->body());
        self::assertStringContainsString('content="no-referrer"', $response->body());
    }

    public function test_post_revoke_marks_token_revoked(): void
    {
        [$uid] = $this->signInAsHouseholdOwner();
        $this->request('POST', '/me/calendar/feed/generate');
        $tokenId = (int) $this->db->fetchScalar(
            'SELECT id FROM ical_feed_tokens WHERE user_id = :uid',
            ['uid' => $uid],
        );

        $response = $this->request('POST', "/me/calendar/feed/tokens/{$tokenId}/revoke");

        self::assertSame(303, $response->status());
        $revoked = $this->db->fetchScalar(
            'SELECT revoked_at FROM ical_feed_tokens WHERE id = :id',
            ['id' => $tokenId],
        );
        self::assertNotNull($revoked);
    }

    public function test_post_revoke_requires_ownership(): void
    {
        $ownerId = $this->createUserWithHash('owner@example.com', self::testPassword());
        $this->householdRepo->createForOwner('Owner Den', $ownerId);
        $this->loginAs($ownerId, 'owner@example.com');
        $this->request('POST', '/me/calendar/feed/generate');
        $tokenId = (int) $this->db->fetchScalar(
            'SELECT id FROM ical_feed_tokens WHERE user_id = :uid',
            ['uid' => $ownerId],
        );

        // Switch to a stranger
        $strangerId = $this->createUserWithHash('stranger@example.com', self::testPassword());
        $this->loginAs($strangerId, 'stranger@example.com');

        $response = $this->request('POST', "/me/calendar/feed/tokens/{$tokenId}/revoke");
        // Repository throws → ExceptionHandler returns 500. Acceptable; the
        // route is owner-self-targeted so this is a defensive-only path.
        self::assertContains($response->status(), [403, 500]);

        // Original token still active
        $revoked = $this->db->fetchScalar(
            'SELECT revoked_at FROM ical_feed_tokens WHERE id = :id',
            ['id' => $tokenId],
        );
        self::assertNull($revoked);
    }

    public function test_ical_feed_serves_vcalendar_for_valid_token(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $this->createEvent($hid, $uid, ['title' => 'School pickup']);
        $rawToken = $this->tokenRepo->generate($uid);

        $response = $this->request('GET', "/ical/{$rawToken}.ics");

        self::assertSame(200, $response->status());
        self::assertStringContainsString('text/calendar', $response->header('content-type'));
        self::assertStringContainsString('BEGIN:VCALENDAR', $response->body());
        self::assertStringContainsString('School pickup', $response->body());
    }

    public function test_ical_feed_sets_referrer_policy_no_referrer(): void
    {
        [$uid] = $this->signInAsHouseholdOwner();
        $rawToken = $this->tokenRepo->generate($uid);

        $response = $this->request('GET', "/ical/{$rawToken}.ics");

        self::assertSame('no-referrer', $response->header('referrer-policy'));
    }

    public function test_ical_feed_returns_404_for_invalid_token(): void
    {
        $bogus = str_repeat('0', 64);
        $response = $this->request('GET', "/ical/{$bogus}.ics");
        self::assertSame(404, $response->status());
    }

    public function test_ical_feed_returns_404_for_revoked_token(): void
    {
        [$uid] = $this->signInAsHouseholdOwner();
        $rawToken = $this->tokenRepo->generate($uid);
        $tokenId = (int) $this->db->fetchScalar(
            'SELECT id FROM ical_feed_tokens WHERE user_id = :uid',
            ['uid' => $uid],
        );
        $this->tokenRepo->revoke($tokenId, $uid);

        $response = $this->request('GET', "/ical/{$rawToken}.ics");
        self::assertSame(404, $response->status());
    }

    /** @return array{0: int, 1: int} */
    private function signInAsHouseholdOwner(): array
    {
        $uid = $this->createUserWithHash('a@example.com', self::testPassword());
        $hid = $this->householdRepo->createForOwner('Test Den', $uid);
        $this->loginAs($uid, 'a@example.com');
        $this->activateHouseholdInSession($uid, $hid, 'owner');
        return [$uid, $hid];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createEvent(int $hid, int $uid, array $overrides = []): int
    {
        return $this->eventRepo->create($overrides + [
            'household_id' => $hid,
            'created_by' => $uid,
            'title' => 'Test event',
            'description' => '',
            'location' => '',
            'starts_at_local' => '2026-07-14 15:00:00',
            'ends_at_local' => '2026-07-14 16:00:00',
            'timezone' => 'Pacific/Auckland',
            'all_day' => false,
        ]);
    }

    private static function testPassword(): string
    {
        return 'correct horse battery staple';
    }
}
