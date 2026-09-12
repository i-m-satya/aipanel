<?php

declare(strict_types=1);

namespace AIPanel\Http;

final class Request
{
    /** @param array<string,mixed> $query @param array<string,mixed> $post @param array<string,string> $attributes */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $headers,
        public readonly string $body,
        public array $attributes = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $body = (string) file_get_contents('php://input');
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }

        return new self(
            method: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            path: rtrim($path, '/') ?: '/',
            query: $_GET,
            post: $_POST,
            headers: $headers,
            body: $body,
        );
    }

    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? $default;

        return is_string($value) ? trim($value) : $default;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function param(string $key, ?string $default = null): ?string
    {
        return $this->attributes[$key] ?? $default;
    }

    public function wantsJson(): bool
    {
        return str_contains($this->headers['accept'] ?? '', 'application/json')
            || str_contains($this->headers['content-type'] ?? '', 'application/json');
    }
}
