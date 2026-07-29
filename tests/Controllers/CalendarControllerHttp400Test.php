<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

/**
 * v0.8.6 — semantic 400 coverage for CalendarController occurrence routes.
 *
 * L609 and L646 in CalendarController::resolveOccurrenceOrRespond were
 * historically returning 404 for two conditions where the event resource
 * EXISTS but the URL asks for an operation the resource state doesn't
 * support. That's textbook 400 Bad Request, not 404 Not Found. v0.8.6
 * flips both to `throw new \InvalidArgumentException(...)` which the
 * karhu ExceptionHandler maps to 400 at src/Error/ExceptionHandler.php:149.
 *
 * L609 previously had no test coverage (round-2 review S5 finding).
 * L646 is now covered here at 400; the counterpart 404 test at
 * CalendarControllerTest::test_get_occurrence_edit_with_non_matching_slug_returns_404
 * is retitled/asserts 400 in the same release.
 */
final class CalendarControllerHttp400Test extends AppTestCase
{
    /** @return array{0: int, 1: int} */
    private function signInAsHouseholdOwner(): array
    {
        $uid = $this->createUserWithHash('a@example.com', 'correct horse battery staple');
        $hid = $this->householdRepo->createForOwner('Test Den', $uid);
        $this->loginAs($uid, 'a@example.com');
        $this->activateHouseholdInSession($uid, $hid, 'owner');
        return [$uid, $hid];
    }

    private function createNonRecurringEvent(int $hid, int $uid): int
    {
        // No rrule set — series['rrule'] is NULL, hitting the L608-610
        // "Not a recurring event" branch of resolveOccurrenceOrRespond.
        return $this->eventRepo->create([
            'household_id' => $hid,
            'created_by' => $uid,
            'title' => 'One-off',
            'description' => '',
            'location' => '',
            'starts_at_local' => '2026-07-14 15:00:00',
            'ends_at_local' => '2026-07-14 16:00:00',
            'timezone' => 'Pacific/Auckland',
            'all_day' => false,
        ]);
    }

    public function test_occurrence_edit_on_non_recurring_event_returns_400(): void
    {
        // Event exists, but has no RRULE — URL claims to address one of
        // its occurrences, which is semantically incoherent. Bad Request.
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $eid = $this->createNonRecurringEvent($hid, $uid);

        $response = $this->request(
            'GET',
            "/calendar/events/{$eid}/occurrences/2026-07-14T15-00/edit",
        );

        self::assertSame(400, $response->status());
    }

    public function test_occurrence_post_on_non_recurring_event_returns_400(): void
    {
        // Same L608-610 branch reached via the POST handler.
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $eid = $this->createNonRecurringEvent($hid, $uid);

        $response = $this->request(
            'POST',
            "/calendar/events/{$eid}/occurrences/2026-07-14T15-00",
            [
                'title' => 'ignored',
                'description' => '',
                'location' => '',
                'starts_at_local' => '2026-07-14T15:00',
                'ends_at_local' => '2026-07-14T16:00',
            ],
        );

        self::assertSame(400, $response->status());
    }

    public function test_occurrence_edit_with_wellformed_but_non_matching_slug_returns_400(): void
    {
        // Recurring series exists, occurrence-key regex matches, event has
        // a valid rrule -- but the parsed occurrence doesn't correspond to
        // ANY occurrence of the series (no override either). URL claims an
        // occurrence that isn't part of the series. Semantic 400.
        [$uid, $hid] = $this->signInAsHouseholdOwner();

        // Weekly Tuesday recurring series anchored 2026-07-07 6pm.
        $eid = $this->eventRepo->create([
            'household_id' => $hid,
            'created_by' => $uid,
            'title' => 'Soccer',
            'description' => '',
            'location' => '',
            'starts_at_local' => '2026-07-07 18:00:00',
            'ends_at_local' => '2026-07-07 19:00:00',
            'timezone' => 'Pacific/Auckland',
            'all_day' => false,
        ]);
        $this->db->run("UPDATE events SET rrule = 'FREQ=WEEKLY;BYDAY=TU' WHERE id = :id", ['id' => $eid]);

        // 2099-01-01 is well past the series but the key regex passes and
        // the format-check passes; expander returns no match. L644-646.
        $response = $this->request(
            'GET',
            "/calendar/events/{$eid}/occurrences/2099-01-01T09-00/edit",
        );

        self::assertSame(400, $response->status());
    }
}
