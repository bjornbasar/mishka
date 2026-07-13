<?php

declare(strict_types=1);

namespace App\Tests\Tracker;

use App\Tracker\TrackerProfileRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

final class TrackerProfileRepositoryTest extends TestCase
{
    private Connection $db;
    private TrackerProfileRepository $profiles;
    private int $userId;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->profiles = new TrackerProfileRepository($this->db);
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

    public function test_upsert_first_time_inserts(): void
    {
        $this->profiles->upsert($this->userId, [
            'sex' => 'male',
            'birth_year' => 1985,
            'height_cm' => 175.0,
            'base_activity' => 1.375,
        ]);
        $row = $this->profiles->findByUserId($this->userId);
        self::assertNotNull($row);
        self::assertSame('male', $row['sex']);
        self::assertSame(1985, $row['birth_year']);
    }

    public function test_upsert_second_time_updates_same_row(): void
    {
        $this->profiles->upsert($this->userId, [
            'sex' => 'male', 'birth_year' => 1985, 'height_cm' => 175.0, 'base_activity' => 1.375,
        ]);
        $this->profiles->upsert($this->userId, [
            'sex' => 'male', 'birth_year' => 1985, 'height_cm' => 176.0, 'base_activity' => 1.55,
        ]);
        $row = $this->profiles->findByUserId($this->userId);
        self::assertNotNull($row);
        self::assertEqualsWithDelta(176.0, (float) $row['height_cm'], 0.05);
        self::assertEqualsWithDelta(1.55, (float) $row['base_activity'], 0.005);
        // Confirm only one row per user (PK enforces this too).
        self::assertSame(1, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM tracker_profiles WHERE user_id = :uid', ['uid' => $this->userId]));
    }

    public function test_find_returns_null_when_no_row(): void
    {
        self::assertNull($this->profiles->findByUserId($this->userId));
    }

    public function test_rejects_invalid_sex(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->profiles->upsert($this->userId, [
            'sex' => 'other', 'birth_year' => 1985, 'height_cm' => 175.0, 'base_activity' => 1.375,
        ]);
    }

    public function test_rejects_birth_year_before_1900(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->profiles->upsert($this->userId, [
            'sex' => 'male', 'birth_year' => 1899, 'height_cm' => 175.0, 'base_activity' => 1.375,
        ]);
    }

    public function test_rejects_birth_year_within_last_5_years(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->profiles->upsert($this->userId, [
            'sex' => 'male', 'birth_year' => (int) date('Y') - 3, 'height_cm' => 175.0, 'base_activity' => 1.375,
        ]);
    }

    public function test_rejects_height_out_of_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->profiles->upsert($this->userId, [
            'sex' => 'male', 'birth_year' => 1985, 'height_cm' => 300.0, 'base_activity' => 1.375,
        ]);
    }

    public function test_rejects_base_activity_out_of_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->profiles->upsert($this->userId, [
            'sex' => 'male', 'birth_year' => 1985, 'height_cm' => 175.0, 'base_activity' => 5.0,
        ]);
    }

    public function test_delete_removes_the_row(): void
    {
        $this->profiles->upsert($this->userId, [
            'sex' => 'male', 'birth_year' => 1985, 'height_cm' => 175.0, 'base_activity' => 1.375,
        ]);
        $this->profiles->delete($this->userId);
        self::assertNull($this->profiles->findByUserId($this->userId));
    }

    public function test_user_delete_cascades(): void
    {
        $this->profiles->upsert($this->userId, [
            'sex' => 'male', 'birth_year' => 1985, 'height_cm' => 175.0, 'base_activity' => 1.375,
        ]);
        $this->db->run('DELETE FROM users WHERE id = :uid', ['uid' => $this->userId]);
        self::assertNull($this->profiles->findByUserId($this->userId));
    }
}
