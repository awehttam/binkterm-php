<?php

/**
 * BinkpClient serializes sessions to the same remote host:port with a
 * flock()-based lock file, so two uplinks served by one physical host are
 * never dialed concurrently. Contention past the bounded wait throws
 * HostLockBusyException; a lock that cannot be created, opened or locked
 * throws HostLockUnavailableException. Neither case dials.
 *
 * The cross-user tests start real helper processes under different uids
 * (root only) to prove that accounts sharing the lock directory's group
 * contend on the same lock under a default umask, and that an account
 * outside the group is refused as "unavailable", not "busy". The lock
 * directory is redirected to a temporary directory via BINKP_HOST_LOCK_DIR.
 */

use BinktermPHP\Binkp\Protocol\BinkpClient;
use BinktermPHP\Binkp\Protocol\HostLockBusyException;
use BinktermPHP\Binkp\Protocol\HostLockUnavailableException;
use PHPUnit\Framework\TestCase;

final class BinkpHostLockStrictnessTest extends TestCase
{
    private ?string $lockDir = null;
    private bool $hadOverride = false;
    private mixed $previousOverride = null;

    protected function setUp(): void
    {
        $this->hadOverride = array_key_exists('BINKP_HOST_LOCK_DIR', $_ENV);
        $this->previousOverride = $_ENV['BINKP_HOST_LOCK_DIR'] ?? null;
        $this->lockDir = sys_get_temp_dir() . '/binkp_host_locks_' . bin2hex(random_bytes(4));
        $_ENV['BINKP_HOST_LOCK_DIR'] = $this->lockDir;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->lockDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->lockDir);
        if ($this->hadOverride) {
            $_ENV['BINKP_HOST_LOCK_DIR'] = $this->previousOverride;
        } else {
            unset($_ENV['BINKP_HOST_LOCK_DIR']);
        }
    }

    public function testDefaultLockDirectoryIsTheApplicationRunDirectory(): void
    {
        unset($_ENV['BINKP_HOST_LOCK_DIR']);
        self::assertSame(dirname(__DIR__, 2) . '/data/run/binkp-host-locks', BinkpClient::hostLockDir());
        self::assertStringNotContainsString(sys_get_temp_dir(), BinkpClient::hostLockDir());
    }

    public function testLockDirectoryAndFileAreGroupWritableRegardlessOfUmask(): void
    {
        $previousUmask = umask(022);
        try {
            $client = $this->newClient();
            $lock = $this->invokeAcquire($client, 'perm-check.invalid', 24554, 1);
            $this->invokeRelease($client, $lock, 'perm-check.invalid', 24554);
        } finally {
            umask($previousUmask);
        }

        self::assertDirectoryExists($this->lockDir);
        self::assertSame(02775, fileperms($this->lockDir) & 07777, 'lock directory must be setgid and group-writable');
        self::assertSame(0664, fileperms($this->lockDir . '/perm-check.invalid_24554.lock') & 0777, 'lock file must be group-writable');
    }

    private function newClient(): BinkpClient
    {
        $config = new class {
            public function getBinkpTimeout()
            {
                return 5;
            }
            public function getUplinkByAddress($address)
            {
                return null;
            }
        };
        $logger = new class {
            public function log($level, $message, $context = []): void
            {
            }
        };
        return new BinkpClient($config, $logger);
    }

    private function invokeAcquire(BinkpClient $client, string $hostname, int $port, int $timeoutSeconds)
    {
        $method = new \ReflectionMethod($client, 'acquireHostLock');
        $method->setAccessible(true);
        return $method->invoke($client, $hostname, $port, $timeoutSeconds);
    }

    private function invokeRelease(BinkpClient $client, $handle, string $hostname, int $port): void
    {
        $method = new \ReflectionMethod($client, 'releaseHostLock');
        $method->setAccessible(true);
        $method->invoke($client, $handle, $hostname, $port);
    }

    public function testSecondContenderIsRefusedAsBusyWithinItsWaitAndCanAcquireAfterRelease(): void
    {
        $hostname = 'test-hostlock-' . bin2hex(random_bytes(4)) . '.invalid';
        $port = 24554;

        $clientA = $this->newClient();
        $lockA = $this->invokeAcquire($clientA, $hostname, $port, 5);

        $start = microtime(true);
        try {
            $this->invokeAcquire($this->newClient(), $hostname, $port, 1);
            self::fail('a second contender must not acquire the lock while the first holds it');
        } catch (HostLockBusyException $e) {
            // expected: contention, not a permissions failure
        }
        self::assertLessThan(3.0, microtime(true) - $start, 'the wait must be bounded');

        $this->invokeRelease($clientA, $lockA, $hostname, $port);

        $clientC = $this->newClient();
        $lockC = $this->invokeAcquire($clientC, $hostname, $port, 5);
        self::assertIsResource($lockC);
        $this->invokeRelease($clientC, $lockC, $hostname, $port);
    }

    public function testConnectRefusesToDialWhenTheLockIsUnavailable(): void
    {
        // A regular file where the lock directory should be: the lock cannot
        // be created. connect() must throw before any socket is created.
        file_put_contents($this->lockDir, 'not a directory');
        try {
            $this->newClient()->connect('999:1/1', '127.0.0.1', 9);
            self::fail('connect() must not proceed without the host lock');
        } catch (HostLockUnavailableException $e) {
            self::assertStringContainsString('Not dialing without serialization', $e->getMessage());
        } finally {
            @unlink($this->lockDir);
        }
    }

    public function testExistingLockDirectoryOwnedByThisAccountIsRepaired(): void
    {
        mkdir($this->lockDir, 0755);
        chmod($this->lockDir, 0755);

        $client = $this->newClient();
        $lock = $this->invokeAcquire($client, 'repair-check.invalid', 24554, 1);
        $this->invokeRelease($client, $lock, 'repair-check.invalid', 24554);

        self::assertSame(02775, fileperms($this->lockDir) & 07777);
    }

    public function testBeforeDialRunsOnlyOnceTheLockIsHeld(): void
    {
        $calls = 0;

        // Lock unavailable: the callback must not run.
        file_put_contents($this->lockDir, 'not a directory');
        $client = $this->newClient();
        $client->setBeforeDial(function () use (&$calls): void {
            $calls++;
        });
        try {
            $client->connect('999:1/1', '127.0.0.1', 9);
            self::fail('connect() must not proceed without the host lock');
        } catch (HostLockUnavailableException $e) {
        } finally {
            @unlink($this->lockDir);
        }
        self::assertSame(0, $calls, 'no attempt is counted when nothing is dialed');

        // Lock held: the callback runs once, before the (refused) dial.
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        try {
            @$client->connect('999:1/1', '127.0.0.1', $port);
            self::fail('the dial should be refused');
        } catch (\Exception $e) {
            self::assertStringContainsString('Failed to connect', $e->getMessage());
        }
        self::assertSame(1, $calls);
    }

    public function testFailedSessionConnectionIsClosedBeforeTheLockIsReleased(): void
    {
        // Keep call arguments in exception traces (as php.ini-development
        // does), so nothing but connect()'s own cleanup can close the socket
        // while the caller still holds the exception.
        $previousIgnoreArgs = ini_set('zend.exception_ignore_args', '0');
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($listener, $errstr);
        $port = (int)substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);

        // The third timeout lookup happens in socketToStream(), after the
        // socket is connected and exported: the session fails part-way.
        $config = new class {
            private int $calls = 0;
            public function getBinkpTimeout()
            {
                if (++$this->calls >= 3) {
                    throw new \RuntimeException('simulated failure after export');
                }
                return 5;
            }
            public function getUplinkByAddress($address)
            {
                return null;
            }
        };
        $logger = new class {
            public function log($level, $message, $context = []): void
            {
            }
        };

        try {
            (new BinkpClient($config, $logger))->connect('999:1/1', '127.0.0.1', $port);
            self::fail('connect() should have failed');
        } catch (\RuntimeException $e) {
            self::assertSame('simulated failure after export', $e->getMessage());

            $peer = stream_socket_accept($listener, 2);
            self::assertNotFalse($peer);
            stream_set_timeout($peer, 2);
            $data = fread($peer, 16);
            $meta = stream_get_meta_data($peer);
            self::assertFalse($meta['timed_out'], 'the client side must already be closed when connect() returns');
            self::assertSame('', $data);
            fclose($peer);
        } finally {
            fclose($listener);
            ini_set('zend.exception_ignore_args', (string)$previousIgnoreArgs);
        }

        // And the host lock is free again.
        $client = $this->newClient();
        $lock = $this->invokeAcquire($client, '127.0.0.1', $port, 1);
        $this->invokeRelease($client, $lock, '127.0.0.1', $port);
    }

    public function testConnectionIsClosedEvenWhenSessionCloseThrows(): void
    {
        // connect() runs closeConnection() in its finally block, before the
        // host lock is released. A session whose close() throws part-way must
        // not leave the descriptor open.
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        [$clientEnd, $peer] = $pair;
        socket_set_option($peer, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);

        $stream = socket_export_stream($clientEnd);
        $config = new class {
            public function getBinkpTimeout()
            {
                return 5;
            }
        };
        $session = new class($stream, true, $config) extends \BinktermPHP\Binkp\Protocol\BinkpSession {
            public function close()
            {
                throw new \RuntimeException('simulated failure inside close()');
            }
        };

        $method = new \ReflectionMethod(BinkpClient::class, 'closeConnection');
        $method->setAccessible(true);
        $method->invoke($this->newClient(), $clientEnd, $stream, $session);

        self::assertSame('', socket_read($peer, 16), 'the peer must see EOF: the connection is closed');
        socket_close($peer);
    }

    // ---- Cross-user participation (real processes, real uids) -------------

    private const SHARED_GID = 64999;

    /**
     * Runs a helper process that drops to $uid/$gid with umask 022, then
     * either holds the lock until its stdin closes ('hold') or tries once
     * with a 1s wait ('try') and prints ACQUIRED, BUSY or UNAVAILABLE.
     *
     * @return array{0:resource,1:array<int,resource>}
     */
    private function startAs(int $uid, int $gid, string $mode, string $hostname)
    {
        $code = <<<'PHP'
[$autoload, $lockDir, $uid, $gid, $mode, $host] = array_slice($argv, 1);
require $autoload;
// Load everything before dropping privileges: the new uid may not be able to
// read the source tree.
foreach (['BinktermPHP\\Binkp\\Protocol\\BinkpClient', 'BinktermPHP\\Binkp\\Protocol\\HostLockBusyException',
          'BinktermPHP\\Binkp\\Protocol\\HostLockUnavailableException', 'BinktermPHP\\Config'] as $class) {
    class_exists($class);
}
BinktermPHP\Config::env('BINKP_HOST_LOCK_DIR');
if (!posix_setgid((int)$gid) || !posix_setuid((int)$uid)) { echo "SETID_FAILED\n"; exit(2); }
umask(022);
$_ENV['BINKP_HOST_LOCK_DIR'] = $lockDir;
$config = new class { public function getBinkpTimeout() { return 5; } };
$logger = new class { public function log($level, $message, $context = []) {} };
$client = new BinktermPHP\Binkp\Protocol\BinkpClient($config, $logger);
$acquire = new ReflectionMethod($client, 'acquireHostLock');
$acquire->setAccessible(true);
try {
    $handle = $acquire->invoke($client, $host, 24554, $mode === 'hold' ? 5 : 1);
    echo "ACQUIRED\n";
} catch (BinktermPHP\Binkp\Protocol\HostLockBusyException $e) {
    echo "BUSY\n"; exit(0);
} catch (BinktermPHP\Binkp\Protocol\HostLockUnavailableException $e) {
    echo "UNAVAILABLE\n"; exit(0);
}
if ($mode === 'hold') { fgets(STDIN); }
exit(0);
PHP;
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $proc = proc_open(
            [PHP_BINARY, '-r', $code, '--', $autoload, $this->lockDir, (string)$uid, (string)$gid, $mode, $hostname],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($proc);
        return [$proc, $pipes];
    }

    private static function readLine(array $pipes): string
    {
        $line = trim((string)fgets($pipes[1]));
        if ($line === '') {
            $line = 'NO OUTPUT: ' . stream_get_contents($pipes[2]);
        }
        return $line;
    }

    private static function finish($proc, array $pipes): void
    {
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }

    private function requireRoot(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('cross-user lock test needs root to switch uids');
        }
    }

    public function testDifferentUsersInTheSharedGroupContendOnTheSameLock(): void
    {
        $this->requireRoot();
        $hostname = 'test-crossuser-' . bin2hex(random_bytes(4)) . '.invalid';

        // User A creates the directory and lock file under umask 022 and holds the lock.
        [$procA, $pipesA] = $this->startAs(64001, self::SHARED_GID, 'hold', $hostname);
        self::assertSame('ACQUIRED', self::readLine($pipesA));

        // User B shares only the group. It must see contention, not a
        // permissions failure, and must not get the lock.
        [$procB, $pipesB] = $this->startAs(64002, self::SHARED_GID, 'try', $hostname);
        self::assertSame('BUSY', self::readLine($pipesB));
        self::finish($procB, $pipesB);

        self::finish($procA, $pipesA);

        // Once A has released, B can take the same lock file.
        [$procB2, $pipesB2] = $this->startAs(64002, self::SHARED_GID, 'try', $hostname);
        self::assertSame('ACQUIRED', self::readLine($pipesB2));
        self::finish($procB2, $pipesB2);
    }

    public function testUserOutsideTheGroupGetsUnavailableNotBusyAndDoesNotDial(): void
    {
        $this->requireRoot();
        $hostname = 'test-crossuser-' . bin2hex(random_bytes(4)) . '.invalid';

        [$procA, $pipesA] = $this->startAs(64001, self::SHARED_GID, 'hold', $hostname);
        self::assertSame('ACQUIRED', self::readLine($pipesA));

        // A permissions problem is reported as such, distinct from contention.
        [$procC, $pipesC] = $this->startAs(64003, 64998, 'try', $hostname);
        self::assertSame('UNAVAILABLE', self::readLine($pipesC));
        self::finish($procC, $pipesC);

        self::finish($procA, $pipesA);
    }
}
