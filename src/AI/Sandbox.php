<?php

declare(strict_types=1);

namespace AIPanel\AI;

/**
 * The working tree the code agent is allowed to touch.
 *
 * Every path from the model is resolved and re-checked against the sandbox
 * root, so a traversal attempt ("../../etc/passwd", a symlink pointing out of
 * the tree) fails as a tool error instead of escaping. This runs inside a
 * throwaway container on a build node; containment here is defence in depth,
 * not the only barrier.
 */
final class Sandbox
{
    private const MAX_READ_BYTES = 512_000;
    private const MAX_WRITE_BYTES = 2_000_000;
    private const MAX_LISTING = 400;

    /** @var list<string> */
    private const DENIED = ['.git', '.github', 'vendor', 'node_modules', '.env'];

    /** @var array<string,true> */
    private array $written = [];

    /** @param list<string> $checkCommand */
    public function __construct(
        private string $root,
        private array $checkCommand = [],
        private int $checkTimeout = 300,
    ) {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException("Sandbox root does not exist: {$root}");
        }
        $this->root = $real;
    }

    public function listFiles(string $path): string
    {
        $absolute = $this->resolve($path, mustExist: true);
        if (!is_dir($absolute)) {
            throw new \RuntimeException("Not a directory: {$path}");
        }

        $entries = [];
        foreach (scandir($absolute) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (in_array($entry, self::DENIED, true)) {
                $entries[] = "{$entry}/  [not available to the agent]";
                continue;
            }
            $full = $absolute . '/' . $entry;
            $entries[] = is_dir($full)
                ? "{$entry}/"
                : sprintf('%s  (%d bytes)', $entry, (int) filesize($full));
            if (count($entries) >= self::MAX_LISTING) {
                $entries[] = '… listing truncated';
                break;
            }
        }

        sort($entries);

        return $entries === [] ? '(empty directory)' : implode("\n", $entries);
    }

    public function readFile(string $path): string
    {
        $absolute = $this->resolve($path, mustExist: true);
        if (!is_file($absolute)) {
            throw new \RuntimeException("Not a file: {$path}");
        }

        $size = (int) filesize($absolute);
        if ($size > self::MAX_READ_BYTES) {
            throw new \RuntimeException(sprintf(
                '%s is %d bytes, over the %d byte read limit. Search it instead.',
                $path,
                $size,
                self::MAX_READ_BYTES
            ));
        }

        $content = (string) file_get_contents($absolute);
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new \RuntimeException("{$path} is not a text file.");
        }

        return $content;
    }

    public function writeFile(string $path, string $content): string
    {
        if (strlen($content) > self::MAX_WRITE_BYTES) {
            throw new \RuntimeException('Refusing to write a file over 2 MB.');
        }

        $absolute = $this->resolve($path, mustExist: false);
        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory for {$path}");
        }

        $existed = is_file($absolute);
        file_put_contents($absolute, $content);
        $this->written[$this->relative($absolute)] = true;

        return sprintf(
            '%s %s (%d bytes).',
            $existed ? 'Overwrote' : 'Created',
            $this->relative($absolute),
            strlen($content)
        );
    }

    public function deleteFile(string $path): string
    {
        $absolute = $this->resolve($path, mustExist: true);
        if (!is_file($absolute)) {
            throw new \RuntimeException("Not a file: {$path}");
        }

        unlink($absolute);
        $this->written[$this->relative($absolute)] = true;

        return 'Deleted ' . $this->relative($absolute) . '.';
    }

    public function search(string $pattern): string
    {
        // Fixed-string search via grep -F: the pattern is an argv element, and
        // a user- or model-supplied regex is not worth the pathological-case
        // risk on a shared build node.
        $result = $this->exec([
            'grep', '-rFn', '--binary-files=without-match',
            '--exclude-dir=.git', '--exclude-dir=vendor', '--exclude-dir=node_modules',
            '--', $pattern, '.',
        ], 30);

        $lines = array_slice(array_filter(explode("\n", $result['output'])), 0, 100);

        return $lines === [] ? 'No matches.' : implode("\n", $lines);
    }

    /** @return array{passed:bool,output:string} */
    public function runChecks(): array
    {
        $command = $this->checkCommand;

        if ($command === []) {
            // Infer the repo's own check command; never invent one.
            if (is_file($this->root . '/composer.json')) {
                $composer = json_decode((string) file_get_contents($this->root . '/composer.json'), true);
                $scripts = is_array($composer) && is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];
                if (isset($scripts['test'])) {
                    $command = ['composer', 'run', '--no-interaction', 'test'];
                }
            }
            if ($command === [] && is_file($this->root . '/package.json')) {
                $package = json_decode((string) file_get_contents($this->root . '/package.json'), true);
                if (is_array($package) && isset($package['scripts']['test'])) {
                    $command = ['npm', 'test', '--silent'];
                }
            }
        }

        if ($command === []) {
            // No test suite: fall back to a syntax sweep of what changed, so a
            // release can still never ship a file that does not parse.
            return $this->lintChangedPhpFiles();
        }

        $result = $this->exec($command, $this->checkTimeout);

        return [
            'passed' => $result['code'] === 0,
            'output' => $this->tail($result['output'], 8000),
        ];
    }

    /** @return list<string> */
    public function changedFiles(): array
    {
        $result = $this->exec(['git', 'status', '--porcelain'], 30);

        // Only trust git when it actually succeeded: on a non-zero exit its
        // stderr would otherwise be parsed as a list of filenames.
        $files = [];
        if ($result['code'] === 0) {
            foreach (explode("\n", $result['output']) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                // Porcelain v1: two status columns, a space, then the path.
                $path = trim(substr($line, 2));
                if ($path !== '') {
                    $files[] = $path;
                }
            }
        }

        // Union with what the agent wrote, so a file is never missed because
        // git is unavailable in the sandbox.
        foreach (array_keys($this->written) as $written) {
            if (!in_array($written, $files, true)) {
                $files[] = $written;
            }
        }

        return $files;
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @return array{passed:bool,output:string} */
    private function lintChangedPhpFiles(): array
    {
        $output = [];
        $passed = true;

        foreach ($this->changedFiles() as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }
            $absolute = $this->root . '/' . $file;
            if (!is_file($absolute)) {
                continue;
            }
            $result = $this->exec(['php', '-l', $absolute], 30);
            if ($result['code'] !== 0) {
                $passed = false;
                $output[] = $result['output'];
            }
        }

        return [
            'passed' => $passed,
            'output' => $output === []
                ? 'No test suite in this repository; changed PHP files parse cleanly.'
                : implode("\n", $output),
        ];
    }

    private function resolve(string $path, bool $mustExist): string
    {
        $path = trim($path);
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new \RuntimeException('Paths must be relative to the repository root.');
        }

        // Strip a leading "./" only — trimming the '.' character itself would
        // turn ".git/config" into "git/config" and slip past the deny list.
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        if ($path === '' || $path === '.') {
            return $this->root;
        }
        $candidate = $this->root . '/' . $path;
        $real = realpath($candidate);

        if ($real === false) {
            if ($mustExist) {
                throw new \RuntimeException("No such file or directory: {$path}");
            }
            // For new files, the nearest existing ancestor must be inside the
            // sandbox — that is what stops a write through a symlinked dir.
            $real = realpath(dirname($candidate));
            if ($real === false) {
                $real = $this->root;
            }
            $this->assertInside($real, $path);

            return rtrim($real, '/') . '/' . basename($candidate);
        }

        $this->assertInside($real, $path);
        $this->assertAllowed($this->relative($real), $path);

        return $real;
    }

    private function assertInside(string $real, string $original): void
    {
        if ($real !== $this->root && !str_starts_with($real, $this->root . '/')) {
            throw new \RuntimeException("Path is outside the repository: {$original}");
        }
    }

    private function assertAllowed(string $relative, string $original): void
    {
        foreach (self::DENIED as $denied) {
            if ($relative === $denied || str_starts_with($relative, $denied . '/')) {
                throw new \RuntimeException(
                    "{$original} is not available to the agent (deploy plumbing, dependencies and secrets are off limits)."
                );
            }
        }
    }

    private function relative(string $absolute): string
    {
        return ltrim(substr($absolute, strlen($this->root)), '/');
    }

    /**
     * @param list<string> $argv
     * @return array{code:int,output:string}
     */
    private function exec(array $argv, int $timeout): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($argv, $descriptors, $pipes, $this->root, [
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'HOME' => $this->root,
            'CI' => '1',
        ]);

        if (!is_resource($process)) {
            return ['code' => 127, 'output' => "Could not run {$argv[0]}."];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = time() + $timeout;

        while (true) {
            $output .= (string) stream_get_contents($pipes[1]);
            $output .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (!$status['running']) {
                $output .= (string) stream_get_contents($pipes[1]);
                $output .= (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return ['code' => (int) $status['exitcode'], 'output' => $output];
            }

            if (time() > $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return ['code' => 124, 'output' => $output . "\n[timed out after {$timeout}s]"];
            }

            usleep(100_000);
        }
    }

    private function tail(string $text, int $bytes): string
    {
        return strlen($text) <= $bytes ? $text : "[…truncated…]\n" . substr($text, -$bytes);
    }
}
