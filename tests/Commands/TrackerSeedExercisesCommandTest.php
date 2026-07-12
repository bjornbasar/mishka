<?php

declare(strict_types=1);

namespace App\Tests\Commands;

use App\Commands\TrackerSeedExercisesCommand;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

final class TrackerSeedExercisesCommandTest extends TestCase
{
    private Connection $db;
    private TrackerSeedExercisesCommand $cmd;
    private string $fixturePath;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->cmd = new TrackerSeedExercisesCommand($this->db);
        $this->fixturePath = sys_get_temp_dir() . '/mishka-exseed-test-' . uniqid('', true) . '.json';
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

    public function test_first_run_seeds_all_exercises(): void
    {
        $this->writeFixture([
            'version' => 1,
            'exercises' => [
                ['name' => 'Running (Moderate)', 'type' => 'duration', 'met' => 9.8, 'source' => 'compendium'],
                ['name' => 'Squats', 'type' => 'strength', 'met' => 5.0, 'default_rom_m' => 0.5, 'source' => 'compendium'],
            ],
        ]);

        self::assertSame(0, $this->cmd->handle(['file' => $this->fixturePath]));
        self::assertSame(2, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM exercises WHERE household_id IS NULL'));
    }

    public function test_second_run_is_idempotent(): void
    {
        $this->writeFixture([
            'version' => 1,
            'exercises' => [
                ['name' => 'Running', 'type' => 'duration', 'met' => 9.8, 'source' => 'compendium'],
            ],
        ]);
        self::assertSame(0, $this->cmd->handle(['file' => $this->fixturePath]));
        self::assertSame(0, $this->cmd->handle(['file' => $this->fixturePath]));
        self::assertSame(1, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM exercises WHERE name = :n', ['n' => 'Running']));
    }

    public function test_missing_file_returns_1(): void
    {
        self::assertSame(1, $this->cmd->handle(['file' => '/no-such.json']));
    }

    public function test_malformed_json_returns_1(): void
    {
        file_put_contents($this->fixturePath, '{ not valid json');
        self::assertSame(1, $this->cmd->handle(['file' => $this->fixturePath]));
    }

    public function test_unknown_version_returns_1(): void
    {
        $this->writeFixture(['version' => 99, 'exercises' => []]);
        self::assertSame(1, $this->cmd->handle(['file' => $this->fixturePath]));
    }

    public function test_empty_exercises_returns_0(): void
    {
        $this->writeFixture(['version' => 1, 'exercises' => []]);
        self::assertSame(0, $this->cmd->handle(['file' => $this->fixturePath]));
    }
}
