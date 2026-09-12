<?php

declare(strict_types=1);

namespace AIPanel\Infra;

use PDO;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(
        private string $dsn,
        private string $user,
        private string $pass,
    ) {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= new PDO($this->dsn, $this->user, $this->pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /** @param array<string|int,mixed> $params */
    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @param array<string|int,mixed> $params */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->select($sql, $params)[0] ?? null;

        return $row === null ? null : $row;
    }

    /** @param array<string|int,mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($this);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
