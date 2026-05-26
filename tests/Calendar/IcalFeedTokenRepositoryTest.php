<?php

declare(strict_types=1);

namespace App\Tests\Calendar;

use App\Calendar\IcalFeedTokenRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IcalFeedTokenRepository.
 *
 * Tests confirm:
 *   - generate() returns a 64-char hex token (raw); only the SHA-256 hash is
 *     persisted
 *   - findByRawToken() matches by SHA-256(raw) and updates last_used_at on hit
 *   - revoked_at filters lookups
 *   - cap-at-3: 4th generate auto-revokes the oldest active row
 */
final class IcalFeedTokenRepositoryTest extends TestCase
{
    private Connection $db;
    private IcalFeedTokenRepository $repo;
    private int $uid;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->repo = new IcalFeedTokenRepository($this->db);

        $this->uid = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES (:e, 'x', 'T') RETURNING id",
            ['e' => 'u-' . bin2hex(random_bytes(3)) . '@example.com'],
        );
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_generate_returns_64_char_hex_token(): void
    {
        $raw = $this->repo->generate($this->uid);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $raw);
    }

    public function test_generate_persists_only_hash_not_raw(): void
    {
        $raw = $this->repo->generate($this->uid);

        $row = $this->db->fetchOne(
            'SELECT token_hash FROM ical_feed_tokens WHERE user_id = :uid',
            ['uid' => $this->uid],
        );
        self::assertNotNull($row);
        self::assertSame(hash('sha256', $raw), $row['token_hash']);
        // The raw token MUST NOT appear anywhere in the row
        self::assertStringNotContainsString($raw, json_encode($row) ?: '');
    }

    public function test_find_by_raw_token_returns_row_on_hit(): void
    {
        $raw = $this->repo->generate($this->uid);

        $row = $this->repo->findByRawToken($raw);

        self::assertNotNull($row);
        self::assertSame($this->uid, $row['user_id']);
        self::assertNull($row['scope_household_id']);
    }

    public function test_find_by_raw_token_updates_last_used_at(): void
    {
        $raw = $this->repo->generate($this->uid);
        $beforeHit = $this->db->fetchScalar(
            'SELECT last_used_at FROM ical_feed_tokens WHERE user_id = :uid',
            ['uid' => $this->uid],
        );
        self::assertNull($beforeHit);

        $this->repo->findByRawToken($raw);

        $afterHit = $this->db->fetchScalar(
            'SELECT last_used_at FROM ical_feed_tokens WHERE user_id = :uid',
            ['uid' => $this->uid],
        );
        self::assertNotNull($afterHit);
    }

    public function test_find_by_raw_token_returns_null_for_bogus_token(): void
    {
        self::assertNull($this->repo->findByRawToken(str_repeat('0', 64)));
    }

    public function test_revoked_tokens_are_not_found(): void
    {
        $raw = $this->repo->generate($this->uid);
        $id = (int) $this->db->fetchScalar(
            'SELECT id FROM ical_feed_tokens WHERE user_id = :uid',
            ['uid' => $this->uid],
        );

        $this->repo->revoke($id, $this->uid);

        self::assertNull($this->repo->findByRawToken($raw));
    }

    public function test_revoke_requires_ownership(): void
    {
        $raw = $this->repo->generate($this->uid);
        $id = (int) $this->db->fetchScalar(
            'SELECT id FROM ical_feed_tokens WHERE user_id = :uid',
            ['uid' => $this->uid],
        );

        $strangerId = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES ('stranger-' || :s || '@example.com', 'x', 'S') RETURNING id",
            ['s' => bin2hex(random_bytes(3))],
        );

        $this->expectException(\RuntimeException::class);
        $this->repo->revoke($id, $strangerId);
    }

    public function test_list_active_for_user_excludes_revoked(): void
    {
        $r1 = $this->repo->generate($this->uid);
        $r2 = $this->repo->generate($this->uid);
        $id1 = (int) $this->db->fetchScalar(
            'SELECT id FROM ical_feed_tokens WHERE token_hash = :h',
            ['h' => hash('sha256', $r1)],
        );
        $this->repo->revoke($id1, $this->uid);

        $active = $this->repo->listActiveForUser($this->uid);
        self::assertCount(1, $active);
    }

    public function test_cap_at_3_auto_revokes_oldest_on_4th_generate(): void
    {
        $this->repo->generate($this->uid);
        // Force distinct created_at values so the "oldest" ordering is deterministic
        // (SQLite's CURRENT_TIMESTAMP has 1-second resolution; we want millisecond-
        // distinct so we manipulate created_at directly).
        $this->db->run(
            "UPDATE ical_feed_tokens SET created_at = '2026-01-01 00:00:00+00' WHERE user_id = :uid",
            ['uid' => $this->uid],
        );
        $this->repo->generate($this->uid);
        $this->db->run(
            "UPDATE ical_feed_tokens SET created_at = '2026-01-02 00:00:00+00'
              WHERE user_id = :uid AND created_at > '2026-01-01 00:00:00+00' AND revoked_at IS NULL",
            ['uid' => $this->uid],
        );
        $this->repo->generate($this->uid);
        $this->db->run(
            "UPDATE ical_feed_tokens SET created_at = '2026-01-03 00:00:00+00'
              WHERE user_id = :uid AND created_at > '2026-01-02 00:00:00+00' AND revoked_at IS NULL",
            ['uid' => $this->uid],
        );

        $activeBefore = $this->repo->listActiveForUser($this->uid);
        self::assertCount(3, $activeBefore);

        // 4th generate — must auto-revoke the oldest (created_at = 2026-01-01)
        $this->repo->generate($this->uid);

        $activeAfter = $this->repo->listActiveForUser($this->uid);
        self::assertCount(3, $activeAfter);

        $oldestStillActive = $this->db->fetchScalar(
            "SELECT id FROM ical_feed_tokens WHERE user_id = :uid AND created_at = '2026-01-01 00:00:00+00' AND revoked_at IS NULL",
            ['uid' => $this->uid],
        );
        self::assertFalse($oldestStillActive);  // oldest is now revoked
    }
}
