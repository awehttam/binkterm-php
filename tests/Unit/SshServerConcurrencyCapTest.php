<?php

declare(strict_types=1);

require_once __DIR__ . '/../../ssh/src/SshStreamWrapper.php';
require_once __DIR__ . '/../../ssh/src/SshSession.php';
require_once __DIR__ . '/../../ssh/src/SshServer.php';

use BinktermPHP\Config;
use BinktermPHP\SshServer\SshServer;
use PHPUnit\Framework\TestCase;

/**
 * SSH hardening slice 2 — global ceiling on simultaneously live SSH child
 * processes (`SSH_MAX_CHILDREN`).
 *
 * The per-IP limiter (slice 1) does not stop a distributed / many-source-IP
 * flood; this cap does. It is admission control checked after the per-IP
 * limiter and before `pcntl_fork()` / the SSH handshake, so a capacity
 * rejection costs no child process and no KEX work.
 *
 * Reap bookkeeping is driven through the `reapOne()` / `childIsGone()` seams.
 * The SIGCHLD ordering and slot self-healing are proven with real child
 * processes; the accept-path ordering is pinned by source.
 */
final class SshServerConcurrencyCapTest extends TestCase
{
    /** @var \ReflectionProperty */
    private static $loadedFlag;

    public static function setUpBeforeClass(): void
    {
        self::$loadedFlag = new ReflectionProperty(Config::class, 'loaded');
        self::$loadedFlag->setAccessible(true);
    }

    protected function setUp(): void
    {
        // Freeze Config so env reads come only from $_ENV we control here.
        self::$loadedFlag->setValue(null, true);
        unset($_ENV['SSH_MAX_CHILDREN'], $_ENV['SSH_RATE_LIMIT_MAX'], $_ENV['SSH_RATE_LIMIT_WINDOW']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['SSH_MAX_CHILDREN'], $_ENV['SSH_RATE_LIMIT_MAX'], $_ENV['SSH_RATE_LIMIT_WINDOW']);
        self::$loadedFlag->setValue(null, false);
    }

    // ---------------------------------------------------------------- helpers

    /** SshServer whose child reaping is a controllable queue instead of real waitpid(). */
    private function server(): object
    {
        return new class('127.0.0.1', 2022, 'http://localhost') extends SshServer {
            /** @var int[] pids to hand back from reapOne(), then 0 forever */
            public array $reapQueue = [];
            /** @var int[] pids childIsGone() reports as no longer running */
            public array $gonePids = [];
            protected function reapOne(): int
            {
                return (int) (array_shift($this->reapQueue) ?? 0);
            }
            protected function childIsGone(int $pid): bool
            {
                return in_array($pid, $this->gonePids, true);
            }
        };
    }

    private function prop(object $srv, string $name)
    {
        $p = new ReflectionProperty(SshServer::class, $name);
        $p->setAccessible(true);
        return $p->getValue($srv);
    }

    private function setProp(object $srv, string $name, $value): void
    {
        $p = new ReflectionProperty(SshServer::class, $name);
        $p->setAccessible(true);
        $p->setValue($srv, $value);
    }

    private function call(object $srv, string $method, array $args = [])
    {
        $m = new ReflectionMethod(SshServer::class, $method);
        $m->setAccessible(true);
        return $m->invokeArgs($srv, $args);
    }

    private static function acceptLoopSource(): string
    {
        $src   = file_get_contents(__DIR__ . '/../../ssh/src/SshServer.php');
        $start = strpos($src, '$connectionCount = 0;');
        $end   = strpos($src, 'private function atCapacity(');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        return substr($src, $start, $end - $start);
    }

    // ---------------------------------------------------------------- config

    public function testDefaultCeilingIs32(): void
    {
        $srv = $this->server();
        self::assertSame(32, $this->prop($srv, 'maxChildren'));
        self::assertFalse($this->prop($srv, 'maxChildrenClamped'));
    }

