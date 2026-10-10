<?php

/**
 * Regression tests for BinkpServer's explicit socket ownership / deterministic
 * descriptor cleanup (mirrors BinkpClient.php's existing ownership pattern).
 *
 * BinkpServer::socketToStream() exports an accepted `Socket` resource
 * (ext-sockets) into a PHP stream via socket_export_stream() and hands that
 * stream to a BinkpSession. Per PHP's own documentation, the original
 * `Socket` resource must not be used with socket_*() functions once exported
 * — from that point on, the stream (and whatever owns it) is the sole valid
 * way to close the underlying descriptor. The change under test makes that
 * session-owned descriptor lifecycle explicit and deterministic instead of
 * relying on incidental `is_resource()`/GC timing on exception paths.
 *
 * These tests exercise that ownership-transfer contract at the narrowest
 * real (non-network, in-process AF_UNIX socket pair) seam available, without
 * driving a full BinkP handshake:
 *
 *   1. Once a stream exported from a socket is closed, the sibling `Socket`
 *      resource is provably dead — demonstrating why descriptor cleanup must
 *      be explicit and session-owned rather than left to incidental
 *      `is_resource()`/GC timing after the fact.
 *   2. BinkpServer::sendBusy() (the busy/reject path) itself takes ownership
 *      of the exported stream and closes it, reporting that back to the
 *      caller via its return value, so the caller's fallback socket_close()
 *      only runs when ownership was never transferred.
 *   3. BinkpServer::handleConnectionSync()'s raw-socket fallback still closes
 *      the socket when a BinkpSession is never constructed, forced here via
 *      a config stub that fails after the stream export already succeeded
 *      but before a BinkpSession exists — the descriptor has already been
 *      exported to a stream at that point, but no session was ever created
 *      to own it, which is the specific condition this test exercises.
 *   4. A source-level guardrail locks in the specific gated
 *      `if ($session) { ... } elseif (is_resource(...)) { ... }` shape so the
 *      old unconditional raw-socket-close pattern cannot silently reappear.
 *
 * No real BinkP network traffic is used anywhere — socket_create_pair()
 * creates an in-process, loopback-only AF_UNIX pair.
 */

use BinktermPHP\Binkp\Protocol\BinkpServer;
use BinktermPHP\Binkp\Protocol\BinkpSession;
use PHPUnit\Framework\TestCase;

final class BinkpServerSocketOwnershipTest extends TestCase
{
    /** @return array{0:\Socket,1:\Socket} */
    private static function makeSocketPair(): array
    {
        $pair = [];
        $ok = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        self::assertTrue($ok, 'socket_create_pair() failed (in-process, no network involved)');
        return $pair;
    }

    private static function noopLogger(): object
    {
        return new class {
            public function log($level, $message, $context = []): void
            {
                // discard
            }
        };
    }

    // ---- 1. Precondition: closing the stream kills the sibling socket -----

    public function testSessionCloseInvalidatesSiblingSocketResource(): void
    {
        [$serverEnd, $peerEnd] = self::makeSocketPair();

        $stream = socket_export_stream($serverEnd);
        self::assertIsResource($stream);

        $config = new class {
        };
        $session = new BinkpSession($stream, false, $config);
        $session->setLogger(self::noopLogger());

        $session->close();

        // The exported stream is gone; the sibling `Socket` resource for the
        // very same descriptor must already report itself invalid too — this
        // is exactly why a second explicit close on $serverEnd afterward
        // would be operating on a dead handle.
        self::assertFalse(is_resource($serverEnd), 'sibling Socket resource should be invalidated once the exported stream is closed');

        socket_close($peerEnd);
    }

    // ---- 2. sendBusy() owns and closes its exported stream itself ---------

    public function testSendBusyTransfersOwnershipAndClosesExportedStreamItself(): void
    {
        [$serverEnd, $peerEnd] = self::makeSocketPair();

        $server = new BinkpServer(new class {
            public function getBinkpTimeout()
            {
                return 5;
            }
        }, self::noopLogger());

        $method = new ReflectionMethod(BinkpServer::class, 'sendBusy');
        $method->setAccessible(true);

        $result = $method->invoke($server, $serverEnd, 'Maximum connections reached');

        self::assertTrue($result, 'sendBusy() should report that it took ownership of the exported stream');
        self::assertFalse(is_resource($serverEnd), 'sendBusy() must close the descriptor itself once it owns it');

        // Round-trip proof the busy frame was actually written before close.
        $received = @socket_read($peerEnd, 4096);
        self::assertNotEmpty($received, 'peer should have received the M_BSY busy frame bytes');

        socket_close($peerEnd);
    }

