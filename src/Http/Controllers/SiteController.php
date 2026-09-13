<?php

declare(strict_types=1);

namespace AIPanel\Http\Controllers;

use AIPanel\Deploy\DeployService;
use AIPanel\Deploy\PromotionService;
use AIPanel\Domain\NodeRepository;
use AIPanel\Domain\SiteRepository;
use AIPanel\Git\GitHubApp;
use AIPanel\Http\Request;
use AIPanel\Http\Response;
use AIPanel\Http\View;
use AIPanel\Infra\Database;
use AIPanel\Jobs\JobQueue;
use AIPanel\Tasks\ValidationException;

final class SiteController
{
    public function __construct(
        private Database $db,
        private SiteRepository $sites,
        private NodeRepository $nodes,
        private JobQueue $queue,
        private DeployService $deploys,
        private PromotionService $promotions,
        private GitHubApp $github,
        private View $view,
    ) {
    }

    public function index(Request $request): Response
    {
        $accountId = (int) $request->param('account_id');
        $sites = $this->sites->forAccount($accountId);

        if ($request->wantsJson()) {
            return Response::json(['sites' => $sites]);
        }

        return Response::html($this->view->render('sites/index', [
            'sites' => $sites,
            'nodes' => $this->nodes->withRole('web'),
        ]));
    }

    public function show(Request $request): Response
    {
        $accountId = (int) $request->param('account_id');
        $site = $this->sites->findForAccount((int) $request->param('id'), $accountId);
        if ($site === null) {
            return Response::json(['error' => 'not_found'], 404);
        }

        $sandbox = $this->sites->sandboxFor($site);

        $releases = $this->db->select(
            'SELECT r.*, s.environment
             FROM releases r JOIN sites s ON s.id = r.site_id
             WHERE r.site_id IN (?, ?) ORDER BY r.id DESC LIMIT 20',
            [(int) $site['id'], (int) ($sandbox['id'] ?? $site['id'])]
        );
        $keys = $this->db->select(
            'SELECT id, label, fingerprint, created_at FROM ssh_keys WHERE site_id = ? AND revoked_at IS NULL',
            [(int) $site['id']]
        );

        $pending = $this->promotions->pendingChanges($site);

        if ($request->wantsJson()) {
            return Response::json([
                'site' => $site,
                'sandbox' => $sandbox,
                'pending_promotion' => $pending,
                'releases' => $releases,
                'ssh_keys' => $keys,
            ]);
        }

        return Response::html($this->view->render('sites/show', [
            'site' => $site,
            'sandbox' => $sandbox,
            'pending' => $pending,
            'releases' => $releases,
            'keys' => $keys,
        ]));
    }

