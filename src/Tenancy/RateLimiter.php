<?php

declare(strict_types=1);

namespace AIPanel\Tenancy;

use AIPanel\Infra\Database;

/**
 * Fixed-window rate limiting, in the database.
 *
 * Deliberately not Redis-only: a single-server install has no Redis, and the
 * endpoints that matter here — signup, site creation, domain checks — must be
 * limited everywhere, not only on large deployments. These are low-rate actions,
 * so a row per bucket costs nothing.
 */
final class RateLimiter
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Consume one hit. Returns false when the limit for this window is spent.
     */
    public function attempt(string $bucket, int $limit, int $windowSeconds): bool
    {
        $bucket = substr($bucket, 0, 190);

        return $this->db->transaction(function (Database $db) use ($bucket, $limit, $windowSeconds): bool {
            $row = $db->selectOne(
                'SELECT bucket, window_started_at, hits FROM rate_limits WHERE bucket = ? FOR UPDATE',
                [$bucket]
            );

            if ($row === null) {
                $db->execute(
                    'INSERT INTO rate_limits (bucket, window_started_at, hits) VALUES (?, NOW(), 1)',
                    [$bucket]
                );

                return true;
            }

            $windowAge = time() - strtotime((string) $row['window_started_at']);

            if ($windowAge >= $windowSeconds) {
                $db->execute(
                    'UPDATE rate_limits SET window_started_at = NOW(), hits = 1 WHERE bucket = ?',
                    [$bucket]
                );

                return true;
            }

            if ((int) $row['hits'] >= $limit) {
                return false;
            }

            $db->execute('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?', [$bucket]);

            return true;
        });
    }

    /** Seconds until the current window for this bucket resets. */
    public function retryAfter(string $bucket, int $windowSeconds): int
    {
        $row = $this->db->selectOne('SELECT window_started_at FROM rate_limits WHERE bucket = ?', [$bucket]);

        if ($row === null) {
            return 0;
        }

        return max(0, $windowSeconds - (time() - strtotime((string) $row['window_started_at'])));
    }

    /** Housekeeping: drop windows nothing is waiting on. */
    public function prune(int $olderThanSeconds = 86400): int
    {
        return $this->db->execute(
            'DELETE FROM rate_limits WHERE window_started_at < DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [$olderThanSeconds]
        );
    }
}
