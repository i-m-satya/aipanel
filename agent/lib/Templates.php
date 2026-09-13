<?php

declare(strict_types=1);

/**
 * Config rendering. Values are escaped for the target config syntax and, for
 * anything that reaches a config file, validated first by the handler.
 */
final class Templates
{
    /** @param array<string,string> $vars */
    public static function render(string $template, array $vars): string
    {
        $path = __DIR__ . '/../templates/' . $template;
        if (!is_readable($path)) {
            throw new RuntimeException("Missing template: {$template}");
        }

        $content = (string) file_get_contents($path);
        foreach ($vars as $key => $value) {
            $content = str_replace('{{' . $key . '}}', $value, $content);
        }

        if (preg_match('/\{\{([a-z_]+)\}\}/', $content, $m) === 1) {
            throw new RuntimeException("Template {$template} has an unfilled placeholder: {$m[1]}");
        }

        return $content;
    }

    /** Atomic write: render to a temp file in the same directory, then rename. */
    public static function writeIfChanged(string $path, string $content, bool $dryRun = false): bool
    {
        if (is_readable($path) && file_get_contents($path) === $content) {
            return false;
        }

        if ($dryRun) {
            return true;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory: {$dir}");
        }

        $tmp = tempnam($dir, '.aipanel');
        if ($tmp === false) {
            throw new RuntimeException("Cannot write to: {$dir}");
        }
        file_put_contents($tmp, $content);
        chmod($tmp, 0o644);
        rename($tmp, $path);

        return true;
    }
}