    /**
     * Provisioning a website creates two tenants, not one:
     *
     *   sandbox.<domain>  ← the sandbox branch
     *   <domain>          ← the production branch
     *
     * They are ordinary, fully isolated tenants — separate Linux users, pools
     * and homes — so a broken sandbox cannot reach the live site. Pushing to
     * the sandbox branch updates only the sandbox; promoting is a merge into
     * the production branch, which deploys production through the same webhook
     * as any other push.
     */
    public function create(Request $request): Response
    {
        $accountId = (int) $request->param('account_id');
        $userId = (int) $request->param('user_id');

        $domain = strtolower((string) $request->input('domain', ''));
        $repo = (string) $request->input('repo', '');
        $nodeId = (int) $request->input('node_id', '0');
        $phpVersion = (string) $request->input('php_version', '8.3');
        $documentRoot = (string) $request->input('document_root', 'public');
        $productionBranch = (string) $request->input('deploy_branch', 'main');
        $sandboxBranch = (string) $request->input('sandbox_branch', 'sandbox');

        $installation = $this->db->selectOne(
            'SELECT id FROM github_installations WHERE account_id = ? ORDER BY id LIMIT 1',
            [$accountId]
        );
        if ($installation === null) {
            return $this->error($request, 'Install the aipanel GitHub App on your organisation first.', 422);
        }

        $sandboxDomain = 'sandbox.' . $domain;

        foreach ([$domain, $sandboxDomain] as $candidate) {
            if ($this->sites->findByDomainForAccount($candidate, $accountId) !== null) {
                return $this->error($request, "{$candidate} already exists in this account.", 422);
            }
        }

        try {
            // Fail before provisioning anything if the repo is not reachable
            // through the customer's installation.
            $this->github->repository((int) $installation['id'], $repo);

            $deployKey = $this->deployPublicKeyFor($nodeId);

            // Both rows are written in one transaction: a website with only
            // one of its two environments is not a state worth having.
            [$siteId, $productionUser, $sandboxId, $sandboxUser] = $this->db->transaction(
                function (Database $db) use (
                    $accountId, $nodeId, $domain, $sandboxDomain, $repo, $installation,
                    $phpVersion, $documentRoot, $productionBranch, $sandboxBranch
                ): array {
                    $productionUser = 'site_' . bin2hex(random_bytes(4));
                    $sandboxUser = 'site_' . bin2hex(random_bytes(4));

                    $siteId = $this->sites->createEnvironment([
                        'account_id' => $accountId,
                        'node_id' => $nodeId,
                        'domain' => $domain,
                        'environment' => 'production',
                        'parent_site_id' => null,
                        'site_user' => $productionUser,
                        'repo' => $repo,
                        'github_installation_id' => (int) $installation['id'],
                        'deploy_branch' => $productionBranch,
                        'document_root' => $documentRoot,
                        'php_version' => $phpVersion,
                    ]);

                    $sandboxId = $this->sites->createEnvironment([
                        'account_id' => $accountId,
                        'node_id' => $nodeId,
                        'domain' => $sandboxDomain,
                        'environment' => 'sandbox',
                        'parent_site_id' => $siteId,
                        'site_user' => $sandboxUser,
                        'repo' => $repo,
                        'github_installation_id' => (int) $installation['id'],
                        'deploy_branch' => $sandboxBranch,
                        'document_root' => $documentRoot,
                        'php_version' => $phpVersion,
                    ]);

                    return [$siteId, $productionUser, $sandboxId, $sandboxUser];
                }
            );

            foreach ([[$domain, $productionUser], [$sandboxDomain, $sandboxUser]] as [$envDomain, $envUser]) {
                $this->queue->enqueue(
                    task: 'site.create',
                    nodeId: $nodeId,
                    params: [
                        'domain' => $envDomain,
                        'site_user' => $envUser,
                        'repo' => $repo,
                        'deploy_public_key' => $deployKey,
                        'php_version' => $phpVersion,
                        'document_root' => $documentRoot,
                    ],
                    accountId: $accountId,
                    requestedByUserId: $userId,
                    priority: 40,
                );
            }
        } catch (ValidationException $e) {
            return $this->error($request, implode('; ', $e->errors), 422);
        } catch (\RuntimeException $e) {
            return $this->error($request, $e->getMessage(), 422);
        }

        return $request->wantsJson()
            ? Response::json([
                'site_id' => $siteId,
                'production' => ['domain' => $domain, 'site_user' => $productionUser, 'branch' => $productionBranch],
                'sandbox' => ['id' => $sandboxId, 'domain' => $sandboxDomain, 'site_user' => $sandboxUser, 'branch' => $sandboxBranch],
                'status' => 'provisioning',
            ], 202)
            : Response::redirect("/sites/{$siteId}");
    }

    /**
     * "Make it live": merge the sandbox branch into the production branch.
     *
     * This deploys nothing itself — GitHub performs the merge, and the
     * resulting push to the production branch deploys through the same webhook
     * as any other push.
     */
    public function promote(Request $request): Response
    {
        $site = $this->sites->findForAccount((int) $request->param('id'), (int) $request->param('account_id'));
        if ($site === null) {
            return Response::json(['error' => 'not_found'], 404);
        }

        $result = $this->promotions->promote($site, (int) $request->param('user_id'));

        if ($request->wantsJson()) {
            return Response::json($result, $result['state'] === 'failed' ? 422 : 202);
        }

        return $result['state'] === 'failed'
            ? $this->error($request, $result['detail'], 422)
            : Response::redirect('/sites/' . (int) $site['id']);
    }

