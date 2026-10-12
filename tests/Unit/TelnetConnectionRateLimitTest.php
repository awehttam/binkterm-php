<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/TelnetServer.php';

use BinktermPHP\Binkp\Logger;
use BinktermPHP\TelnetServer\TelnetServer;
use BinktermPHP\Terminal\ConnectionRateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * The Telnet daemon's per-IP connection rate limit now uses the shared
 * ConnectionRateLimiter (also used by the SSH daemon) instead of its own
 * copy. TELNET_RATE_LIMIT_* names and defaults, and the suppressed-rejection
 * log line, must be unchanged.
 */
final class TelnetConnectionRateLimitTest extends TestCase
{
    private array $savedEnv = [];
    private ?string $logFile = null;

    protected function setUp(): void
    {
        foreach (['TELNET_RATE_LIMIT_MAX', 'TELNET_RATE_LIMIT_WINDOW'] as $k) {
            $this->savedEnv[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
        if ($this->logFile !== null) {
            @unlink($this->logFile);
        }
    }

    private function limiterOf(TelnetServer $server): ConnectionRateLimiter
    {
        $p = new ReflectionProperty(TelnetServer::class, 'rateLimiter');
        $p->setAccessible(true);
        return $p->getValue($server);
    }

    private static function attempts(ConnectionRateLimiter $limiter, string $ip, int $n): array
    {
        $results = [];
        for ($i = 0; $i < $n; $i++) {
            $results[] = $limiter->check($ip);
        }
        return $results;
    }

    public function testDefaultsAreFivePerSixtySeconds(): void
    {
        $limiter = $this->limiterOf(new TelnetServer('127.0.0.1', 0, 'http://127.0.0.1:9'));

        self::assertTrue($limiter->isEnabled());
        self::assertSame([0, 0, 0, 0, 0, 1, 2], self::attempts($limiter, '192.0.2.10', 7));
    }

    public function testTelnetSettingsAreHonoured(): void
    {
        $_ENV['TELNET_RATE_LIMIT_MAX'] = '2';
        $limiter = $this->limiterOf(new TelnetServer('127.0.0.1', 0, 'http://127.0.0.1:9'));
        self::assertSame([0, 0, 1, 2], self::attempts($limiter, '192.0.2.11', 4));

        $_ENV['TELNET_RATE_LIMIT_MAX'] = '0';
        $limiter = $this->limiterOf(new TelnetServer('127.0.0.1', 0, 'http://127.0.0.1:9'));
        self::assertFalse($limiter->isEnabled());
        self::assertSame([0, 0, 0, 0, 0, 0, 0, 0], self::attempts($limiter, '192.0.2.12', 8));
    }

    public function testIpv4MappedClientSharesTheIpv4Counter(): void
    {
        $_ENV['TELNET_RATE_LIMIT_MAX'] = '2';
        $limiter = $this->limiterOf(new TelnetServer('127.0.0.1', 0, 'http://127.0.0.1:9'));

        self::assertSame(0, $limiter->check('192.0.2.13'));
        self::assertSame(0, $limiter->check('::ffff:192.0.2.13'));
        self::assertSame(1, $limiter->check('192.0.2.13'), 'the mapped form must count against the same client');
    }

    public function testSuppressedRejectionsAreFlushedToTheTelnetLogWithTheSameText(): void
    {
        $_ENV['TELNET_RATE_LIMIT_MAX'] = '1';
        $_ENV['TELNET_RATE_LIMIT_WINDOW'] = '1';
        $server = new TelnetServer('127.0.0.1', 0, 'http://127.0.0.1:9');

        $this->logFile = sys_get_temp_dir() . '/telnet_rl_' . bin2hex(random_bytes(4)) . '.log';
        $p = new ReflectionProperty(TelnetServer::class, 'logger');
        $p->setAccessible(true);
        $p->setValue($server, new Logger($this->logFile, 'INFO', false));

        $limiter = $this->limiterOf($server);
        self::assertSame([0, 1, 2, 2], self::attempts($limiter, '192.0.2.14', 4));

        sleep(1);
        $limiter->clean();

        self::assertStringContainsString(
            'Rate limit: 2 additional rejection(s) from 192.0.2.14 suppressed',
            (string)file_get_contents($this->logFile)
        );
        self::assertSame(0, $limiter->trackedIpCount(), 'expired entries are pruned');
    }

    public function testTelnetNoLongerCarriesItsOwnLimiterCopy(): void
    {
        self::assertFalse(method_exists(TelnetServer::class, 'isRateLimited'));
        self::assertFalse(method_exists(TelnetServer::class, 'cleanRateTable'));
    }
}
