<?php

declare(strict_types=1);

require_once __DIR__ . '/../../ssh/src/SshStreamWrapper.php';
require_once __DIR__ . '/../../ssh/src/SshSession.php';
require_once __DIR__ . '/../../ssh/src/SshServer.php';

use BinktermPHP\SshServer\SshServer;
use PHPUnit\Framework\TestCase;

/**
 * SSH hardening slice 1 — the SSH accept loop applies the per-IP connection
 * rate limit BEFORE it forks or does any SSH/auth work.
 *
 * Spinning a real listener + fork inside a unit test is not practical, so the
 * "before fork" property is pinned by source order (the same approach other
 * slices on this branch use for structural guarantees), plus a direct check of
 * the peer-IP extraction the guard relies on.
 */
final class SshServerRateLimitGuardTest extends TestCase
{
    private static function acceptLoopSource(): string
    {
        $src = file_get_contents(__DIR__ . '/../../ssh/src/SshServer.php');
        self::assertIsString($src);

        // Isolate the accept loop itself ($connectionCount is declared just
        // above `while (true)`), excluding the earlier daemonize fork.
        $start = strpos($src, '$connectionCount = 0;');
        $end   = strpos($src, 'private function extractIp(');
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        return substr($src, $start, $end - $start);
    }

    public function testRateLimitCheckHappensBeforePcntlForkInTheAcceptLoop(): void
    {
        $loop = self::acceptLoopSource();

        $checkPos = strpos($loop, '$this->rateLimiter->check(');
        $forkPos  = strpos($loop, '$pid = pcntl_fork();');

        self::assertNotFalse($checkPos, 'accept loop must call the rate limiter');
        self::assertNotFalse($forkPos, 'accept loop must fork per connection');
        self::assertLessThan($forkPos, $checkPos, 'rate-limit check must precede pcntl_fork()');
    }

    public function testRejectedConnectionIsClosedAndSkippedWithoutForking(): void
    {
        $loop = self::acceptLoopSource();

        // The reject branch: log-once, close, continue — and no fork between the
        // check and that continue.
        $checkPos    = strpos($loop, '$rl = $this->rateLimiter->check(');
        $continuePos = strpos($loop, 'continue;', $checkPos);
        self::assertNotFalse($continuePos);

        $rejectBranch = substr($loop, $checkPos, $continuePos - $checkPos);
        self::assertStringContainsString('fclose($conn);', $rejectBranch);
        self::assertStringContainsString('rate limit exceeded', strtolower($rejectBranch));
        self::assertStringNotContainsString('pcntl_fork', $rejectBranch);
    }

    public function testSelectTimeoutPrunesTheRateTable(): void
    {
        $loop = self::acceptLoopSource();
        self::assertStringContainsString('$this->rateLimiter->clean();', $loop);
    }

    public function testExtractIpParsesV4AndV6AndRejectsGarbage(): void
    {
        $server = new SshServer('127.0.0.1', 2022, 'http://localhost');
        $m = new ReflectionMethod(SshServer::class, 'extractIp');
        $m->setAccessible(true);

        self::assertSame('198.51.100.7', $m->invoke($server, '198.51.100.7:51514'));
        self::assertSame('2001:db8::1', $m->invoke($server, '[2001:db8::1]:51514'));
        self::assertNull($m->invoke($server, null));
        self::assertNull($m->invoke($server, ''));
        self::assertNull($m->invoke($server, 'not-an-address'));
    }

    public function testLimiterIsConstructedFromSshNamespacedConfig(): void
    {
        $src = file_get_contents(__DIR__ . '/../../ssh/src/SshServer.php');
        self::assertStringContainsString("Config::env('SSH_RATE_LIMIT_MAX', '5')", $src);
        self::assertStringContainsString("Config::env('SSH_RATE_LIMIT_WINDOW', '60')", $src);
    }
}
