<?php

declare(strict_types=1);

namespace AIPanel\Infra;

final class Migrator
{
    public function __construct(private Database $db, private string $path)
    {
    }

    /** @return list<string> names of applied migrations */
    public function migrate(): array
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS migrations (
                name VARCHAR(255) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $applied = array_column($this->db->select('SELECT name FROM migrations'), 'name');
        $files = glob(rtrim($this->path, '/') . '/*.sql') ?: [];
        sort($files);

        $ran = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            foreach ($this->statements($sql) as $statement) {
                $this->db->execute($statement);
            }
            $this->db->execute(
                'INSERT INTO migrations (name, applied_at) VALUES (?, NOW())',
                [$name]
            );
            $ran[] = $name;
        }

        return $ran;
    }

    /** @return list<string> */
    private function statements(string $sql): array
    {
        $parts = array_map('trim', explode(";\n", $sql . "\n"));

        return array_values(array_filter($parts, static fn (string $s): bool => $s !== '' && !str_starts_with($s, '--')));
    }
}
