<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Tracker\TrackerProfileRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * v0.8.2 — PG-only smoke for TrackerProfileRepository.
 *
 * Guards against driver-portability regressions on:
 *   - `ON CONFLICT (user_id) DO UPDATE` upsert path (SQLite ≥ 3.24 +
 *     PG both support this — three existing sites in mishka use it,
 *     but the pattern must be re-verified on PG for each new consumer).
 *   - NUMERIC(4,3) roundtrip: bind `1.375` → SELECT → verify float
 *     equality. PG stores as exact decimal; PDO returns string; PHP
 *     float cast rounds to binary. Widget math depends on this roundtrip
 *     landing precisely.
 *
 * SKIPS unless DB_DSN points at pgsql://. Uses explicit transaction +
 * rollBack() so smoke-test rows never leak into shareddb. See DOCS #72.
 */
final class TrackerProfileRepositoryPgSmokeTest extends TestCase
{
    private Connection $db;
    private TrackerProfileRepository $profiles;
    private int $userId;

    protected function setUp(): void
    {
        $dsn = getenv('DB_DSN') ?: ($_ENV['DB_DSN'] ?? '');
        if (!is_string($dsn) || !str_starts_with($dsn, 'pgsql:')) {
            self::markTestSkipped('PG smoke tests require DB_DSN=pgsql:...');
        }
        $this->db = new Connection(
            $dsn,
            (string) (getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? '')),
            (string) (getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? '')),
        );
        $this->db->pdo()->beginTransaction();
        $this->profiles = new TrackerProfileRepository($this->db);
        $this->userId = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name)
             VALUES ('smoketracker@example.test', 'x', 'Smoke') RETURNING id",
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_upsert_insert_then_update_on_pg(): void
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
        // PK enforces one row per user.
        self::assertSame(1, (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM tracker_profiles WHERE user_id = :uid',
            ['uid' => $this->userId],
        ));
    }

    public function test_numeric_4_3_roundtrip_precision_on_pg(): void
    {
        // 1.375 fits NUMERIC(4,3) exactly. Confirm roundtrip: PHP → PG → PHP.
        $this->profiles->upsert($this->userId, [
            'sex' => 'female', 'birth_year' => 1990, 'height_cm' => 165.0, 'base_activity' => 1.375,
        ]);
        $row = $this->profiles->findByUserId($this->userId);
        self::assertNotNull($row);
        // Both string comparison and float-with-delta variants — either shape works on PG.
        self::assertEqualsWithDelta(1.375, (float) $row['base_activity'], 0.0005);
    }

    public function test_user_delete_cascades_on_pg(): void
    {
        $this->profiles->upsert($this->userId, [
            'sex' => 'male', 'birth_year' => 1985, 'height_cm' => 175.0, 'base_activity' => 1.375,
        ]);
        $this->db->run('DELETE FROM users WHERE id = :uid', ['uid' => $this->userId]);
        self::assertNull($this->profiles->findByUserId($this->userId));
    }
}
