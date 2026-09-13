<?php

declare(strict_types=1);

namespace AIPanel\Support;

/**
 * Minimal .env loader. Real deployments should set real environment variables;
 * the file is a development convenience.
 */
final class Env
{
    private static array $vars = [];

    public static function load(string $path): void
    {
        if (!is_readable($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // strip inline comment on unquoted values
            if (!str_starts_with($value, '"') && !str_starts_with($value, "'")) {
                $value = trim(preg_replace('/\s+#.*$/', '', $value));
            }
            $value = trim($value, "\"'");
            self::$vars[$key] = $value;
        }
    }

    public static function get(string $key, string|int|bool|null $default = null): string|int|bool|null
    {
        $value = self::$vars[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $value,
        };
    }
}
