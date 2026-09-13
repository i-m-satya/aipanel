<?php

declare(strict_types=1);

namespace AIPanel\Http;

final class View
{
    public function __construct(private string $path)
    {
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $file = rtrim($this->path, '/') . '/' . $template . '.php';
        if (!is_readable($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }

        $data['csrf'] = Middleware\VerifyCsrf::token();
        $data['github_login'] = $_SESSION['github_login'] ?? null;

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;

        return (string) ob_get_clean();
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
