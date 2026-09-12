<?php

declare(strict_types=1);

namespace AIPanel\Deploy;

use AIPanel\Domain\SiteRepository;
use AIPanel\Git\GitHubApp;
use AIPanel\Infra\Database;
use AIPanel\Jobs\JobQueue;

/**
 * Turns "main moved" into a deploy job for exactly one site.
 *
 * A deploy is always pinned to a full commit SHA: resolving the branch here
 * (rather than letting the node fetch "main") means the release that ships is
 * the commit that was reviewed, even if main moves again mid-deploy.
 */
final class DeployService
{
    public function __construct(
        private Database $db,
        private SiteRepository $sites,
        private JobQueue $queue,
        private GitHubApp $github,
    ) {
    }

    /**
     * Called from the verified GitHub webhook.
     *
     * @return list<int> ids of the deploy jobs enqueued
     */
    public function onPush(string $repo, string $ref, string $commit, ?string $pusher = null): array
    {
        $sites = $this->db->select(
            'SELECT * FROM sites WHERE repo = ? AND status IN (?, ?)',
            [$repo, 'active', 'provisioning']
        );

        $jobIds = [];
        foreach ($sites as $site) {
            // Only the site's own deploy branch triggers a release. Pushes to
            // any other branch are recorded and ignored.
            if ($ref !== 'refs/heads/' . (string) $site['deploy_branch']) {
                continue;
            }

            $jobIds[] = $this->enqueueDeploy(
                site: $site,
                commit: $commit,
                trigger: 'webhook',
                triggeredBy: $pusher,
            );
        }

        return $jobIds;
    }

    /**
     * Manual or scheduled deploy: resolve the branch head via the API, then
     * queue the same job a webhook would.
     */
    public function deployLatest(array $site, ?int $userId = null): int
    {
        $commit = $this->github->branchHead(
            (int) $site['github_installation_id'],
            (string) $site['repo'],
            (string) $site['deploy_branch']
        );

        return $this->enqueueDeploy($site, $commit, 'manual', $userId === null ? null : (string) $userId, $userId);
    }

    /** @param array<string,mixed> $site */
    private function enqueueDeploy(
        array $site,
        string $commit,
        string $trigger,
        ?string $triggeredBy,
        ?int $userId = null,
    ): int {
        $jobId = $this->queue->enqueue(
            task: 'deploy.run',
            nodeId: (int) $site['node_id'],
            params: [
                'site_user' => (string) $site['site_user'],
                'repo' => (string) $site['repo'],
                'commit' => $commit,
                'run_migrations' => (bool) $site['run_migrations'],
            ],
            accountId: (int) $site['account_id'],
            requestedByUserId: $userId,
            priority: 50, // deploys jump ahead of housekeeping
        );

        $this->db->execute(
            'INSERT INTO releases (site_id, job_id, commit_sha, trigger_source, triggered_by, state, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [(int) $site['id'], $jobId, $commit, $trigger, $triggeredBy, 'queued']
        );

        return $jobId;
    }

    /**
     * Sites whose live release is not the head of their deploy branch.
     * Because the live tree is a pure function of a commit SHA, drift is a
     * simple comparison rather than a filesystem audit.
     *
     * @return list<array{domain:string,live:?string,head:string}>
     */
    public function detectDrift(): array
    {
        $drifted = [];

        foreach ($this->db->select("SELECT * FROM sites WHERE status = 'active' AND repo IS NOT NULL") as $site) {
            try {
                $head = $this->github->branchHead(
                    (int) $site['github_installation_id'],
                    (string) $site['repo'],
                    (string) $site['deploy_branch']
                );
            } catch (\RuntimeException) {
                continue; // reported separately by the node health sweep
            }

            $live = $site['live_commit'] === null ? null : (string) $site['live_commit'];
            if ($live !== $head) {
                $drifted[] = ['domain' => (string) $site['domain'], 'live' => $live, 'head' => $head];
            }
        }

        return $drifted;
    }

    /** Record the outcome of a finished deploy job against its release row. */
    public function recordResult(int $jobId, bool $ok, string $releaseId = '', string $detail = ''): void
    {
        $this->db->execute(
            'UPDATE releases SET state = ?, release_id = ?, detail = ?, finished_at = NOW() WHERE job_id = ?',
            [$ok ? 'live' : 'failed', $releaseId !== '' ? $releaseId : null, $detail, $jobId]
        );

        if ($ok) {
            $this->db->execute(
                'UPDATE sites s
                 JOIN releases r ON r.site_id = s.id AND r.job_id = ?
                 SET s.live_commit = r.commit_sha, s.live_release = r.release_id, s.deployed_at = NOW()',
                [$jobId]
            );
        }
    }
}
