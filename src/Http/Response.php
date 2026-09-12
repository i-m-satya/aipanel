<?php

declare(strict_types=1);

namespace AIPanel\Http;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<string,mixed>|list<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json']
        );
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $this->body;
    }
}
