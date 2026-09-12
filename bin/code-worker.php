<?php

declare(strict_types=1);

/**
 * aipanel code worker.
 *
 * Picks up change requests and runs the AI code agent against one site's
 * repository in an ephemeral sandbox, then opens a pull request. It never
 * touches a web node: shipping happens only when main moves and GitHub's
 * webhook fires.
 *
 * Run this on a build node, not on a node that serves tenant traffic.
 *
 * Usage: php bin/code-worker.php [--once]
 */

use AIPanel\AI\CodeAgent;
use AIPanel\AI\Sandbox;
use AIPanel\Git\GitHubApp;
use AIPanel\Infra\Database;
use AIPanel\Support\Config;

$container = require dirname(__DIR__) . '/src/bootstrap.php';

$db = $container->get(Database::class);
$github = $container->get(GitHubApp::class);
$config = $container->get(Config::class);

$once = in_array('--once', $argv, true);
$workspaceRoot = sys_get_temp_dir() . '/aipanel-code';

/** @return array{code:int,output:string} */
$git = static function (array $argv, string $cwd, array $env = []): array {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($argv, $descriptors, $pipes, $cwd, [
        'PATH' => '/usr/local/bin:/usr/bin:/bin',
        'HOME' => $cwd,
        'GIT_TERMINAL_PROMPT' => '0',
        ...$env,
    ]);
    if (!is_resource($process)) {
        return ['code' => 127, 'output' => 'could not run git'];
    }
    $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'output' => $output];
};

do {
    $request = $db->selectOne(
        "SELECT c.*, s.repo, s.github_installation_id, s.deploy_branch, s.ai_autopilot, s.domain,
                u.email AS requester_email, u.github_login AS requester_login
         FROM change_requests c
         JOIN sites s ON s.id = c.site_id
         JOIN users u ON u.id = c.requested_by
         WHERE c.state = 'queued'
         ORDER BY c.id LIMIT 1"
    );

    if ($request === null) {
        if ($once) {
            break;
        }
        sleep(5);
        continue;
    }

    $id = (int) $request['id'];
    $db->execute("UPDATE change_requests SET state = 'working' WHERE id = ?", [$id]);

    $workspace = $workspaceRoot . '/cr-' . $id . '-' . bin2hex(random_bytes(4));
    $branch = 'aipanel/cr-' . $id;

    try {
        if (!mkdir($workspace, 0o700, true) && !is_dir($workspace)) {
            throw new RuntimeException("Cannot create workspace {$workspace}");
        }

        // A token scoped to this one repository, valid for an hour.
        $token = $github->installationToken((int) $request['github_installation_id']);
        $remote = "https://x-access-token:{$token}@github.com/{$request['repo']}.git";

        $clone = $git(['git', 'clone', '--depth', '50', '--branch', (string) $request['deploy_branch'], $remote, $workspace], sys_get_temp_dir());
        if ($clone['code'] !== 0) {
            throw new RuntimeException('clone failed: ' . $clone['output']);
        }

        $git(['git', 'checkout', '-b', $branch], $workspace);
        $git(['git', 'config', 'user.name', 'aipanel'], $workspace);
        $git(['git', 'config', 'user.email', 'bot@aipanel.local'], $workspace);

        $agent = new CodeAgent(
            new Anthropic\Client(apiKey: (string) $config->get('assistant.api_key')),
            $github,
            new Sandbox($workspace),
            (string) $config->get('assistant.model', 'claude-opus-5'),
        );

        $result = $agent->applyChangeRequest(
            (string) $request['instruction'],
            (string) ($request['requester_email'] ?? $request['requester_login'])
        );

        if (!$result['ok']) {
            $reason = $result['files_changed'] === []
                ? 'the agent made no changes'
                : 'the repository\'s own checks failed';

            $db->execute(
                "UPDATE change_requests SET state = 'failed', checks_output = ?, finished_at = NOW() WHERE id = ?",
                [$reason . "\n\n" . $result['checks']['output'] . "\n\n" . $result['summary'], $id]
            );
            fwrite(STDERR, "[code-worker] change request {$id} failed: {$reason}\n");
            continue;
        }

        $git(['git', 'add', '-A'], $workspace);
        $commit = $git(['git', 'commit', '-m', $result['commit_message']], $workspace);
        if ($commit['code'] !== 0) {
            throw new RuntimeException('commit failed: ' . $commit['output']);
        }

        $sha = trim($git(['git', 'rev-parse', 'HEAD'], $workspace)['output']);

        $push = $git(['git', 'push', '-u', 'origin', $branch], $workspace);
        if ($push['code'] !== 0) {
            throw new RuntimeException('push failed: ' . $push['output']);
        }

        // A PR by default. Autopilot sites still go through the branch and the
        // checks — they just do not wait for a human to press merge.
        $pr = $github->createPullRequest(
            (int) $request['github_installation_id'],
            (string) $request['repo'],
            $branch,
            'aipanel: ' . trim(explode("\n", (string) $request['instruction'])[0]),
            sprintf(
                "%s\n\n---\nRequested by **%s** for `%s` via aipanel.\nFiles changed: %s\nChecks: %s\n",
                $result['summary'],
                (string) $request['requester_login'],
                (string) $request['domain'],
                implode(', ', $result['files_changed']),
                $result['checks']['ran'] ? ($result['checks']['passed'] ? 'passed' : 'failed') : 'no suite in repo'
            ),
            (string) $request['deploy_branch'],
        );

        $db->execute(
            "UPDATE change_requests
             SET state = 'review', branch = ?, pull_request_url = ?, commit_sha = ?, checks_output = ?, finished_at = NOW()
             WHERE id = ?",
            [$branch, (string) ($pr['html_url'] ?? ''), $sha, $result['checks']['output'], $id]
        );

        fwrite(STDOUT, sprintf("[code-worker] change request %d -> %s\n", $id, (string) ($pr['html_url'] ?? $branch)));
    } catch (Throwable $e) {
        $db->execute(
            "UPDATE change_requests SET state = 'failed', checks_output = ?, finished_at = NOW() WHERE id = ?",
            [$e->getMessage(), $id]
        );
        fwrite(STDERR, "[code-worker] change request {$id} errored: " . $e->getMessage() . "\n");
    } finally {
        // The sandbox is disposable and holds a token-bearing remote: remove it.
        if (is_dir($workspace)) {
            exec('rm -rf ' . escapeshellarg($workspace));
        }
    }
} while (!$once);
