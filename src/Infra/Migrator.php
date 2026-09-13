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

    /**
     * Split a migration file into individual statements.
     *
     * Naive splitting is why this is hand-written: splitting on ";\n" and then
     * discarding chunks that begin with "--" silently drops every statement
     * that happens to be preceded by a comment. This walks the SQL instead,
     * tracking quoting so a semicolon or comment marker inside a string or a
     * backticked identifier is not treated as syntax.
     *
     * @return list<string>
     */
    private function statements(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $quote = null; // ' " or ` when inside a quoted region

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $sql[++$i]; // escaped character, never a delimiter
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            // Line comment: drop it through to the end of the line.
            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                $current .= "\n";
                continue;
            }

            // Block comment. MySQL executable comments (/*! ... */) are left
            // intact so version-gated DDL still reaches the server.
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*' && ($sql[$i + 2] ?? '') !== '!') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
                continue;
            }

            if ($char === ';') {
                $statements[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $statements[] = $current;

        // A trailing statement without a semicolon is still a statement; an
        // empty tail (or a file that is only comments) is not.
        return array_values(array_filter(
            array_map('trim', $statements),
            static fn (string $statement): bool => $statement !== ''
        ));
    }
}
