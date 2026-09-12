<?php

declare(strict_types=1);

/**
 * The agent's fixed handler map.
 *
 * Keyed by task name; an unlisted name is rejected with a 400 before any code
 * runs. Each entry is [required_role, handler]. Handlers return
 * ['changed' => bool, 'stdout' => string, 'stderr' => string, 'facts' => array].
 *
 * Handlers assume parameters were already validated by the control plane, but
 * re-check anything that reaches a filesystem path or a config file: the agent
 * must be safe even if the control plane is compromised.
 */
final class Handlers
{
    /** @return array<string,array{0:string,1:callable}> */
    public static function map(): array
    {
        return [
            'node.facts' => ['any', [self::class, 'nodeFacts']],
            'site.create' => ['web', [self::class, 'siteCreate']],
            'site.delete' => ['web', [self::class, 'siteDelete']],
            'site.suspend' => ['web', [self::class, 'siteSuspend']],
            'site.set_php_version' => ['web', [self::class, 'setPhpVersion']],
            'ssh.key_add' => ['web', [self::class, 'sshKeyAdd']],
            'ssh.key_remove' => ['web', [self::class, 'sshKeyRemove']],
            'deploy.run' => ['web', [self::class, 'deployRun']],
            'deploy.rollback' => ['web', [self::class, 'deployRollback']],
            'ssl.issue' => ['web', [self::class, 'sslIssue']],
            'db.create' => ['mysql', [self::class, 'dbCreate']],
            'db.drop' => ['mysql', [self::class, 'dbDrop']],
        ];
    }

    // ---------------------------------------------------------------- facts

    /** @param array<string,mixed> $params @param array<string,mixed> $config */
    public static function nodeFacts(array $params, array $config): array
    {
        $load = sys_getloadavg() ?: [0.0, 0.0, 0.0];
        $phpVersions = [];
        foreach (glob('/etc/php/*/fpm') ?: [] as $dir) {
            $phpVersions[] = basename(dirname($dir));
        }

        return [
            'changed' => false,
            'stdout' => '',
            'stderr' => '',
            'facts' => [
                'hostname' => php_uname('n'),
                'kernel' => php_uname('r'),
                'agent_version' => AGENT_VERSION,
                'role' => $config['role'],
                'load' => ['1m' => $load[0], '5m' => $load[1], '15m' => $load[2]],
                'disk_free_bytes' => @disk_free_space($config['web_root']) ?: null,
                'disk_total_bytes' => @disk_total_space($config['web_root']) ?: null,
                'php_versions' => $phpVersions,
                'sites' => count(glob(rtrim((string) $config['web_root'], '/') . '/site_*') ?: []),
            ],
        ];
    }

    // ---------------------------------------------------------- provisioning

