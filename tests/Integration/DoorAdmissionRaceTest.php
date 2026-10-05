<?php

declare(strict_types=1);

use BinktermPHP\Binkp\Logger;
use BinktermPHP\DoorCapacityException;
use BinktermPHP\DoorSessionManager;
use PHPUnit\Framework\TestCase;

/**
 * Concurrent door admission against a real PostgreSQL server.
 *
 * Overlap is forced: the test holds DoorSessionManager::ADMISSION_LOCK_KEY
 * while several forked processes (each with its own connection) enter
 * findAvailableNode(), waits until they are all blocked on it, then releases
 * it. A door with max_nodes = 1 must admit exactly one of two launches, and
 * concurrent launches must never receive the same node number.
 *
 * Opt-in: set DOOR_ADMISSION_TEST_DSN (e.g. pgsql:host=127.0.0.1;dbname=door_test)
 * and optionally DOOR_ADMISSION_TEST_USER / DOOR_ADMISSION_TEST_PASS. The test
 * creates and drops its own schema; nothing else in the database is touched.
 */
final class DoorAdmissionRaceTest extends TestCase
{
    private ?PDO $admin = null;
    private string $schema = '';
    private string $dsn = '';
    private string $user = '';
    private string $pass = '';

    protected function setUp(): void
    {
        $dsn = getenv('DOOR_ADMISSION_TEST_DSN');
        if (!$dsn || !extension_loaded('pdo_pgsql') || !function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            self::markTestSkipped('Set DOOR_ADMISSION_TEST_DSN to an isolated PostgreSQL database (needs pdo_pgsql, pcntl and posix).');
        }
        $this->dsn = $dsn;
        $this->user = getenv('DOOR_ADMISSION_TEST_USER') ?: 'postgres';
        $this->pass = getenv('DOOR_ADMISSION_TEST_PASS') ?: '';
        $this->admin = $this->connect();
        $this->schema = 'door_admission_' . bin2hex(random_bytes(4));
        $this->admin->exec("CREATE SCHEMA {$this->schema}");
        $this->admin->exec("SET search_path TO {$this->schema}");
        $this->admin->exec(<<<'SQL'
CREATE TABLE door_sessions (
    session_id  TEXT PRIMARY KEY,
    user_id     INT,
    door_id     TEXT NOT NULL,
    node_number INT NOT NULL,
    expires_at  TIMESTAMPTZ NOT NULL,
    ended_at    TIMESTAMPTZ
);
SQL);
    }

    protected function tearDown(): void
    {
        if ($this->admin !== null) {
            $this->admin->exec('SELECT pg_advisory_unlock_all()');
            if ($this->schema !== '') {
                $this->admin->exec("DROP SCHEMA IF EXISTS {$this->schema} CASCADE");
            }
        }
    }

    public function testDoorWithOneSlotAdmitsExactlyOneOfTwoConcurrentLaunches(): void
    {
        $results = $this->race([['lord', 1], ['lord', 1]]);

        self::assertSame(1, count(array_filter($results, fn ($r) => $r['ok'])), json_encode($results));
        self::assertSame(1, count(array_filter($results, fn ($r) => ($r['error'] ?? '') === DoorCapacityException::class)), json_encode($results));
        self::assertSame(1, (int)$this->admin->query("SELECT COUNT(*) FROM door_sessions WHERE door_id = 'lord'")->fetchColumn());
    }

    public function testConcurrentLaunchesGetDistinctNodes(): void
    {
        $results = $this->race([['a', null], ['b', null], ['c', null]]);

        $nodes = array_map(fn ($r) => $r['node'], array_filter($results, fn ($r) => $r['ok']));
        self::assertCount(3, $nodes, json_encode($results));
        self::assertSame(3, count(array_unique($nodes)), 'node numbers must be distinct: ' . json_encode($nodes));
    }

    public function testSequentialLaunchAtCapacityIsRefused(): void
    {
        self::assertTrue($this->race([['lord', 1]])[0]['ok']);
        $second = $this->race([['lord', 1]])[0];
        self::assertSame(DoorCapacityException::class, $second['error'] ?? null);
    }

    /**
     * @param list<array{0:string,1:?int}> $launches door id + per-door cap
     * @return list<array<string,mixed>>
     */
    private function race(array $launches): array
    {
        $this->admin->query('SELECT pg_advisory_lock(' . DoorSessionManager::ADMISSION_LOCK_KEY . ')')->closeCursor();

        $children = [];
        foreach ($launches as $i => [$door, $cap]) {
            $out = tempnam(sys_get_temp_dir(), 'door-race-');
            $pid = pcntl_fork();
            if ($pid === 0) {
                $result = ['ok' => false];
                try {
                    $db = $this->connect();
                    $db->exec("SET search_path TO {$this->schema}");
                    $node = $this->allocate($db, $door, $cap);
                    if ($node !== null) {
                        $db->prepare("INSERT INTO door_sessions (session_id, user_id, door_id, node_number, expires_at) VALUES (?, ?, ?, ?, NOW() + INTERVAL '1 hour')")
                            ->execute([bin2hex(random_bytes(8)), $i + 1, $door, $node]);
                        $db->commit();
                        $result = ['ok' => true, 'node' => $node];
                    }
                } catch (Throwable $e) {
                    $result = ['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage()];
                }
                file_put_contents($out, json_encode($result));
                // Leave without PHP shutdown. The child inherited the parent's
                // $this->admin socket, and running PDO's destructor here would
                // send the server a Terminate on it and close the parent's
                // connection.
                posix_kill(posix_getpid(), SIGKILL);
            }
            $children[] = [$pid, $out];
        }

        // Wait until every child is blocked on the admission lock, then release it.
        $waiting = 0;
        $deadline = microtime(true) + 10;
        do {
            $waiting = (int)$this->admin->query("SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted")->fetchColumn();
            if ($waiting >= count($launches)) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        self::assertSame(count($launches), $waiting, 'all launches must be blocked on the admission lock before it is released');
        $this->admin->query('SELECT pg_advisory_unlock(' . DoorSessionManager::ADMISSION_LOCK_KEY . ')')->closeCursor();

        $results = [];
        foreach ($children as [$pid, $out]) {
            pcntl_waitpid($pid, $status);
            $results[] = json_decode((string)file_get_contents($out), true);
            @unlink($out);
        }

        return $results;
    }

    private function allocate(PDO $db, string $door, ?int $cap): ?int
    {
        $manager = (new ReflectionClass(DoorSessionManager::class))->newInstanceWithoutConstructor();
        foreach (['db' => $db, 'maxSessions' => 10, 'logger' => new Logger('/dev/null', 'ERROR', false)] as $name => $value) {
            $property = new ReflectionProperty(DoorSessionManager::class, $name);
            $property->setAccessible(true);
            $property->setValue($manager, $value);
        }
        $method = new ReflectionMethod(DoorSessionManager::class, 'findAvailableNode');
        $method->setAccessible(true);

        return $method->invoke($manager, $door, $cap);
    }

    private function connect(): PDO
    {
        return new PDO($this->dsn, $this->user, $this->pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
