<?php

declare(strict_types=1);

/**
 * aipanel node agent.
 *
 * Runs on every managed host under systemd, listening on localhost (put a TLS
 * terminator in front, and firewall it to the control plane's addresses).
 *
 * Security properties this file is responsible for:
 *   - HMAC-SHA256 over "timestamp.nonce.body" with the per-node secret
 *   - timestamp window + nonce replay cache
 *   - a FIXED handler map: an unrecognised task name is a 400, never an eval
 *   - no shell strings anywhere; every exec is proc_open with an argv array
 *
 * Usage: php aipanel-agent.php --config=/etc/aipanel/agent.json
 *        (dev: php -S 127.0.0.1:9443 aipanel-agent.php)
 */

const AGENT_VERSION = '0.1.0';

require __DIR__ . '/lib/Exec.php';
require __DIR__ . '/lib/Templates.php';
require __DIR__ . '/lib/Handlers.php';

final class Agent
{
    /** @param array<string,mixed> $config */
    public function __construct(private array $config)
    {
    }

    /** @param array<string,mixed> $config */
    public static function loadConfig(string $path): array
    {
        $defaults = [
            'secret' => '',
            'role' => 'web',
            'clock_skew' => 30,
            'nonce_dir' => '/var/lib/aipanel/nonces',
            'web_root' => '/var/www',
            'vhost_dir' => '/etc/nginx/sites-available',
            'vhost_enabled_dir' => '/etc/nginx/sites-enabled',
            'fpm_pool_dir' => '/etc/php/%s/fpm/pool.d',
            'zone_dir' => '/var/lib/bind',
            'acme_client' => '/usr/bin/certbot',
            'dry_run' => false,
        ];

        $config = $defaults;
        if (is_readable($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $config = [...$defaults, ...$decoded];
            }
        }

        // Env overrides make containerised dev runs easy.
        foreach (['AIPANEL_AGENT_SECRET' => 'secret', 'AIPANEL_AGENT_ROLE' => 'role'] as $env => $key) {
            $value = getenv($env);
            if (is_string($value) && $value !== '') {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    public function handleRequest(): void
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if ($path === '/health' && $method === 'GET') {
            $this->respond(200, ['ok' => true, 'version' => AGENT_VERSION, 'role' => $this->config['role']]);

            return;
        }

        if ($path !== '/run' || $method !== 'POST') {
            $this->respond(404, ['ok' => false, 'error' => 'not_found']);

            return;
        }

        $body = (string) file_get_contents('php://input');

        try {
            $this->authenticate($body);
        } catch (RuntimeException $e) {
            $this->respond(401, ['ok' => false, 'error' => $e->getMessage()]);

            return;
        }

        $payload = json_decode($body, true);
        if (!is_array($payload) || !is_string($payload['task'] ?? null)) {
            $this->respond(400, ['ok' => false, 'error' => 'malformed_payload']);

            return;
        }

        $task = $payload['task'];
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
        $idempotencyKey = (string) ($payload['idempotency_key'] ?? '');

        $handlers = Handlers::map();
        if (!isset($handlers[$task])) {
            $this->respond(400, ['ok' => false, 'error' => "unsupported_task:{$task}"]);

            return;
        }

        [$requiredRole, $handler] = $handlers[$task];
        if ($requiredRole !== 'any' && $requiredRole !== $this->config['role']) {
            $this->respond(400, [
                'ok' => false,
                'error' => "wrong_role: this node is '{$this->config['role']}', task needs '{$requiredRole}'",
            ]);

            return;
        }

        try {
            $result = $handler($params, $this->config, $idempotencyKey);
            $this->respond(200, [
                'ok' => true,
                'changed' => (bool) ($result['changed'] ?? false),
                'stdout' => (string) ($result['stdout'] ?? ''),
                'stderr' => (string) ($result['stderr'] ?? ''),
                'facts' => is_array($result['facts'] ?? null) ? $result['facts'] : [],
            ]);
        } catch (Throwable $e) {
            $this->respond(500, ['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private function authenticate(string $body): void
    {
        $secret = (string) $this->config['secret'];
        if ($secret === '') {
            throw new RuntimeException('agent_not_configured');
        }

        $timestamp = (string) ($_SERVER['HTTP_X_AIPANEL_TIMESTAMP'] ?? '');
        $nonce = (string) ($_SERVER['HTTP_X_AIPANEL_NONCE'] ?? '');
        $signature = (string) ($_SERVER['HTTP_X_AIPANEL_SIGNATURE'] ?? '');

        if ($timestamp === '' || $nonce === '' || $signature === '') {
            throw new RuntimeException('missing_signature_headers');
        }

        $skew = (int) $this->config['clock_skew'];
        if (abs(time() - (int) $timestamp) > $skew) {
            throw new RuntimeException('stale_timestamp');
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$nonce}.{$body}", $secret);
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('bad_signature');
        }

        $this->consumeNonce($nonce, $skew);
    }

    private function consumeNonce(string $nonce, int $skew): void
    {
        if (!preg_match('/^[a-f0-9]{16,64}$/', $nonce)) {
            throw new RuntimeException('bad_nonce');
        }

        $dir = (string) $this->config['nonce_dir'];
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new RuntimeException('nonce_store_unavailable');
        }

        // Prune anything older than twice the accepted clock skew.
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (filemtime($file) < time() - ($skew * 2)) {
                @unlink($file);
            }
        }

        $file = $dir . '/' . $nonce;
        $handle = @fopen($file, 'x');
        if ($handle === false) {
            throw new RuntimeException('replayed_nonce');
        }
        fclose($handle);
    }

    /** @param array<string,mixed> $data */
    private function respond(int $status, array $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
    }
}

$configPath = '/etc/aipanel/agent.json';
foreach ($argv ?? [] as $arg) {
    if (str_starts_with((string) $arg, '--config=')) {
        $configPath = substr((string) $arg, 9);
    }
}

(new Agent(Agent::loadConfig($configPath)))->handleRequest();
