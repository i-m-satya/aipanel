<?php

declare(strict_types=1);

namespace AIPanel\Tests;

use AIPanel\Infra\Migrator;
use PHPUnit\Framework\TestCase;

/**
 * The migration splitter decides which DDL ever reaches the database, and a
 * statement it drops fails silently — the migration is recorded as applied
 * with a table missing. These tests exist because an earlier naive splitter
 * discarded every statement that happened to be preceded by a comment.
 */
final class MigrationTest extends TestCase
{
    /** @return list<string> */
    private function split(string $sql): array
    {
        $method = new \ReflectionMethod(Migrator::class, 'statements');
        $method->setAccessible(true);

        return $method->invoke((new \ReflectionClass(Migrator::class))->newInstanceWithoutConstructor(), $sql);
    }

    public function testCommentedStatementsAreNotDropped(): void
    {
        $sql = <<<'SQL'
            -- A leading comment.
            CREATE TABLE a (id INT);

            -- A comment before the second statement.
            CREATE TABLE b (id INT);
            SQL;

        $statements = $this->split($sql);

        self::assertCount(2, $statements);
        self::assertStringStartsWith('CREATE TABLE a', $statements[0]);
        self::assertStringStartsWith('CREATE TABLE b', $statements[1]);
    }

    public function testAFileOfOnlyCommentsProducesNothing(): void
    {
        self::assertSame([], $this->split("-- nothing here\n-- nor here\n"));
    }

    public function testATrailingStatementWithoutASemicolonIsKept(): void
    {
        $statements = $this->split("CREATE TABLE a (id INT);\nALTER TABLE a ADD COLUMN b INT\n");

        self::assertCount(2, $statements);
        self::assertStringStartsWith('ALTER TABLE a', $statements[1]);
    }

    public function testSemicolonsInsideStringsDoNotSplit(): void
    {
        $statements = $this->split("INSERT INTO t (v) VALUES ('a;b');\nCREATE TABLE u (id INT);");

        self::assertCount(2, $statements);
        self::assertStringContainsString("'a;b'", $statements[0]);
    }

    public function testCommentMarkersInsideStringsAreNotStripped(): void
    {
        $statements = $this->split("INSERT INTO t (v) VALUES ('a -- b');");

        self::assertCount(1, $statements);
        self::assertStringContainsString('a -- b', $statements[0]);
    }

    public function testBlockCommentsAreRemovedButExecutableCommentsSurvive(): void
    {
        $statements = $this->split("/* dropped */ CREATE TABLE a (id INT);\n/*!40101 SET NAMES utf8 */;");

        self::assertCount(2, $statements);
        self::assertStringStartsWith('CREATE TABLE a', $statements[0]);
        self::assertStringContainsString('/*!40101', $statements[1]);
    }

    /**
     * Every CREATE/ALTER in the shipped migrations must survive splitting — a
     * dropped one would leave production missing a table.
     */
    public function testShippedMigrationsSplitIntoEveryStatement(): void
    {
        $files = glob(dirname(__DIR__) . '/db/migrations/*.sql') ?: [];
        self::assertNotSame([], $files, 'No migrations found.');

        foreach ($files as $file) {
            $sql = (string) file_get_contents($file);
            $expected = preg_match_all('/^CREATE TABLE /m', $sql)
                + preg_match_all('/^ALTER TABLE /m', $sql)
                + preg_match_all('/^DROP TABLE /m', $sql);

            $statements = $this->split($sql);

            self::assertCount(
                $expected,
                $statements,
                basename($file) . ' did not split into one statement per CREATE/ALTER/DROP'
            );

            foreach ($statements as $statement) {
                self::assertMatchesRegularExpression(
                    '/^(CREATE|ALTER|INSERT|DROP|SET)\b/i',
                    $statement,
                    basename($file) . ' produced a statement that is not DDL: ' . substr($statement, 0, 60)
                );
            }
        }
    }
}
