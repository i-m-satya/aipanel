<?php

declare(strict_types=1);

namespace AIPanel\Git;

/**
 * GitHub App authentication and the handful of API calls aipanel needs.
 *
 * aipanel authenticates as an App, not with a personal token: tokens are
 * per-installation, short-lived, scoped to one repository where possible, and
 * revocable by the customer at any time.
 */
final class GitHubApp
{
    private const API = 'https://api.github.com';

    /** @var array<int,array{token:string,expires:int}> */
    private array $tokenCache = [];

    public function __construct(
        private string $appId,
        private string $privateKeyPem,
        private int $timeout = 30,
    ) {
    }

    /**
     * Short-lived installation token. Cached until shortly before expiry so a
     * burst of jobs for one customer does not mint a token per job.
     */
    public function installationToken(int $installationId): string
    {
        $cached = $this->tokenCache[$installationId] ?? null;
        if ($cached !== null && $cached['expires'] > time() + 60) {
            return $cached['token'];
        }

        $response = $this->request(
            'POST',
            "/app/installations/{$installationId}/access_tokens",
            null,
            $this->appJwt()
        );

        $token = (string) ($response['token'] ?? '');
        if ($token === '') {
            throw new GitHubException('GitHub did not return an installation token.');
        }

        $this->tokenCache[$installationId] = [
            'token' => $token,
            'expires' => strtotime((string) ($response['expires_at'] ?? 'now +50 minutes')) ?: time() + 3000,
        ];

        return $token;
    }

    /** @return array<string,mixed> */
    public function repository(int $installationId, string $repo): array
    {
        return $this->request('GET', "/repos/{$repo}", null, $this->installationToken($installationId));
    }

    /** Head commit SHA of a branch — deploys always resolve to a SHA first. */
    public function branchHead(int $installationId, string $repo, string $branch = 'main'): string
    {
        $response = $this->request(
            'GET',
            "/repos/{$repo}/git/ref/heads/{$branch}",
            null,
            $this->installationToken($installationId)
        );

        $sha = (string) ($response['object']['sha'] ?? '');
        if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new GitHubException("Could not resolve {$repo}@{$branch} to a commit.");
        }

        return $sha;
    }

    /** Register the node's read-only deploy key on the repository. */
    public function addDeployKey(int $installationId, string $repo, string $title, string $publicKey): void
    {
        $this->request('POST', "/repos/{$repo}/keys", [
            'title' => $title,
            'key' => $publicKey,
            'read_only' => true,
        ], $this->installationToken($installationId));
    }

    /** @return array<string,mixed> */
    public function createPullRequest(
        int $installationId,
        string $repo,
        string $head,
        string $title,
        string $body,
        string $base = 'main',
    ): array {
        return $this->request('POST', "/repos/{$repo}/pulls", [
            'title' => $title,
            'head' => $head,
            'base' => $base,
            'body' => $body,
        ], $this->installationToken($installationId));
    }

    /**
     * Verify a webhook delivery before anything is queued from it.
     * Constant-time compare; a delivery that fails this is dropped silently.
     */
    public static function verifyWebhook(string $payload, string $signatureHeader, string $secret): bool
    {
        if (!str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256=' . hash_hmac('sha256', $payload, $secret), $signatureHeader);
    }

    /** RS256 JWT signed with the App private key, valid for ten minutes. */
    private function appJwt(): string
    {
        $header = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64Url((string) json_encode([
            'iat' => time() - 60,
            'exp' => time() + 540,
            'iss' => $this->appId,
        ]));

        $key = openssl_pkey_get_private($this->privateKeyPem);
        if ($key === false) {
            throw new GitHubException('GitHub App private key could not be read.');
        }

        $signature = '';
        if (!openssl_sign("{$header}.{$claims}", $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new GitHubException('Failed to sign the GitHub App JWT.');
        }

        return "{$header}.{$claims}." . $this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body, string $token): array
    {
        $ch = curl_init(self::API . $path);
        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: aipanel',
            "Authorization: Bearer {$token}",
        ];

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $body === null
                ? $headers
                : [...$headers, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES),
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new GitHubException("GitHub transport error: {$error}");
        }

        $decoded = json_decode((string) $raw, true);
        if ($status >= 400) {
            throw new GitHubException(sprintf(
                'GitHub %s %s failed (HTTP %d): %s',
                $method,
                $path,
                $status,
                is_array($decoded) ? (string) ($decoded['message'] ?? 'unknown') : 'unknown'
            ));
        }

        return is_array($decoded) ? $decoded : [];
    }
}