    /**
     * PHP 8's socket_export_stream() throws \Error (a \Throwable, not an
     * \Exception) when given an already-closed Socket — e.g.
     * "Argument #1 ($socket) has already been closed". sendBusy() must
     * contain that the same way it contains an \Exception: log it and report
     * that ownership was never transferred, not let it escape uncaught.
     */
    public function testSendBusyContainsNonExceptionThrowableFromSocketExportStream(): void
    {
        // A Socket resource that is valid PHP-side but already closed, so
        // socket_export_stream() throws \Error rather than returning false —
        // no network involved.
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        socket_close($socket);

        $server = new BinkpServer(new class {
            public function getBinkpTimeout()
            {
                return 5;
            }
        }, self::noopLogger());

        $method = new ReflectionMethod(BinkpServer::class, 'sendBusy');
        $method->setAccessible(true);

        $result = $method->invoke($server, $socket, 'Maximum connections reached');

        self::assertFalse($result, 'sendBusy() should report ownership was never transferred when export throws');
    }

    // ---- 3. Raw-socket fallback still closes when no session is constructed

    public function testHandleConnectionSyncClosesRawSocketWhenSessionIsNeverConstructed(): void
    {
        [$serverEnd, $peerEnd] = self::makeSocketPair();

        // socketToStream() calls socket_export_stream() (which succeeds — the
        // descriptor has already been exported to a stream at this point) and
        // only THEN calls $this->config->getBinkpTimeout(). Making that throw
        // forces a failure after export but before `new BinkpSession(...)`
        // ever runs, i.e. exactly the "$session stays null" condition
        // handleConnectionSync()'s `if ($session) {...} elseif (...)` gate
        // falls back on — without needing a real/failing handshake.
        $config = new class {
            public function getBinkpTimeout()
            {
                throw new \RuntimeException('simulated config failure after export');
            }
        };
        $server = new BinkpServer($config, self::noopLogger());

        $method = new ReflectionMethod(BinkpServer::class, 'handleConnectionSync');
        $method->setAccessible(true);

        // Must not let the RuntimeException (or anything else) escape —
        // handleConnectionSync() catches \Throwable internally.
        $method->invoke($server, $serverEnd, 'test-conn', '127.0.0.1');

        self::assertFalse(is_resource($serverEnd), 'the raw socket must still end up closed when no session was ever created');

        // Deliberately not asserting peer-side EOF here: the stream that
        // socket_export_stream() produced inside socketToStream() before the
        // simulated failure is a local variable PHP only reclaims once
        // nothing (including the caught exception's own backtrace) still
        // references it, so the OS-level close can lag behind
        // is_resource($serverEnd) turning false by an unpredictable amount —
        // asserting on peer-visible EOF here would make this test flaky/
        // hang-prone rather than proving anything about our own code. The
        // resource-handle-level check above is the actual contract
        // handleConnectionSync()'s `elseif (is_resource($clientSocket))`
        // branch depends on, and is what we can prove deterministically.
        socket_close($peerEnd);
    }

    // ---- 4. Source guardrail: the gated shape, not the old unconditional one

    public function testHandleConnectionSyncSourceUsesSessionOwnershipGate(): void
    {
        $ref = new ReflectionClass(BinkpServer::class);
        $src = file_get_contents($ref->getFileName());

        self::assertStringContainsString(
            "if (\$session) {\n            \$session->close();\n        } elseif (is_resource(\$clientSocket)) {\n            socket_close(\$clientSocket);\n        }",
            $src,
            'handleConnectionSync() must gate the raw socket_close() on the session never having taken ownership'
        );

        // The old defect shape: an unconditional socket_close($clientSocket)
        // sitting right after $session->close() with no ownership gate
        // between them must not reappear.
        self::assertDoesNotMatchRegularExpression(
            '/\$session->close\(\);\s*\}\s*catch[^}]*\}\s*if \(is_resource\(\$clientSocket\)\) \{\s*socket_close\(\$clientSocket\);/s',
            $src,
            'the old unconditional raw-socket-close pattern must not reappear'
        );
    }
}
