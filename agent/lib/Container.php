<?php

declare(strict_types=1);

/**
 * Per-site container isolation.
 *
 * On a shared instance tenants run arbitrary PHP, so a Unix user plus
 * open_basedir is not a sufficient boundary — one local privilege-escalation bug
 * and a tenant is on the host with every other tenant's data. Each site
 * therefore gets its own container: own filesystem, own network namespace, own
 * cgroup limits, no capabilities, and a read-only image.
 *
 * nginx stays on the host and talks to the container's php-fpm over a unix
 * socket in a per-site directory, so no port is exposed and nothing is routable
 * between tenants.
 */
final class Container
{
    /** Container and network names are derived, never taken from input. */
    public static function name(string $siteUser): string
    {
        return 'aipanel-' . $siteUser;
    }

    public static function network(string $siteUser): string
    {
        return 'aipanel-net-' . $siteUser;
    }

    public static function runtime(array $config): string
    {
        return (string) ($config['container_runtime'] ?? 'docker');
    }

    public static function available(array $config): bool
    {
        return Exec::run([self::runtime($config), 'version'])['code'] === 0;
    }

    public static function exists(string $siteUser, array $config): bool
    {
        $result = Exec::run([
            self::runtime($config), 'inspect', '--format', '{{.Id}}', self::name($siteUser),
        ]);

        return $result['code'] === 0;
    }

    /**
     * Create (or recreate) a tenant's container.
     *
     * @param array<string,mixed> $config
     * @param array{home:string,uid:int,php_version:string,memory_mb:int,cpus:string,document_root:string} $spec
     */
    public static function create(string $siteUser, array $spec, array $config, bool $dryRun = false): array
    {
        $runtime = self::runtime($config);
        $name = self::name($siteUser);
        $network = self::network($siteUser);
        $home = $spec['home'];
        $socketDir = "/run/aipanel/{$siteUser}";

        // A private network per site. Containers on different networks cannot
        // reach each other, so one compromised tenant cannot scan or attack the
        // others, and --internal keeps it off the host bridge entirely.
        if (Exec::run([$runtime, 'network', 'inspect', $network])['code'] !== 0) {
            Exec::mustRun([$runtime, 'network', 'create', '--internal', $network], $dryRun);
        }

        if (!$dryRun) {
            foreach ([$socketDir, "{$home}/shared/tmp", "{$home}/shared/logs", "{$home}/shared/sessions"] as $dir) {
                if (!is_dir($dir) && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
                    throw new RuntimeException("Cannot create {$dir}");
                }
            }
            Exec::mustRun(['/bin/chown', '-R', "{$spec['uid']}:{$spec['uid']}", $socketDir]);
        }

        if (self::exists($siteUser, $config)) {
            Exec::run([$runtime, 'rm', '-f', $name], $dryRun);
        }

        $image = sprintf((string) ($config['container_image'] ?? 'aipanel/php:%s'), $spec['php_version']);

        $argv = [
            $runtime, 'run', '--detach', '--restart', 'unless-stopped',
            '--name', $name,
            '--hostname', $siteUser,
            '--network', $network,

            // Identity: the tenant's own uid, never root inside the container.
            '--user', "{$spec['uid']}:{$spec['uid']}",

            // Kernel-level confinement.
            '--read-only',
            '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges',
            '--tmpfs', '/tmp:rw,noexec,nosuid,size=64m',
            '--tmpfs', '/run:rw,noexec,nosuid,size=8m',

            // Resource ceilings, so one tenant cannot starve the node.
            '--memory', $spec['memory_mb'] . 'm',
            '--memory-swap', $spec['memory_mb'] . 'm',
            '--cpus', $spec['cpus'],
            '--pids-limit', '96',
            '--ulimit', 'nofile=1024:2048',
            '--ulimit', 'nproc=96:96',

            // The release being served is read-only to the code serving it:
            // tenant code cannot rewrite its own deployed files.
            '--volume', "{$home}/current:/app:ro",
            // Writable state the tenant is meant to have.
            '--volume', "{$home}/shared:/app/shared:rw",
            // Where nginx on the host finds this site's php-fpm socket.
            '--volume', "{$socketDir}:/run/php:rw",

            '--env', 'AIPANEL_SITE=' . $siteUser,
            '--env', 'PHP_FPM_LISTEN=/run/php/fpm.sock',
            '--workdir', '/app',
            $image,
        ];

        $result = Exec::mustRun($argv, $dryRun);

        return [
            'name' => $name,
            'network' => $network,
            'socket' => "{$socketDir}/fpm.sock",
            'stdout' => $result['stdout'],
        ];
    }

    public static function remove(string $siteUser, array $config, bool $dryRun = false): void
    {
        $runtime = self::runtime($config);
        Exec::run([$runtime, 'rm', '-f', self::name($siteUser)], $dryRun);
        Exec::run([$runtime, 'network', 'rm', self::network($siteUser)], $dryRun);
        Exec::run(['/bin/rm', '-rf', "/run/aipanel/{$siteUser}"], $dryRun);
    }

    public static function stop(string $siteUser, array $config, bool $dryRun = false): void
    {
        Exec::run([self::runtime($config), 'stop', self::name($siteUser)], $dryRun);
    }

    /**
     * Reload php-fpm inside the container so a new release is picked up.
     * A reload, not a restart: in-flight requests finish.
     */
    public static function reload(string $siteUser, array $config, bool $dryRun = false): void
    {
        $runtime = self::runtime($config);
        $name = self::name($siteUser);

        // USR2 is php-fpm's graceful reload. If the container is not running
        // (first deploy, or a tenant crash loop), starting it is the fix.
        if (Exec::run([$runtime, 'kill', '--signal', 'USR2', $name], $dryRun)['code'] !== 0) {
            Exec::run([$runtime, 'start', $name], $dryRun);
        }
    }

    /** @return array<string,mixed> */
    public static function stats(string $siteUser, array $config): array
    {
        $result = Exec::run([
            self::runtime($config), 'stats', '--no-stream', '--format',
            '{{.MemUsage}}|{{.CPUPerc}}|{{.PIDs}}', self::name($siteUser),
        ]);

        if ($result['code'] !== 0) {
            return ['running' => false];
        }

        $parts = explode('|', trim($result['stdout']));

        return [
            'running' => true,
            'memory' => $parts[0] ?? '',
            'cpu' => $parts[1] ?? '',
            'pids' => $parts[2] ?? '',
        ];
    }
}
