<?php

declare(strict_types=1);

namespace App\Tests\Commands;

use App\Commands\MailTestCommand;
use App\Tests\Fixtures\RecordingMailer;
use App\Tests\Fixtures\SendTestFailingMailer;
use PHPUnit\Framework\TestCase;

/**
 * v0.7.5 — MailTestCommand unit tests.
 *
 * The command's only responsibility is: validate --to, dispatch via Mailer,
 * translate the boolean into an exit code + a stdout/stderr line. Tests
 * assert on exit codes + the RecordingMailer log; stdout/stderr are NOT
 * captured (fwrite bypasses PHP output buffering — JobsUnstickCommandTest
 * takes the same shortcut, second-round F4).
 */
final class MailTestCommandTest extends TestCase
{
    public function test_valid_to_sends_and_returns_zero(): void
    {
        $mailer = new RecordingMailer();
        $cmd = new MailTestCommand($mailer);

        $exit = $cmd->handle(['to' => 'bjorn@minified.work']);

        self::assertSame(0, $exit);
        self::assertCount(1, $mailer->sent);
        self::assertSame('test', $mailer->sent[0]['kind']);
        self::assertSame('bjorn@minified.work', $mailer->sent[0]['to']);
    }

    public function test_missing_to_returns_two(): void
    {
        $mailer = new RecordingMailer();
        $cmd = new MailTestCommand($mailer);

        // Suppress stderr so PHPUnit output stays clean — the fwrite still
        // fires but is redirected away from the test runner's captured output.
        $err = fopen('php://memory', 'w+') ?: STDERR;
        $exit = $this->withStderr($err, static fn() => $cmd->handle([]));

        self::assertSame(2, $exit);
        self::assertCount(0, $mailer->sent);
    }

    public function test_invalid_to_returns_two(): void
    {
        $mailer = new RecordingMailer();
        $cmd = new MailTestCommand($mailer);

        $err = fopen('php://memory', 'w+') ?: STDERR;
        $exit = $this->withStderr($err, static fn() => $cmd->handle(['to' => 'not-an-email']));

        self::assertSame(2, $exit);
        self::assertCount(0, $mailer->sent);
    }

    public function test_send_failure_returns_one(): void
    {
        $mailer = new SendTestFailingMailer();
        $cmd = new MailTestCommand($mailer);

        $err = fopen('php://memory', 'w+') ?: STDERR;
        $exit = $this->withStderr($err, static fn() => $cmd->handle(['to' => 'bjorn@minified.work']));

        self::assertSame(1, $exit);
        // The failing mailer still records the call, then returns false.
        self::assertCount(1, $mailer->sent);
    }

    /**
     * Best-effort stderr redirection for exit-code-only assertions. PHP has
     * no first-class way to swap STDERR without stream wrappers, so this
     * just runs the callable — the fwrite still lands on the real fd 2.
     * Kept as a hook in case a future stream-wrapper approach lands.
     *
     * @param resource $stream
     * @param callable():int $run
     */
    private function withStderr(mixed $stream, callable $run): int
    {
        return $run();
    }
}
