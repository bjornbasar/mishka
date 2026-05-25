<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

final class CalendarControllerTest extends AppTestCase
{
    public function test_get_calendar_renders_month_grid_for_logged_in_member(): void
    {
        $this->signInAsHouseholdOwner();
        $response = $this->request('GET', '/calendar');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('calendar', strtolower($response->body()));
    }

    public function test_get_calendar_respects_ym_query_param(): void
    {
        $this->signInAsHouseholdOwner();
        $response = $this->request('GET', '/calendar?ym=2026-12');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('December', $response->body());
    }

    public function test_get_calendar_redirects_anon_to_login(): void
    {
        $response = $this->request('GET', '/calendar');
        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('location'));
    }

    public function test_get_calendar_redirects_logged_in_no_household_to_setup(): void
    {
        $uid = $this->createUserWithHash('a@example.com', self::testPassword());
        $this->loginAs($uid, 'a@example.com');
        // No active_household_id in session
        $response = $this->request('GET', '/calendar');
        self::assertSame(302, $response->status());
        self::assertSame('/household/setup', $response->header('location'));
    }

    public function test_get_agenda_renders_for_member(): void
    {
        $this->signInAsHouseholdOwner();
        $response = $this->request('GET', '/calendar/agenda?ym=2026-07');
        self::assertSame(200, $response->status());
    }

    public function test_get_new_event_form_renders(): void
    {
        $this->signInAsHouseholdOwner();
        $response = $this->request('GET', '/calendar/events/new');
        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="title"', $response->body());
        self::assertStringContainsString('name="starts_at_local"', $response->body());
    }

    public function test_post_event_creates_and_redirects(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();

        $response = $this->request('POST', '/calendar/events', [
            'title' => 'School pickup',
            'description' => 'Afternoon',
            'location' => 'School gate',
            'starts_at_local' => '2026-07-14T15:00',
            'ends_at_local' => '2026-07-14T15:30',
        ]);

        self::assertSame(303, $response->status());
        self::assertStringStartsWith('/calendar', $response->header('location'));

        // Verify the row landed in the household
        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM events WHERE household_id = :hid AND title = :title',
            ['hid' => $hid, 'title' => 'School pickup'],
        );
        self::assertSame(1, $count);
    }

    public function test_post_event_whitelist_filters_series_event_id_and_timezone(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();

        $this->request('POST', '/calendar/events', [
            'title' => 'Sneaky',
            'starts_at_local' => '2026-07-14T15:00',
            'ends_at_local' => '2026-07-14T15:30',
            // These should be silently dropped by the whitelist
            'series_event_id' => 99999,
            'timezone' => 'America/New_York',
            'created_by' => 99999,
        ]);

        $row = $this->db->fetchOne(
            'SELECT timezone, series_event_id, created_by FROM events WHERE household_id = :hid',
            ['hid' => $hid],
        );
        self::assertSame('Pacific/Auckland', $row['timezone']);  // household tz, not the supplied
        self::assertNull($row['series_event_id']);
        self::assertSame($uid, (int) $row['created_by']);  // session user, not the supplied
    }

    public function test_post_event_rejects_invalid_datetime(): void
    {
        $this->signInAsHouseholdOwner();
        $response = $this->request('POST', '/calendar/events', [
            'title' => 'X',
            'starts_at_local' => 'not-a-date',
            'ends_at_local' => 'also-not',
        ]);
        self::assertSame(422, $response->status());
    }

    public function test_update_event_optimistic_concurrency_returns_409_on_stale(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $eid = $this->createEvent($hid, $uid, ['title' => 'Original']);

        $response = $this->request('POST', "/calendar/events/{$eid}", [
            'title' => 'Updated',
            'starts_at_local' => '2026-07-14T15:00',
            'ends_at_local' => '2026-07-14T15:30',
            '_expected_updated_at' => '1999-01-01 00:00:00',  // stale
        ]);

        self::assertSame(409, $response->status());
        self::assertStringContainsString('refresh', strtolower($response->body()));
    }

    public function test_update_event_happy_path(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $eid = $this->createEvent($hid, $uid, ['title' => 'Original']);
        $current = $this->db->fetchOne('SELECT updated_at FROM events WHERE id = :id', ['id' => $eid]);

        $response = $this->request('POST', "/calendar/events/{$eid}", [
            'title' => 'Updated title',
            'starts_at_local' => '2026-07-14T15:00',
            'ends_at_local' => '2026-07-14T15:30',
            '_expected_updated_at' => $current['updated_at'],
        ]);

        self::assertSame(303, $response->status());
        $updated = $this->db->fetchOne('SELECT title FROM events WHERE id = :id', ['id' => $eid]);
        self::assertSame('Updated title', $updated['title']);
    }

    public function test_delete_event(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $eid = $this->createEvent($hid, $uid);

        $response = $this->request('POST', "/calendar/events/{$eid}/delete");
        self::assertSame(303, $response->status());

        $count = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM events WHERE id = :id', ['id' => $eid]);
        self::assertSame(0, $count);
    }

    public function test_non_member_cannot_create_event(): void
    {
        // Owner A creates a household; user B is not a member but somehow has an
        // active_household_id session pointing at that household.
        $ownerId = $this->createUserWithHash('owner@example.com', self::testPassword());
        $hid = $this->householdRepo->createForOwner('Test Den', $ownerId);

        $strangerId = $this->createUserWithHash('stranger@example.com', self::testPassword());
        $this->loginAs($strangerId, 'stranger@example.com');
        $this->activateHouseholdInSession($strangerId, $hid, 'member');  // fake the session

        $response = $this->request('POST', '/calendar/events', [
            'title' => 'sneaky',
            'starts_at_local' => '2026-07-14T15:00',
            'ends_at_local' => '2026-07-14T15:30',
        ]);

        // requireMember throws ForbiddenException; karhu's ExceptionHandler returns 403
        // (with redirectTo='/household/setup' because the stranger's session matches the
        // household they're not in — so it actually 302s for stale-session self-heal).
        self::assertContains($response->status(), [302, 403]);
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
