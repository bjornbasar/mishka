<?php

declare(strict_types=1);

namespace App\Auth;

use Karhu\Db\Connection;

/**
 * v0.5.0 — app-layer rate-limit accounting (H4).
 *
 * Closes abuse at the app layer regardless of upstream reverse-proxy / WAF
 * configuration. Two independent buckets share this one table:
 *
 *   - kind='password_reset_request', keyed by ip_address (anonymous endpoint)
 *     Limit: 5 / 10min / IP
 *   - kind='verify_resend',          keyed by user_id    (authed endpoint)
 *     Limit: 3 / 10min / user
 *
 * The two are separated by `kind` so a flood of resends doesn't lock out a
 * password-reset attempt and vice versa. ip_address vs user_id is whichever
 * is non-null per row — both columns ship NULLable.
 *
 * Window math is in PHP via gmdate (B3 portability), comparing the
 * `attempted_at` TIMESTAMPTZ column to `gmdate('Y-m-d H:i:s', time() - 60*N)`.
 *
 * The table grows unbounded by design; at family-scale (~5-10 attempts/day)
 * row growth is < 4k/year. A future pruning job can DELETE WHERE attempted_at
 * < NOW() - '90 days'; for now the SELECT always hits the
 * (kind, attempted_at) index so unbounded growth is acceptable.
 */
final class EmailSendAttemptRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Insert a single attempt row. Either $ip or $userId should be set — the
     * other stays NULL. We don't enforce that at the DB level (NULLable both)
     * because forgetting one is a soft error, not a data-corruption one.
     */
    public function record(string $kind, ?string $ip, ?int $userId): void
    {
        $this->db->run(
            'INSERT INTO email_send_attempts (kind, ip_address, user_id, attempted_at)
             VALUES (:k, :ip, :uid, :now)',
            [
                'k'   => $kind,
                'ip'  => $ip,
                'uid' => $userId,
                'now' => gmdate('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Count IP-keyed attempts of $kind in the last $minutes. Used for the
     * password-reset bucket where the caller is anonymous.
     */
    public function countRecentByIp(string $kind, string $ip, int $minutes): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $minutes * 60);

        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM email_send_attempts
             WHERE kind = :k AND ip_address = :ip AND attempted_at >= :cutoff',
            ['k' => $kind, 'ip' => $ip, 'cutoff' => $cutoff],
        );
    }

    /**
     * Count user-keyed attempts of $kind in the last $minutes. Used for the
     * verify-resend bucket where the caller is authed.
     */
    public function countRecentByUser(string $kind, int $userId, int $minutes): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $minutes * 60);

        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM email_send_attempts
             WHERE kind = :k AND user_id = :uid AND attempted_at >= :cutoff',
            ['k' => $kind, 'uid' => $userId, 'cutoff' => $cutoff],
        );
    }
}
