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
use AIPanel\Support\Config;
use AIPanel\Jobs\JobQueue;
use AIPanel\Tasks\ValidationException;
use AIPanel\Tenancy\AccessDeniedException;
use AIPanel\Tenancy\DomainVerifier;
use AIPanel\Tenancy\Quota;
use AIPanel\Tenancy\RateLimiter;
use AIPanel\Tenancy\RepositoryAccess;

final class SiteController
{
    public function __construct(
        private Database $db,
        private Config $config,
        private SiteRepository $sites,
        private NodeRepository $nodes,
        private JobQueue $queue,
        private DeployService $deploys,
        private PromotionService $promotions,
        private GitHubApp $github,
        private RepositoryAccess $repositories,
        private DomainVerifier $domains,
        private Quota $quota,
        private RateLimiter $limiter,
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

        $domain = DomainVerifier::normalise((string) $request->input('domain', ''));
        $repo = RepositoryAccess::normalise((string) $request->input('repo', ''));
        $nodeId = (int) $request->input('node_id', '0');
        $phpVersion = (string) $request->input('php_version', '8.3');
        $documentRoot = (string) $request->input('document_root', 'public');
        $productionBranch = (string) $request->input('deploy_branch', 'main');
        $sandboxBranch = (string) $request->input('sandbox_branch', 'sandbox');

        // Cheap to ask for, expensive to run: rate-limit before doing any work.
        if (!$this->limiter->attempt("site-create:{$accountId}", limit: 10, windowSeconds: 3600)) {
            return $this->error($request, 'Too many websites created recently. Try again later.', 429);
        }

        $quota = $this->quota->canCreateSite($accountId);
        if (!$quota['allowed']) {
            return $this->error($request, (string) $quota['reason'], 422);
        }

        // --- Ownership, before anything is provisioned -----------------------
        //
        // On a shared instance these two checks are what stop one customer
        // taking another's hostname or deploying another's repository. They run
        // before a row is written, not after.

        if (DomainVerifier::isReserved($domain, (string) $this->config->get('app.url'))) {
            return $this->error($request, 'That hostname cannot be used here.', 422);
        }

        $sandboxDomain = 'sandbox.' . $domain;

        foreach ([$domain, $sandboxDomain] as $candidate) {
            if ($this->domains->claimedByAnotherAccount($accountId, $candidate)) {
                // Deliberately vague: whether another customer holds a hostname
                // is not this customer's business.
                return $this->error($request, "{$candidate} is not available.", 409);
            }
            if ($this->sites->findByDomainForAccount($candidate, $accountId) !== null) {
                return $this->error($request, "{$candidate} already exists in this account.", 422);
            }
        }

        if (!$this->domains->isCovered($accountId, $domain)) {
            $challenge = $this->domains->challenge($accountId, $domain);

            return $request->wantsJson()
                ? Response::json([
                    'error' => 'domain_not_verified',
                    'verify' => $challenge,
                    'detail' => 'Publish this TXT record, then check verification and try again.',
                ], 409)
                : Response::html($this->view->render('sites/verify', [
                    'challenge' => $challenge,
                    'repo' => $repo,
                ]), 409);
        }

        try {
            // Which of this account's installations actually grants the repo?
            // Asking GitHub is the only trustworthy answer.
            $installationId = $this->repositories->installationGranting($accountId, $repo);
        } catch (AccessDeniedException $e) {
            return $this->error($request, $e->getMessage(), 403);
        }

        $installation = ['id' => $installationId];

        // A first site from an untrusted account waits for a human, so abuse is
        // never fully self-serve.
        $needsApproval = $this->siteNeedsApproval($accountId);

        try {
            $deployKey = $this->deployPublicKeyFor($nodeId);

            // Authorise this node to fetch the repository. Without it the first
            // deploy fails at git clone with a permission error that looks like
            // a panel bug rather than a missing key.
            try {
                $this->github->addDeployKey(
                    (int) $installation['id'],
                    $repo,
                    'aipanel node ' . $nodeId,
                    $deployKey
                );
            } catch (\RuntimeException $e) {
                // GitHub rejects a key that is already on the repo, which is the
                // normal case for the second and later sites on a node.
                if (!str_contains($e->getMessage(), 'already in use')
                    && !str_contains($e->getMessage(), 'key is already')) {
                    throw $e;
                }
            }

            // Both rows are written in one transaction: a website with only
            // one of its two environments is not a state worth having.
            [$siteId, $productionUser, $sandboxId, $sandboxUser] = $this->db->transaction(
                function (Database $db) use (
                    $accountId, $nodeId, $domain, $sandboxDomain, $repo, $installation,
                    $phpVersion, $documentRoot, $productionBranch, $sandboxBranch
                ): array {
                    $productionUser = 'site_' . bin2hex(random_bytes(4));
                    $sandboxUser = 'site_' . bin2hex(random_bytes(4));

                    $approvalState = $needsApproval ? 'pending' : 'approved';

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
                        'approval_state' => $approvalState,
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
                        'approval_state' => $approvalState,
                    ]);

                    return [$siteId, $productionUser, $sandboxId, $sandboxUser];
                }
            );

