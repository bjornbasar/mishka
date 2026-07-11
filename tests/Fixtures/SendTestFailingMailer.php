<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

/**
 * v0.7.5 — RecordingMailer variant whose sendTest() returns false.
 *
 * Used exclusively by MailTestCommandTest::test_send_failure_returns_one
 * to exercise the exit-1 branch of MailTestCommand::handle without
 * needing to mock the full Symfony transport stack. Records the call
 * (parent behaviour) so the test can also assert dispatch happened.
 */
final class SendTestFailingMailer extends RecordingMailer
{
    public function sendTest(string $toEmail): bool
    {
        // Record via the parent shape so tests can inspect $sent, but
        // signal "SMTP transport failed" back to the caller.
        parent::sendTest($toEmail);
        return false;
    }
}
