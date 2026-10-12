<?php

/**
 * Regression tests for BinkpServer's accepted-socket ownership.
 *
 * On PHP 8 an accepted socket is a \Socket object, not a resource. After
 * socket_export_stream() the stream owns the descriptor: fclose() on the
 * stream closes the socket, and socket_close() on the exported \Socket then
 * throws. Before export, the \Socket is the only owner.
 *
 * Each test proves closure from the outside: the peer end of an in-process
 * AF_UNIX pair must read EOF, and the process's open descriptor count must
 * return to where it started. No network traffic is involved.
 */

use BinktermPHP\Binkp\Protocol\BinkpServer;
use BinktermPHP\Binkp\Protocol\BinkpSession;
use PHPUnit\Framework\TestCase;

final class BinkpServerSocketOwnershipTest extends TestCase
{
    private ?\Socket $peer = null;

    protected function setUp(): void
    {
        if (!is_dir('/proc/self/fd')) {
            $this->markTestSkipped('needs /proc/self/fd to count open descriptors');
        }
    }

    protected function tearDown(): void
    {
        if ($this->peer instanceof \Socket) {
            try {
                socket_close($this->peer);
            } catch (\Throwable $e) {
            }
        }
        $this->peer = null;
    }

    /** Returns the server end; the peer end is kept for EOF checks. */
    private function makeSocketPair(): \Socket
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $this->peer = $pair[1];
        socket_set_option($this->peer, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);
        return $pair[0];
    }

    private static function openFdCount(): int
    {
        return count(scandir('/proc/self/fd'));
    }

    /**
     * Reads from the peer until EOF. Fails if the descriptor is still open
     * (the read times out instead of returning EOF).
     */
    private function readPeerUntilEof(): string
    {
        $data = '';
        while (true) {
            $chunk = @socket_read($this->peer, 4096);
            if ($chunk === false) {
                self::fail('peer did not see EOF: the server side of the connection is still open');
            }
            if ($chunk === '') {
                return $data;
            }
            $data .= $chunk;
        }
    }

    private static function makeServer(object $config, ?array &$logLines = null): BinkpServer
    {
        $logLines = [];
        $logger = new class($logLines) {
            private array $lines;
            public function __construct(array &$lines)
            {
                $this->lines = &$lines;
            }
            public function log($level, $message, $context = []): void
            {
                $this->lines[] = "{$level} {$message}";
            }
        };
        return new BinkpServer($config, $logger);
    }

    private static function config(): object
    {
        return new class {
            public function getBinkpTimeout()
            {
                return 5;
            }
        };
    }

    /** Fails after socket_export_stream() has already succeeded. */
    private static function configFailingAfterExport(): object
    {
        return new class {
            public function getBinkpTimeout()
            {
                throw new \RuntimeException('simulated failure after export');
            }
        };
    }

    private static function invokePrivate(BinkpServer $server, string $method, array $args)
    {
        $ref = new ReflectionMethod(BinkpServer::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($server, $args);
    }

    // ---- sendBusy() --------------------------------------------------------

    public function testSendBusySendsFrameAndClosesDescriptor(): void
    {
        $server = self::makeServer(self::config());
        $socket = $this->makeSocketPair();
        $baseline = self::openFdCount();

        $owned = self::invokePrivate($server, 'sendBusy', [$socket, 'Maximum connections reached']);

        self::assertTrue($owned, 'sendBusy() exported the socket, so it owns the descriptor');
        $received = $this->readPeerUntilEof();
        self::assertStringContainsString('Maximum connections reached', $received, 'peer should receive the M_BSY frame before EOF');
        self::assertSame($baseline - 1, self::openFdCount(), 'the server-side descriptor must be closed');
    }

    public function testSendBusyOwnsDescriptorWhenStreamSetupFailsAfterExport(): void
    {
        $server = self::makeServer(self::configFailingAfterExport(), $log);
        $socket = $this->makeSocketPair();
        $baseline = self::openFdCount();

        $owned = self::invokePrivate($server, 'sendBusy', [$socket, 'Maximum connections reached']);

        // The socket was exported before the failure. Reporting false here
        // would make the caller socket_close() an exported socket.
        self::assertTrue($owned, 'export succeeded, so ownership moved even though setup failed');
        self::assertSame('', $this->readPeerUntilEof());
        self::assertSame($baseline - 1, self::openFdCount());
        self::assertNotEmpty(preg_grep('/Failed to send busy message/', $log));
    }

    public function testSendBusyContainsErrorFromClosedSocketAndReportsNoTransfer(): void
    {
        $server = self::makeServer(self::config());
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        socket_close($socket);

        // socket_set_option()/socket_export_stream() throw \Error on a closed
        // \Socket; it must not escape into the accept loop.
        $owned = self::invokePrivate($server, 'sendBusy', [$socket, 'Maximum connections reached']);

        self::assertFalse($owned);
    }

    // ---- handleConnectionSync() -------------------------------------------

    public function testHandleConnectionSyncClosesExportedStreamWhenNoSessionIsCreated(): void
    {
        $server = self::makeServer(self::configFailingAfterExport(), $log);
        $socket = $this->makeSocketPair();
        $baseline = self::openFdCount();

        self::invokePrivate($server, 'handleConnectionSync', [$socket, 'test-conn', '127.0.0.1']);

        self::assertSame('', $this->readPeerUntilEof(), 'the exported descriptor must be closed even though no session was created');
        self::assertSame($baseline - 1, self::openFdCount());
        self::assertNotEmpty(preg_grep('/Connection error for 127\.0\.0\.1/', $log));
        self::assertEmpty(preg_grep('/Failed to close/', $log), 'the exported socket must not be closed a second time');
    }

    // ---- releaseConnection() ownership matrix ------------------------------

    public function testReleaseClosesRawSocketThatWasNeverExported(): void
    {
        $server = self::makeServer(self::config(), $log);
        $socket = $this->makeSocketPair();
        $baseline = self::openFdCount();

        self::invokePrivate($server, 'releaseConnection', [$socket, false, null, null, '127.0.0.1', 'test-conn']);

        self::assertSame('', $this->readPeerUntilEof());
        self::assertSame($baseline - 1, self::openFdCount());
        self::assertEmpty(preg_grep('/Failed to close/', $log));
    }

    public function testReleaseClosesSessionOwnedDescriptorOnce(): void
    {
        $server = self::makeServer(self::config(), $log);
        $socket = $this->makeSocketPair();
        $baseline = self::openFdCount();

        $stream = socket_export_stream($socket);
        $session = new BinkpSession($stream, false, self::config());

        self::invokePrivate($server, 'releaseConnection', [$socket, true, $stream, $session, '127.0.0.1', 'test-conn']);

        self::assertSame('', $this->readPeerUntilEof());
        self::assertSame($baseline - 1, self::openFdCount());
        self::assertEmpty(preg_grep('/Failed to close|Error closing session/', $log), 'no second close attempt on the exported socket');
    }

    public function testReleaseContainsSessionCloseFailureAndStillClosesDescriptor(): void
    {
        $server = self::makeServer(self::config(), $log);
        $socket = $this->makeSocketPair();
        $baseline = self::openFdCount();

        $stream = socket_export_stream($socket);
        $session = new class($stream, false, self::config()) extends BinkpSession {
            public function close()
            {
                throw new \RuntimeException('simulated cleanup failure');
            }
        };

        // In a forked child an escaping exception would skip exit(0) and run
        // the parent's accept loop, so release must not throw.
        self::invokePrivate($server, 'releaseConnection', [$socket, true, $stream, $session, '127.0.0.1', 'test-conn']);

        self::assertNotEmpty(preg_grep('/Error closing session .*simulated cleanup failure/', $log));
        self::assertSame('', $this->readPeerUntilEof(), 'the stream must still be closed after session close() failed');
        self::assertSame($baseline - 1, self::openFdCount());
    }

    // ---- Cleanup survives a failing logger --------------------------------

    private static function throwingLogger(): object
    {
        return new class {
            public function log($level, $message, $context = []): void
            {
                throw new \RuntimeException('logger unavailable');
            }
        };
    }

    public function testHandleConnectionSyncContainsPreExportFailure(): void
    {
        // A closed \Socket makes the first socket call throw \Error before
        // export; handleConnectionSync() must contain it.
        $server = self::makeServer(self::config(), $log);
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        socket_close($socket);

        self::invokePrivate($server, 'handleConnectionSync', [$socket, 'test-conn', '127.0.0.1']);

        self::assertNotEmpty(preg_grep('/Connection error for 127\.0\.0\.1/', $log));
    }

    public function testHandleConnectionSyncClosesDescriptorAndThrowsNothingWhenLoggerFails(): void
    {
        $server = new BinkpServer(self::configFailingAfterExport(), self::throwingLogger());
        $socket = $this->makeSocketPair();
        $baseline = self::openFdCount();

        self::invokePrivate($server, 'handleConnectionSync', [$socket, 'test-conn', '127.0.0.1']);

        self::assertSame('', $this->readPeerUntilEof());
        self::assertSame($baseline - 1, self::openFdCount());
    }

    public function testReleaseClosesDescriptorWhenSessionCloseAndLoggerBothFail(): void
    {
        $server = new BinkpServer(self::config(), self::throwingLogger());
        $socket = $this->makeSocketPair();
        $baseline = self::openFdCount();

        $stream = socket_export_stream($socket);
        $session = new class($stream, false, self::config()) extends BinkpSession {
            public function close()
            {
                throw new \RuntimeException('simulated cleanup failure');
            }
        };

        self::invokePrivate($server, 'releaseConnection', [$socket, true, $stream, $session, '127.0.0.1', 'test-conn']);

        self::assertSame('', $this->readPeerUntilEof());
        self::assertSame($baseline - 1, self::openFdCount());
    }

    public function testSendBusyThrowsNothingWhenLoggerFails(): void
    {
        $server = new BinkpServer(self::configFailingAfterExport(), self::throwingLogger());
        $socket = $this->makeSocketPair();

        self::assertTrue(self::invokePrivate($server, 'sendBusy', [$socket, 'Maximum connections reached']));
        self::assertSame('', $this->readPeerUntilEof());
    }
}
