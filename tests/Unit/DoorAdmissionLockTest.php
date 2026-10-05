<?php

declare(strict_types=1);

use BinktermPHP\Binkp\Logger;
use BinktermPHP\DoorCapacityException;
use BinktermPHP\DoorSessionManager;
use PHPUnit\Framework\TestCase;

/**
 * Door admission must check the per-door cap and pick a node inside one
 * critical section: DoorSessionManager::findAvailableNode() takes a
 * transaction-scoped advisory lock before counting sessions or reading used
 * nodes, and refuses with DoorCapacityException (rolling back) when the door
 * is full. Runs without a database (recording PDO stub); the concurrent
 * behaviour itself is covered by tests/Integration/DoorAdmissionRaceTest.php.
 */
final class DoorAdmissionLockTest extends TestCase
{
    public function testLockIsTakenBeforeCountingAndAllocating(): void
    {
        $pdo = new AdmissionRecordingPdo(activeForDoor: 0, usedNodes: [1, 2]);
        $node = $this->allocate($pdo, 'lord', 3);

        self::assertSame(3, $node);
        self::assertTrue($pdo->inTransaction(), 'caller inserts and commits inside the same transaction');
        self::assertSame(['begin', 'advisory_lock', 'count:lord', 'used_nodes'], $pdo->events);
        self::assertStringContainsString((string)DoorSessionManager::ADMISSION_LOCK_KEY, $pdo->lockSql);
        self::assertStringContainsString('pg_advisory_xact_lock', $pdo->lockSql, 'transaction-scoped: released on commit/rollback');
    }

    public function testFullDoorIsRefusedAndRolledBack(): void
    {
        $pdo = new AdmissionRecordingPdo(activeForDoor: 2, usedNodes: [1, 2]);

        try {
            $this->allocate($pdo, 'lord', 2);
            self::fail('expected DoorCapacityException');
        } catch (DoorCapacityException $e) {
            self::assertSame('lord', $e->doorId);
            self::assertSame(2, $e->maxNodes);
            self::assertSame(2, $e->activeSessions);
        }
        self::assertFalse($pdo->inTransaction());
        self::assertSame(['begin', 'advisory_lock', 'count:lord', 'rollback'], $pdo->events);
    }

    public function testNoPerDoorCapSkipsTheCount(): void
    {
        $pdo = new AdmissionRecordingPdo(activeForDoor: 99, usedNodes: []);

        self::assertSame(1, $this->allocate($pdo, 'lord', null));
        self::assertSame(['begin', 'advisory_lock', 'used_nodes'], $pdo->events);
    }

    public function testLaunchRoutesPassTheirCapAndMapCapacityTo503(): void
    {
        $routes = (string)file_get_contents(__DIR__ . '/../../routes/door-routes.php');

        self::assertStringContainsString("\$sessionManager->startSession(\$guestUserId, \$doorName, \$userData, 'native', \$maxNodes);", $routes);
        self::assertSame(2, substr_count($routes, 'catch (DoorCapacityException $e)'));
    }

    private function allocate(AdmissionRecordingPdo $pdo, ?string $door, ?int $maxNodes): ?int
    {
        $manager = (new ReflectionClass(DoorSessionManager::class))->newInstanceWithoutConstructor();
        foreach (['db' => $pdo, 'maxSessions' => 10, 'logger' => new Logger('/dev/null', 'ERROR', false)] as $name => $value) {
            $property = new ReflectionProperty(DoorSessionManager::class, $name);
            $property->setAccessible(true);
            $property->setValue($manager, $value);
        }
        $method = new ReflectionMethod(DoorSessionManager::class, 'findAvailableNode');
        $method->setAccessible(true);

        return $method->invoke($manager, $door, $maxNodes);
    }
}

final class AdmissionRecordingPdo extends PDO
{
    /** @var list<string> */
    public array $events = [];
    public string $lockSql = '';
    private bool $tx = false;

    public function __construct(private int $activeForDoor, private array $usedNodes)
    {
    }

    public function beginTransaction(): bool
    {
        $this->events[] = 'begin';
        return $this->tx = true;
    }

    public function rollBack(): bool
    {
        $this->events[] = 'rollback';
        $this->tx = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->tx;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (str_contains($query, 'pg_advisory')) {
            $this->events[] = 'advisory_lock';
            $this->lockSql = $query;
            return new AdmissionRecordingStatement([]);
        }
        $this->events[] = 'used_nodes';
        return new AdmissionRecordingStatement($this->usedNodes);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $pdo = $this;
        return new AdmissionRecordingStatement([$this->activeForDoor], function (array $params) use ($pdo) {
            $pdo->events[] = 'count:' . ($params[0] ?? '');
        });
    }
}

final class AdmissionRecordingStatement extends PDOStatement
{
    public function __construct(private array $rows, private ?Closure $onExecute = null)
    {
    }

    public function execute(?array $params = null): bool
    {
        if ($this->onExecute) {
            ($this->onExecute)($params ?? []);
        }
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->rows[0] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }

    public function closeCursor(): bool
    {
        return true;
    }
}
