<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

final class ExerciseLogControllerTest extends AppTestCase
{
    /** @return array{0: int, 1: int, 2: int, 3: int} [uid, hid, durationExerciseId, strengthExerciseId] */
    private function signedIn(): array
    {
        $uid = $this->createUserWithHash('bjorn@x', 'passw0rd!', 'Bjorn');
        $hid = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('HH', 'AAAAAA', 'Pacific/Auckland') RETURNING id",
        );
        $this->db->run(
            "INSERT INTO household_members (household_id, user_id, role) VALUES (:hid, :uid, 'owner')",
            ['hid' => $hid, 'uid' => $uid],
        );
        $dur = $this->exerciseRepo->create(null, ['name' => 'Running', 'type' => 'duration', 'met' => 9.8, 'source' => 'compendium'], null);
        $str = $this->exerciseRepo->create(null, ['name' => 'Squats', 'type' => 'strength', 'met' => 5.0, 'default_rom_m' => 0.5, 'source' => 'compendium'], null);
        $this->loginAs($uid, 'bjorn@x');
        $this->activateHouseholdInSession($uid, $hid, 'owner');
        return [$uid, $hid, $dur, $str];
    }

    public function test_get_form_renders(): void
    {
        $this->signedIn();
        $r = $this->request('GET', '/health/log/exercise');
        self::assertSame(200, $r->status());
        self::assertStringContainsString('data-exercise-search', $r->body());
    }

    public function test_form_carries_search_contract_attrs(): void
    {
        // Contract-drift test — v0.8.0 Plan-agent R2-#10 pattern.
        $this->signedIn();
        $body = $this->request('GET', '/health/log/exercise')->body();
        self::assertStringContainsString('data-exercise-search', $body);
        self::assertStringContainsString('data-exercise-search-input', $body);
        self::assertStringContainsString('data-exercise-search-results', $body);
        self::assertStringContainsString('data-branch-duration', $body);
        self::assertStringContainsString('data-branch-strength', $body);
    }

    public function test_search_endpoint_returns_json(): void
    {
        $this->signedIn();
        $r = $this->request('GET', '/health/log/exercise/search?q=run', headers: ['accept' => 'application/json']);
        self::assertSame(200, $r->status());
        self::assertStringContainsString('application/json', (string) $r->header('content-type'));
        self::assertSame('no-store', $r->header('cache-control'));
        $data = json_decode($r->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($data['results']);
        self::assertSame('Running', $data['results'][0]['name']);
        self::assertSame('duration', $data['results'][0]['type']);
    }

    public function test_post_duration_branch_creates_entry(): void
    {
        [$uid, $hid, $durId, ] = $this->signedIn();
        // Set weight so kcal computes.
        $this->weightLogRepo->create($uid, 70.0, '2026-07-13');
        $r = $this->request('POST', '/health/log/exercise', [
            'exercise_id' => (string) $durId,
            'minutes' => '30',
        ], headers: ['content-type' => 'application/x-www-form-urlencoded']);
        self::assertSame(303, $r->status());
        self::assertSame('/health', $r->header('location'));
        $row = $this->db->fetchOne('SELECT * FROM exercise_log WHERE user_id = :uid', ['uid' => $uid]);
        self::assertNotNull($row);
        self::assertSame('duration', $row['exercise_type_snapshot']);
        self::assertSame('Running', $row['exercise_name_snapshot']);
        self::assertEqualsWithDelta(30.0, (float) $row['minutes'], 0.01);
        // kcal computed: 9.8 × 3.5 × 70 / 200 × 30 = 360.15 → 360
        self::assertSame(360, (int) $row['kcal_snapshot']);
    }

    public function test_post_duration_without_weight_leaves_kcal_null(): void
    {
        [$uid, $hid, $durId, ] = $this->signedIn();
        // No weight_log entry.
        $this->request('POST', '/health/log/exercise', [
            'exercise_id' => (string) $durId,
            'minutes' => '30',
        ], headers: ['content-type' => 'application/x-www-form-urlencoded']);
        $row = $this->db->fetchOne('SELECT kcal_snapshot, met_minutes FROM exercise_log WHERE user_id = :uid', ['uid' => $uid]);
        self::assertNull($row['kcal_snapshot']);
        // met_minutes still populated (weight-independent).
        self::assertEqualsWithDelta(294.0, (float) $row['met_minutes'], 0.01);
    }

    public function test_post_strength_branch_creates_entry(): void
    {
        [$uid, $hid, , $strId] = $this->signedIn();
        $r = $this->request('POST', '/health/log/exercise', [
            'exercise_id' => (string) $strId,
            'sets' => '3',
            'reps' => '10',
            'load_kg' => '20',
        ], headers: ['content-type' => 'application/x-www-form-urlencoded']);
        self::assertSame(303, $r->status());
        $row = $this->db->fetchOne('SELECT * FROM exercise_log WHERE user_id = :uid', ['uid' => $uid]);
        self::assertNotNull($row);
        self::assertSame('strength', $row['exercise_type_snapshot']);
        self::assertSame(3, (int) $row['sets']);
        self::assertSame(10, (int) $row['reps']);
        self::assertNull($row['met_minutes']);
        // kcal via mechanical work: 0.011723 × 20 × 0.5 × 10 = 1.1723 → 1
        self::assertSame(1, (int) $row['kcal_snapshot']);
    }

    public function test_post_rejects_foreign_household_exercise(): void
    {
        [$uid, $hid, , ] = $this->signedIn();
        $otherHid = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('Other', 'BBBBBB', 'Pacific/Auckland') RETURNING id",
        );
        $foreignId = $this->exerciseRepo->create($otherHid, ['name' => 'Foreign', 'type' => 'duration', 'met' => 5.0, 'source' => 'custom'], null);
        $this->request('POST', '/health/log/exercise', [
            'exercise_id' => (string) $foreignId,
            'minutes' => '30',
        ], headers: ['content-type' => 'application/x-www-form-urlencoded']);
        self::assertSame(0, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM exercise_log WHERE user_id = :uid', ['uid' => $uid]));
    }

    public function test_delete_removes_own_entry(): void
    {
        [$uid, $hid, $durId, ] = $this->signedIn();
        $logId = $this->exerciseLogRepo->create(
            $hid, $uid, $durId,
            'duration', 'Running',
            minutes: 30.0, sets: null, reps: null, loadKg: null,
            metMinutes: 294.0, kcalSnapshot: 360, loggedOn: '2026-07-13',
        );
        $r = $this->request('POST', "/health/log/exercise/{$logId}/delete", [], headers: ['content-type' => 'application/x-www-form-urlencoded']);
        self::assertSame(303, $r->status());
        self::assertSame(0, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM exercise_log WHERE id = :id', ['id' => $logId]));
    }
}
