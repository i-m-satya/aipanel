<?php

declare(strict_types=1);

namespace AIPanel\Domain;

use AIPanel\Infra\Database;

final class SiteRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function forAccount(int $accountId): array
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

    public function create(int $accountId, int $nodeId, string $domain, string $phpVersion): int
    {
        $this->db->execute(
            "INSERT INTO sites (account_id, node_id, domain, php_version, status, created_at)
             VALUES (?, ?, ?, ?, 'provisioning', NOW())",
            [$accountId, $nodeId, $domain, $phpVersion]
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
