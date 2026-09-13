<?php

declare(strict_types=1);

namespace AIPanel\Jobs;

use AIPanel\Infra\Database;
use AIPanel\Tasks\Catalogue;
use AIPanel\Tasks\TaskValidator;

/**
 * DB-backed queue with lease semantics.
 *
 * A job is claimed by taking a time-bounded lease under
 * `FOR UPDATE SKIP LOCKED`, so any number of workers can poll the same table
 * without a broker and without ever handing one job to two workers. If a
 * worker dies mid-task the lease expires and the job becomes claimable again —
 * which is safe because every task is idempotent (see the idempotency key).
 */
final class JobQueue
{
    public function __construct(
        private Database $db,
        private TaskValidator $validator,
        private int $leaseSeconds = 60,
        private int $maxAttempts = 5,
        private int $nodeMaxConcurrency = 4,
    ) {
    }

    /**
     * @param array<string,mixed> $params
     * @throws \AIPanel\Tasks\ValidationException
     */
    public function enqueue(
        string $task,
        int $nodeId,
        array $params,
        int $accountId,
        ?int $requestedByUserId = null,
        ?string $planId = null,
        int $priority = 100,
    ): int {
        $nodeRole = $this->db->selectOne('SELECT role FROM nodes WHERE id = ?', [$nodeId])['role'] ?? null;
        $clean = $this->validator->validate($task, $params, is_string($nodeRole) ? $nodeRole : null);

        $idempotencyKey = $this->idempotencyKey($task, $nodeId, $clean);

        $this->db->execute(
            "INSERT INTO jobs
                (account_id, node_id, task, params, idempotency_key, plan_id, requested_by,
                 priority, state, attempts, run_after, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'queued', 0, NOW(), NOW())",
            [
                $accountId,
                $nodeId,
                $task,
                json_encode($clean, JSON_UNESCAPED_SLASHES),
                $idempotencyKey,
                $planId,
                $requestedByUserId,
                Catalogue::isDestructive($task) ? $priority + 10 : $priority,
            ]
        );

        return $this->db->lastInsertId();
    }

    /**
     * Claim the next runnable job, respecting the per-node concurrency cap so a
     * large worker pool cannot stampede a single host.
     *
     * @return array<string,mixed>|null
     */
    public function claim(string $workerId): ?array
    {
        return $this->db->transaction(function (Database $db) use ($workerId): ?array {
            $candidate = $db->selectOne(
                "SELECT j.id
                 FROM jobs j
                 WHERE j.state = 'queued'
                   AND j.run_after <= NOW()
                   AND (
                       SELECT COUNT(*) FROM jobs r
                       WHERE r.node_id = j.node_id
                         AND r.state = 'running'
                         AND r.lease_expires_at > NOW()
                   ) < ?
                 ORDER BY j.priority ASC, j.id ASC
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED",
                [$this->nodeMaxConcurrency]
            );

            if ($candidate === null) {
                return null;
            }

            $db->execute(
                "UPDATE jobs
                 SET state = 'running',
                     lease_owner = ?,
                     lease_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                     attempts = attempts + 1,
                     started_at = COALESCE(started_at, NOW())
                 WHERE id = ?",
                [$workerId, $this->leaseSeconds, $candidate['id']]
            );

            return $db->selectOne('SELECT * FROM jobs WHERE id = ?', [$candidate['id']]);
        });
    }

    public function extendLease(int $jobId, string $workerId): void
    {
        $this->db->execute(
            'UPDATE jobs SET lease_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
             WHERE id = ? AND lease_owner = ?',
            [$this->leaseSeconds, $jobId, $workerId]
        );
    }

    /** @param array<string,mixed> $result */
    public function complete(int $jobId, array $result): void
    {
        $this->db->execute(
            "UPDATE jobs
             SET state = 'done', result = ?, lease_owner = NULL, lease_expires_at = NULL, finished_at = NOW()
             WHERE id = ?",
            [json_encode($result, JSON_UNESCAPED_SLASHES), $jobId]
        );
    }

    /**
     * Reschedule with exponential backoff, or bury the job once it has burned
     * through its attempts.
     */
    public function fail(int $jobId, string $error): void
    {
        $job = $this->db->selectOne('SELECT attempts FROM jobs WHERE id = ?', [$jobId]);
        $attempts = (int) ($job['attempts'] ?? $this->maxAttempts);

        if ($attempts >= $this->maxAttempts) {
            $this->db->execute(
                "UPDATE jobs
                 SET state = 'failed', error = ?, lease_owner = NULL, lease_expires_at = NULL, finished_at = NOW()
                 WHERE id = ?",
                [$error, $jobId]
            );

            return;
        }

        $backoff = min(3600, 2 ** $attempts * 15);
        $this->db->execute(
            "UPDATE jobs
             SET state = 'queued', error = ?, lease_owner = NULL, lease_expires_at = NULL,
                 run_after = DATE_ADD(NOW(), INTERVAL ? SECOND)
             WHERE id = ?",
            [$error, $backoff, $jobId]
        );
    }

    /**
     * Return jobs whose lease expired (worker crashed) to the queue. Called by
     * the scheduler, not by workers.
     */
    public function reclaimExpiredLeases(): int
    {
        return $this->db->execute(
            "UPDATE jobs
             SET state = 'queued', lease_owner = NULL, lease_expires_at = NULL
             WHERE state = 'running' AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()"
        );
    }

    /** @return list<array<string,mixed>> */
    public function recentForAccount(int $accountId, int $limit = 50): array
    {
        return $this->db->select(
            'SELECT id, task, node_id, state, attempts, error, created_at, finished_at
             FROM jobs WHERE account_id = ? ORDER BY id DESC LIMIT ?',
            [$accountId, $limit]
        );
    }

    public function depthForNode(int $nodeId): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS depth FROM jobs WHERE node_id = ? AND state IN ('queued','running')",
            [$nodeId]
        );

        return (int) ($row['depth'] ?? 0);
    }

    /** @param array<string,mixed> $params */
    private function idempotencyKey(string $task, int $nodeId, array $params): string
    {
        ksort($params);

        return hash('sha256', $task . '|' . $nodeId . '|' . json_encode($params, JSON_UNESCAPED_SLASHES));
    }
}
