<?php

declare(strict_types=1);

namespace App\Tests\Tracker;

use App\Tracker\FoodRepository;
use App\Tracker\FoodServingRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

final class FoodServingRepositoryTest extends TestCase
{
    private Connection $db;
    private FoodRepository $foods;
    private FoodServingRepository $servings;
    private int $foodId;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->foods = new FoodRepository($this->db);
        $this->servings = new FoodServingRepository($this->db);
        $this->foodId = $this->foods->create(null, ['name' => 'Test Dish', 'source' => 'custom'], null);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_create_with_is_default_true_makes_it_the_default(): void
    {
        $id = $this->servings->create($this->foodId, ['label' => '1 bowl', 'grams' => 350, 'kcal' => 480, 'is_default' => true]);
        $default = $this->servings->defaultForFood($this->foodId);
        self::assertNotNull($default);
        self::assertSame($id, $default['id']);
    }

    public function test_creating_second_default_demotes_the_first(): void
    {
        $first = $this->servings->create($this->foodId, ['label' => '1 bowl', 'grams' => 350, 'kcal' => 480, 'is_default' => true]);
        $second = $this->servings->create($this->foodId, ['label' => '1 cup', 'grams' => 200, 'kcal' => 275, 'is_default' => true]);
        $default = $this->servings->defaultForFood($this->foodId);
        self::assertSame($second, $default['id']);
        // First is demoted, not deleted.
        $firstRow = $this->servings->findById($first);
        self::assertFalse($firstRow['is_default']);
    }

    public function test_update_promoting_a_serving_demotes_the_current_default(): void
    {
        $a = $this->servings->create($this->foodId, ['label' => '1 bowl', 'grams' => 350, 'kcal' => 480, 'is_default' => true]);
        $b = $this->servings->create($this->foodId, ['label' => '1 cup', 'grams' => 200, 'kcal' => 275, 'is_default' => false]);

        $this->servings->update($b, ['is_default' => true]);

        $aRow = $this->servings->findById($a);
        $bRow = $this->servings->findById($b);
        self::assertFalse($aRow['is_default']);
        self::assertTrue($bRow['is_default']);
    }

    public function test_partial_unique_index_prevents_two_defaults(): void
    {
        $this->servings->create($this->foodId, ['label' => '1 bowl', 'grams' => 350, 'kcal' => 480, 'is_default' => true]);
        // Directly attempt a second default row bypassing the demote-then-promote path.
        $this->expectException(\PDOException::class);
        $this->db->run(
            "INSERT INTO food_servings (food_id, label, grams, kcal, is_default) VALUES (:fid, '1 cup', 200, 275, TRUE)",
            ['fid' => $this->foodId],
        );
    }

    public function test_list_for_food_orders_default_first(): void
    {
        $this->servings->create($this->foodId, ['label' => '1 cup', 'grams' => 200, 'kcal' => 275, 'is_default' => false]);
        $bowl = $this->servings->create($this->foodId, ['label' => '1 bowl', 'grams' => 350, 'kcal' => 480, 'is_default' => true]);

        $list = $this->servings->listForFood($this->foodId);
        self::assertCount(2, $list);
        self::assertSame($bowl, $list[0]['id']);
        self::assertTrue($list[0]['is_default']);
    }

    public function test_delete_cascades_when_food_deleted(): void
    {
        $sid = $this->servings->create($this->foodId, ['label' => '1 bowl', 'grams' => 350, 'kcal' => 480, 'is_default' => true]);
        $this->foods->delete($this->foodId);
        self::assertNull($this->servings->findById($sid));
    }

    public function test_create_rejects_zero_or_negative_grams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->servings->create($this->foodId, ['label' => '1 bowl', 'grams' => 0, 'kcal' => 480, 'is_default' => true]);
    }

    public function test_create_rejects_negative_kcal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->servings->create($this->foodId, ['label' => '1 bowl', 'grams' => 350, 'kcal' => -1, 'is_default' => true]);
    }
}
