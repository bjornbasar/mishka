<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Tracker\FoodRepository;
use App\Tracker\FoodServingRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * v0.8.1.1 — PG-only smoke for FoodRepository::search.
 *
 * Regression guard for the v0.8.1.1 hotfix. FoodRepository::search's
 * INNER JOIN uses a BOOLEAN comparison on food_servings.is_default —
 * SQLite treats BOOLEAN as INTEGER and accepts `is_default = 1`, but
 * PG rejects the implicit integer→boolean cast with
 *   "operator does not exist: boolean = integer".
 *
 * The v0.8.0 test suite passed on SQLite but the search endpoint 500'd
 * in prod PG. Root cause: the SQLite-in-memory test harness never
 * exercised the PG path. PgSmoke tests filter runs against real PG16
 * (see .github/workflows/ci.yml pg-smoke job), so this test catches
 * the regression class.
 *
 * SKIPS unless DB_DSN points at pgsql://. Uses explicit transaction +
 * rollBack() so smoke-test rows never leak into shareddb.
 */
final class FoodRepositoryPgSmokeTest extends TestCase
{
    private Connection $db;
    private FoodRepository $foods;
    private FoodServingRepository $servings;

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
        $this->foods = new FoodRepository($this->db);
        $this->servings = new FoodServingRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_search_returns_dish_with_default_serving_on_pg(): void
    {
        // Create a household to attach a dish to.
        $hid = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone)
             VALUES ('SmokeHH', 'SMKAAA', 'Pacific/Auckland') RETURNING id",
        );
        $foodId = $this->foods->create($hid, [
            'name' => 'Smoke-Test Kare-Kare',
            'source' => 'custom',
        ], null);
        $this->servings->create($foodId, [
            'label' => '1 bowl',
            'grams' => 350,
            'kcal' => 480,
            'is_default' => true,
        ]);

        // The bug: this SELECT with INNER JOIN + is_default comparison
        // 500'd in prod. Confirm it works on PG now.
        $hits = $this->foods->search($hid, 'Smoke');

        self::assertCount(1, $hits);
        self::assertSame('Smoke-Test Kare-Kare', $hits[0]['name']);
        self::assertSame(480, $hits[0]['default_serving_kcal']);
    }

    public function test_search_drops_default_less_dish_on_pg(): void
    {
        $hid = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone)
             VALUES ('SmokeHH2', 'SMKBBB', 'Pacific/Auckland') RETURNING id",
        );
        // Dish with no default serving — INNER JOIN drops it.
        $noDefaultId = $this->foods->create($hid, ['name' => 'Smoke-No-Default', 'source' => 'custom'], null);
        $this->servings->create($noDefaultId, [
            'label' => '1 cup',
            'grams' => 200,
            'kcal' => 300,
            'is_default' => false,
        ]);

        $hits = $this->foods->search($hid, 'Smoke-No-Default');
        self::assertCount(0, $hits, 'INNER JOIN excludes dishes without a default serving');
    }
}