    public function testConfigOverrideIsHonoured(): void
    {
        $_ENV['SSH_MAX_CHILDREN'] = '8';
        $srv = $this->server();
        self::assertSame(8, $this->prop($srv, 'maxChildren'));
        self::assertFalse($this->prop($srv, 'maxChildrenClamped'));
    }

    public function testNonPositiveConfigFallsBackToDefaultAndIsFlagged(): void
    {
        foreach (['0', '-5', 'nonsense'] as $bad) {
            $_ENV['SSH_MAX_CHILDREN'] = $bad;
            $srv = $this->server();
            self::assertSame(32, $this->prop($srv, 'maxChildren'), "value {$bad} should fall back");
            self::assertTrue($this->prop($srv, 'maxChildrenClamped'), "value {$bad} should be flagged");
        }
    }

    // ---------------------------------------------------------------- accounting

    public function testAtCapacityBoundary(): void
    {
        $_ENV['SSH_MAX_CHILDREN'] = '3';
        $srv = $this->server();

        $this->setProp($srv, 'childPids', [101 => true, 102 => true]);
        self::assertFalse($this->call($srv, 'atCapacity'), 'below max may proceed');

        $this->setProp($srv, 'childPids', [101 => true, 102 => true, 103 => true]);
        self::assertTrue($this->call($srv, 'atCapacity'), 'at max must be rejected');

        $this->setProp($srv, 'childPids', [101 => true, 102 => true, 103 => true, 104 => true]);
        self::assertTrue($this->call($srv, 'atCapacity'), 'over max stays rejected');
    }

    public function testReapReleasesExactlyTheExitedSlots(): void
    {
        $srv = $this->server();
        $this->setProp($srv, 'childPids', [10 => true, 20 => true, 30 => true]);
        $srv->reapQueue = [20]; // one child exited

        $this->call($srv, 'reapChildren');

        self::assertSame([10 => true, 30 => true], $this->prop($srv, 'childPids'));
    }

    public function testReapDrainsEveryExitedChildInOnePass(): void
    {
        $srv = $this->server();
        $this->setProp($srv, 'childPids', [1 => true, 2 => true, 3 => true, 4 => true]);
        $srv->reapQueue = [2, 4, 1];

        $this->call($srv, 'reapChildren');

        self::assertSame([3 => true], $this->prop($srv, 'childPids'));
    }

    public function testIdleReapNeitherLeaksNorDropsLiveChildren(): void
    {
        $srv = $this->server();
        $this->setProp($srv, 'childPids', [10 => true, 20 => true]);
        $srv->reapQueue = []; // nothing ready -> reapOne() returns 0

        $this->call($srv, 'reapChildren');

        self::assertSame([10 => true, 20 => true], $this->prop($srv, 'childPids'));
    }

    public function testReapStopsAtSentinelAndCannotLoopForever(): void
    {
        $srv = $this->server();
        $this->setProp($srv, 'childPids', [7 => true]);
        $srv->reapQueue = [-1]; // ECHILD-style: no children

        $this->call($srv, 'reapChildren');

        self::assertSame([7 => true], $this->prop($srv, 'childPids'));
    }

    public function testCapacityFreesUpAfterAChildIsReaped(): void
    {
        $_ENV['SSH_MAX_CHILDREN'] = '2';
        $srv = $this->server();

        $this->setProp($srv, 'childPids', [40 => true, 41 => true]);
        self::assertTrue($this->call($srv, 'atCapacity'));

        $srv->reapQueue = [41];
        $this->call($srv, 'reapChildren');

        self::assertFalse($this->call($srv, 'atCapacity'), 'a reaped child must return one slot');
    }

    // ---------------------------------------------------------------- source guarantees

    public function testCapacityGuardRunsAfterPerIpLimiterAndBeforeFork(): void
    {
        $loop = self::acceptLoopSource();

        $ipLimiterPos = strpos($loop, '$this->rateLimiter->check(');
        $capacityPos  = strpos($loop, '$this->atCapacity()');
        $forkPos      = strpos($loop, '$this->forkConnectionChild()');

        self::assertNotFalse($ipLimiterPos);
        self::assertNotFalse($capacityPos);
        self::assertNotFalse($forkPos);
        self::assertLessThan($capacityPos, $ipLimiterPos, 'per-IP limiter runs first');
        self::assertLessThan($forkPos, $capacityPos, 'capacity guard runs before pcntl_fork()');
    }

