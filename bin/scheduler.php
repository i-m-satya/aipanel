<?php

declare(strict_types=1);

/**
 * aipanel scheduler.
 *
 * Cron-like work that must happen exactly once across N control-plane
 * replicas: expired lease reclamation, node fact refresh, certificate renewal
 * sweeps and deploy drift detection. Leadership is a short lease in the
 * leader_locks table, renewed each tick, so a dead leader is replaced within
 * one lease period without any coordination service.
 *
 * Usage: php bin/scheduler.php [--once]
 */

use AIPanel\Deploy\DeployService;
use AIPanel\Domain\NodeRepository;
use AIPanel\Infra\Database;
use AIPanel\Jobs\JobQueue;

$container = require dirname(__DIR__) . '/src/bootstrap.php';

$db = $container->get(Database::class);
$queue = $container->get(JobQueue::class);
$nodes = $container->get(NodeRepository::class);
$deploys = $container->get(DeployService::class);

$me = gethostname() . ':' . getmypid();
$once = in_array('--once', $argv, true);
$leaseSeconds = 60;

/** Take or renew the scheduler lease. Only the holder does any work. */
$acquireLeadership = static function () use ($db, $me, $leaseSeconds): bool {
    $db->execute(
        "INSERT INTO leader_locks (name, owner, expires_at)
         VALUES ('scheduler', ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
         ON DUPLICATE KEY UPDATE
            owner = IF(expires_at < NOW() OR owner = VALUES(owner), VALUES(owner), owner),
            expires_at = IF(owner = VALUES(owner), VALUES(expires_at), expires_at)",
        [$me, $leaseSeconds]
    );

    $row = $db->selectOne("SELECT owner FROM leader_locks WHERE name = 'scheduler'");

    return ($row['owner'] ?? '') === $me;
};

$tick = 0;
do {
    if (!$acquireLeadership()) {
        fwrite(STDOUT, "[scheduler] not the leader; standing by\n");
        $once ? exit(0) : sleep(30);
        continue;
    }

    // 1. Jobs whose worker died — the lease expired, so requeue them.
    $reclaimed = $queue->reclaimExpiredLeases();
    if ($reclaimed > 0) {
        fwrite(STDOUT, "[scheduler] reclaimed {$reclaimed} expired job lease(s)\n");
    }

    // 2. Refresh node facts so dashboards never have to touch a live node.
    foreach ($nodes->all() as $node) {
        try {
            $queue->enqueue(
                task: 'node.facts',
                nodeId: (int) $node['id'],
                params: [],
                accountId: 0,
                priority: 200, // housekeeping yields to tenant work
            );
        } catch (Throwable $e) {
            fwrite(STDERR, '[scheduler] fact refresh: ' . $e->getMessage() . "\n");
        }
    }

    // 3. Report sites whose live release is behind their deploy branch.
    if ($tick % 10 === 0) {
        foreach ($deploys->detectDrift() as $drift) {
            fwrite(STDOUT, sprintf(
                "[scheduler] drift: %s live=%s head=%s\n",
                $drift['domain'],
                $drift['live'] ?? 'none',
                substr($drift['head'], 0, 8)
            ));
        }
    }

    $tick++;
    $once ?: sleep(30);
} while (!$once);
