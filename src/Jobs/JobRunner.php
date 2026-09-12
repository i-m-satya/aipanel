<?php

declare(strict_types=1);

namespace AIPanel\Jobs;

use AIPanel\Domain\NodeRepository;
use AIPanel\Domain\SiteRepository;
use AIPanel\Infra\Database;
use AIPanel\Nodes\AgentClient;
use AIPanel\Nodes\AgentException;

/**
 * Executes one claimed job: dispatch the task to its node's agent, record the
 * transcript in the audit log, and reconcile panel state with the result.
 */
final class JobRunner
{
    public function __construct(
        private Database $db,
        private JobQueue $queue,
        private NodeRepository $nodes,
        private SiteRepository $sites,
        private AgentClient $agent,
    ) {
    }

    /** @param array<string,mixed> $job */
    public function run(array $job, string $workerId): void
    {
        $jobId = (int) $job['id'];
        $nodeId = (int) $job['node_id'];
        $task = (string) $job['task'];
        $params = json_decode((string) $job['params'], true);
        $params = is_array($params) ? $params : [];

        $node = $this->nodes->find($nodeId);
        if ($node === null) {
            $this->queue->fail($jobId, "node {$nodeId} no longer exists");

            return;
        }

        try {
            $result = $this->agent->run(
                endpoint: (string) $node['endpoint'],
                secret: $this->nodes->secretFor($nodeId),
                task: $task,
                params: $params,
                idempotencyKey: (string) $job['idempotency_key'],
            );
        } catch (AgentException $e) {
            $this->audit($job, 'error', ['error' => $e->getMessage()]);
            if (str_contains($e->getMessage(), 'transport error')) {
                $this->nodes->markUnreachable($nodeId);
            }
            $this->queue->fail($jobId, $e->getMessage());

            return;
        }

        $this->reconcile($task, $params, $result, (int) $job['account_id'], $nodeId);
        $this->audit($job, 'ok', $result);
        $this->queue->complete($jobId, $result);
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $result
     */
    private function reconcile(string $task, array $params, array $result, int $accountId, int $nodeId): void
    {
        switch ($task) {
            case 'node.facts':
                $this->nodes->recordFacts($nodeId, $result['facts']);
                break;

            case 'site.create':
                $site = $this->sites->findByDomainForAccount((string) $params['domain'], $accountId);
                if ($site !== null) {
                    $this->sites->setStatus((int) $site['id'], 'active');
                }
                break;

            case 'site.delete':
                $site = $this->sites->findByDomainForAccount((string) $params['domain'], $accountId);
                if ($site !== null) {
                    $this->sites->delete((int) $site['id']);
                }
                break;

            case 'site.set_php_version':
                $this->db->execute(
                    'UPDATE sites SET php_version = ? WHERE domain = ? AND account_id = ?',
                    [(string) $params['php_version'], (string) $params['domain'], $accountId]
                );
                break;

            case 'ssl.issue':
                $this->db->execute(
                    'UPDATE sites SET ssl_status = ?, ssl_renewed_at = NOW() WHERE domain = ? AND account_id = ?',
                    ['active', (string) $params['domain'], $accountId]
                );
                break;
        }
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $detail
     */
    private function audit(array $job, string $outcome, array $detail): void
    {
        $this->db->execute(
            'INSERT INTO audit_log (account_id, user_id, job_id, node_id, task, params, outcome, detail, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                (int) $job['account_id'],
                $job['requested_by'] !== null ? (int) $job['requested_by'] : null,
                (int) $job['id'],
                (int) $job['node_id'],
                (string) $job['task'],
                (string) $job['params'],
                $outcome,
                json_encode($detail, JSON_UNESCAPED_SLASHES),
            ]
        );
    }
}
