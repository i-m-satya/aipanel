<?php

declare(strict_types=1);

namespace AIPanel\Http\Controllers;

use AIPanel\Deploy\DeployService;
use AIPanel\Git\GitHubApp;
use AIPanel\Http\Request;
use AIPanel\Http\Response;
use AIPanel\Infra\Database;
use AIPanel\Support\Crypto;
use AIPanel\Support\Env;
use AIPanel\Tenancy\RepositoryAccess;

/**
 * GitHub webhook endpoint: this is what makes "whatever lands on main is live"
 * true.
 *
 * Nothing is queued before the signature is verified, and a delivery for a
 * repository aipanel does not host is recorded and dropped — never followed.
 */
final class WebhookController
{
    public function __construct(
        private Database $db,
        private DeployService $deploys,
        private Crypto $crypto,
        private RepositoryAccess $repositories,
    ) {
    }

    public function github(Request $request): Response
    {
        $event = $request->headers['x-github-event'] ?? '';
        $deliveryId = $request->headers['x-github-delivery'] ?? bin2hex(random_bytes(8));
        $signature = $request->headers['x-hub-signature-256'] ?? '';
        $payload = json_decode($request->body, true);
        $payload = is_array($payload) ? $payload : [];

        $repo = isset($payload['repository']['full_name']) ? (string) $payload['repository']['full_name'] : null;
        $installationId = isset($payload['installation']['id']) ? (int) $payload['installation']['id'] : 0;

        // The signing secret is per installation, so a leak is scoped to one
        // customer rather than the whole fleet.
        $secret = $this->secretFor($installationId);
        if ($secret === null || !GitHubApp::verifyWebhook($request->body, $signature, $secret)) {
            $this->record($deliveryId, $event, $repo, null, null, 'bad_signature');

            // 202, not 401: a wrong signature must not tell a prober whether
            // the repo or installation exists.
            return Response::json(['received' => true], 202);
        }

        if ($event === 'ping') {
            $this->record($deliveryId, $event, $repo, null, null, 'ignored');

            return Response::json(['pong' => true]);
        }

        // Self-serve onboarding: a customer installs the GitHub App on their own
        // account, and these events are how the panel learns about it. Without
        // them every customer would need an operator to run a CLI command.
        if ($event === 'installation' || $event === 'installation_repositories') {
            $this->record($deliveryId, $event, $repo, null, null, 'ignored');

            return $this->handleInstallation($event, $payload, $installationId);
        }

        if ($event !== 'push' || $repo === null) {
            $this->record($deliveryId, $event, $repo, null, null, 'ignored');

            return Response::json(['received' => true]);
        }

        // Replayed delivery: the unique index means the second insert loses,
        // and a duplicate must not deploy twice.
        if (!$this->record($deliveryId, $event, $repo, (string) ($payload['ref'] ?? ''), $this->headCommit($payload), 'ignored')) {
            return Response::json(['received' => true, 'duplicate' => true]);
        }

        $ref = (string) ($payload['ref'] ?? '');
        $commit = $this->headCommit($payload);

        if ($commit === null || ($payload['deleted'] ?? false) === true) {
            return Response::json(['received' => true, 'reason' => 'no_commit']);
        }

        $jobIds = $this->deploys->onPush(
            repo: $repo,
            ref: $ref,
            commit: $commit,
            pusher: isset($payload['pusher']['name']) ? (string) $payload['pusher']['name'] : null,
        );

        $this->db->execute(
            'UPDATE webhook_deliveries SET outcome = ? WHERE delivery_id = ?',
            [$jobIds === [] ? 'unknown_repo' : 'deployed', $deliveryId]
        );

        return Response::json(['received' => true, 'deploys_queued' => count($jobIds)], 202);
    }

