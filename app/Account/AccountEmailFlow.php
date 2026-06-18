<?php

declare(strict_types=1);

namespace App\Account;

use App\Auth\EmailChangeTokenRepository;
use App\Auth\EmailSendAttemptRepository;
use App\Auth\EmailVerificationTokenRepository;
use App\Auth\PasswordResetTokenRepository;
use App\Mail\UrlBuilder;

/**
 * v0.6.20 — bundle of email-change-flow dependencies.
 *
 * Groups the 5 deps that are STRICTLY email-change-specific (verified
 * by the v0.6.20 dep-usage matrix — see DOCS.md decision #61):
 *
 *   - EmailChangeTokenRepository: issue + redeem the change token
 *   - EmailSendAttemptRepository: rate-limit /me/email POSTs
 *   - PasswordResetTokenRepository: invalidate pending tokens on swap
 *   - EmailVerificationTokenRepository: invalidate pending tokens on swap
 *   - UrlBuilder: build the confirmation link that goes into the email
 *
 * Mailer is NOT included — it's shared with the delete-flow's courtesy
 * email at AccountController::handleDelete. HouseholdRepository +
 * SystemRoleRepository stay at the controller level because they're
 * shared across 2+ flows (delete, admin-promote, profile).
 *
 * Closes the 14-param ctor refactor flag from DOCS #53 (v0.6.12
 * candidate deferred through v0.6.19's audit-table addition).
 */
final readonly class AccountEmailFlow
{
    public function __construct(
        public EmailChangeTokenRepository $changeTokens,
        public EmailSendAttemptRepository $attempts,
        public PasswordResetTokenRepository $resetTokens,
        public EmailVerificationTokenRepository $verifyTokens,
        public UrlBuilder $urls,
    ) {}
}
