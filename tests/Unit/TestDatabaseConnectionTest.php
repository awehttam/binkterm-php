<?php

declare(strict_types=1);

use BinktermPHP\Config;
use BinktermPHP\Database;
use BinktermPHP\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/TestDatabase.php';

/**
 * End-to-end check of the isolated test database harness against a real
 * PostgreSQL server: TestDatabase connects only to Config::getTestDatabaseName(), and
 * Database::setInstanceForTesting() routes Database::getInstance() to it.
 *
 * Skipped when pdo_pgsql is missing or the test database is not
 * reachable with DB_HOST/DB_PORT/DB_USER/DB_PASS.
 */
final class TestDatabaseConnectionTest extends TestCase
{
    public function testHarnessConnectsToTheTestDatabaseAndRoutesTheSingleton(): void
    {
        $testDb = Config::getTestDatabaseName();
        if (!extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('pdo_pgsql not available');
        }
        try {
            $pdo = TestDatabase::pdo();
        } catch (PDOException $e) {
            self::markTestSkipped('test database not reachable: ' . $e->getMessage());
        }

        self::assertSame($testDb, $pdo->query('SELECT current_database()')->fetchColumn());

        Database::setInstanceForTesting($pdo);
        try {
            $db = Database::getInstance()->getPdo();
            self::assertSame($pdo, $db);
            self::assertSame($testDb, $db->query('SELECT current_database()')->fetchColumn());
            self::assertSame('UTC', $db->query('SHOW TIME ZONE')->fetchColumn());
        } finally {
            Database::resetInstanceForTesting();
        }
    }
}
