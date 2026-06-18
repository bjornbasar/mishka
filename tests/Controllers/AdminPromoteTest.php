<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

/**
 * v0.6.19 — /me/admin/promote integration tests.
 *
 * Pinned invariants:
 *   - GET 302→/login for anonymous users.
 *   - GET 403 with templates/account/forbidden.twig for logged-in non-admins.
 *   - GET renders form for admins, listing all OTHER users.
 *   - POST grants 'admin' to target via SystemRoleRepository.
 *   - POST is idempotent on already-admin target (silent no-op via the
 *     composite-PK ON CONFLICT / INSERT OR IGNORE branch).
 *   - POST rejects self-target with 422.
 *   - Promote-only semantics: the granter keeps their own admin role.
 */
final class AdminPromoteTest extends AppTestCase
{
    private const VALID_PASSWORD = 'correct horse battery staple';

    public function test_get_redirects_anonymous_to_login(): void
    {
        $response = $this->request('GET', '/me/admin/promote');
        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('location'));
    }

    public function test_get_403_for_logged_in_non_admin(): void
    {
        // First user is sentinel admin; second user has no admin role.
        $this->createUserWithHash('admin@example.com', self::VALID_PASSWORD);
        $bob = $this->createUserWithHash('bob@example.com', self::VALID_PASSWORD);
        $this->loginAs($bob, 'bob@example.com');

        $response = $this->request('GET', '/me/admin/promote');
        self::assertSame(403, $response->status());
        self::assertStringContainsString('system administrators only', $response->body());
    }

    public function test_get_renders_form_with_other_users_for_admin(): void
    {
        $admin = $this->createUserWithHash('admin@example.com', self::VALID_PASSWORD, 'AdminUser');
        $bob = $this->createUserWithHash('bob@example.com', self::VALID_PASSWORD, 'Bob');
        $this->createUserWithHash('carol@example.com', self::VALID_PASSWORD, 'Carol');
        $this->loginAs($admin, 'admin@example.com');

        $response = $this->request('GET', '/me/admin/promote');
        self::assertSame(200, $response->status());

        $body = $response->body();
        self::assertStringContainsString('<form', $body);
        self::assertStringContainsString('target_user_id', $body);
        self::assertStringContainsString('Bob', $body);
        self::assertStringContainsString('Carol', $body);
        // Self is NOT in the candidate dropdown.
        self::assertStringNotContainsString('AdminUser', $body);
    }

    public function test_get_renders_only_admin_copy_when_caller_is_sole_admin(): void
    {
        $admin = $this->createUserWithHash('admin@example.com', self::VALID_PASSWORD);
        $this->createUserWithHash('bob@example.com', self::VALID_PASSWORD);
        $this->loginAs($admin, 'admin@example.com');

        $response = $this->request('GET', '/me/admin/promote');
        self::assertStringContainsString('only system administrator', $response->body());
    }

    public function test_get_renders_redundancy_copy_when_other_admins_exist(): void
    {
        $admin = $this->createUserWithHash('admin@example.com', self::VALID_PASSWORD);
        $bob = $this->createUserWithHash('bob@example.com', self::VALID_PASSWORD);
        $this->grantSystemAdmin($bob);
        $this->loginAs($admin, 'admin@example.com');

        $response = $this->request('GET', '/me/admin/promote');
        $body = $response->body();
        self::assertStringNotContainsString('only system administrator', $body);
        self::assertStringContainsString('Promote-only', $body);
    }

    public function test_post_grants_admin_role_to_target(): void
    {
        $admin = $this->createUserWithHash('admin@example.com', self::VALID_PASSWORD);
        $bob = $this->createUserWithHash('bob@example.com', self::VALID_PASSWORD);
        $this->loginAs($admin, 'admin@example.com');

        $response = $this->request('POST', '/me/admin/promote', [
            'target_user_id' => (string) $bob,
        ]);

        self::assertSame(303, $response->status());
        self::assertSame('/me/profile', $response->header('location'));

        // Bob is now a system admin.
        $row = $this->db->fetchScalar(
            "SELECT 1 FROM system_roles WHERE user_id = :uid AND role = 'admin'",
            ['uid' => $bob],
        );
        self::assertSame(1, (int) $row);
    }

    public function test_post_is_idempotent_on_already_admin_target(): void
    {
        $admin = $this->createUserWithHash('admin@example.com', self::VALID_PASSWORD);
        $bob = $this->createUserWithHash('bob@example.com', self::VALID_PASSWORD);
        $this->grantSystemAdmin($bob);
        $this->loginAs($admin, 'admin@example.com');

        // First grant: already done in setup.
        $response = $this->request('POST', '/me/admin/promote', [
            'target_user_id' => (string) $bob,
        ]);
        self::assertSame(303, $response->status());

        // Still exactly one admin row for bob (no duplicate).
        $count = (int) $this->db->fetchScalar(
            "SELECT COUNT(*) FROM system_roles WHERE user_id = :uid AND role = 'admin'",
            ['uid' => $bob],
        );
        self::assertSame(1, $count);
    }

    public function test_post_rejects_self_target_with_422(): void
    {
        $admin = $this->createUserWithHash('admin@example.com', self::VALID_PASSWORD);
        $this->loginAs($admin, 'admin@example.com');

        $response = $this->request('POST', '/me/admin/promote', [
            'target_user_id' => (string) $admin,
        ]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Invalid target user', $response->body());
    }

    public function test_post_rejects_nonexistent_target_with_422(): void
    {
        $admin = $this->createUserWithHash('admin@example.com', self::VALID_PASSWORD);
        $this->loginAs($admin, 'admin@example.com');

        $response = $this->request('POST', '/me/admin/promote', [
            'target_user_id' => '99999',
        ]);

        self::assertSame(422, $response->status());
    }

    public function test_post_403_for_non_admin(): void
    {
        $this->createUserWithHash('admin@example.com', self::VALID_PASSWORD);
        $bob = $this->createUserWithHash('bob@example.com', self::VALID_PASSWORD);
        $carol = $this->createUserWithHash('carol@example.com', self::VALID_PASSWORD);
        $this->loginAs($bob, 'bob@example.com');

        $response = $this->request('POST', '/me/admin/promote', [
            'target_user_id' => (string) $carol,
        ]);

        self::assertSame(403, $response->status());

        // Carol was NOT promoted.
        $count = (int) $this->db->fetchScalar(
            "SELECT COUNT(*) FROM system_roles WHERE user_id = :uid AND role = 'admin'",
            ['uid' => $carol],
        );
        self::assertSame(0, $count);
    }

    public function test_post_keeps_granters_own_admin_role(): void
    {
        // Promote-only semantics: granter does NOT lose their admin.
        $admin = $this->createUserWithHash('admin@example.com', self::VALID_PASSWORD);
        $bob = $this->createUserWithHash('bob@example.com', self::VALID_PASSWORD);
        $this->loginAs($admin, 'admin@example.com');

        $this->request('POST', '/me/admin/promote', [
            'target_user_id' => (string) $bob,
        ]);

        $stillAdmin = $this->db->fetchScalar(
            "SELECT 1 FROM system_roles WHERE user_id = :uid AND role = 'admin'",
            ['uid' => $admin],
        );
        self::assertSame(1, (int) $stillAdmin);
    }
}
