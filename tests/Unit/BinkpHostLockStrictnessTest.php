<?php

/**
 * BinkpClient serializes sessions to the same remote host:port with a
 * flock()-based lock file, so two uplinks served by one physical host are
 * never dialed concurrently. A failure to acquire the lock (contention past
 * the bounded wait, or the lock file being unavailable) must return null,
 * which connect() turns into HostLockBusyException before any network code
 * runs - never "proceed without the lock".
 *
 * Two file handles in one process stand in for two processes: flock() locks
 * belong to the open file description, so this reproduces real contention
 * without forking or network I/O. The lock directory is redirected to a
 * temporary directory via BINKP_HOST_LOCK_DIR.
 */

use BinktermPHP\Binkp\Protocol\BinkpClient;
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

    public function testLockDirectoryIsCreatedWithoutWorldWriteAccess(): void
    {
        $client = new BinkpClient();
        $lock = $this->invokeAcquire($client, 'perm-check.invalid', 24554, 1);
        self::assertNotNull($lock);
        $this->invokeRelease($client, $lock, 'perm-check.invalid', 24554);

        self::assertDirectoryExists($this->lockDir);
        self::assertSame(0, fileperms($this->lockDir) & 0002, 'lock directory must not be world-writable');
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

    public function testSecondContenderCannotAcquireWhileFirstHoldsItAndDoesNotBlockPastItsTimeout(): void
    {
        // Unique per test run so parallel/previous runs never collide.
        $hostname = 'test-hostlock-' . bin2hex(random_bytes(4)) . '.invalid';
        $port = 24554;

        $clientA = new BinkpClient();
        $clientB = new BinkpClient();

        $lockA = $this->invokeAcquire($clientA, $hostname, $port, 5);
        self::assertNotNull($lockA, 'the first contender must acquire an uncontended lock');

        $start = microtime(true);
        $lockB = $this->invokeAcquire($clientB, $hostname, $port, 1); // 1s bounded wait
        $elapsed = microtime(true) - $start;

        self::assertNull(
            $lockB,
            'a second contender must NOT acquire the lock while the first still holds it - null is the ' .
            '"do not dial" signal that BinkpClient::connect() now treats as a hard refusal, never a green light'
        );
        // acquireHostLock()'s wait loop compares whole-second time()
        // boundaries every 250ms, so a 1s requested timeout can legitimately
        // resolve in anywhere from one 250ms tick (if the wall clock's
        // integer-second boundary had already ticked over by the time of
        // the first check) up to just over 1s. The invariant that matters is
        // the upper bound - it must not hang past its requested timeout.
        self::assertLessThan(3.0, $elapsed, 'the wait must be bounded to the requested timeout, not hang indefinitely');

        $this->invokeRelease($clientA, $lockA, $hostname, $port);

        // After release, a later attempt must be able to acquire it again -
        // this proves lock release is correct and the earlier failure was
        // genuine contention, not a broken/stuck lock file.
        $clientC = new BinkpClient();
        $lockC = $this->invokeAcquire($clientC, $hostname, $port, 5);
        self::assertNotNull($lockC, 'once the first contender releases, a later attempt must be able to acquire the lock');
        $this->invokeRelease($clientC, $lockC, $hostname, $port);
    }

    /**
     * connect()'s wiring of the null-lock path to HostLockBusyException is
     * verified by source inspection rather than a live end-to-end test here:
     * connect()'s only call into acquireHostLock() uses the hardcoded 90s
     * default timeout (not overridable per-call), so a real end-to-end test
     * through connect() would have to wait out that full 90s to observe the
     * timeout path - not appropriate for this suite ("keep timeout in
     * milliseconds/seconds"). The acquireHostLock() contract itself (null
     * means "do not dial", proven above) plus the connect() diff:
     *
     *   $hostLock = $this->acquireHostLock($hostname, $port);
     *   if ($hostLock === null) {
     *       throw new HostLockBusyException(...);
     *   }
     *
     * ...is a direct, unconditional check with no other code path between
     * acquiring the lock and the throw - there is nothing left to race or
     * fall through.
     */
}