    public function testCapacityRejectBranchClosesSocketAndContinuesWithoutForking(): void
    {
        $loop = self::acceptLoopSource();

        $branchStart = strpos($loop, 'if ($this->atCapacity()) {');
        $continuePos = strpos($loop, 'continue;', $branchStart);
        self::assertNotFalse($branchStart);
        self::assertNotFalse($continuePos);

        $branch = substr($loop, $branchStart, $continuePos - $branchStart);
        self::assertStringContainsString('fclose($conn);', $branch);
        self::assertStringContainsString('at capacity', strtolower($branch));
        self::assertStringNotContainsString('pcntl_fork', $branch);
        self::assertStringNotContainsString('handleConnection', $branch);
    }

    public function testCapacityCheckDropsEntriesWhoseChildIsGone(): void
    {
        $_ENV['SSH_MAX_CHILDREN'] = '2';
        $srv = $this->server();
        $this->setProp($srv, 'childPids', [50 => true, 51 => true]);
        $srv->gonePids = [51];

        self::assertFalse($this->call($srv, 'atCapacity'), 'a leaked entry must not hold a slot');
        self::assertSame([50 => true], $this->prop($srv, 'childPids'));
    }

    // ---------------------------------------------------------------- real processes

    private static function requireSignals(): void
    {
        foreach (['pcntl_fork', 'pcntl_signal', 'pcntl_async_signals', 'pcntl_sigprocmask', 'posix_kill'] as $fn) {
            if (!function_exists($fn)) {
                self::markTestSkipped("{$fn}() not available");
            }
        }
    }

