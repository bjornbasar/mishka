<?php

declare(strict_types=1);

namespace App\Tests\Tracker;

use App\Tracker\WeightLogRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

final class WeightLogRepositoryTest extends TestCase
{
    private Connection $db;
    private WeightLogRepository $weight;
    private int $userId;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->weight = new WeightLogRepository($this->db);
        $this->userId = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES ('u@x', 'x', 'User') RETURNING id",
        );
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_create_returns_id_and_stores_row(): void
    {
        $id = $this->weight->create($this->userId, 68.5, '2026-07-13');
        self::assertGreaterThan(0, $id);
    }

    public function test_create_rejects_weight_below_20(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->weight->create($this->userId, 15.0, '2026-07-13');
    }

    public function test_create_rejects_weight_above_300(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->weight->create($this->userId, 301.0, '2026-07-13');
    }

    public function test_latest_for_user_returns_newest(): void
    {
        $this->weight->create($this->userId, 70.0, '2026-07-11');
        $this->weight->create($this->userId, 68.5, '2026-07-13');
        $this->weight->create($this->userId, 69.0, '2026-07-12');
        $latest = $this->weight->latestForUser($this->userId);
        self::assertNotNull($latest);
        // NUMERIC formatting varies by driver (PG: '68.50'; SQLite: '68.5') — compare as float.
        self::assertEqualsWithDelta(68.5, (float) $latest['weight_kg'], 0.001);
        self::assertSame('2026-07-13', $latest['measured_on']);
    }

    public function test_latest_for_user_returns_null_when_none_recorded(): void
    {
        self::assertNull($this->weight->latestForUser($this->userId));
    }

    public function test_list_for_user_ordered_by_date_desc(): void
    {
        $this->weight->create($this->userId, 70.0, '2026-07-11');
        $this->weight->create($this->userId, 68.5, '2026-07-13');
        $this->weight->create($this->userId, 69.0, '2026-07-12');
        $list = $this->weight->listForUser($this->userId, 10);
        self::assertCount(3, $list);
        self::assertSame('2026-07-13', $list[0]['measured_on']);
        self::assertSame('2026-07-12', $list[1]['measured_on']);
        self::assertSame('2026-07-11', $list[2]['measured_on']);
    }

    public function test_delete_owned_by_returns_1_when_owner_matches(): void
    {
        $id = $this->weight->create($this->userId, 68.5, '2026-07-13');
        self::assertSame(1, $this->weight->deleteOwnedById($id, $this->userId));
        self::assertNull($this->weight->latestForUser($this->userId));
    }

    public function test_delete_owned_by_returns_0_for_other_user(): void
    {
        $otherUid = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES ('b@x', 'x', 'B') RETURNING id",
        );
        $id = $this->weight->create($this->userId, 68.5, '2026-07-13');
        self::assertSame(0, $this->weight->deleteOwnedById($id, $otherUid));
    }
}
