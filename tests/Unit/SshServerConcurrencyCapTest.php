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
 * Real forking is not practical in a unit test, so child accounting is driven
 * through the `reapOne()` seam and the accept-path ordering is pinned by source.
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
            protected function reapOne(): int
            {
                return (int) (array_shift($this->reapQueue) ?? 0);
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
        $forkPos      = strpos($loop, '$pid = pcntl_fork();');

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

    public function testOnlyTheSuccessfulForkBranchRegistersAChild(): void
    {
        $loop = self::acceptLoopSource();

        // exactly one place adds to the live-child map
        self::assertSame(1, substr_count($loop, '$this->childPids[$pid] = true;'));

        // and it is in the parent branch, after the "$pid === 0" child branch,
        // never in the "$pid === -1" fork-failure branch
        $failPos   = strpos($loop, '$pid === -1');
        $childPos  = strpos($loop, '$pid === 0');
        $addPos    = strpos($loop, '$this->childPids[$pid] = true;');
        self::assertGreaterThan($childPos, $addPos, 'child registration is in the parent branch');

        $failBranch = substr($loop, $failPos, $childPos - $failPos);
        self::assertStringNotContainsString('childPids', $failBranch, 'fork failure must not consume a slot');
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
        $parentStart = strpos($loop, '// Parent — track the child');
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