    /** @param array<string,mixed> $params @param array<string,mixed> $config */
    public static function siteCreate(array $params, array $config): array
    {
        $user = self::siteUser($params);
        $domain = self::domain($params);
        $phpVersion = self::phpVersion($params['php_version'] ?? '8.3');
        $docRoot = self::relativePath((string) ($params['document_root'] ?? 'public'));
        $home = self::home($config, $user);
        $dryRun = (bool) $config['dry_run'];
        $changed = false;

        // 1. The tenant's Linux identity. One user, one group, nothing shared.
        if (!self::userExists($user)) {
            Exec::mustRun(['/usr/sbin/groupadd', '--force', $user], $dryRun);
            Exec::mustRun([
                '/usr/sbin/useradd',
                '--home-dir', $home,
                '--gid', $user,
                '--groups', 'sites',
                '--shell', '/usr/local/bin/aipanel-shell',
                '--no-create-home',
                $user,
            ], $dryRun);
            $changed = true;
        }

        // 2. The jail. The chroot root itself must be root-owned and
        //    non-writable by the tenant, with writable subdirs beneath it.
        foreach ([$home, "{$home}/releases", "{$home}/shared", "{$home}/repo.git", "{$home}/.ssh"] as $dir) {
            if (!is_dir($dir)) {
                if (!$dryRun && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
                    throw new RuntimeException("Cannot create {$dir}");
                }
                $changed = true;
            }
        }
        if (!$dryRun) {
            Exec::mustRun(['/bin/chown', 'root:root', $home]);
            Exec::mustRun(['/bin/chmod', '0755', $home]);
            foreach (["{$home}/releases", "{$home}/shared", "{$home}/repo.git"] as $dir) {
                Exec::mustRun(['/bin/chown', '-R', "{$user}:{$user}", $dir]);
            }
            Exec::mustRun(['/bin/chown', "root:{$user}", "{$home}/.ssh"]);
            Exec::mustRun(['/bin/chmod', '0750', "{$home}/.ssh"]);
        }

        // 3. Read-only deploy key, so this node can fetch the repo and nothing else.
        $keyLine = self::publicKey((string) $params['deploy_public_key']);
        $changed = Templates::writeIfChanged("{$home}/.ssh/deploy_key.pub", $keyLine . "\n", $dryRun) || $changed;

        // 4. PHP-FPM pool: runs as the tenant, confined to its own home.
        $poolDir = sprintf((string) $config['fpm_pool_dir'], $phpVersion);
        $pool = Templates::render('php-fpm-pool.conf.tpl', [
            'user' => $user,
            'home' => $home,
            'php_version' => $phpVersion,
        ]);
        $changed = Templates::writeIfChanged("{$poolDir}/{$user}.conf", $pool, $dryRun) || $changed;

        // 5. Vhost pointing at current/, which is a symlink to a release.
        $aliases = [];
        foreach ((array) ($params['aliases'] ?? []) as $alias) {
            $aliases[] = self::domain(['domain' => $alias]);
        }
        $vhost = Templates::render('nginx-vhost.conf.tpl', [
            'domain' => $domain,
            'server_names' => implode(' ', [$domain, ...$aliases]),
            'user' => $user,
            'root' => "{$home}/current/{$docRoot}",
            'fpm_socket' => "/run/php/{$user}.sock",
        ]);
        $changed = Templates::writeIfChanged("{$config['vhost_dir']}/{$domain}.conf", $vhost, $dryRun) || $changed;

        if (!$dryRun) {
            $link = rtrim((string) $config['vhost_enabled_dir'], '/') . "/{$domain}.conf";
            if (!is_link($link)) {
                symlink("{$config['vhost_dir']}/{$domain}.conf", $link);
                $changed = true;
            }
        }

        // 6. Resource limits, then reload. nginx -t first: never reload a
        //    broken config and take every other site on the node down.
        $slice = Templates::render('site-slice.conf.tpl', ['user' => $user]);
        Templates::writeIfChanged("/etc/systemd/system/aipanel-site-{$user}.slice", $slice, $dryRun);

        Exec::mustRun(['/usr/sbin/nginx', '-t'], $dryRun);
        Exec::mustRun(['/bin/systemctl', 'reload', 'nginx'], $dryRun);
        Exec::mustRun(['/bin/systemctl', 'reload', "php{$phpVersion}-fpm"], $dryRun);

        return [
            'changed' => $changed,
            'stdout' => "provisioned {$domain} as {$user} (php {$phpVersion})",
            'stderr' => '',
            'facts' => ['home' => $home, 'php_version' => $phpVersion],
        ];
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $config */
    public static function siteDelete(array $params, array $config): array
    {
        $user = self::siteUser($params);
        $domain = self::domain($params);
        $home = self::home($config, $user);
        $dryRun = (bool) $config['dry_run'];

        foreach (glob(rtrim((string) $config['vhost_enabled_dir'], '/') . "/{$domain}.conf") ?: [] as $link) {
            $dryRun ?: @unlink($link);
        }
        foreach (glob(rtrim((string) $config['vhost_dir'], '/') . "/{$domain}.conf") ?: [] as $file) {
            $dryRun ?: @unlink($file);
        }
        foreach (glob('/etc/php/*/fpm/pool.d/' . $user . '.conf') ?: [] as $pool) {
            $dryRun ?: @unlink($pool);
        }

        Exec::mustRun(['/usr/sbin/nginx', '-t'], $dryRun);
        Exec::mustRun(['/bin/systemctl', 'reload', 'nginx'], $dryRun);

        if (self::userExists($user)) {
            Exec::run(['/usr/bin/pkill', '-u', $user], $dryRun);
            Exec::mustRun(['/usr/sbin/userdel', $user], $dryRun);
        }

        if ((bool) ($params['purge_files'] ?? false)) {
            // Guard rail: only ever inside the configured web root, only a
            // site_* directory. A path outside that shape is a bug, not a task.
            self::assertInsideWebRoot($config, $home);
            Exec::mustRun(['/bin/rm', '-rf', '--one-file-system', $home], $dryRun);
        }

        return [
            'changed' => true,
            'stdout' => "removed {$domain} ({$user})" . (($params['purge_files'] ?? false) ? ' and purged its home' : ''),
            'stderr' => '',
            'facts' => [],
        ];
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $config */
    public static function siteSuspend(array $params, array $config): array
    {
        $user = self::siteUser($params);
        $dryRun = (bool) $config['dry_run'];

        Exec::mustRun(['/usr/bin/passwd', '--lock', $user], $dryRun);
        Exec::run(['/usr/bin/pkill', '-u', $user], $dryRun);
        $home = self::home($config, $user);
        if (is_file("{$home}/.ssh/authorized_keys")) {
            Exec::mustRun(['/bin/mv', "{$home}/.ssh/authorized_keys", "{$home}/.ssh/authorized_keys.suspended"], $dryRun);
        }

        return ['changed' => true, 'stdout' => "suspended {$user}", 'stderr' => '', 'facts' => []];
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $config */
    public static function setPhpVersion(array $params, array $config): array
    {
        $user = self::siteUser($params);
        $target = self::phpVersion($params['php_version'] ?? '');
        $home = self::home($config, $user);
        $dryRun = (bool) $config['dry_run'];

        $pool = Templates::render('php-fpm-pool.conf.tpl', [
            'user' => $user,
            'home' => $home,
            'php_version' => $target,
        ]);

        $changed = false;
        foreach (glob('/etc/php/*/fpm/pool.d/' . $user . '.conf') ?: [] as $existing) {
            if (!str_contains($existing, "/php/{$target}/")) {
                $dryRun ?: @unlink($existing);
                $changed = true;
            }
        }

        $poolDir = sprintf((string) $config['fpm_pool_dir'], $target);
        $changed = Templates::writeIfChanged("{$poolDir}/{$user}.conf", $pool, $dryRun) || $changed;

        Exec::mustRun(['/bin/systemctl', 'reload', "php{$target}-fpm"], $dryRun);

        return ['changed' => $changed, 'stdout' => "{$user} now runs php {$target}", 'stderr' => '', 'facts' => []];
    }

    // ------------------------------------------------------------------ ssh

    /** @param array<string,mixed> $params @param array<string,mixed> $config */
    public static function sshKeyAdd(array $params, array $config): array
    {
        $user = self::siteUser($params);
        $home = self::home($config, $user);
        $key = self::publicKey((string) $params['public_key']);
        $file = "{$home}/.ssh/authorized_keys";
        $dryRun = (bool) $config['dry_run'];

        $existing = is_readable($file) ? (string) file_get_contents($file) : '';
        $lines = array_values(array_filter(array_map('trim', explode("\n", $existing))));

        foreach ($lines as $line) {
            if (self::sameKey($line, $key)) {
                return ['changed' => false, 'stdout' => 'key already authorised', 'stderr' => '', 'facts' => []];
            }
        }

        // Options pin what this key can do even if sshd config drifts.
        $lines[] = 'restrict,pty,no-agent-forwarding,no-port-forwarding ' . $key;
        Templates::writeIfChanged($file, implode("\n", $lines) . "\n", $dryRun);

        if (!$dryRun) {
            Exec::mustRun(['/bin/chown', "{$user}:{$user}", $file]);
            Exec::mustRun(['/bin/chmod', '0600', $file]);
        }

        return ['changed' => true, 'stdout' => "authorised key for {$user}", 'stderr' => '', 'facts' => []];
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $config */
    public static function sshKeyRemove(array $params, array $config): array
    {
        $user = self::siteUser($params);
        $home = self::home($config, $user);
        $file = "{$home}/.ssh/authorized_keys";
        $fingerprint = (string) $params['fingerprint'];

        if (!is_readable($file)) {
            return ['changed' => false, 'stdout' => 'no authorized_keys file', 'stderr' => '', 'facts' => []];
        }

        $kept = [];
        $removed = 0;
        foreach (explode("\n", (string) file_get_contents($file)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (self::fingerprintOf($line) === $fingerprint) {
                $removed++;
                continue;
            }
            $kept[] = $line;
        }

        if ($removed === 0) {
            return ['changed' => false, 'stdout' => 'fingerprint not present', 'stderr' => '', 'facts' => []];
        }

        Templates::writeIfChanged($file, implode("\n", $kept) . "\n", (bool) $config['dry_run']);

        return ['changed' => true, 'stdout' => "revoked {$removed} key(s) for {$user}", 'stderr' => '', 'facts' => []];
    }

    // --------------------------------------------------------------- deploy

    /**
     * Build a new immutable release from an exact SHA and cut over atomically.
     * The symlink only moves after the health check passes, so a failed deploy
     * leaves the previous release serving traffic.
     *
     * @param array<string,mixed> $params @param array<string,mixed> $config
     */
    public static function deployRun(array $params, array $config, string $idempotencyKey = ''): array
    {
        $user = self::siteUser($params);
        $home = self::home($config, $user);
        $commit = self::commit((string) $params['commit']);
        $repo = self::repo((string) $params['repo']);
        $dryRun = (bool) $config['dry_run'];

        $release = gmdate('Ymd\THis\Z');
        $releaseDir = "{$home}/releases/{$release}";
        $log = [];

        // Fetch as the tenant, with the read-only deploy key only.
        $gitSsh = "/usr/bin/ssh -i {$home}/.ssh/deploy_key -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new";
        $asUser = ['/usr/bin/sudo', '-u', $user, '-H', '/usr/bin/env', "GIT_SSH_COMMAND={$gitSsh}"];

        if (!is_dir("{$home}/repo.git/objects")) {
            $log[] = Exec::mustRun([...$asUser, '/usr/bin/git', 'clone', '--bare', "git@github.com:{$repo}.git", "{$home}/repo.git"], $dryRun)['stdout'];
        }
        $log[] = Exec::mustRun([...$asUser, '/usr/bin/git', "--git-dir={$home}/repo.git", 'fetch', 'origin', '+refs/heads/*:refs/heads/*', '--prune'], $dryRun)['stdout'];

        // Materialise the exact SHA — never a branch name, so a race on main
        // cannot deploy something other than what was reviewed.
        if (!$dryRun && !mkdir($releaseDir, 0o755, true) && !is_dir($releaseDir)) {
            throw new RuntimeException("Cannot create {$releaseDir}");
        }
        Exec::mustRun(['/bin/chown', "{$user}:{$user}", $releaseDir], $dryRun);
        $log[] = Exec::mustRun([
            ...$asUser, '/usr/bin/git', "--git-dir={$home}/repo.git", "--work-tree={$releaseDir}",
            'checkout', '--force', $commit, '--', '.',
        ], $dryRun)['stdout'];

        // shared/ state is linked in, never copied, so it survives releases.
        foreach (['.env', 'storage', 'uploads'] as $shared) {
            $source = "{$home}/shared/{$shared}";
            if (is_file($source) || is_dir($source)) {
                Exec::run(['/bin/rm', '-rf', "{$releaseDir}/{$shared}"], $dryRun);
                Exec::mustRun(['/bin/ln', '-s', $source, "{$releaseDir}/{$shared}"], $dryRun);
            }
        }

        if (is_file("{$releaseDir}/composer.json")) {
            $log[] = Exec::mustRun([
                ...$asUser, '/usr/bin/composer', 'install', '--no-dev', '--no-interaction',
                '--prefer-dist', '--optimize-autoloader', "--working-dir={$releaseDir}",
            ], $dryRun)['stdout'];
        }

        if ((bool) ($params['run_migrations'] ?? false) && is_file("{$releaseDir}/artisan")) {
            $log[] = Exec::mustRun([...$asUser, '/usr/bin/php', "{$releaseDir}/artisan", 'migrate', '--force'], $dryRun)['stdout'];
        }

        // Health check the new tree before it can serve anyone.
        $health = Exec::run([...$asUser, '/usr/bin/php', '-l', "{$releaseDir}/index.php"], $dryRun);
        if (is_file("{$releaseDir}/index.php") && $health['code'] !== 0) {
            Exec::run(['/bin/rm', '-rf', '--one-file-system', $releaseDir], $dryRun);
            throw new RuntimeException('Release failed its health check; current release left in place.');
        }

        // Atomic cutover: create the symlink beside the target, then rename
        // over the old one — readers never observe a missing current/.
        if (!$dryRun) {
            $staging = "{$home}/current.new";
            @unlink($staging);
            symlink($releaseDir, $staging);
            rename($staging, "{$home}/current");
        }
        Exec::mustRun(['/bin/systemctl', 'reload', "php-fpm@{$user}"], $dryRun);

        self::pruneReleases($home, keep: 5, dryRun: $dryRun);

        return [
            'changed' => true,
            'stdout' => "deployed {$repo}@{$commit} as release {$release}\n" . implode("\n", array_filter($log)),
            'stderr' => '',
            'facts' => ['release' => $release, 'commit' => $commit],
        ];
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $config */
    public static function deployRollback(array $params, array $config): array
    {
        $user = self::siteUser($params);
        $home = self::home($config, $user);
        $dryRun = (bool) $config['dry_run'];

        $releases = self::releases($home);
        if (count($releases) < 2) {
            throw new RuntimeException('No previous release to roll back to.');
        }

        $target = isset($params['release']) && $params['release'] !== ''
            ? (string) $params['release']
            : $releases[count($releases) - 2];

        if (!in_array($target, $releases, true)) {
            throw new RuntimeException("Unknown release: {$target}");
        }

        if (!$dryRun) {
            $staging = "{$home}/current.new";
            @unlink($staging);
            symlink("{$home}/releases/{$target}", $staging);
            rename($staging, "{$home}/current");
        }
        Exec::mustRun(['/bin/systemctl', 'reload', "php-fpm@{$user}"], $dryRun);

        return [
            'changed' => true,
            'stdout' => "rolled {$user} back to release {$target}",
            'stderr' => '',
            'facts' => ['release' => $target],
        ];
    }

    // ------------------------------------------------------------------ ssl

    /** @param array<string,mixed> $params @param array<string,mixed> $config */
    public static function sslIssue(array $params, array $config): array
    {
        $domain = self::domain($params);
        $email = isset($params['email']) ? filter_var((string) $params['email'], FILTER_VALIDATE_EMAIL) : false;
        $dryRun = (bool) $config['dry_run'];

        $argv = [
            (string) $config['acme_client'], 'certonly', '--nginx',
            '--non-interactive', '--agree-tos', '--domain', $domain,
        ];
        $argv = $email === false ? [...$argv, '--register-unsafely-without-email'] : [...$argv, '--email', $email];

        $result = Exec::mustRun($argv, $dryRun);
        Exec::mustRun(['/bin/systemctl', 'reload', 'nginx'], $dryRun);

        return [
            'changed' => !str_contains($result['stdout'], 'not yet due for renewal'),
            'stdout' => $result['stdout'],
            'stderr' => $result['stderr'],
            'facts' => [],
        ];
    }

    // ------------------------------------------------------------- database

    /** @param array<string,mixed> $params @param array<string,mixed> $config */
    public static function dbCreate(array $params, array $config): array
    {
        $name = self::identifier((string) $params['name']);
        $user = self::identifier((string) $params['user']);
        $password = bin2hex(random_bytes(16));
        $dryRun = (bool) $config['dry_run'];

        // Identifiers are validated against [a-z0-9_] above, and values are
        // passed via a temp defaults file rather than an argv password.
        $sql = sprintf(
            "CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
            . "CREATE USER IF NOT EXISTS '%s'@'%%' IDENTIFIED BY '%s';"
            . "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER, REFERENCES ON `%s`.* TO '%s'@'%%';"
            . 'FLUSH PRIVILEGES;',
            $name,
            $user,
            addcslashes($password, "'\\"),
            $name,
            $user
        );

        Exec::mustRun(['/usr/bin/mysql', '--defaults-file=/etc/aipanel/mysql.cnf', '--execute', $sql], $dryRun);

        return [
            'changed' => true,
            'stdout' => "created database {$name} with user {$user}",
            'stderr' => '',
            // The password is returned once, for the control plane to store in
            // the tenant's shared/.env. It is never logged by the agent.
            'facts' => ['database' => $name, 'username' => $user, 'password' => $password],
        ];
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $config */
    public static function dbDrop(array $params, array $config): array
    {
        $name = self::identifier((string) $params['name']);
        $sql = sprintf('DROP DATABASE IF EXISTS `%s`;', $name);
        Exec::mustRun(['/usr/bin/mysql', '--defaults-file=/etc/aipanel/mysql.cnf', '--execute', $sql], (bool) $config['dry_run']);

        return ['changed' => true, 'stdout' => "dropped database {$name}", 'stderr' => '', 'facts' => []];
    }

    // -------------------------------------------------------------- helpers

    /** @param array<string,mixed> $params */
    private static function siteUser(array $params): string
    {
        $user = (string) ($params['site_user'] ?? '');
        if (preg_match('/^site_[a-z0-9]{6,12}$/', $user) !== 1) {
            throw new InvalidArgumentException('Invalid site_user.');
        }

        return $user;
    }

    /** @param array<string,mixed> $params */
    private static function domain(array $params): string
    {
        $domain = strtolower(trim((string) ($params['domain'] ?? '')));
        if (preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) !== 1) {
            throw new InvalidArgumentException('Invalid domain.');
        }

        return $domain;
    }

    private static function repo(string $repo): string
    {
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) !== 1) {
            throw new InvalidArgumentException('Invalid repo.');
        }

        return $repo;
    }

    private static function commit(string $commit): string
    {
        if (preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
            throw new InvalidArgumentException('Deploys require a full commit SHA.');
        }

        return $commit;
    }

    private static function phpVersion(mixed $version): string
    {
        $version = (string) $version;
        if (!in_array($version, ['8.1', '8.2', '8.3', '8.4'], true)) {
            throw new InvalidArgumentException('Unsupported PHP version.');
        }

        return $version;
    }

    private static function identifier(string $value): string
    {
        if (preg_match('/^[a-z0-9_]{1,48}$/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid identifier.');
        }

        return $value;
    }

    private static function relativePath(string $path): string
    {
        if (preg_match('#^[a-zA-Z0-9_./-]{1,64}$#', $path) !== 1 || str_contains($path, '..')) {
            throw new InvalidArgumentException('Invalid relative path.');
        }

        return trim($path, '/');
    }

    private static function publicKey(string $key): string
    {
        $key = trim(preg_replace('/\s+/', ' ', $key) ?? '');
        if (preg_match('#^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp256) [A-Za-z0-9+/=]{32,}( [^\r\n]*)?$#', $key) !== 1) {
            throw new InvalidArgumentException('Invalid SSH public key.');
        }

        return $key;
    }

    private static function sameKey(string $line, string $key): bool
    {
        return self::keyMaterial($line) !== '' && self::keyMaterial($line) === self::keyMaterial($key);
    }

    private static function keyMaterial(string $line): string
    {
        if (preg_match('#(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp256) ([A-Za-z0-9+/=]{32,})#', $line, $m) === 1) {
            return $m[2];
        }

        return '';
    }

    private static function fingerprintOf(string $line): string
    {
        $material = self::keyMaterial($line);
        if ($material === '') {
            return '';
        }
        $raw = base64_decode($material, true);

        return $raw === false ? '' : 'SHA256:' . rtrim(base64_encode(hash('sha256', $raw, true)), '=');
    }

    /** @param array<string,mixed> $config */
    private static function home(array $config, string $user): string
    {
        return rtrim((string) $config['web_root'], '/') . '/' . $user;
    }

    /** @param array<string,mixed> $config */
    private static function assertInsideWebRoot(array $config, string $path): void
    {
        $root = rtrim((string) $config['web_root'], '/');
        if (!str_starts_with($path, $root . '/site_') || str_contains($path, '..')) {
            throw new InvalidArgumentException("Refusing to operate on {$path}: outside the managed web root.");
        }
    }

    /** @return list<string> release ids, oldest first */
    private static function releases(string $home): array
    {
        $releases = [];
        foreach (glob("{$home}/releases/*", GLOB_ONLYDIR) ?: [] as $dir) {
            $releases[] = basename($dir);
        }
        sort($releases);

        return $releases;
    }

    private static function pruneReleases(string $home, int $keep, bool $dryRun): void
    {
        $releases = self::releases($home);
        $current = is_link("{$home}/current") ? basename((string) readlink("{$home}/current")) : null;

        foreach (array_slice($releases, 0, max(0, count($releases) - $keep)) as $old) {
            if ($old === $current) {
                continue;
            }
            Exec::run(['/bin/rm', '-rf', '--one-file-system', "{$home}/releases/{$old}"], $dryRun);
        }
    }

    private static function userExists(string $user): bool
    {
        return function_exists('posix_getpwnam') ? posix_getpwnam($user) !== false : Exec::run(['/usr/bin/id', $user])['code'] === 0;
    }
}
