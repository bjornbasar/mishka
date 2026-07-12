<?php

declare(strict_types=1);

namespace App\Tests\Tracker;

use App\Tracker\FoodRepository;
use App\Tracker\FoodServingRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

final class FoodRepositoryTest extends TestCase
{
    private Connection $db;
    private FoodRepository $foods;
    private FoodServingRepository $servings;
    private int $householdId;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->foods = new FoodRepository($this->db);
        $this->servings = new FoodServingRepository($this->db);
        // Seed a household to attach household-scoped foods to.
        $this->householdId = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('Test HH', 'ABCDEF', 'Pacific/Auckland') RETURNING id",
        );
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_create_writes_name_lc_via_mb_strtolower(): void
    {
        $id = $this->foods->create(null, ['name' => 'Kare-Kare', 'source' => 'philfct'], null);
        $row = $this->db->fetchOne('SELECT name, name_lc FROM foods WHERE id = :id', ['id' => $id]);
        self::assertSame('Kare-Kare', $row['name']);
        self::assertSame('kare-kare', $row['name_lc']);
    }

    public function test_create_rejects_empty_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->foods->create(null, ['name' => '   ', 'source' => 'custom'], null);
    }

    public function test_create_rejects_invalid_source(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->foods->create(null, ['name' => 'X', 'source' => 'wikipedia'], null);
    }

    public function test_update_recomputes_name_lc_when_name_changes(): void
    {
        $id = $this->foods->create(null, ['name' => 'Adobo', 'source' => 'philfct'], null);
        $this->foods->update($id, ['name' => 'Chicken Adobo']);
        $row = $this->db->fetchOne('SELECT name, name_lc FROM foods WHERE id = :id', ['id' => $id]);
        self::assertSame('Chicken Adobo', $row['name']);
        self::assertSame('chicken adobo', $row['name_lc']);
    }

    public function test_update_bumps_updated_at(): void
    {
        $id = $this->foods->create(null, ['name' => 'Adobo', 'source' => 'philfct'], null);
        $before = (string) $this->db->fetchScalar('SELECT updated_at FROM foods WHERE id = :id', ['id' => $id]);
        // Nudge time forward so CURRENT_TIMESTAMP visibly differs on both drivers.
        usleep(1_100_000);
        $this->foods->update($id, ['aliases' => 'adobong manok']);
        $after = (string) $this->db->fetchScalar('SELECT updated_at FROM foods WHERE id = :id', ['id' => $id]);
        self::assertNotSame($before, $after);
    }

    public function test_search_hits_case_insensitive_via_name_lc(): void
    {
        $foodId = $this->foods->create(null, ['name' => 'Kare-Kare', 'source' => 'philfct'], null);
        $this->servings->create($foodId, ['label' => '1 bowl', 'grams' => 350, 'kcal' => 480, 'is_default' => true]);

        $hits = $this->foods->search($this->householdId, 'KARE');
        self::assertCount(1, $hits);
        self::assertSame('Kare-Kare', $hits[0]['name']);
        self::assertSame(480, $hits[0]['default_serving_kcal']);
    }

    public function test_search_excludes_dishes_with_no_default_serving(): void
    {
        // Dish with no default serving — INNER JOIN drops it.
        $noDefaultId = $this->foods->create(null, ['name' => 'Adobo', 'source' => 'philfct'], null);
        $this->servings->create($noDefaultId, ['label' => '1 cup', 'grams' => 200, 'kcal' => 300, 'is_default' => false]);

        // Dish with default — should be returned.
        $ok = $this->foods->create(null, ['name' => 'Adobong sitaw', 'source' => 'philfct'], null);
        $this->servings->create($ok, ['label' => '1 cup', 'grams' => 180, 'kcal' => 220, 'is_default' => true]);

        $hits = $this->foods->search($this->householdId, 'adob');
        self::assertCount(1, $hits);
        self::assertSame('Adobong sitaw', $hits[0]['name']);
    }

    public function test_search_escapes_like_wildcards_in_input(): void
    {
        $this->foods->create(null, ['name' => 'Rice', 'source' => 'nzfcd'], null);
        // Attach a default serving so the INNER JOIN doesn't filter it out.
        $riceId = (int) $this->db->fetchScalar('SELECT id FROM foods WHERE name = :n', ['n' => 'Rice']);
        $this->servings->create($riceId, ['label' => '1 cup', 'grams' => 158, 'kcal' => 205, 'is_default' => true]);

        // A user searching for '%' MUST NOT get everything back.
        $hits = $this->foods->search($this->householdId, '%');
        self::assertCount(0, $hits, 'raw % should be escaped, matching literal % only (none in name_lc)');
    }

    public function test_search_returns_household_dishes_before_global_seed(): void
    {
        // Global seed
        $seedId = $this->foods->create(null, ['name' => 'Zzz Global', 'source' => 'philfct'], null);
        $this->servings->create($seedId, ['label' => '1 bowl', 'grams' => 300, 'kcal' => 400, 'is_default' => true]);
        // Household-scoped, alphabetically-later
        $ownId = $this->foods->create($this->householdId, ['name' => 'ZZZ HH Own', 'source' => 'custom'], null);
        $this->servings->create($ownId, ['label' => '1 bowl', 'grams' => 300, 'kcal' => 400, 'is_default' => true]);

        $hits = $this->foods->search($this->householdId, 'zzz');
        self::assertCount(2, $hits);
        // Household-first ordering: NOT NULL household_id ranks before NULL.
        self::assertSame($this->householdId, $this->db->fetchScalar('SELECT household_id FROM foods WHERE id = :id', ['id' => $hits[0]['id']]));
    }

    public function test_search_ignores_dishes_from_other_households(): void
    {
        $otherHhId = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('Other HH', 'ZZZZZZ', 'Pacific/Auckland') RETURNING id",
        );
        $otherFoodId = $this->foods->create($otherHhId, ['name' => 'Foreign Adobo', 'source' => 'custom'], null);
        $this->servings->create($otherFoodId, ['label' => '1 bowl', 'grams' => 300, 'kcal' => 400, 'is_default' => true]);

        $hits = $this->foods->search($this->householdId, 'foreign');
        self::assertCount(0, $hits);
    }

    public function test_seed_uniqueness_partial_index_blocks_duplicate_global_by_name_source(): void
    {
        $this->foods->create(null, ['name' => 'Adobo', 'source' => 'philfct'], null);

        $this->expectException(\PDOException::class);
        $this->foods->create(null, ['name' => 'Adobo', 'source' => 'philfct'], null);
    }

    public function test_partial_unique_index_permits_household_scoped_duplicate_of_seed_name(): void
    {
        // Global seed with name X
        $this->foods->create(null, ['name' => 'Adobo', 'source' => 'philfct'], null);
        // Same name, but household-scoped — partial index only covers household_id IS NULL rows.
        $ownId = $this->foods->create($this->householdId, ['name' => 'Adobo', 'source' => 'custom'], null);
        self::assertGreaterThan(0, $ownId);
    }

    public function test_list_for_household_returns_global_plus_own(): void
    {
        $this->foods->create(null, ['name' => 'Zeta Seed', 'source' => 'philfct'], null);
        $this->foods->create($this->householdId, ['name' => 'Alpha HH', 'source' => 'custom'], null);

        $rows = $this->foods->listForHousehold($this->householdId);
        $names = array_column($rows, 'name');
        self::assertContains('Zeta Seed', $names);
        self::assertContains('Alpha HH', $names);
    }

    public function test_delete_removes_the_food(): void
    {
        $id = $this->foods->create(null, ['name' => 'Temp', 'source' => 'custom'], null);
        self::assertNotNull($this->foods->findById($id));
        $this->foods->delete($id);
        self::assertNull($this->foods->findById($id));
    }
}