    /**
     * A child that exits immediately after fork must not leave its PID in the
     * registry. The seam holds the parent between fork and registration long
     * enough for the child to exit and SIGCHLD to be raised; the production
     * SIGCHLD handler (async signals, as in start()) is installed. Without
     * SIGCHLD blocked across fork and registration, the handler reaps the
     * child first and the late registration leaks the slot.
     */
    public function testChildExitingBeforeRegistrationDoesNotLeakASlot(): void
    {
        self::requireSignals();
        $srv = new class('127.0.0.1', 2022, 'http://localhost') extends SshServer {
            protected function beforeChildRegistered(int $pid): void
            {
                usleep(200000);
            }
        };

        $previousAsync = pcntl_async_signals(true);
        $this->call($srv, 'installChildReaper');
        try {
            for ($i = 0; $i < 5; $i++) {
                $pid = $this->call($srv, 'forkConnectionChild');
                if ($pid === 0) {
                    // Child: exit at once, without running PHPUnit shutdown code.
                    posix_kill(posix_getpid(), SIGKILL);
                }
                self::assertGreaterThan(0, $pid, 'fork failed');
            }

            // Only the SIGCHLD handler reaps here; nothing else touches the registry.
            $deadline = microtime(true) + 3.0;
            while ($this->prop($srv, 'childPids') !== [] && microtime(true) < $deadline) {
                usleep(20000);
            }

            self::assertSame([], $this->prop($srv, 'childPids'), 'every exited child must release its slot');
        } finally {
            pcntl_signal(SIGCHLD, SIG_DFL);
            pcntl_async_signals($previousAsync);
            while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
            }
        }
    }

    public function testChildRestoresTheSignalMaskAfterFork(): void
    {
        self::requireSignals();
        $srv = new SshServer('127.0.0.1', 2022, 'http://localhost');
        $pipe = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $pid = $this->call($srv, 'forkConnectionChild');
        if ($pid === 0) {
            $blocked = [];
            pcntl_sigprocmask(SIG_BLOCK, [], $blocked);
            fwrite($pipe[1], in_array(SIGCHLD, $blocked, true) ? 'blocked' : 'unblocked');
            posix_kill(posix_getpid(), SIGKILL);
        }

        $answer = fread($pipe[0], 16);
        pcntl_waitpid($pid, $status);
        fclose($pipe[0]);
        fclose($pipe[1]);

        self::assertSame('unblocked', $answer, 'the session child must not inherit a blocked SIGCHLD');
        $blocked = [];
        pcntl_sigprocmask(SIG_BLOCK, [], $blocked);
        self::assertNotContains(SIGCHLD, $blocked, 'the parent must restore its mask');
    }

    public function testAtCapacityHealsASlotLeakedByAnAlreadyReapedChild(): void
    {
        self::requireSignals();
        $_ENV['SSH_MAX_CHILDREN'] = '1';
        $srv = new SshServer('127.0.0.1', 2022, 'http://localhost');

        $pid = pcntl_fork();
        if ($pid === 0) {
            posix_kill(posix_getpid(), SIGKILL);
        }
        pcntl_waitpid($pid, $status);

        // Simulate a leaked registration: the child has been reaped already.
        $this->setProp($srv, 'childPids', [$pid => true]);

        self::assertFalse($this->call($srv, 'atCapacity'), 'a reaped child must not hold the only slot');
        self::assertSame([], $this->prop($srv, 'childPids'));
    }

    public function testAtCapacityKeepsALiveChild(): void
    {
        self::requireSignals();
        $_ENV['SSH_MAX_CHILDREN'] = '1';
        $srv = new SshServer('127.0.0.1', 2022, 'http://localhost');

        $pid = pcntl_fork();
        if ($pid === 0) {
            sleep(5);
            posix_kill(posix_getpid(), SIGKILL);
        }
        try {
            $this->setProp($srv, 'childPids', [$pid => true]);
            self::assertTrue($this->call($srv, 'atCapacity'), 'a running child holds its slot');
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    public function testSelectTimeoutReapsChildren(): void
    {
        $loop = self::acceptLoopSource();
        $timeoutBranch = substr(
            $loop,
            strpos($loop, 'if ($changed === false'),
            strpos($loop, 'stream_socket_accept') - strpos($loop, 'if ($changed === false')
        );
        self::assertStringContainsString('$this->reapChildren();', $timeoutBranch);
    }

    public function testCapacityGuardIsGlobalNotPerIp(): void
    {
        // atCapacity() takes no argument and keys on nothing per-IP: every
        // source shares the one ceiling.
        $m = new ReflectionMethod(SshServer::class, 'atCapacity');
        self::assertSame(0, $m->getNumberOfParameters());

        $body = self::acceptLoopSource();
        // the guard is a bare count() >= max, no peer/ip token in the method
        $src  = file_get_contents(__DIR__ . '/../../ssh/src/SshServer.php');
        $mStart = strpos($src, 'private function atCapacity()');
        $mEnd   = strpos($src, 'protected function reapOne()');
        $method = substr($src, $mStart, $mEnd - $mStart);
        self::assertStringContainsString('count($this->childPids) >= $this->maxChildren', $method);
        self::assertStringNotContainsString('peerIp', $method);
    }

    public function testCapacityLogResetsAndFlushesOnNextAdmit(): void
    {
        $loop = self::acceptLoopSource();
        $parentStart = strpos($loop, '// Parent — the child is already registered');
        $parentEnd   = strpos($loop, '} else {', $parentStart);
        $parent      = substr($loop, $parentStart, $parentEnd - $parentStart);

        self::assertStringContainsString('$this->capacityLogged = false;', $parent);
        self::assertStringContainsString('$this->capacitySuppressed = 0;', $parent);
        self::assertStringContainsString('further connection(s) were rejected while full', $parent);
    }

    public function testDocumentsSshMaxChildren(): void
    {
        $doc = file_get_contents(__DIR__ . '/../../docs/SSHServer.md');
        self::assertStringContainsString('`SSH_MAX_CHILDREN`', $doc);
        self::assertStringContainsString('| `SSH_MAX_CHILDREN` | `32` |', $doc);
    }
}
