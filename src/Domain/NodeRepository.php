<?php

declare(strict_types=1);

namespace AIPanel\Domain;

use AIPanel\Infra\Database;
use AIPanel\Support\Crypto;

final class NodeRepository
{
    public function __construct(private Database $db, private Crypto $crypto)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT id, hostname, endpoint, role, status, last_seen_at, facts FROM nodes ORDER BY hostname');
    }

    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM nodes WHERE id = ?', [$id]);
    }

    /** @return list<array<string,mixed>> */
    public function withRole(string $role): array
    {
        return $this->db->select(
            "SELECT id, hostname, endpoint, role, status FROM nodes WHERE role = ? AND status = 'online' ORDER BY hostname",
            [$role]
        );
    }

    public function create(string $hostname, string $endpoint, string $role, string $secret): int
    {
        $this->db->execute(
            'INSERT INTO nodes (hostname, endpoint, role, secret_encrypted, status, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$hostname, $endpoint, $role, $this->crypto->encrypt($secret), 'unknown']
        );

        return $this->db->lastInsertId();
    }

    public function secretFor(int $nodeId): string
    {
        $row = $this->db->selectOne('SELECT secret_encrypted FROM nodes WHERE id = ?', [$nodeId]);
        if ($row === null) {
            throw new \RuntimeException("Unknown node: {$nodeId}");
        }

        return $this->crypto->decrypt((string) $row['secret_encrypted']);
    }

    /** @param array<string,mixed> $facts */
    public function recordFacts(int $nodeId, array $facts): void
    {
        $this->db->execute(
            "UPDATE nodes SET facts = ?, status = 'online', last_seen_at = NOW() WHERE id = ?",
            [json_encode($facts, JSON_UNESCAPED_SLASHES), $nodeId]
        );
    }

    public function markUnreachable(int $nodeId): void
    {
        $this->db->execute("UPDATE nodes SET status = 'unreachable' WHERE id = ?", [$nodeId]);
    }
}
