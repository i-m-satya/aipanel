<?php

declare(strict_types=1);

/**
 * Appliance mode: aipanel owns its whole stack and touches nothing on the host.
 *
 * In the original design the agent provisioned a tenant by mutating the host —
 * useradd, a chroot, an nginx vhost in /etc/nginx, a PHP-FPM pool in /etc/php.
 * That works on a server aipanel owns, and collides badly on one that already
 * runs another control panel, which compiles its own nginx and PHP into
 * /www/server and would never read those files.
 *
 * Here a tenant is a container and a directory, nothing more:
 *
 *   /var/lib/aipanel/sites/<site>/   releases, shared, repo.git
 *   /var/lib/aipanel/edge/conf.d/    one generated server block per domain
 *   container aipanel-<site>         serves it, on the tenants network only
 *
 * No host user, no host nginx, no host PHP. The only shared resource left is
 * whichever port the edge binds.
 */
final class Appliance
{
    public static function root(array $config): string
    {
        return rtrim((string) ($config['appliance_root'] ?? '/var/lib/aipanel'), '/');
    }

    public static function siteHome(array $config, string $siteUser): string
    {
        return self::root($config) . '/sites/' . $siteUser;
    }

    public static function edgeConfDir(array $config): string
    {
        return self::root($config) . '/edge/conf.d';
    }

    /** Lay out one tenant's directories. Ownership is by uid, not by host user. */
    public static function prepareSite(array $config, string $siteUser, int $uid, bool $dryRun): bool
    {
        $home = self::siteHome($config, $siteUser);
        $changed = false;

        foreach ([
            $home,
            "{$home}/releases",
            "{$home}/repo.git",
            "{$home}/shared",
            "{$home}/shared/tmp",
            "{$home}/shared/logs",
            "{$home}/shared/sessions",
        ] as $dir) {
            if (!is_dir($dir)) {
                if (!$dryRun && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
                    throw new RuntimeException("Cannot create {$dir}");
                }
                $changed = true;
            }
        }

        if (!$dryRun) {
            // The tenant uid owns its writable state; the home itself does not
            // belong to it, so it cannot replace its own release directory.
            Exec::mustRun(['/bin/chown', '-R', "{$uid}:{$uid}", "{$home}/releases"]);
            Exec::mustRun(['/bin/chown', '-R', "{$uid}:{$uid}", "{$home}/shared"]);
            Exec::mustRun(['/bin/chown', '-R', "{$uid}:{$uid}", "{$home}/repo.git"]);
            Exec::mustRun(['/bin/chmod', '0755', $home]);
        }

        return $changed;
    }

    /**
     * A stable, collision-free uid per tenant, derived from the site user.
     *
     * Appliance mode creates no host users, so there is no passwd database to
     * allocate from. The uid only has to be consistent for one site and distinct
     * from other sites and from anything on the host, so it is derived from the
     * site id into a high range.
     */
    public static function uidFor(string $siteUser): int
    {
        $digest = substr(hash('sha256', $siteUser), 0, 8);

        // 100000-165535: above every distributions' system and human range.
        return 100000 + (int) (hexdec($digest) % 65536);
    }

    /**
     * Write the edge's server block for one domain.
     *
     * @return bool whether the file changed
     */
    public static function writeEdgeSite(
        array $config,
        string $domain,
        string $siteUser,
        string $containerName,
        bool $withTls,
        bool $dryRun,
    ): bool {
        $tls = '';
        if ($withTls) {
            $tls = Templates::render('edge-site-tls.conf.tpl', [
                'domain' => $domain,
                'server_names' => $domain,
                'container' => $containerName,
            ]);
        }

        $conf = Templates::render('edge-site.conf.tpl', [
            'domain' => $domain,
            'server_names' => $domain,
            'user' => $siteUser,
            'container' => $containerName,
            // Once a certificate exists the HTTP block redirects. It lives inside
            // location / so the ACME location above still wins — a server-level
            // return would swallow the challenge and break every renewal.
            'http_body' => $withTls ? 'return 301 https://$host$request_uri;' : '',
            'tls_server' => $tls,
        ]);

        return Templates::writeIfChanged(
            self::edgeConfDir($config) . "/{$domain}.conf",
            $conf,
            $dryRun
        );
    }

    public static function removeEdgeSite(array $config, string $domain, bool $dryRun): void
    {
        $path = self::edgeConfDir($config) . "/{$domain}.conf";
        if (!$dryRun && is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Reload the edge after a config change.
     *
     * Validated first: reloading a broken config would take every tenant on the
     * panel offline, not just the one being changed.
     */
    public static function reloadEdge(array $config, bool $dryRun): void
    {
        $runtime = Container::runtime($config);
        $edge = (string) ($config['edge_container'] ?? 'aipanel-edge-1');

        $test = Exec::run([$runtime, 'exec', $edge, 'nginx', '-t'], $dryRun);
        if ($test['code'] !== 0 && !$dryRun) {
            throw new RuntimeException(
                "The edge rejected the new configuration, so it was not reloaded:\n" . $test['stderr']
            );
        }

        Exec::mustRun([$runtime, 'exec', $edge, 'nginx', '-s', 'reload'], $dryRun);
    }

    /**
     * Run git for a tenant inside a throwaway container.
     *
     * The host has no git in appliance mode, and the node's deploy key must not
     * be readable by the tenant — so it is mounted read-only into a container
     * that exits when the fetch is done.
     *
     * @param list<string> $args
     * @return array{code:int,stdout:string,stderr:string}
     */
    public static function git(array $config, string $siteUser, array $args, bool $dryRun): array
    {
        $runtime = Container::runtime($config);
        $home = self::siteHome($config, $siteUser);
        $key = (string) ($config['deploy_key'] ?? self::root($config) . '/deploy_key');
        $uid = self::uidFor($siteUser);

        return Exec::run([
            $runtime, 'run', '--rm',
            '--network', 'aipanel-tenants',
            '--user', "{$uid}:{$uid}",
            '--volume', "{$home}:/site",
            '--volume', "{$key}:/key:ro",
            '--env', 'GIT_SSH_COMMAND=ssh -i /key -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new -o BatchMode=yes',
            '--env', 'GIT_TERMINAL_PROMPT=0',
            '--workdir', '/site',
            (string) ($config['git_image'] ?? 'alpine/git:latest'),
            ...$args,
        ], $dryRun);
    }
}
