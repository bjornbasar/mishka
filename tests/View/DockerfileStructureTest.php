<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\TestCase;

/**
 * v0.8.5 — file-parsing regression guard for the Dockerfile's non-root
 * container posture.
 *
 * Mirrors {@see ServiceWorkerStructureTest} — invariants that don't live
 * in PHP code but MUST persist across future Dockerfile edits. A future
 * maintainer accidentally reverting to root without changing anything
 * else would leave the container running as root with a mode-700 sessions
 * dir → 100% session_start failure on every request → prod outage. This
 * test catches that class of regression at commit time.
 *
 * See DOCS #75 (v0.8.5 non-root container user).
 */
final class DockerfileStructureTest extends TestCase
{
    private const DOCKERFILE_PATH = __DIR__ . '/../../Dockerfile';

    public function test_dockerfile_declares_user_www_data(): void
    {
        $dockerfile = $this->dockerfile();
        // Single-line anchor with multiline flag — matches exactly one occurrence.
        self::assertSame(
            1,
            preg_match_all('/^USER www-data$/m', $dockerfile),
            'Dockerfile must contain exactly one `USER www-data` line — v0.8.5 non-root container invariant (DOCS #75).',
        );
    }

    public function test_sessions_dir_is_chowned_to_www_data_at_mode_700(): void
    {
        $dockerfile = $this->dockerfile();

        // chown to www-data:www-data present.
        self::assertSame(
            1,
            preg_match('/chown www-data:www-data \/var\/lib\/mishka\/sessions/', $dockerfile),
            '`chown www-data:www-data /var/lib/mishka/sessions` MUST be present — required for the non-root www-data user to write session files. See DOCS #75.',
        );

        // chmod 700 present.
        self::assertSame(
            1,
            preg_match('/chmod 700 \/var\/lib\/mishka\/sessions/', $dockerfile),
            '`chmod 700 /var/lib/mishka/sessions` MUST be present — tightest mode matching the exclusive USER www-data. See DOCS #75.',
        );

        // chmod 733 (the v0.7.3 tripwire mode) MUST NOT be present — v0.8.5 closed the tripwire.
        self::assertSame(
            0,
            preg_match('/chmod 733/', $dockerfile),
            '`chmod 733` MUST NOT be present — v0.7.6 tripwire (DOCS #68) was closed in v0.8.5. Regression indicates an accidental root-mode reversion.',
        );
    }

    public function test_user_directive_precedes_cmd_directive(): void
    {
        $dockerfile = $this->dockerfile();
        $lines = explode("\n", $dockerfile);
        $userLine = null;
        $cmdLine = null;
        foreach ($lines as $i => $line) {
            if ($userLine === null && preg_match('/^USER www-data$/', $line) === 1) {
                $userLine = $i;
            }
            if ($cmdLine === null && preg_match('/^CMD \[/', $line) === 1) {
                $cmdLine = $i;
            }
        }
        self::assertNotNull($userLine, 'USER www-data directive not found');
        self::assertNotNull($cmdLine, 'CMD directive not found');
        self::assertLessThan(
            $cmdLine,
            $userLine,
            "USER directive (line {$userLine}) must precede CMD (line {$cmdLine}) so it is in effect at container start.",
        );
    }

    private function dockerfile(): string
    {
        $contents = @file_get_contents(self::DOCKERFILE_PATH);
        self::assertNotFalse($contents, 'Dockerfile missing at ' . self::DOCKERFILE_PATH);
        return $contents;
    }
}
