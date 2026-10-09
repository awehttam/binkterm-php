<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Support;

use BinktermPHP\Config;
use PDO;
use RuntimeException;

/**
 * TestDatabase -- the single shared source of the isolated `binktermphp_test`
 * PDO connection for database-backed unit tests. Reuses
 * DB_HOST/DB_PORT/DB_USER/DB_PASS from the same environment
 * BinktermPHP\Database would use, but NEVER reads DB_NAME and NEVER calls
 * BinktermPHP\Database::getInstance(), which resolves production.
 *
 * Fail-closed: before the PDO is ever returned, current_database() is
 * checked and must equal exactly `binktermphp_test`, or this throws
 * RuntimeException -- a misconfigured environment fails loudly instead of
 * silently reaching production.
 */
final class TestDatabase
{
    private const DATABASE_NAME = 'binktermphp_test';

    private static ?PDO $pdo = null;

    /**
     * Return the cached isolated test-database PDO, connecting and
     * fail-closed verifying it on first use.
     */
    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            Config::env('DB_HOST', 'localhost'),
            Config::env('DB_PORT', '5432'),
            self::DATABASE_NAME
        );
        $pdo = new PDO($dsn, Config::env('DB_USER', 'postgres'), Config::env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        self::assertConnectedToTestDatabase($pdo);

        self::$pdo = $pdo;

        return $pdo;
    }

    /**
     * Fail-closed production guard. Must run before any mutating fixture SQL
     * or application call that could write. Deliberately checks the database
     * identity itself (current_database()) rather than any naming convention
     * on the fixture rows -- the exact failure mode this guards against is a
     * misconfigured connection reaching production regardless of what the
     * caller intended to write.
     */
    private static function assertConnectedToTestDatabase(PDO $pdo): void
    {
        $actual = (string)$pdo->query('SELECT current_database()')->fetchColumn();
        if ($actual !== self::DATABASE_NAME) {
            throw new RuntimeException(
                "Refusing to use a non-test database for Unit tests: {$actual}"
            );
        }
    }
}