    /**
     * Link an installation to the account of the GitHub user who installed it,
     * and keep the granted-repository list in step.
     *
     * The account is resolved from the installing GitHub user id — never from a
     * name in the payload — so an installation can only ever attach to the
     * account of a user who has actually signed in here.
     *
     * @param array<string,mixed> $payload
     */
    private function handleInstallation(string $event, array $payload, int $installationId): Response
    {
        $action = (string) ($payload['action'] ?? '');
        $senderId = isset($payload['sender']['id']) ? (int) $payload['sender']['id'] : 0;
        $login = (string) ($payload['installation']['account']['login'] ?? '');

        if ($installationId === 0) {
            return Response::json(['received' => true, 'reason' => 'no_installation'], 202);
        }

        if ($action === 'deleted') {
            // The customer revoked access. Drop the link and the cached
            // repository list; their sites stop deploying, which is correct.
            $this->db->execute('DELETE FROM installation_repositories WHERE installation_id = ?', [$installationId]);
            $this->db->execute('DELETE FROM github_installations WHERE id = ?', [$installationId]);

            return Response::json(['received' => true, 'installation' => 'deleted'], 202);
        }

        $user = $this->db->selectOne('SELECT id, account_id FROM users WHERE github_id = ?', [$senderId]);
        if ($user === null) {
            // Installed by someone who has never signed in here. Nothing to
            // attach it to, and guessing an account would be a security bug.
            return Response::json(['received' => true, 'reason' => 'unknown_user'], 202);
        }

        $accountId = (int) $user['account_id'];

        $existing = $this->db->selectOne('SELECT account_id FROM github_installations WHERE id = ?', [$installationId]);
        if ($existing !== null && (int) $existing['account_id'] !== $accountId) {
            // An installation already belonging to another account must never be
            // re-pointed by a webhook: that would hand one customer another's
            // repositories.
            return Response::json(['received' => true, 'reason' => 'installation_belongs_elsewhere'], 202);
        }

        if ($existing === null) {
            // The App has one webhook secret, set in the environment; the column
            // stays for installations created by the older CLI flow.
            $this->db->execute(
                'INSERT INTO github_installations (id, account_id, github_account_login, webhook_secret_encrypted, created_at)
                 VALUES (?, ?, ?, ?, NOW())',
                [$installationId, $accountId, $login, $this->crypto->encrypt('')]
            );
        }

        $repositories = [
            ...(array) ($payload['repositories'] ?? []),
            ...(array) ($payload['repositories_added'] ?? []),
        ];
        $this->repositories->sync($installationId, $accountId, $repositories);

        foreach ((array) ($payload['repositories_removed'] ?? []) as $removed) {
            if (isset($removed['full_name'])) {
                $this->repositories->forget($installationId, (string) $removed['full_name']);
            }
        }

        return Response::json([
            'received' => true,
            'installation' => $installationId,
            'account' => $accountId,
            'repositories' => count($repositories),
        ], 202);
    }

    /** @param array<string,mixed> $payload */
    private function headCommit(array $payload): ?string
    {
        $sha = (string) ($payload['after'] ?? '');

        return preg_match('/^[0-9a-f]{40}$/', $sha) === 1 ? $sha : null;
    }

    /**
     * The GitHub App has ONE webhook secret, so that is the primary source.
     *
     * It has to be: the `installation` event that first links a customer arrives
     * before any per-installation row exists, so a per-installation secret could
     * never verify it. The stored per-installation secret remains a fallback for
     * installations created by the older CLI flow.
     */
    private function secretFor(int $installationId): ?string
    {
        $appSecret = (string) Env::get('GITHUB_APP_WEBHOOK_SECRET', '');
        if ($appSecret !== '') {
            return $appSecret;
        }

        if ($installationId === 0) {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT webhook_secret_encrypted FROM github_installations WHERE id = ?',
            [$installationId]
        );

        if ($row === null) {
            return null;
        }

        try {
            $secret = $this->crypto->decrypt((string) $row['webhook_secret_encrypted']);

            return $secret === '' ? null : $secret;
        } catch (\RuntimeException) {
            return null;
        }
    }

    /** @return bool false when this delivery id was already seen */
    private function record(
        string $deliveryId,
        string $event,
        ?string $repo,
        ?string $ref,
        ?string $commit,
        string $outcome,
    ): bool {
        try {
            $this->db->execute(
                'INSERT INTO webhook_deliveries (delivery_id, event, repo, ref, commit_sha, outcome, received_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [$deliveryId, $event, $repo, $ref, $commit, $outcome]
            );

            return true;
        } catch (\PDOException) {
            return false; // duplicate delivery
        }
    }
}