            // Nothing reaches a server until the site is approved. The rows
            // exist so the customer can see what is pending.
            foreach ($needsApproval ? [] : [[$domain, $productionUser], [$sandboxDomain, $sandboxUser]] as [$envDomain, $envUser]) {
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
                'status' => $needsApproval ? 'awaiting_approval' : 'provisioning',
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
        $site = $this->actionableSite($request);
        if ($site instanceof Response) {
            return $site;
        }

        $result = $this->promotions->promote($site, (int) $request->param('user_id'));

        if ($request->wantsJson()) {
            return Response::json($result, $result['state'] === 'failed' ? 422 : 202);
        }

        return $result['state'] === 'failed'
            ? $this->error($request, $result['detail'], 422)
            : Response::redirect('/sites/' . (int) $site['id']);
    }

    /**
     * Fetch a site for an action, refusing one that is not approved.
     *
     * The approval gate has to hold on every path, not just at creation: these
     * routes take a site id, and a pending site still has rows.
     *
     * @return array<string,mixed>|Response
     */
    private function actionableSite(Request $request): array|Response
    {
        $site = $this->sites->findForAccount((int) $request->param('id'), (int) $request->param('account_id'));

        if ($site === null) {
            return Response::json(['error' => 'not_found'], 404);
        }

        if (!$this->sites->isDeployable($site)) {
            return $this->error(
                $request,
                $site['approval_state'] === 'pending'
                    ? 'This website is waiting for approval.'
                    : 'This website was not approved' . ($site['rejected_reason'] ? ': ' . $site['rejected_reason'] : '.'),
                409
            );
        }

        return $site;
    }

    /** Deploy whatever is currently on the site's deploy branch. */
    public function deploy(Request $request): Response
    {
        $site = $this->actionableSite($request);
        if ($site instanceof Response) {
            return $site;
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
        $site = $this->actionableSite($request);
        if ($site instanceof Response) {
            return $site;
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
        $site = $this->actionableSite($request);
        if ($site instanceof Response) {
            return $site;
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

    /**
     * Does a new site from this account need a human to approve it?
     *
     * Policy lives in settings so an operator can open or close the gate without
     * a deploy: 'untrusted' (the shared-hosting default) approves nothing from an
     * untrusted account; 'always' approves nothing from anyone; 'never' is the
     * single-operator case.
     */
    private function siteNeedsApproval(int $accountId): bool
    {
        $row = $this->db->selectOne("SELECT value FROM settings WHERE name = 'site_approval'");
        $policy = (string) ($row['value'] ?? 'untrusted');

        return match ($policy) {
            'never' => false,
            'always' => true,
            default => !$this->quota->isTrusted($accountId),
        };
    }

    /** Re-check a domain's TXT record on demand. */
    public function verifyDomain(Request $request): Response
    {
        $accountId = (int) $request->param('account_id');
        $domain = DomainVerifier::normalise((string) $request->input('domain', ''));

        // DNS lookups are cheap for us and a nuisance to resolvers: cap them.
        if (!$this->limiter->attempt("domain-check:{$accountId}", limit: 30, windowSeconds: 600)) {
            return Response::json(['error' => 'Too many verification attempts. Try again shortly.'], 429);
        }

        $challenge = $this->domains->challenge($accountId, $domain);
        $result = $this->domains->check($accountId, $domain);

        return $request->wantsJson()
            ? Response::json(['verified' => $result['verified'], 'error' => $result['error'], 'verify' => $challenge])
            : Response::redirect('/sites?verified=' . ($result['verified'] ? '1' : '0'));
    }

    private function error(Request $request, string $message, int $status): Response
    {
        return $request->wantsJson()
            ? Response::json(['error' => $message], $status)
            : Response::html($this->view->render('error', ['message' => $message]), $status);
    }
}
