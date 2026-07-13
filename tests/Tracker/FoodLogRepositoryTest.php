<?php

declare(strict_types=1);

namespace App\Tests\Tracker;

use App\Tracker\FoodLogRepository;
use App\Tracker\FoodRepository;
use App\Tracker\FoodServingRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

final class FoodLogRepositoryTest extends TestCase
{
    private Connection $db;
    private FoodRepository $foods;
    private FoodServingRepository $servings;
    private FoodLogRepository $log;
    private int $hh;
    private int $user;
    private int $foodId;
    private int $servingId;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->foods = new FoodRepository($this->db);
        $this->servings = new FoodServingRepository($this->db);
        $this->log = new FoodLogRepository($this->db);

        $this->hh = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('HH', 'AAAAAA', 'Pacific/Auckland') RETURNING id",
        );
        $this->user = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES ('u@x', 'x', 'User') RETURNING id",
        );
        $this->foodId = $this->foods->create(null, ['name' => 'Adobo', 'source' => 'philfct'], null);
        $this->servingId = $this->servings->create($this->foodId, ['label' => '1 bowl', 'grams' => 350, 'kcal' => 480, 'is_default' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_create_returns_new_id(): void
    {
        $id = $this->log->create($this->hh, $this->user, $this->foodId, $this->servingId, 1.0, 'breakfast', '2026-07-12', 480);
        self::assertGreaterThan(0, $id);
    }

    public function test_create_rejects_invalid_meal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->log->create($this->hh, $this->user, $this->foodId, $this->servingId, 1.0, 'elevenses', '2026-07-12', 480);
    }

    public function test_create_rejects_non_positive_qty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->log->create($this->hh, $this->user, $this->foodId, $this->servingId, 0.0, 'breakfast', '2026-07-12', 0);
    }

    public function test_list_for_user_day_returns_meal_ordered_entries(): void
    {
        $this->log->create($this->hh, $this->user, $this->foodId, $this->servingId, 1.0, 'dinner', '2026-07-12', 480);
        $this->log->create($this->hh, $this->user, $this->foodId, $this->servingId, 1.0, 'breakfast', '2026-07-12', 480);
        $this->log->create($this->hh, $this->user, $this->foodId, $this->servingId, 1.0, 'lunch', '2026-07-12', 480);

        $entries = $this->log->listForUserDay($this->user, $this->hh, '2026-07-12');
        self::assertCount(3, $entries);
        self::assertSame('breakfast', $entries[0]['meal']);
        self::assertSame('lunch', $entries[1]['meal']);
        self::assertSame('dinner', $entries[2]['meal']);
    }

    public function test_list_survives_food_deletion_via_left_join(): void
    {
        $this->log->create($this->hh, $this->user, $this->foodId, $this->servingId, 1.0, 'breakfast', '2026-07-12', 480);
        $this->foods->delete($this->foodId);  // CASCADES the serving, SET NULL on log FKs

        $entries = $this->log->listForUserDay($this->user, $this->hh, '2026-07-12');
        self::assertCount(1, $entries);
        self::assertSame('(deleted dish)', $entries[0]['food_name']);
        self::assertNull($entries[0]['food_id']);
        self::assertSame(480, $entries[0]['kcal_snapshot'], 'kcal_snapshot survives food deletion');
    }

    public function test_list_filters_by_household_and_user(): void
    {
        $otherHh = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('Other', 'BBBBBB', 'Pacific/Auckland') RETURNING id",
        );
        $this->log->create($this->hh, $this->user, $this->foodId, $this->servingId, 1.0, 'breakfast', '2026-07-12', 480);
        $this->log->create($otherHh, $this->user, $this->foodId, $this->servingId, 1.0, 'breakfast', '2026-07-12', 480);

        $entries = $this->log->listForUserDay($this->user, $this->hh, '2026-07-12');
        self::assertCount(1, $entries);
    }

    public function test_delete_owned_by_returns_1_when_owner_matches(): void
    {
        $id = $this->log->create($this->hh, $this->user, $this->foodId, $this->servingId, 1.0, 'breakfast', '2026-07-12', 480);
        self::assertSame(1, $this->log->deleteOwnedById($id, $this->user));
        self::assertCount(0, $this->log->listForUserDay($this->user, $this->hh, '2026-07-12'));
    }

    public function test_delete_owned_by_returns_0_when_owner_mismatches(): void
    {
        $otherUser = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES ('o@x', 'x', 'Other') RETURNING id",
        );
        $id = $this->log->create($this->hh, $this->user, $this->foodId, $this->servingId, 1.0, 'breakfast', '2026-07-12', 480);
        self::assertSame(0, $this->log->deleteOwnedById($id, $otherUser));
        self::assertCount(1, $this->log->listForUserDay($this->user, $this->hh, '2026-07-12'));
    }

    public function test_intake_kcal_for_user_day_sums_own_entries(): void
    {
        // v0.8.2 per-user aggregation for the Today energy-balance widget.
        $this->log->create($this->hh, $this->user, $this->foodId, $this->servingId, 1.0, 'breakfast', '2026-07-14', 400);
        $this->log->create($this->hh, $this->user, $this->foodId, $this->servingId, 1.0, 'lunch', '2026-07-14', 600);
        // Also a different-day entry that should NOT count.
        $this->log->create($this->hh, $this->user, $this->foodId, $this->servingId, 1.0, 'dinner', '2026-07-13', 700);

        self::assertSame(1000, $this->log->intakeKcalForUserDay($this->user, $this->hh, '2026-07-14'));
    }

    public function test_intake_kcal_scopes_to_user_and_household(): void
    {
        $otherUser = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES ('other@x', 'x', 'Other') RETURNING id",
        );
        $otherHh = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('Other', 'CCCCCC', 'Pacific/Auckland') RETURNING id",
        );
        // Same-user, different household — should NOT count.
        $this->log->create($otherHh, $this->user, $this->foodId, $this->servingId, 1.0, 'breakfast', '2026-07-14', 999);
        // Different user, same household — should NOT count.
        $this->log->create($this->hh, $otherUser, $this->foodId, $this->servingId, 1.0, 'breakfast', '2026-07-14', 888);
        // Same-user, same household — SHOULD count.
        $this->log->create($this->hh, $this->user, $this->foodId, $this->servingId, 1.0, 'breakfast', '2026-07-14', 100);

        self::assertSame(100, $this->log->intakeKcalForUserDay($this->user, $this->hh, '2026-07-14'));
    }

    public function test_daily_totals_for_household_aggregates_per_user(): void
    {
        $userB = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES ('b@x', 'x', 'B') RETURNING id",
        );
        $this->log->create($this->hh, $this->user, $this->foodId, $this->servingId, 1.0, 'breakfast', '2026-07-12', 400);
        $this->log->create($this->hh, $this->user, $this->foodId, $this->servingId, 1.0, 'lunch', '2026-07-12', 600);
        $this->log->create($this->hh, $userB, $this->foodId, $this->servingId, 1.0, 'dinner', '2026-07-12', 500);

        $totals = $this->log->dailyTotalsForHousehold($this->hh, '2026-07-12');
        self::assertSame(1000, $totals[$this->user]['total_kcal']);
        self::assertSame(2, $totals[$this->user]['entries']);
        self::assertSame(500, $totals[$userB]['total_kcal']);
    }
}
