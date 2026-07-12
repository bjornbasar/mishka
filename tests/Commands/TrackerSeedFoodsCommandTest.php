<?php

declare(strict_types=1);

namespace App\Tests\Commands;

use App\Commands\TrackerSeedFoodsCommand;
use App\Tracker\FoodServingRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

final class TrackerSeedFoodsCommandTest extends TestCase
{
    private Connection $db;
    private TrackerSeedFoodsCommand $cmd;
    private string $fixturePath;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->cmd = new TrackerSeedFoodsCommand(
            $this->db,
            new FoodServingRepository($this->db),
        );
        $this->fixturePath = sys_get_temp_dir() . '/mishka-seed-test-' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (is_file($this->fixturePath)) {
            @unlink($this->fixturePath);
        }
    }

    private function writeFixture(array $data): void
    {
        file_put_contents($this->fixturePath, json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function test_first_run_seeds_all_dishes_and_servings(): void
    {
        $this->writeFixture([
            'version' => 1,
            'foods' => [
                [
                    'name' => 'Adobo',
                    'cuisine_tag' => 'filipino',
                    'source' => 'philfct',
                    'servings' => [
                        ['label' => '1 cup', 'grams' => 200, 'kcal' => 250, 'is_default' => true],
                        ['label' => '1 bowl', 'grams' => 320, 'kcal' => 400, 'is_default' => false],
                    ],
                ],
                [
                    'name' => 'Kūmara',
                    'cuisine_tag' => 'nz',
                    'source' => 'nzfcd',
                    'servings' => [
                        ['label' => '100 g', 'grams' => 100, 'kcal' => 90, 'is_default' => true],
                    ],
                ],
            ],
        ]);

        $exit = $this->cmd->handle(['file' => $this->fixturePath]);

        self::assertSame(0, $exit);
        $foodCount = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM foods WHERE household_id IS NULL');
        self::assertSame(2, $foodCount);
        $servingCount = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM food_servings');
        self::assertSame(3, $servingCount);
    }

    public function test_second_run_is_idempotent(): void
    {
        $this->writeFixture([
            'version' => 1,
            'foods' => [
                ['name' => 'Adobo', 'source' => 'philfct', 'servings' => [
                    ['label' => '1 cup', 'grams' => 200, 'kcal' => 250, 'is_default' => true],
                ]],
            ],
        ]);

        self::assertSame(0, $this->cmd->handle(['file' => $this->fixturePath]));
        self::assertSame(0, $this->cmd->handle(['file' => $this->fixturePath]));

        self::assertSame(1, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM foods WHERE name = :n', ['n' => 'Adobo']));
        // Servings are also not re-inserted on the second run (food skipped).
        self::assertSame(1, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM food_servings'));
    }

    public function test_missing_file_returns_1(): void
    {
        self::assertSame(1, $this->cmd->handle(['file' => '/nonexistent-path.json']));
    }

    public function test_malformed_json_returns_1(): void
    {
        file_put_contents($this->fixturePath, '{ this is not valid json');
        self::assertSame(1, $this->cmd->handle(['file' => $this->fixturePath]));
    }

    public function test_unknown_schema_version_returns_1(): void
    {
        $this->writeFixture(['version' => 99, 'foods' => []]);
        self::assertSame(1, $this->cmd->handle(['file' => $this->fixturePath]));
    }

    public function test_empty_foods_array_returns_0_with_zero_counts(): void
    {
        $this->writeFixture(['version' => 1, 'foods' => []]);
        self::assertSame(0, $this->cmd->handle(['file' => $this->fixturePath]));
        self::assertSame(0, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM foods'));
    }
}
