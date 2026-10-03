<?php
// Fixture for ApplianceModeTest: replaces Exec with a recorder and reports every
// command appliance mode would run. Run as a subprocess, because the real Exec
// class cannot be redefined inside a loaded test suite.
//
// Prove appliance mode emits no host-mutating command. Exec is replaced with a
// recorder, so every argv the handlers would run is captured instead of run.
const AGENT_VERSION = 'test';
$root = dirname(__DIR__, 2);

final class Exec {
    public static array $calls = [];
    public static function run(array $argv, bool $dryRun = false): array {
        self::$calls[] = $argv;
        return ['code' => 0, 'stdout' => '', 'stderr' => ''];
    }
    public static function mustRun(array $argv, bool $dryRun = false): array {
        return self::run($argv, $dryRun);
    }
}

require "$root/agent/lib/Templates.php";
require "$root/agent/lib/Container.php";
require "$root/agent/lib/Appliance.php";
require "$root/agent/lib/Handlers.php";

$tmp = sys_get_temp_dir() . '/appl-' . bin2hex(random_bytes(3));
$config = [
  'isolation' => 'appliance', 'appliance_root' => $tmp, 'dry_run' => true,
  'container_runtime' => 'docker', 'container_image' => 'aipanel/php:%s',
  'edge_container' => 'aipanel-edge-1', 'acme_client' => '/bin/true',
  'deploy_key' => "$tmp/deploy_key", 'web_root' => '/srv/sites',
  'vhost_dir' => '/etc/nginx/sites-available', 'vhost_enabled_dir' => '/etc/nginx/sites-enabled',
  'fpm_pool_dir' => '/etc/php/%s/fpm/pool.d', 'acme_webroot' => '/var/www/acme',
];

[$role, $handler] = Handlers::map()['site.create'];
$handler([
  'domain' => 'customer.example.com', 'site_user' => 'site_abc123',
  'repo' => 'acme/site', 'php_version' => '8.3', 'document_root' => 'public',
  'deploy_public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExampleKeyMaterial0000',
], $config, 'k1');

$forbidden = ['useradd','groupadd','userdel','passwd','systemctl','nginx','pkill','chpasswd','adduser'];
$violations = [];
foreach (Exec::$calls as $argv) {
    $bin = basename($argv[0] ?? '');
    if (in_array($bin, $forbidden, true)) $violations[] = implode(' ', $argv);
    foreach ($argv as $a) {
        if (is_string($a) && (str_starts_with($a, '/etc/') || str_starts_with($a, '/www/') || str_starts_with($a, '/srv/'))) {
            $violations[] = implode(' ', $argv);
        }
    }
}

echo "commands emitted by appliance site.create:\n";
foreach (Exec::$calls as $argv) echo "  ", basename($argv[0]), " ", implode(' ', array_slice($argv,1,4)), "\n";
echo "\nhost-mutating commands or host paths: ", $violations === [] ? "NONE (good)\n" : "FOUND:\n  " . implode("\n  ", array_unique($violations)) . "\n";
exec('rm -rf ' . escapeshellarg($tmp));
