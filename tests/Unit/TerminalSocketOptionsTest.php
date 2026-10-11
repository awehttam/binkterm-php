<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/TerminalSocketOptions.php';

use BinktermPHP\TelnetServer\TerminalSocketOptions;
use PHPUnit\Framework\TestCase;

/**
 * TCP_NODELAY tuning for accepted interactive terminal sockets.
 *
 * The Telnet, TLS-Telnet and SSH accept loops call
 * {@see TerminalSocketOptions::enableNoDelay()} on the freshly accepted socket.
 * These pin the contract the daemons rely on: it actually clears Nagle on a
 * real accepted TCP socket, it honours the TERMINAL_TCP_NODELAY kill switch,
 * and every failure/edge path is swallowed so a caller can never be dropped.
 */
final class TerminalSocketOptionsTest extends TestCase
{
    private ?string $savedEnv = null;

    protected function setUp(): void
    {
        $this->savedEnv = $_ENV['TERMINAL_TCP_NODELAY'] ?? null;
        unset($_ENV['TERMINAL_TCP_NODELAY']);
    }

    protected function tearDown(): void
    {
        if ($this->savedEnv === null) {
            unset($_ENV['TERMINAL_TCP_NODELAY']);
        } else {
            $_ENV['TERMINAL_TCP_NODELAY'] = $this->savedEnv;
        }
    }

    /**
     * @return array{0: resource, 1: resource, 2: resource} [server, client, accepted]
     */
    private function tcpPair(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server, "bind failed: $errstr ($errno)");
        $addr = stream_socket_get_name($server, false);
        $client = stream_socket_client("tcp://$addr", $errno, $errstr, 2);
        self::assertIsResource($client, "connect failed: $errstr ($errno)");
        $accepted = stream_socket_accept($server, 2);
        self::assertIsResource($accepted, 'accept failed');

        return [$server, $client, $accepted];
    }

    private function closeAll(array $resources): void
    {
        foreach ($resources as $r) {
            if (is_resource($r)) {
                fclose($r);
            }
        }
    }

    public function testEnablesNoDelayOnARealAcceptedSocket(): void
    {
        if (!function_exists('socket_import_stream') || !defined('TCP_NODELAY')) {
            self::markTestSkipped('sockets extension / TCP_NODELAY not available');
        }

        [$server, $client, $accepted] = $this->tcpPair();
        try {
            $result = TerminalSocketOptions::enableNoDelay($accepted);
            self::assertTrue($result, 'enableNoDelay() should report success on a live TCP socket');

            // Confirm the kernel actually has the option set.
            $sock = socket_import_stream($accepted);
            self::assertNotFalse($sock);
            self::assertSame(1, socket_get_option($sock, SOL_TCP, TCP_NODELAY));

            // The stream must remain fully usable after the import + setsockopt.
            self::assertSame(4, fwrite($client, 'ping'));
            self::assertSame('ping', fread($accepted, 4));
            self::assertSame(4, fwrite($accepted, 'pong'));
            self::assertSame('pong', fread($client, 4));
        } finally {
            $this->closeAll([$accepted, $client, $server]);
        }
    }

    public function testConfigKillSwitchDisablesTuning(): void
    {
        [$server, $client, $accepted] = $this->tcpPair();
        try {
            foreach (['0', 'false', 'no', 'off', ' OFF '] as $falsey) {
                $_ENV['TERMINAL_TCP_NODELAY'] = $falsey;
                self::assertFalse(
                    TerminalSocketOptions::enableNoDelay($accepted),
                    "value '$falsey' must disable the tuning"
                );
            }
        } finally {
            $this->closeAll([$accepted, $client, $server]);
        }
    }

    public function testDefaultAndTruthyConfigEnableTuning(): void
    {
        if (!function_exists('socket_import_stream') || !defined('TCP_NODELAY')) {
            self::markTestSkipped('sockets extension / TCP_NODELAY not available');
        }

        foreach ([null, 'true', '1', 'yes'] as $truthy) {
            [$server, $client, $accepted] = $this->tcpPair();
            try {
                if ($truthy === null) {
                    unset($_ENV['TERMINAL_TCP_NODELAY']);
                } else {
                    $_ENV['TERMINAL_TCP_NODELAY'] = $truthy;
                }
                self::assertTrue(
                    TerminalSocketOptions::enableNoDelay($accepted),
                    'default / truthy config must enable the tuning'
                );
            } finally {
                $this->closeAll([$accepted, $client, $server]);
            }
        }
    }

    public function testNonResourceInputIsSwallowed(): void
    {
        self::assertFalse(TerminalSocketOptions::enableNoDelay(null));
        self::assertFalse(TerminalSocketOptions::enableNoDelay('not a socket'));
        self::assertFalse(TerminalSocketOptions::enableNoDelay(42));
    }

    public function testClosedResourceIsSwallowed(): void
    {
        [$server, $client, $accepted] = $this->tcpPair();
        fclose($accepted);
        fclose($client);
        fclose($server);
        // A stale/closed handle must not raise — just report failure.
        self::assertFalse(TerminalSocketOptions::enableNoDelay($accepted));
    }

    public function testDebugLoggerStaysSilentOnTheSuccessPath(): void
    {
        if (!function_exists('socket_import_stream') || !defined('TCP_NODELAY')) {
            self::markTestSkipped('sockets extension / TCP_NODELAY not available');
        }

        $calls = [];
        $logger = function (string $m) use (&$calls): void {
            $calls[] = $m;
        };

        [$server, $client, $accepted] = $this->tcpPair();
        try {
            self::assertTrue(TerminalSocketOptions::enableNoDelay($accepted, $logger));
            self::assertSame([], $calls, 'no debug output on the success path');
        } finally {
            $this->closeAll([$accepted, $client, $server]);
        }
    }

    public function testTelnetAndSshDaemonsApplyItToAcceptedConnections(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['telnet/src/TelnetServer.php', 'ssh/src/SshServer.php'] as $server) {
            self::assertStringContainsString('TerminalSocketOptions::enableNoDelay(', (string)file_get_contents($root . '/' . $server), $server);
        }
        foreach (['telnet/telnet_daemon.php', 'ssh/ssh_daemon.php'] as $daemon) {
            self::assertStringContainsString('TerminalSocketOptions.php', (string)file_get_contents($root . '/' . $daemon), $daemon);
        }
    }
}
