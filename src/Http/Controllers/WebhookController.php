<?php

declare(strict_types=1);

namespace AIPanel\Http\Controllers;

use AIPanel\Deploy\DeployService;
use AIPanel\Git\GitHubApp;
use AIPanel\Http\Request;
use AIPanel\Http\Response;
use AIPanel\Infra\Database;
use AIPanel\Support\Crypto;

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

    /** @param array<string,mixed> $payload */
    private function headCommit(array $payload): ?string
    {
        $sha = (string) ($payload['after'] ?? '');

        return preg_match('/^[0-9a-f]{40}$/', $sha) === 1 ? $sha : null;
    }

    private function secretFor(int $installationId): ?string
    {
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
            return $this->crypto->decrypt((string) $row['webhook_secret_encrypted']);
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
