<?php

declare(strict_types=1);

namespace AIPanel\Support;

final class Config
{
    /** @param array<string,mixed> $values */
    public function __construct(private array $values)
    {
    }

    public static function fromEnv(): self
    {
        return new self([
            'app' => [
                'env' => Env::get('APP_ENV', 'production'),
                'debug' => (bool) Env::get('APP_DEBUG', false),
                'url' => Env::get('APP_URL', 'http://localhost:8080'),
                'key' => Env::get('APP_KEY', ''),
            ],
            'db' => [
                'dsn' => Env::get('DB_DSN', 'mysql:host=127.0.0.1;dbname=aipanel;charset=utf8mb4'),
                'user' => Env::get('DB_USER', 'aipanel'),
                'pass' => Env::get('DB_PASS', ''),
            ],
            'redis' => ['dsn' => Env::get('REDIS_DSN', 'tcp://127.0.0.1:6379')],
            'agent' => [
                'timeout' => (int) Env::get('AGENT_TIMEOUT', 60),
                'clock_skew' => (int) Env::get('AGENT_CLOCK_SKEW', 30),
            ],
            'worker' => [
                'lease_seconds' => (int) Env::get('WORKER_LEASE_SECONDS', 60),
                'max_attempts' => (int) Env::get('WORKER_MAX_ATTEMPTS', 5),
                'sleep_ms' => (int) Env::get('WORKER_SLEEP_MS', 500),
                'node_max_concurrency' => (int) Env::get('NODE_MAX_CONCURRENCY', 4),
            ],
            'assistant' => [
                'enabled' => (bool) Env::get('ASSISTANT_ENABLED', false),
                'api_key' => (string) Env::get('ANTHROPIC_API_KEY', ''),
                'model' => (string) Env::get('ANTHROPIC_MODEL', 'claude-opus-5'),
            ],
        ]);
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $cursor = $this->values;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
