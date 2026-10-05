<?php

/**
 * BinkpFrame::parseFromSocket($socket, true) must be genuinely non-blocking
 * for the whole frame, not only for its first byte.
 *
 * Previously the non-blocking mode only used stream_select() to check that
 * some byte was available, then fell into a blocking readExactly(). If a
 * peer delivered a frame in pieces (a header, then a pause before the rest),
 * that "non-blocking" call blocked for the stream's full timeout. Partial
 * frames are now kept per socket and completed on a later call.
 *
 * Cases A and B exercise a whole originator session (prompt and delayed
 * file offers) as regression coverage for the session loop that polls
 * parseFromSocket(); case C exercises the fragmented frame directly.
 *
 * All I/O uses socket_create_pair(AF_UNIX, ...) plus a pcntl_fork()'d mock
 * peer. No network and no database are involved.
 */

use BinktermPHP\Binkp\Protocol\BinkpFrame;
use BinktermPHP\Binkp\Protocol\BinkpSession;
use PHPUnit\Framework\TestCase;

final class BinkpNonBlockingFrameReadTest extends TestCase
{
    /** @var string[] */
    private array $tmpDirs = [];

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

        foreach ($this->tmpDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        $this->tmpDirs = [];
    }

    private function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . '_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    /** @return array{0:\Socket,1:\Socket} */
    private static function makeSocketPair(): array
    {
        $pair = [];
        $ok = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        self::assertTrue($ok, 'socket_create_pair() failed (in-process, no network involved)');
        return $pair;
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

    /**
     * Forks a child that plays the "peer" (answerer) side directly on the
     * given raw \Socket end: it writes an M_FILE command frame + data
     * frame(s) for $payload, after waiting $delayMicros, then closes its end.
     * Runs entirely in-process; the child never touches the network.
     */
    private function forkPeerSendingFile(\Socket $peerSocketEnd, string $filename, string $payload, int $delayMicros): int
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::fail('pcntl_fork() failed');
        }
        if ($pid === 0) {
            // Child: mock peer.
            if ($delayMicros > 0) {
                usleep($delayMicros);
            }
            $stream = socket_export_stream($peerSocketEnd);
            stream_set_blocking($stream, true);
            $fileFrame = BinkpFrame::createCommand(BinkpFrame::M_FILE, "{$filename} " . strlen($payload) . ' ' . time() . ' 0');
            $fileFrame->writeToSocket($stream);
            $dataFrame = BinkpFrame::createData($payload);
            $dataFrame->writeToSocket($stream);
            $eobFrame = BinkpFrame::createCommand(BinkpFrame::M_EOB, '');
            $eobFrame->writeToSocket($stream);
            fflush($stream);
            fclose($stream);
            exit(0);
        }
        return $pid;
    }

    // ---- CASE A: prompt file (<2s), control case ---------------------------

    public function testCaseA_PromptFileWithinAbbreviatedWindowIsReceivedInFull(): void
    {
        [$sessionEnd, $peerEnd] = self::makeSocketPair();
        $sessionStream = socket_export_stream($sessionEnd);
        stream_set_blocking($sessionStream, true);
        stream_set_timeout($sessionStream, 5);

        $inbound = $this->makeTempDir('binkp_frame_inbound_a');
        $outbound = $this->makeTempDir('binkp_frame_outbound_a');
        $payload = str_repeat('A', 2000); // small payload, well under one frame's max size

        $childPid = $this->forkPeerSendingFile($peerEnd, 'PROMPT.TST', $payload, 0); // ~immediate

        $session = new BinkpSession($sessionStream, true /* originator */, $this->makeConfigStub(5, $inbound, $outbound));
        $session->setLogger(self::silentLogger());

        $start = microtime(true);
        $result = $session->processSession();
        $elapsed = microtime(true) - $start;

        pcntl_waitpid($childPid, $status);
        fclose($sessionStream);

        self::assertTrue($result, 'processSession() should report success for a clean, prompt exchange');
        self::assertFileExists($inbound . '/PROMPT.TST', 'the promptly-offered file should have been received');
        self::assertSame($payload, file_get_contents($inbound . '/PROMPT.TST'));
        self::assertLessThan(5.0, $elapsed, 'a prompt, complete exchange should not need anywhere near the session timeout');
    }

    // ---- CASE B: file offered after the originator's short EOB wait ------------

    public function testCaseB_FileOfferedAfterAbbreviatedWindowIsStillReceived(): void
    {
        [$sessionEnd, $peerEnd] = self::makeSocketPair();
        $sessionStream = socket_export_stream($sessionEnd);
        stream_set_blocking($sessionStream, true);
        stream_set_timeout($sessionStream, 8);

        $inbound = $this->makeTempDir('binkp_frame_inbound_b');
        $outbound = $this->makeTempDir('binkp_frame_outbound_b');
        $payload = str_repeat('B', 2000);

        // 3s delay: past the hardcoded 2s abbreviated originator wait window,
        // so the session WILL commit to its own M_EOB before this arrives.
        $childPid = $this->forkPeerSendingFile($peerEnd, 'DELAYED.TST', $payload, 3_000_000);

        $session = new BinkpSession($sessionStream, true /* originator */, $this->makeConfigStub(8, $inbound, $outbound));
        $logger = self::silentLogger();
        $session->setLogger($logger);

        $start = microtime(true);
        $result = $session->processSession();
        $elapsed = microtime(true) - $start;

        pcntl_waitpid($childPid, $status);
        fclose($sessionStream);

        $sentEobBeforeFile = null;
        foreach ($logger->lines as $line) {
            if (str_contains($line, 'Sending EOB') || str_contains($line, 'No active file transfer, sending EOB')) {
                $sentEobBeforeFile = true;
                break;
            }
            if (str_contains($line, 'Receiving file:')) {
                $sentEobBeforeFile = false;
                break;
            }
        }

        self::assertTrue($sentEobBeforeFile, 'session should have committed to M_EOB before the delayed file arrived');
        self::assertTrue($result, 'processSession() should still report success');
        self::assertFileExists($inbound . '/DELAYED.TST', 'a late M_FILE after our own EOB must still be received');
        self::assertSame($payload, file_get_contents($inbound . '/DELAYED.TST'));
        self::assertLessThan(8.0, $elapsed, 'should complete well inside the session timeout, not hang for it');
    }

    // ---- CASE C: fragmented frame ------------------------------------------------

    /**
     * Before the fix, the first parseFromSocket(nonBlocking=true) call below
     * blocked for the entire stream timeout. Now it must return promptly
     * without discarding the partial bytes, and a later call (once the rest
     * arrives) must complete the same frame and keep the stream in sync.
     */
    public function testCaseC_FragmentedFrameDoesNotBlockAndCompletesOnceRemainingBytesArrive(): void
    {
        [$sessionEnd, $peerEnd] = self::makeSocketPair();
        $sessionStream = socket_export_stream($sessionEnd);
        stream_set_blocking($sessionStream, true);

        // Short stream timeout stands in for the configured binkp timeout
        // - if the fix regresses back to a real blocking read, the first
        // parseFromSocket() call below would take close to this long.
        $shortTimeoutSeconds = 2;
        stream_set_timeout($sessionStream, $shortTimeoutSeconds);

        $peerStream = socket_export_stream($peerEnd);
        stream_set_blocking($peerStream, true);

        $filename = 'FRAG.TST';
        $payload = str_repeat('C', 500);
        $fullFrame = BinkpFrame::createCommand(BinkpFrame::M_FILE, "{$filename} " . strlen($payload) . ' ' . time() . ' 0');
        $dataFrame = BinkpFrame::createData($payload);

        // Capture the exact bytes writeToSocket() would send, so we can
        // forward them to the peer stream in two deliberately-split writes.
        $mem = fopen('php://memory', 'r+b');
        $fullFrame->writeToSocket($mem);
        $dataFrame->writeToSocket($mem);
        rewind($mem);
        $rawBytes = stream_get_contents($mem);
        fclose($mem);

        // Send only the M_FILE command frame's header + command byte (3
        // bytes) first, withholding the filename/size string that makes up
        // the rest of that frame's payload, plus the entire second (data)
        // frame that follows it.
        $prefixLen = 3;
        self::assertGreaterThan($prefixLen, strlen($rawBytes), 'sanity: there must be more to send after the split point');

        fwrite($peerStream, substr($rawBytes, 0, $prefixLen));
        fflush($peerStream);

        // ---- First call: must return promptly, without blocking ----------
        $start = microtime(true);
        $frame1 = BinkpFrame::parseFromSocket($sessionStream, true); // nonBlocking=true
        $firstCallElapsed = microtime(true) - $start;

        self::assertNull($frame1, 'an incomplete frame must not be returned as a usable frame yet');
        self::assertLessThan(
            $shortTimeoutSeconds * 0.5,
            $firstCallElapsed,
            "parseFromSocket(nonBlocking=true) must not block for the stream timeout on a fragmented frame (took {$firstCallElapsed}s)"
        );

        // ---- Deliver the rest, then resume -------------------------------
        fwrite($peerStream, substr($rawBytes, $prefixLen));
        fflush($peerStream);
        fclose($peerStream);

        // Give the bytes a moment to actually land in the kernel buffer,
        // then poll the same way every real caller does.
        $frame2 = null;
        $deadline = microtime(true) + 2.0;
        while ($frame2 === null && microtime(true) < $deadline) {
            $frame2 = BinkpFrame::parseFromSocket($sessionStream, true);
            if ($frame2 === null) {
                usleep(20000);
            }
        }

        self::assertNotNull($frame2, 'the frame must be completable once the remaining bytes arrive - partial bytes must have been preserved, not discarded');
        self::assertTrue($frame2->isCommand());
        self::assertSame(BinkpFrame::M_FILE, $frame2->getCommand());
        self::assertStringStartsWith("{$filename} " . strlen($payload) . ' ', $frame2->getData(), 'the M_FILE frame reassembled from split bytes must carry the original filename/size, not a corrupted resync');

        // The data frame that followed on the wire must also still parse
        // correctly and in order - proof the resumable header/body state
        // machine did not desync the stream after the fragmented frame.
        $dataFrameOut = null;
        $deadline2 = microtime(true) + 2.0;
        while ($dataFrameOut === null && microtime(true) < $deadline2) {
            $dataFrameOut = BinkpFrame::parseFromSocket($sessionStream, true);
            if ($dataFrameOut === null) {
                usleep(20000);
            }
        }
        self::assertNotNull($dataFrameOut, 'the data frame following the fragmented M_FILE frame must still parse correctly');
        self::assertFalse($dataFrameOut->isCommand());
        self::assertSame($payload, $dataFrameOut->getData());

        fclose($sessionStream);
    }
}