    /** Deploy whatever is currently on the site's deploy branch. */
    public function deploy(Request $request): Response
    {
        $site = $this->sites->findForAccount((int) $request->param('id'), (int) $request->param('account_id'));
        if ($site === null) {
            return Response::json(['error' => 'not_found'], 404);
        }

        try {
            $jobId = $this->deploys->deployLatest($site, (int) $request->param('user_id'));
        } catch (\RuntimeException $e) {
            return $this->error($request, $e->getMessage(), 422);
        }

        return $request->wantsJson()
            ? Response::json(['job_id' => $jobId, 'state' => 'queued'], 202)
            : Response::redirect("/sites/{$site['id']}");
    }

    public function rollback(Request $request): Response
    {
        $site = $this->sites->findForAccount((int) $request->param('id'), (int) $request->param('account_id'));
        if ($site === null) {
            return Response::json(['error' => 'not_found'], 404);
        }

        try {
            $jobId = $this->queue->enqueue(
                task: 'deploy.rollback',
                nodeId: (int) $site['node_id'],
                params: ['site_user' => (string) $site['site_user']],
                accountId: (int) $site['account_id'],
                requestedByUserId: (int) $request->param('user_id'),
                priority: 40,
            );
        } catch (ValidationException $e) {
            return $this->error($request, implode('; ', $e->errors), 422);
        }

        return $request->wantsJson()
            ? Response::json(['job_id' => $jobId, 'state' => 'queued'], 202)
            : Response::redirect("/sites/{$site['id']}");
    }

    /** Authorise an SSH key for one site — it can reach that site's jail only. */
    public function addSshKey(Request $request): Response
    {
        $site = $this->sites->findForAccount((int) $request->param('id'), (int) $request->param('account_id'));
        if ($site === null) {
            return Response::json(['error' => 'not_found'], 404);
        }

        $publicKey = trim((string) $request->input('public_key', ''));
        $label = (string) $request->input('label', 'ssh key');

        try {
            $this->queue->enqueue(
                task: 'ssh.key_add',
                nodeId: (int) $site['node_id'],
                params: ['site_user' => (string) $site['site_user'], 'public_key' => $publicKey, 'label' => $label],
                accountId: (int) $site['account_id'],
                requestedByUserId: (int) $request->param('user_id'),
            );
        } catch (ValidationException $e) {
            return $this->error($request, implode('; ', $e->errors), 422);
        }

        $this->db->execute(
            'INSERT INTO ssh_keys (site_id, label, fingerprint, public_key, created_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE label = VALUES(label), revoked_at = NULL',
            [(int) $site['id'], $label, $this->fingerprint($publicKey), $publicKey]
        );

        return $request->wantsJson()
            ? Response::json(['state' => 'queued'], 202)
            : Response::redirect("/sites/{$site['id']}");
    }

    private function fingerprint(string $publicKey): string
    {
        if (preg_match('#(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp256) ([A-Za-z0-9+/=]{32,})#', $publicKey, $m) !== 1) {
            return '';
        }
        $raw = base64_decode($m[2], true);

        return $raw === false ? '' : 'SHA256:' . rtrim(base64_encode(hash('sha256', $raw, true)), '=');
    }

    /**
     * The node's own read-only deploy key. One key per node, registered on the
     * repo, so a node can fetch the repos it hosts and nothing else.
     */
    private function deployPublicKeyFor(int $nodeId): string
    {
        $node = $this->nodes->find($nodeId);
        $key = is_array($node) ? (string) ($node['deploy_public_key'] ?? '') : '';
        if ($key === '') {
            throw new \RuntimeException('This node has no deploy key yet; run `php bin/console.php node:deploy-key` for it.');
        }

        return $key;
    }

    private function error(Request $request, string $message, int $status): Response
    {
        return $request->wantsJson()
            ? Response::json(['error' => $message], $status)
            : Response::html($this->view->render('error', ['message' => $message]), $status);
    }
}
