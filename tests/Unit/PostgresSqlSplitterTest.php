<?php

declare(strict_types=1);

use BinktermPHP\PostgresSqlSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostgresSqlSplitterTest extends TestCase
{
    public static function protectedSql(): array
    {
        return [
            'ordinary' => ['SELECT 1;SELECT 2;', 2],
            'dollar body' => ['CREATE FUNCTION f() RETURNS void AS $$ BEGIN PERFORM 1; PERFORM 2; END; $$ LANGUAGE plpgsql; SELECT 1;', 2],
            'tagged body' => ['DO $name$ BEGIN PERFORM $$a;b$$; END; $name$;', 1],
            'procedure' => ['CREATE PROCEDURE p() LANGUAGE plpgsql AS $$ BEGIN RAISE NOTICE \'a;b\'; END; $$;', 1],
            'single quote' => ["SELECT 'a;--b/*x*/''c'; SELECT 2", 2],
            'identifier' => ['SELECT "a;""b"; SELECT 2;', 2],
            'line comment' => ["-- ;\nSELECT 1; -- ignored ;", 1],
            'nested comments' => ["/* ; /* nested ; */ x */ SELECT 1; /* tail */", 1],
            'escape string' => ["SELECT E'a\\';b'; SELECT 2;", 2],
            'continued escape string' => ["SELECT E'a'\n'\\';b'; SELECT 2;", 2],
            'ordinary backslash' => ["SELECT 'a\\'; SELECT 2;", 2],
            'dollar identifier' => ['SELECT foo$tag$bar; SELECT 2;', 2],
            'parameters' => ['SELECT $1; SELECT 2;', 2],
            'case sensitive tags' => ['SELECT $Tag$a;$tag$b$Tag$;', 1],
            'unicode tag' => ['SELECT $é$a;b$é$;', 1],
            'empty' => [" ; /* x */; -- x", 0],
        ];
    }

    #[DataProvider('protectedSql')]
    public function testProtectedConstructs(string $sql, int $count): void
    {
        $parts = PostgresSqlSplitter::split($sql);
        self::assertCount($count, $parts);
        foreach ($parts as $part) {
            self::assertStringContainsString($part, $sql);
        }
    }

    public function testLegacyBackslashMode(): void
    {
        self::assertCount(2, PostgresSqlSplitter::split("SELECT 'a\\';b'; SELECT 2;", false));
    }

    public function testModeIsReadAfterEachYield(): void
    {
        $mode = true;
        $readMode = function () use (&$mode): bool { return $mode; };
        $parts = [];
        foreach (PostgresSqlSplitter::statements("SET standard_conforming_strings=off; SELECT 'a\\';b';", $readMode) as $part) {
            $parts[] = $part;
            $mode = false;
        }
        self::assertCount(2, $parts);
        self::assertSame("SELECT 'a\\';b'", $parts[1]);
    }

    public static function malformed(): array
    {
        return array_map(fn ($s) => [$s], ["SELECT 'a", 'SELECT "a', '/* a', '/* /* a */', 'DO $$a;', 'DO $tag$a;', "SELECT E'a\\'"]);
    }

    #[DataProvider('malformed')]
    public function testUnclosedFailsClearly(string $sql): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unclosed PostgreSQL');
        PostgresSqlSplitter::split($sql);
    }

    public function testCompleteMigrationCorpus(): void
    {
        $paths = glob(__DIR__ . '/../../database/migrations/*.sql');
        self::assertNotEmpty($paths);
        foreach ($paths as $path) {
            try {
                self::assertIsArray(PostgresSqlSplitter::split(file_get_contents($path)), basename($path));
            } catch (RuntimeException $e) {
                self::fail(basename($path) . ': ' . $e->getMessage());
            }
        }
    }
}
