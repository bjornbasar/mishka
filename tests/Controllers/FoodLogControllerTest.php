<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

final class FoodLogControllerTest extends AppTestCase
{
    /** @return array{0: int, 1: int, 2: int, 3: int} [uid, hid, foodId, servingId] */
    private function signedInWithGlobalSeed(): array
    {
        $uid = $this->createUserWithHash('bjorn@x', 'passw0rd!', 'Bjorn');
        $hid = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('HH', 'AAAAAA', 'Pacific/Auckland') RETURNING id",
        );
        $this->db->run(
            "INSERT INTO household_members (household_id, user_id, role) VALUES (:hid, :uid, 'owner')",
            ['hid' => $hid, 'uid' => $uid],
        );
        $foodId = $this->foodRepo->create(null, ['name' => 'Adobo', 'source' => 'philfct'], null);
        $servingId = $this->foodServingRepo->create($foodId, [
            'label' => '1 cup', 'grams' => 200, 'kcal' => 250, 'is_default' => true,
        ]);
        $this->loginAs($uid, 'bjorn@x');
        $this->activateHouseholdInSession($uid, $hid, 'owner');
        return [$uid, $hid, $foodId, $servingId];
    }

    public function test_get_form_renders_when_authed(): void
    {
        $this->signedInWithGlobalSeed();
        $response = $this->request('GET', '/health/log/food');
        self::assertSame(200, $response->status());
        self::assertStringContainsString('data-live-search', $response->body());
    }

    public function test_get_form_carries_live_search_data_attrs(): void
    {
        // Plan-agent finding R2-#10: contract test catches template drift.
        $this->signedInWithGlobalSeed();
        $body = $this->request('GET', '/health/log/food')->body();
        self::assertStringContainsString('data-live-search', $body);
        self::assertStringContainsString('data-live-search-input', $body);
        self::assertStringContainsString('data-live-search-results', $body);
        self::assertStringContainsString('data-search-url', $body);
    }

    public function test_search_endpoint_returns_json_with_no_store(): void
    {
        $this->signedInWithGlobalSeed();
        $response = $this->request('GET', '/health/log/food/search?q=adob', headers: ['accept' => 'application/json']);
        self::assertSame(200, $response->status());
        self::assertStringContainsString('application/json', (string) $response->header('content-type'));
        self::assertSame('no-store', $response->header('cache-control'));
        $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($data['results']);
        self::assertSame('Adobo', $data['results'][0]['name']);
        self::assertSame(250, $data['results'][0]['default_serving']['kcal']);
    }

    public function test_post_creates_log_entry_and_redirects(): void
    {
        [$uid, $hid, $foodId, $servingId] = $this->signedInWithGlobalSeed();
        $response = $this->request('POST', '/health/log/food', [
            'meal' => 'breakfast',
            'food_id' => (string) $foodId,
            'serving_id' => (string) $servingId,
            'qty' => '1',
        ], headers: ['content-type' => 'application/x-www-form-urlencoded']);
        self::assertSame(303, $response->status());
        self::assertSame('/health', $response->header('location'));
        // Verify the row landed.
        $count = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM food_log WHERE user_id = :uid', ['uid' => $uid]);
        self::assertSame(1, $count);
    }

    public function test_post_rejects_invalid_meal(): void
    {
        [$uid, $hid, $foodId, $servingId] = $this->signedInWithGlobalSeed();
        $this->request('POST', '/health/log/food', [
            'meal' => 'elevenses',
            'food_id' => (string) $foodId,
            'serving_id' => (string) $servingId,
            'qty' => '1',
        ], headers: ['content-type' => 'application/x-www-form-urlencoded']);
        self::assertSame(0, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM food_log WHERE user_id = :uid', ['uid' => $uid]));
    }

    public function test_post_rejects_foreign_household_food(): void
    {
        [$uid, $hid, ] = $this->signedInWithGlobalSeed();
        // Create a food belonging to a different household.
        $otherHid = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('Other', 'BBBBBB', 'Pacific/Auckland') RETURNING id",
        );
        $foreignFoodId = $this->foodRepo->create($otherHid, ['name' => 'Foreign Dish', 'source' => 'custom'], null);
        $foreignServingId = $this->foodServingRepo->create($foreignFoodId, [
            'label' => '1 bowl', 'grams' => 300, 'kcal' => 400, 'is_default' => true,
        ]);

        $this->request('POST', '/health/log/food', [
            'meal' => 'breakfast',
            'food_id' => (string) $foreignFoodId,
            'serving_id' => (string) $foreignServingId,
            'qty' => '1',
        ], headers: ['content-type' => 'application/x-www-form-urlencoded']);

        self::assertSame(0, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM food_log WHERE user_id = :uid', ['uid' => $uid]));
    }

    public function test_delete_removes_own_log_entry(): void
    {
        [$uid, $hid, $foodId, $servingId] = $this->signedInWithGlobalSeed();
        $logId = $this->foodLogRepo->create($hid, $uid, $foodId, $servingId, 1.0, 'breakfast', '2026-07-12', 250);
        $response = $this->request('POST', "/health/log/food/{$logId}/delete", [], headers: ['content-type' => 'application/x-www-form-urlencoded']);
        self::assertSame(303, $response->status());
        self::assertSame(0, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM food_log WHERE id = :id', ['id' => $logId]));
    }
}
