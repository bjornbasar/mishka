<?php

declare(strict_types=1);

namespace App\Tests\Tracker;

use App\Tracker\ExerciseRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

final class ExerciseRepositoryTest extends TestCase
{
    private Connection $db;
    private ExerciseRepository $exercises;
    private int $householdId;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->exercises = new ExerciseRepository($this->db);
        $this->householdId = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('HH', 'AAAAAA', 'Pacific/Auckland') RETURNING id",
        );
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_create_writes_name_lc(): void
    {
        $id = $this->exercises->create(null, ['name' => 'Running (Moderate)', 'type' => 'duration', 'met' => 9.8, 'source' => 'compendium'], null);
        $row = $this->db->fetchOne('SELECT name, name_lc FROM exercises WHERE id = :id', ['id' => $id]);
        self::assertSame('Running (Moderate)', $row['name']);
        self::assertSame('running (moderate)', $row['name_lc']);
    }

    public function test_create_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->exercises->create(null, ['name' => 'X', 'type' => 'balance', 'met' => 3.0, 'source' => 'custom'], null);
    }

    public function test_create_rejects_met_out_of_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->exercises->create(null, ['name' => 'X', 'type' => 'duration', 'met' => 25.5, 'source' => 'custom'], null);
    }

    public function test_create_rejects_zero_met(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->exercises->create(null, ['name' => 'X', 'type' => 'duration', 'met' => 0.0, 'source' => 'custom'], null);
    }

    public function test_update_recomputes_name_lc(): void
    {
        $id = $this->exercises->create(null, ['name' => 'Running', 'type' => 'duration', 'met' => 9.8, 'source' => 'compendium'], null);
        $this->exercises->update($id, ['name' => 'Jogging Slow']);
        $row = $this->db->fetchOne('SELECT name, name_lc FROM exercises WHERE id = :id', ['id' => $id]);
        self::assertSame('Jogging Slow', $row['name']);
        self::assertSame('jogging slow', $row['name_lc']);
    }

    public function test_update_rejects_out_of_range_met(): void
    {
        $id = $this->exercises->create(null, ['name' => 'Running', 'type' => 'duration', 'met' => 9.8, 'source' => 'compendium'], null);
        $this->expectException(\InvalidArgumentException::class);
        $this->exercises->update($id, ['met' => 250.0]);
    }

    public function test_search_case_insensitive(): void
    {
        $this->exercises->create(null, ['name' => 'Running', 'type' => 'duration', 'met' => 9.8, 'source' => 'compendium'], null);
        $hits = $this->exercises->search($this->householdId, 'RUN');
        self::assertCount(1, $hits);
        self::assertSame('Running', $hits[0]['name']);
    }

    public function test_search_household_first_ordering(): void
    {
        $this->exercises->create(null, ['name' => 'Zzz Global', 'type' => 'duration', 'met' => 3.0, 'source' => 'compendium'], null);
        $this->exercises->create($this->householdId, ['name' => 'ZZZ HH Own', 'type' => 'duration', 'met' => 3.0, 'source' => 'custom'], null);
        $hits = $this->exercises->search($this->householdId, 'zzz');
        self::assertCount(2, $hits);
        // Household-scoped ranks first (household_id IS NULL = TRUE sorts AFTER FALSE).
        self::assertSame($this->householdId, $hits[0]['household_id']);
    }

    public function test_search_ignores_foreign_household(): void
    {
        $otherHh = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('Other', 'ZZZZZZ', 'Pacific/Auckland') RETURNING id",
        );
        $this->exercises->create($otherHh, ['name' => 'Foreign Row', 'type' => 'duration', 'met' => 3.0, 'source' => 'custom'], null);
        $hits = $this->exercises->search($this->householdId, 'foreign');
        self::assertCount(0, $hits);
    }

    public function test_seed_uniqueness_partial_index(): void
    {
        $this->exercises->create(null, ['name' => 'Running', 'type' => 'duration', 'met' => 9.8, 'source' => 'compendium'], null);
        $this->expectException(\PDOException::class);
        $this->exercises->create(null, ['name' => 'Running', 'type' => 'duration', 'met' => 7.0, 'source' => 'compendium'], null);
    }

    public function test_partial_unique_permits_household_scoped_duplicate(): void
    {
        $this->exercises->create(null, ['name' => 'Running', 'type' => 'duration', 'met' => 9.8, 'source' => 'compendium'], null);
        $ownId = $this->exercises->create($this->householdId, ['name' => 'Running', 'type' => 'duration', 'met' => 8.0, 'source' => 'custom'], null);
        self::assertGreaterThan(0, $ownId);
    }
}
