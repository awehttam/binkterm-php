<?php

/**
 * BinkpSession::processSession() must report failure when a session ends
 * without reaching clean termination.
 *
 * The "wait for session termination" loop can end four ways:
 *
 *   1. the peer-close-first grace period completing (clean)          -> STATE_TERMINATED
 *   2. the peer closing after both sides exchanged M_EOB (clean)     -> STATE_TERMINATED
 *   3. the hard EOB/inactivity timeout expiring (abnormal)           -> no STATE_TERMINATED
 *   4. the peer closing before the EOB exchange completed (abnormal) -> no STATE_TERMINATED
 *
 * Previously the method returned true after this loop regardless, so
 * outcomes 3 and 4 were logged as a warning but reported success to
 * BinkpClient::connect(), the answerer path in BinkpServer and the session
 * log. The return value is now true only when STATE_TERMINATED was reached.
 *
 * This test drives outcome 3 end-to-end against a silent mock peer created
 * with socket_create_pair(AF_UNIX, ...) + pcntl_fork(): no network and no
 * external peer.
 *
 * Timing: processSession() floors its EOB/inactivity timeout at
 * max(30, (int) $config->getBinkpTimeout()), so the mock peer stays silent
 * for at least that long and the test takes about 30 seconds.
 */

use BinktermPHP\Binkp\Protocol\BinkpSession;
use PHPUnit\Framework\TestCase;

final class BinkpSessionAbnormalTimeoutTest extends TestCase
{
    private mixed $originalDatabase = null;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required to drive the mock peer process');
        }

        // The session's optional database lookups (FREQ queue, hub outbound,
        // insecure allowlist) are wrapped in try/catch. Give them a database
        // whose queries fail, so this test needs no real PostgreSQL server.
        $instance = new \ReflectionProperty(\BinktermPHP\Database::class, 'instance');
        $instance->setAccessible(true);
        $this->originalDatabase = $instance->getValue();
        $database = (new \ReflectionClass(\BinktermPHP\Database::class))->newInstanceWithoutConstructor();
        $pdo = new \ReflectionProperty(\BinktermPHP\Database::class, 'pdo');
        $pdo->setAccessible(true);
        $pdo->setValue($database, new class extends \PDO {
            public function __construct()
            {
            }

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                throw new \RuntimeException('database unavailable in this test');
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                throw new \RuntimeException('database unavailable in this test');
            }

            public function exec(string $statement): int|false
            {
                throw new \RuntimeException('database unavailable in this test');
            }
        });
        $instance->setValue(null, $database);
    }

    protected function tearDown(): void
    {
        $instance = new \ReflectionProperty(\BinktermPHP\Database::class, 'instance');
        $instance->setAccessible(true);
        $instance->setValue(null, $this->originalDatabase);
    }

    private function makeConfigStub(int $binkpTimeout, string $inboundPath, string $outboundPath): object
    {
        return new class ($binkpTimeout, $inboundPath, $outboundPath) {
            public function __construct(
                private int $timeout,
                private string $inbound,
                private string $outbound
            ) {
            }
            public function getBinkpTimeout()
            {
                return $this->timeout;
            }
            public function getInboundPath()
            {
                return $this->inbound;
            }
            public function getOutboundPath()
            {
                return $this->outbound;
            }
            public function getPreserveSentPackets()
            {
                return false;
            }
        };
    }

    private static function silentLogger(): object
    {
        return new class {
            public array $lines = [];
            public function log($level, $message, $context = [])
            {
                $this->lines[] = "[{$level}] {$message}";
            }
        };
    }

    /** @return array{0:\Socket,1:\Socket} */
    private static function makeSocketPair(): array
    {
        $pair = [];
        $ok = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        self::assertTrue($ok, 'socket_create_pair() failed (in-process, no network involved)');
        return $pair;
    }

    /**
     * Forks a mock peer that authenticates nothing, sends nothing, and never
     * closes its end - it simply holds the connection open and silent long
     * enough to outlast our session's hard timeout, so our side is forced to
     * give up on its own via the EOB/inactivity timeout rather than seeing a
     * clean close or any frame at all.
     */
    private function forkSilentPeer(\Socket $peerSocketEnd, int $holdSeconds): int
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::fail('pcntl_fork() failed');
        }
        if ($pid === 0) {
            // Child: silent peer. Keep the fd open and idle; never write, never close.
            sleep($holdSeconds);
            exit(0);
        }
        return $pid;
    }

    public function testHardEobInactivityTimeoutIsReportedAsFailureNotSuccess(): void
    {
        [$sessionEnd, $peerEnd] = self::makeSocketPair();
        $sessionStream = socket_export_stream($sessionEnd);
        stream_set_blocking($sessionStream, true);
        stream_set_timeout($sessionStream, 40);

        $inbound = sys_get_temp_dir() . '/binkp_abnormal_timeout_inbound_' . bin2hex(random_bytes(4));
        $outbound = sys_get_temp_dir() . '/binkp_abnormal_timeout_outbound_' . bin2hex(random_bytes(4));
        mkdir($inbound, 0777, true);
        mkdir($outbound, 0777, true);

        // Held open well past the ~30s floor so it's still silent when our
        // side gives up; killed explicitly afterward rather than waited out.
        $childPid = $this->forkSilentPeer($peerEnd, 40);

        // The configured timeout value itself (1) is deliberately tiny to
        // prove this test does NOT depend on a large configured value - the
        // max(30, ...) floor in processSession() is what actually
        // governs the wait, unmodified by this fix.
        $config = $this->makeConfigStub(1, $inbound, $outbound);
        $logger = self::silentLogger();

        $session = new BinkpSession($sessionStream, true /* originator */, $config);
        $session->setLogger($logger);

        $start = microtime(true);
        $result = $session->processSession();
        $elapsed = microtime(true) - $start;

        posix_kill($childPid, SIGKILL);
        pcntl_waitpid($childPid, $status);
        fclose($sessionStream);
        foreach (glob($inbound . '/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($outbound . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($inbound);
        @rmdir($outbound);

        self::assertFalse(
            $result,
            'processSession() must NOT report success for a session that never reached clean termination - ' .
            'this is the exact defect that let a 300-second, zero-byte, stalled session be recorded as `success`'
        );

        self::assertGreaterThanOrEqual(
            29.0,
            $elapsed,
            'the hard EOB/inactivity timeout floor (max(30, ...) in processSession()) must actually be honored, not bypassed'
        );
        self::assertLessThan(
            60.0,
            $elapsed,
            'must be bounded by the configured timeout, not hang indefinitely'
        );

        $sawTimeoutWarning = false;
        $sawAbnormalEnd = false;
        foreach ($logger->lines as $line) {
            if (str_contains($line, 'timeout')) {
                $sawTimeoutWarning = true;
            }
            if (str_contains($line, 'Session ended abnormally')) {
                $sawAbnormalEnd = true;
            }
        }
        self::assertTrue($sawTimeoutWarning, 'the EOB/inactivity timeout must still be logged (unchanged diagnostic)');
        self::assertTrue($sawAbnormalEnd, 'the final outcome must be logged distinctly from "Session completed successfully"');
    }
}
