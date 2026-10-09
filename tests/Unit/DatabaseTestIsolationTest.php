<?php

declare(strict_types=1);

use BinktermPHP\Database;
use PHPUnit\Framework\TestCase;

/**
 * Database::setInstanceForTesting() lets database-backed tests point every
 * Database::getInstance() call at the isolated `binktermphp_test` database.
 * It must refuse any connection to another database (independently of any
 * helper's own check) and leave the existing singleton untouched.
 * Runs without a database server, using PDO stubs.
 */
final class DatabaseTestIsolationTest extends TestCase
{
    private mixed $original;
    private ReflectionProperty $instance;

    protected function setUp(): void
    {
        $this->instance = new ReflectionProperty(Database::class, 'instance');
        $this->instance->setAccessible(true);
        $this->original = $this->instance->getValue();
    }

    protected function tearDown(): void
    {
        $this->instance->setValue(null, $this->original);
    }

    public function testRefusesAConnectionToAnyOtherDatabaseAndKeepsTheSingleton(): void
    {
        $sentinel = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        $this->instance->setValue(null, $sentinel);

        foreach (['binktermphp', 'postgres', 'binktermphp_test_copy', ''] as $name) {
            try {
                Database::setInstanceForTesting(new IsolationProbePdo($name));
                self::fail("must refuse database '{$name}'");
            } catch (RuntimeException $e) {
                self::assertStringContainsString('only "binktermphp_test" is accepted', $e->getMessage());
            }
            self::assertSame($sentinel, $this->instance->getValue(), 'singleton must be left untouched');
        }
    }

    public function testInstallsTheTestConnectionAndInitializesItsSession(): void
    {
        $pdo = new IsolationProbePdo('binktermphp_test');
        Database::setInstanceForTesting($pdo);

        self::assertSame($pdo, Database::getInstance()->getPdo());
        self::assertContains("SET TIME ZONE 'UTC'", $pdo->executed);
    }

    public function testResetDropsTheSingletonWithoutConnecting(): void
    {
        Database::setInstanceForTesting(new IsolationProbePdo('binktermphp_test'));
        Database::resetInstanceForTesting();

        self::assertNull($this->instance->getValue());
    }
}

final class IsolationProbePdo extends PDO
{
    /** @var list<string> */
    public array $executed = [];

    public function __construct(private string $databaseName)
    {
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return new IsolationProbeStatement($this->databaseName);
    }

    public function exec(string $statement): int|false
    {
        $this->executed[] = $statement;

        return 0;
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return "'" . str_replace("'", "''", $string) . "'";
    }
}

final class IsolationProbeStatement extends PDOStatement
{
    public function __construct(private string $value)
    {
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->value;
    }
}
