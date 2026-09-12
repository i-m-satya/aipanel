<?php

declare(strict_types=1);

/**
 * aipanel console.
 *
 *   php bin/console.php key:generate            32-byte APP_KEY for secrets at rest
 *   php bin/console.php migrate                 apply pending SQL migrations
 *   php bin/console.php node:add <host> <url> <role>
 *   php bin/console.php node:deploy-key <id>    generate the node's read-only deploy key
 *   php bin/console.php github:install <installation_id> <account_id> <login>
 *   php bin/console.php queue:status
 */

use AIPanel\Domain\NodeRepository;
use AIPanel\Infra\Database;
use AIPanel\Infra\Migrator;
use AIPanel\Support\Crypto;

$container = require dirname(__DIR__) . '/src/bootstrap.php';

$command = $argv[1] ?? 'help';
$args = array_slice($argv, 2);

try {
    switch ($command) {
        case 'key:generate':
            $key = base64_encode(random_bytes(32));
            fwrite(STDOUT, "APP_KEY={$key}\n\nAdd this to your .env. Rotating it invalidates every stored node secret.\n");
            break;

        case 'migrate':
            $ran = $container->get(Migrator::class)->migrate();
            fwrite(STDOUT, $ran === []
                ? "Nothing to migrate.\n"
                : "Applied:\n  " . implode("\n  ", $ran) . "\n");
            break;

        case 'node:add':
            [$hostname, $endpoint, $role] = [$args[0] ?? '', $args[1] ?? '', $args[2] ?? 'web'];
            if ($hostname === '' || $endpoint === '') {
                throw new RuntimeException('Usage: node:add <hostname> <https://endpoint> <web|mysql|dns|mail>');
            }

            // The node secret is generated here and printed once: it goes into
            // the agent's config on that host and is never retrievable again.
            $secret = bin2hex(random_bytes(32));
            $nodeId = $container->get(NodeRepository::class)->create($hostname, $endpoint, $role, $secret);

            fwrite(STDOUT, "Node {$nodeId} registered.\n\nPut this in /etc/aipanel/agent.json on {$hostname}:\n");
            fwrite(STDOUT, json_encode([
                'secret' => $secret,
                'role' => $role,
            ], JSON_PRETTY_PRINT) . "\n\nThis secret is shown once.\n");
            break;

        case 'node:deploy-key':
            $nodeId = (int) ($args[0] ?? 0);
            $db = $container->get(Database::class);
            $node = $container->get(NodeRepository::class)->find($nodeId);
            if ($node === null) {
                throw new RuntimeException("No such node: {$nodeId}");
            }

            $keyPath = sys_get_temp_dir() . '/aipanel-deploy-' . $nodeId;
            @unlink($keyPath);
            @unlink($keyPath . '.pub');
            exec(sprintf(
                'ssh-keygen -t ed25519 -N "" -C %s -f %s',
                escapeshellarg('aipanel-node-' . $node['hostname']),
                escapeshellarg($keyPath)
            ), $output, $code);

            if ($code !== 0) {
                throw new RuntimeException('ssh-keygen failed: ' . implode("\n", $output));
            }

            $public = trim((string) file_get_contents($keyPath . '.pub'));
            $private = (string) file_get_contents($keyPath);
            @unlink($keyPath);
            @unlink($keyPath . '.pub');

            $db->execute(
                'UPDATE nodes SET deploy_public_key = ?, deploy_key_fingerprint = ? WHERE id = ?',
                [$public, substr(sha1($public), 0, 32), $nodeId]
            );

            fwrite(STDOUT, "Public key stored for node {$nodeId}.\n\n");
            fwrite(STDOUT, "Install the private key at /srv/sites/<site>/.ssh/deploy_key on that node:\n\n{$private}\n");
            break;

        case 'github:install':
            $installationId = (int) ($args[0] ?? 0);
            $accountId = (int) ($args[1] ?? 0);
            $login = (string) ($args[2] ?? '');
            if ($installationId === 0 || $accountId === 0 || $login === '') {
                throw new RuntimeException('Usage: github:install <installation_id> <account_id> <github_login>');
            }

            $secret = bin2hex(random_bytes(32));
            $container->get(Database::class)->execute(
                'INSERT INTO github_installations (id, account_id, github_account_login, webhook_secret_encrypted, created_at)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE account_id = VALUES(account_id), github_account_login = VALUES(github_account_login)',
                [$installationId, $accountId, $login, $container->get(Crypto::class)->encrypt($secret)]
            );

            fwrite(STDOUT, "Installation {$installationId} linked to account {$accountId}.\n");
            fwrite(STDOUT, "Set this as the App's webhook secret in GitHub:\n\n{$secret}\n");
            break;

        case 'queue:status':
            $rows = $container->get(Database::class)->select(
                'SELECT state, COUNT(*) AS n FROM jobs GROUP BY state ORDER BY state'
            );
            foreach ($rows as $row) {
                fwrite(STDOUT, sprintf("%-10s %d\n", $row['state'], (int) $row['n']));
            }
            if ($rows === []) {
                fwrite(STDOUT, "Queue is empty.\n");
            }
            break;

        default:
            fwrite(STDOUT, (string) file_get_contents(__FILE__, false, null, 0, 900));
            fwrite(STDOUT, "\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
