<?php

declare(strict_types=1);

namespace AIPanel\Domain;

use AIPanel\Infra\Database;

/**
 * A website is two rows: a production environment and a sandbox environment.
 * Both are ordinary tenants on the same repository, each watching its own
 * branch, so the sandbox needs no special delivery path.
 */
final class SiteRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Both environments of a website, whichever one you start from.
     *
     * @param array<string,mixed> $site
     * @return array<string,mixed>|null
     */
    public function productionFor(array $site): ?array
    {
        if (($site['environment'] ?? 'production') === 'production') {
            return $site;
        }

        return $site['parent_site_id'] === null
            ? null
            : $this->db->selectOne('SELECT * FROM sites WHERE id = ?', [(int) $site['parent_site_id']]);
    }

    /**
     * @param array<string,mixed> $site
     * @return array<string,mixed>|null
     */
    public function sandboxFor(array $site): ?array
    {
        if (($site['environment'] ?? 'production') === 'sandbox') {
            return $site;
        }

        return $this->db->selectOne(
            "SELECT * FROM sites WHERE parent_site_id = ? AND environment = 'sandbox'",
            [(int) $site['id']]
        );
    }

    /**
     * Websites in an account: production rows, each carrying its sandbox's
     * state, so a listing shows one row per website rather than two.
     *
     * @return list<array<string,mixed>>
     */
    public function forAccount(int $accountId): array
    {
        return $this->db->select(
            "SELECT s.*, n.hostname AS node_hostname,
                    sb.id AS sandbox_id, sb.domain AS sandbox_domain,
                    sb.live_commit AS sandbox_commit, sb.status AS sandbox_status
             FROM sites s
             JOIN nodes n ON n.id = s.node_id
             LEFT JOIN sites sb ON sb.parent_site_id = s.id AND sb.environment = 'sandbox'
             WHERE s.account_id = ? AND s.environment = 'production'
             ORDER BY s.domain",
            [$accountId]
        );
    }

    /** Every environment row, for the places that genuinely need both. */
    public function allEnvironmentsForAccount(int $accountId): array
    {
        return $this->db->select(
            'SELECT s.*, n.hostname AS node_hostname
             FROM sites s JOIN nodes n ON n.id = s.node_id
             WHERE s.account_id = ? ORDER BY s.domain',
            [$accountId]
        );
    }

    public function findForAccount(int $id, int $accountId): ?array
    {
        return $this->db->selectOne('SELECT * FROM sites WHERE id = ? AND account_id = ?', [$id, $accountId]);
    }

    public function findByDomainForAccount(string $domain, int $accountId): ?array
    {
        return $this->db->selectOne('SELECT * FROM sites WHERE domain = ? AND account_id = ?', [$domain, $accountId]);
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function createEnvironment(array $attributes): int
    {
        $this->db->execute(
            "INSERT INTO sites
                (account_id, node_id, domain, environment, parent_site_id, site_user, repo,
                 github_installation_id, deploy_branch, document_root, php_version, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'provisioning', NOW())",
            [
                $attributes['account_id'],
                $attributes['node_id'],
                $attributes['domain'],
                $attributes['environment'],
                $attributes['parent_site_id'] ?? null,
                $attributes['site_user'],
                $attributes['repo'],
                $attributes['github_installation_id'],
                $attributes['deploy_branch'],
                $attributes['document_root'],
                $attributes['php_version'],
            ]
        );

        return $this->db->lastInsertId();
    }

    public function setStatus(int $siteId, string $status): void
    {
        $this->db->execute('UPDATE sites SET status = ? WHERE id = ?', [$status, $siteId]);
    }

    public function delete(int $siteId): void
    {
        $this->db->execute('DELETE FROM sites WHERE id = ?', [$siteId]);
    }
}
