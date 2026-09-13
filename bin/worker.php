<?php

declare(strict_types=1);

/**
 * aipanel worker.
 *
 * Claims leased jobs and runs them. Workers are interchangeable and
 * horizontally scalable: nothing is sharded to a particular worker, and a
 * worker that dies mid-job loses its lease rather than losing the job.
 *
 * Usage: php bin/worker.php [--once] [--queue=ops|code]
 */

use AIPanel\Jobs\JobQueue;
use AIPanel\Jobs\JobRunner;
use AIPanel\Support\Config;

$container = require dirname(__DIR__) . '/src/bootstrap.php';

$config = $container->get(Config::class);
$queue = $container->get(JobQueue::class);
$runner = $container->get(JobRunner::class);

$once = in_array('--once', $argv, true);
$workerId = gethostname() . ':' . getmypid();
$sleepMicroseconds = max(50, (int) $config->get('worker.sleep_ms', 500)) * 1000;

$running = true;
$currentJobId = null;

// Finish the job in hand before exiting, so a deploy is never cut in half by a
// rolling restart.
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $signal) {
        pcntl_signal($signal, static function () use (&$running): void {
            $running = false;
            fwrite(STDERR, "[worker] shutdown requested; finishing current job\n");
        });
    }
}

fwrite(STDOUT, "[worker] {$workerId} started\n");

while ($running) {
    try {
        $job = $queue->claim($workerId);
    } catch (Throwable $e) {
        // A database blip must not kill the worker; back off and retry.
        fwrite(STDERR, '[worker] claim failed: ' . $e->getMessage() . "\n");
        usleep(2_000_000);
        continue;
    }

    if ($job === null) {
        if ($once) {
            break;
        }
        usleep($sleepMicroseconds);
        continue;
    }

    $currentJobId = (int) $job['id'];
    $started = microtime(true);

    try {
        $runner->run($job, $workerId);
        fwrite(STDOUT, sprintf(
            "[worker] job %d %s done in %.2fs\n",
            $currentJobId,
            (string) $job['task'],
            microtime(true) - $started
        ));
    } catch (Throwable $e) {
        fwrite(STDERR, sprintf("[worker] job %d failed: %s\n", $currentJobId, $e->getMessage()));
        $queue->fail($currentJobId, $e->getMessage());
    }

    $currentJobId = null;

    if ($once) {
        break;
    }
}

fwrite(STDOUT, "[worker] {$workerId} stopped\n");
