<?php

declare(strict_types=1);

namespace AIPanel\Tasks;

/**
 * The single contract shared by the UI, the HTTP API and the job queue.
 *
 * Nothing may run against a node unless it appears here, with typed parameters
 * and a required node role. Adding a capability means one entry here plus one
 * handler in the node agent.
 */
final class Catalogue
{
    /**
     * @var array<string,array{
     *   role:string,
     *   destructive:bool,
     *   description:string,
     *   params:array<string,array{type:string,required:bool,pattern?:string,enum?:list<string>,description:string}>
     * }>
     */
    private const TASKS = [
        'node.facts' => [
            'role' => 'any',
            'destructive' => false,
            'description' => 'Collect inventory facts from a node: OS, load, disk, memory, installed PHP versions.',
            'params' => [],
        ],
        'site.create' => [
            'role' => 'web',
            'destructive' => false,
            'description' => 'Provision a website as an isolated tenant: jailed Linux user, chrooted SSH, PHP-FPM pool, vhost, release layout and repo wiring.',
            'params' => [
                'domain' => ['type' => 'string', 'required' => true, 'pattern' => '/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/', 'description' => 'Fully qualified domain name.'],
                'site_user' => ['type' => 'string', 'required' => true, 'pattern' => '/^site_[a-z0-9]{6,12}$/', 'description' => 'Linux user for this tenant; owns everything under its home and nothing else.'],
                'repo' => ['type' => 'string', 'required' => true, 'pattern' => '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', 'description' => 'GitHub repository as owner/name. The main branch is what runs live.'],
                'deploy_public_key' => ['type' => 'string', 'required' => true, 'description' => 'Read-only deploy public key the node uses to fetch this repo.'],
                'php_version' => ['type' => 'string', 'required' => false, 'enum' => ['8.1', '8.2', '8.3', '8.4'], 'description' => 'PHP-FPM version for the pool.'],
                'aliases' => ['type' => 'array', 'required' => false, 'description' => 'Additional server_name entries.'],
                'document_root' => ['type' => 'string', 'required' => false, 'pattern' => '#^[a-zA-Z0-9_./-]{1,64}$#', 'description' => 'Web root relative to the release directory, e.g. public.'],
            ],
        ],
        'site.delete' => [
            'role' => 'web',
            'destructive' => true,
            'description' => 'Remove a tenant entirely: vhost, FPM pool, SSH access, Linux user and (optionally) its home directory.',
            'params' => [
                'site_user' => ['type' => 'string', 'required' => true, 'pattern' => '/^site_[a-z0-9]{6,12}$/', 'description' => 'Tenant user to remove.'],
                'domain' => ['type' => 'string', 'required' => true, 'description' => 'Domain of the site to remove.'],
                'purge_files' => ['type' => 'bool', 'required' => false, 'description' => 'Delete the tenant home directory as well.'],
            ],
        ],
        'site.suspend' => [
            'role' => 'web',
            'destructive' => true,
            'description' => 'Take a site offline: stop its FPM pool and lock its SSH access, leaving all data in place.',
            'params' => [
                'site_user' => ['type' => 'string', 'required' => true, 'pattern' => '/^site_[a-z0-9]{6,12}$/', 'description' => 'Tenant user to suspend.'],
                'reason' => ['type' => 'string', 'required' => false, 'description' => 'Reason recorded in the audit log.'],
            ],
        ],
        'ssh.key_add' => [
            'role' => 'web',
            'destructive' => false,
            'description' => "Authorise an SSH public key for one tenant. The key can only reach that tenant's jail.",
            'params' => [
                'site_user' => ['type' => 'string', 'required' => true, 'pattern' => '/^site_[a-z0-9]{6,12}$/', 'description' => 'Tenant the key belongs to.'],
                'public_key' => ['type' => 'string', 'required' => true, 'pattern' => '#^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp256) [A-Za-z0-9+/=]{32,} ?[^\\r\\n]*$#', 'description' => 'OpenSSH public key line.'],
                'label' => ['type' => 'string', 'required' => false, 'description' => 'Human label for the key.'],
            ],
        ],
        'ssh.key_remove' => [
            'role' => 'web',
            'destructive' => false,
            'description' => 'Revoke a previously authorised SSH key for one tenant.',
            'params' => [
                'site_user' => ['type' => 'string', 'required' => true, 'pattern' => '/^site_[a-z0-9]{6,12}$/', 'description' => 'Tenant the key belongs to.'],
                'fingerprint' => ['type' => 'string', 'required' => true, 'description' => 'SHA256 fingerprint of the key to remove.'],
            ],
        ],
        'deploy.run' => [
            'role' => 'web',
            'destructive' => false,
            'description' => 'Build an immutable release from an exact commit on main, health-check it, then atomically repoint current/ at it and reload only this site\'s pool.',
            'params' => [
                'site_user' => ['type' => 'string', 'required' => true, 'pattern' => '/^site_[a-z0-9]{6,12}$/', 'description' => 'Tenant to deploy.'],
                'repo' => ['type' => 'string', 'required' => true, 'pattern' => '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', 'description' => 'GitHub repository as owner/name.'],
                'commit' => ['type' => 'string', 'required' => true, 'pattern' => '/^[0-9a-f]{40}$/', 'description' => 'Full commit SHA to deploy. Always a SHA, never a branch name.'],
                'run_migrations' => ['type' => 'bool', 'required' => false, 'description' => 'Run the project\'s migration command as part of the release.'],
            ],
        ],
        'deploy.rollback' => [
            'role' => 'web',
            'destructive' => true,
            'description' => 'Repoint current/ at a previous release directory and reload the pool. Data and shared/ are untouched.',
            'params' => [
                'site_user' => ['type' => 'string', 'required' => true, 'pattern' => '/^site_[a-z0-9]{6,12}$/', 'description' => 'Tenant to roll back.'],
                'release' => ['type' => 'string', 'required' => false, 'pattern' => '/^[0-9]{8}T[0-9]{6}Z$/', 'description' => 'Release id to roll back to. Defaults to the previous release.'],
            ],
        ],
        'site.set_php_version' => [
            'role' => 'web',
            'destructive' => false,
            'description' => "Switch a tenant's FPM pool to a different PHP version and reload it.",
            'params' => [
                'site_user' => ['type' => 'string', 'required' => true, 'pattern' => '/^site_[a-z0-9]{6,12}$/', 'description' => 'Tenant to switch.'],
                'domain' => ['type' => 'string', 'required' => true, 'description' => 'Domain of the site.'],
                'php_version' => ['type' => 'string', 'required' => true, 'enum' => ['8.1', '8.2', '8.3', '8.4'], 'description' => 'Target PHP version.'],
            ],
        ],
        'ssl.issue' => [
            'role' => 'web',
            'destructive' => false,
            'description' => "Issue or renew a Let's Encrypt certificate for a domain, switch its vhost to HTTPS and reload nginx. Idempotent: a certificate that is not yet due for renewal is left alone.",
            'params' => [
                'domain' => ['type' => 'string', 'required' => true, 'description' => 'Domain to issue for.'],
                'site_user' => ['type' => 'string', 'required' => true, 'pattern' => '/^site_[a-z0-9]{6,12}$/', 'description' => 'Tenant that owns the domain.'],
                'document_root' => ['type' => 'string', 'required' => false, 'pattern' => '#^[a-zA-Z0-9_./-]{1,64}$#', 'description' => 'Web root relative to the release directory.'],
                'email' => ['type' => 'string', 'required' => false, 'description' => 'ACME account contact address.'],
            ],
        ],
        'db.create' => [
            'role' => 'mysql',
            'destructive' => false,
            'description' => 'Create a MySQL database plus a dedicated user scoped to it.',
            'params' => [
                'name' => ['type' => 'string', 'required' => true, 'pattern' => '/^[a-z0-9_]{1,48}$/', 'description' => 'Database name.'],
                'user' => ['type' => 'string', 'required' => true, 'pattern' => '/^[a-z0-9_]{1,32}$/', 'description' => 'Database user to create.'],
            ],
        ],
        'db.drop' => [
            'role' => 'mysql',
            'destructive' => true,
            'description' => 'Drop a database and its dedicated user.',
            'params' => [
                'name' => ['type' => 'string', 'required' => true, 'pattern' => '/^[a-z0-9_]{1,48}$/', 'description' => 'Database name.'],
            ],
        ],
        'dns.zone_create' => [
            'role' => 'dns',
            'destructive' => false,
            'description' => 'Create an authoritative zone with default SOA/NS/A records.',
            'params' => [
                'zone' => ['type' => 'string', 'required' => true, 'description' => 'Zone apex, e.g. example.com.'],
                'ip' => ['type' => 'string', 'required' => true, 'description' => 'IPv4 address for the apex A record.'],
            ],
        ],
        'dns.record_upsert' => [
            'role' => 'dns',
            'destructive' => false,
            'description' => 'Create or replace a single DNS record in an existing zone.',
            'params' => [
                'zone' => ['type' => 'string', 'required' => true, 'description' => 'Zone the record belongs to.'],
                'name' => ['type' => 'string', 'required' => true, 'description' => 'Record name relative to the zone, or @ for the apex.'],
                'type' => ['type' => 'string', 'required' => true, 'enum' => ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV'], 'description' => 'Record type.'],
                'value' => ['type' => 'string', 'required' => true, 'description' => 'Record value.'],
                'ttl' => ['type' => 'int', 'required' => false, 'description' => 'TTL in seconds.'],
            ],
        ],
        'mail.mailbox_create' => [
            'role' => 'mail',
            'destructive' => false,
            'description' => 'Create a mailbox for a domain hosted on this panel.',
            'params' => [
                'address' => ['type' => 'string', 'required' => true, 'description' => 'Full email address.'],
                'quota_mb' => ['type' => 'int', 'required' => false, 'description' => 'Mailbox quota in megabytes.'],
            ],
        ],
        'backup.run' => [
            'role' => 'any',
            'destructive' => false,
            'description' => 'Run a backup of a site and/or its database to the configured target.',
            'params' => [
                'domain' => ['type' => 'string', 'required' => true, 'description' => 'Site to back up.'],
                'include_db' => ['type' => 'bool', 'required' => false, 'description' => 'Include the linked database.'],
            ],
        ],
        'backup.restore' => [
            'role' => 'any',
            'destructive' => true,
            'description' => 'Restore a previously taken backup over the live site.',
            'params' => [
                'domain' => ['type' => 'string', 'required' => true, 'description' => 'Site to restore.'],
                'backup_id' => ['type' => 'string', 'required' => true, 'description' => 'Identifier of the backup to restore.'],
            ],
        ],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::TASKS);
    }

    public static function has(string $task): bool
    {
        return isset(self::TASKS[$task]);
    }

    /** @return array<string,mixed> */
    public static function get(string $task): array
    {
        if (!self::has($task)) {
            throw new \InvalidArgumentException("Unknown task: {$task}");
        }

        return self::TASKS[$task];
    }

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        return self::TASKS;
    }

    public static function isDestructive(string $task): bool
    {
        return (bool) self::get($task)['destructive'];
    }

    public static function requiredRole(string $task): string
    {
        return (string) self::get($task)['role'];
    }
}
