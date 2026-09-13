<?php

declare(strict_types=1);

namespace AIPanel\Nodes;

/**
 * Transport to a node agent.
 *
 * Requests are signed with HMAC-SHA256 over "timestamp.nonce.body" using the
 * per-node secret; the agent rejects stale timestamps and replayed nonces.
 */
final class AgentClient
{
    public function __construct(
        private int $timeout = 60,
    ) {
    }

    /**
     * @param array<string,mixed> $params
     * @return array{ok:bool,changed:bool,stdout:string,stderr:string,facts:array<string,mixed>}
     */
    public function run(
        string $endpoint,
        string $secret,
        string $task,
        array $params,
        string $idempotencyKey,
    ): array {
        $payload = (string) json_encode([
            'task' => $task,
            'params' => $params,
            'idempotency_key' => $idempotencyKey,
        ], JSON_UNESCAPED_SLASHES);

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', "{$timestamp}.{$nonce}.{$payload}", $secret);

        $ch = curl_init(rtrim($endpoint, '/') . '/run');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "X-AIPanel-Timestamp: {$timestamp}",
                "X-AIPanel-Nonce: {$nonce}",
                "X-AIPanel-Signature: {$signature}",
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new AgentException("Agent transport error for task '{$task}': {$error}");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new AgentException("Agent returned a non-JSON response (HTTP {$status}).");
        }

        if ($status !== 200 || ($decoded['ok'] ?? false) !== true) {
            throw new AgentException(sprintf(
                "Agent refused task '%s' (HTTP %d): %s",
                $task,
                $status,
                (string) ($decoded['error'] ?? $decoded['stderr'] ?? 'unknown error')
            ));
        }

        return [
            'ok' => true,
            'changed' => (bool) ($decoded['changed'] ?? false),
            'stdout' => (string) ($decoded['stdout'] ?? ''),
            'stderr' => (string) ($decoded['stderr'] ?? ''),
            'facts' => is_array($decoded['facts'] ?? null) ? $decoded['facts'] : [],
        ];
    }
}
