<?php

declare(strict_types=1);

/**
 * Command execution for the agent.
 *
 * Everything goes through proc_open with an argv array — there is no code path
 * that builds a shell string, so task parameters can never become shell
 * syntax.
 */
final class Exec
{
    /**
     * @param list<string> $argv
     * @return array{code:int,stdout:string,stderr:string}
     */
    public static function run(array $argv, bool $dryRun = false): array
    {
        if ($argv === []) {
            throw new InvalidArgumentException('Empty command.');
        }

        if ($dryRun) {
            return ['code' => 0, 'stdout' => '[dry-run] ' . implode(' ', $argv), 'stderr' => ''];
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($argv, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start: ' . $argv[0]);
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * @param list<string> $argv
     * @return array{code:int,stdout:string,stderr:string}
     */
    public static function mustRun(array $argv, bool $dryRun = false): array
    {
        $result = self::run($argv, $dryRun);
        if ($result['code'] !== 0) {
            throw new RuntimeException(sprintf(
                'Command failed (%d): %s — %s',
                $result['code'],
                $argv[0],
                trim($result['stderr']) !== '' ? trim($result['stderr']) : trim($result['stdout'])
            ));
        }

        return $result;
    }
}
