<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\Household\HouseholdRepository;
use App\View\NavContext;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

final class NavContextTest extends TestCase
{
    private Connection $db;
    private HouseholdRepository $repo;
    private NavContext $nav;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->repo = new HouseholdRepository($this->db);
        $this->nav = new NavContext($this->repo);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        $_SESSION = [];
    }

    public function test_anonymous_user_returns_empty_context(): void
    {
        $ctx = $this->nav->forCurrentUser();
        self::assertNull($ctx['session_email']);
        self::assertSame([], $ctx['households']);
        self::assertNull($ctx['active_household']);
    }

    public function test_logged_in_user_with_no_household_returns_email_and_empty_lists(): void
    {
        $userId = $this->insertUser('a@example.com');
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = 'a@example.com';

        $ctx = $this->nav->forCurrentUser();

        self::assertSame('a@example.com', $ctx['session_email']);
        self::assertSame([], $ctx['households']);
        self::assertNull($ctx['active_household']);
    }

    public function test_logged_in_user_with_one_household_returns_active_record(): void
    {
        $userId = $this->insertUser('a@example.com');
        $hid = $this->repo->createForOwner('Test Den', $userId);
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = 'a@example.com';
        $_SESSION['active_household_id'] = $hid;

        $ctx = $this->nav->forCurrentUser();

        self::assertSame('a@example.com', $ctx['session_email']);
        self::assertCount(1, $ctx['households']);
        self::assertSame($hid, $ctx['households'][0]['id']);
        self::assertNotNull($ctx['active_household']);
        self::assertSame('Test Den', $ctx['active_household']['name']);
    }

    public function test_stale_active_household_returns_null_active_for_self_heal(): void
    {
        // User was a member but their session active_household_id no longer matches
        // any current membership (kicked since login). NavContext returns null active
        // so the home controller can redirect to /household/setup.
        $userId = $this->insertUser('a@example.com');
        $hid = $this->repo->createForOwner('Test', $userId);

        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = 'a@example.com';
        $_SESSION['active_household_id'] = $hid;

        // Kick them out — simulating the owner removing them since they last logged in.
        $this->db->run('DELETE FROM household_members WHERE user_id = :uid', ['uid' => $userId]);

        $ctx = $this->nav->forCurrentUser();

        self::assertSame('a@example.com', $ctx['session_email']);
        self::assertSame([], $ctx['households']);
        self::assertNull($ctx['active_household']);
    }

    private function insertUser(string $email): int
    {
        return (int) $this->db->fetchScalar(
            'INSERT INTO users (email, password_hash, display_name)
             VALUES (:email, :hash, :name) RETURNING id',
            ['email' => $email, 'hash' => 'unused', 'name' => 'T'],
        );
    }
}
