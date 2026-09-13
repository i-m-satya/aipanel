<?php

declare(strict_types=1);

namespace AIPanel\Deploy;

use AIPanel\Domain\SiteRepository;
use AIPanel\Git\GitHubApp;
use AIPanel\Git\GitHubException;
use AIPanel\Infra\Database;

/**
 * "Make it live": promote a website's sandbox to production.
 *
 * A promotion is a merge of the sandbox branch into the production branch,
 * performed by GitHub. Nothing here deploys anything — the resulting push to
 * main fires the same webhook as a human push, and production deploys through
 * the ordinary path. That keeps one delivery mechanism rather than a second,
 * privileged one that only the panel can trigger.
 */
final class PromotionService
{
    public function __construct(
        private Database $db,
        private SiteRepository $sites,
        private GitHubApp $github,
    ) {
    }

    /**
     * @param array<string,mixed> $site either environment of the website
     * @return array{state:string,detail:string,merge_commit:?string}
     */
    public function promote(array $site, int $userId): array
    {
        $production = $this->sites->productionFor($site);
        $sandbox = $this->sites->sandboxFor($site);

        if ($production === null || $sandbox === null) {
            return [
                'state' => 'failed',
                'detail' => 'This website does not have both a sandbox and a production environment.',
                'merge_commit' => null,
            ];
        }

        $installationId = (int) $production['github_installation_id'];
        $repo = (string) $production['repo'];
        $from = (string) $sandbox['deploy_branch'];
        $to = (string) $production['deploy_branch'];

        try {
            $sandboxHead = $this->github->branchHead($installationId, $repo, $from);
        } catch (GitHubException $e) {
            return ['state' => 'failed', 'detail' => $e->getMessage(), 'merge_commit' => null];
        }

        // Promote only what has actually been seen running in the sandbox: if
        // the sandbox branch has moved on since its last deploy, that newer
        // commit has never been exercised anywhere.
        if ((string) ($sandbox['live_commit'] ?? '') !== $sandboxHead) {
            return [
                'state' => 'failed',
                'detail' => sprintf(
                    'The sandbox is not running the head of %s yet (live %s, head %s). Wait for its deploy to finish, then promote.',
                    $from,
                    substr((string) ($sandbox['live_commit'] ?? 'nothing'), 0, 8),
                    substr($sandboxHead, 0, 8)
                ),
                'merge_commit' => null,
            ];
        }

        try {
            $result = $this->github->mergeBranch(
                $installationId,
                $repo,
                $to,
                $from,
                sprintf('Promote %s to production (%s)', $from, substr($sandboxHead, 0, 8))
            );
        } catch (GitHubException $e) {
            $this->record($production, $userId, $from, $to, $sandboxHead, null, 'failed', $e->getMessage());

            return ['state' => 'failed', 'detail' => $e->getMessage(), 'merge_commit' => null];
        }

        $this->record($production, $userId, $from, $to, $sandboxHead, $result['sha'], $result['state'], $result['detail']);

        return [
            'state' => $result['state'],
            'detail' => $result['state'] === 'merged'
                ? 'Promoted. Production deploys as soon as GitHub delivers the push.'
                : $result['detail'],
            'merge_commit' => $result['sha'],
        ];
    }

    /**
     * What the sandbox has that production does not — shown before promoting
     * so "make it live" is never a blind action.
     *
     * @param array<string,mixed> $site
     * @return array{ahead:bool,sandbox_commit:?string,production_commit:?string}
     */
    public function pendingChanges(array $site): array
    {
        $production = $this->sites->productionFor($site);
        $sandbox = $this->sites->sandboxFor($site);

        $sandboxCommit = $sandbox === null ? null : ($sandbox['live_commit'] ?? null);
        $productionCommit = $production === null ? null : ($production['live_commit'] ?? null);

        return [
            'ahead' => $sandboxCommit !== null && $sandboxCommit !== $productionCommit,
            'sandbox_commit' => $sandboxCommit === null ? null : (string) $sandboxCommit,
            'production_commit' => $productionCommit === null ? null : (string) $productionCommit,
        ];
    }

    /** @param array<string,mixed> $site */
    private function record(
        array $site,
        int $userId,
        string $from,
        string $to,
        string $fromCommit,
        ?string $mergeCommit,
        string $state,
        string $detail,
    ): void {
        $this->db->execute(
            'INSERT INTO promotions
                (site_id, account_id, promoted_by, from_branch, to_branch, from_commit, merge_commit, state, detail, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                (int) $site['id'],
                (int) $site['account_id'],
                $userId,
                $from,
                $to,
                $fromCommit,
                $mergeCommit,
                $state,
                $detail,
            ]
        );
    }
}
