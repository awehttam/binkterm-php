<?php

declare(strict_types=1);

use BinktermPHP\Terminal\ConnectionRateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * SSH hardening slice 1 — the shared per-source-IP connection rate limiter.
 *
 * The algorithm mirrors the Telnet daemon's `TELNET_RATE_LIMIT_*` fixed-window
 * logic ({@see \BinktermPHP\TelnetServer\TelnetServer}); these pin the behaviour
 * the SSH accept loop now depends on: allow up to `max` per window, reject the
 * rest with a log-once/suppress-rest signal, independent windows per IP, and
 * expiry that frees the entry.
 */
final class ConnectionRateLimiterTest extends TestCase
{
    public function testConnectionsUpToTheThresholdAreAllowed(): void
    {
        $rl = new ConnectionRateLimiter(5, 60);

        for ($i = 1; $i <= 5; $i++) {
            self::assertSame(0, $rl->check('198.51.100.7'), "connection #{$i} should be allowed");
        }
    }

    public function testSourceIpOverTheThresholdIsRejectedLogOnceThenSuppressed(): void
    {
        $rl = new ConnectionRateLimiter(3, 60);

        self::assertSame(0, $rl->check('203.0.113.9'));
        self::assertSame(0, $rl->check('203.0.113.9'));
        self::assertSame(0, $rl->check('203.0.113.9'));
        // 4th within the window: first rejection -> caller should log
        self::assertSame(1, $rl->check('203.0.113.9'));
        // 5th, 6th: still rejected, but caller stays quiet
        self::assertSame(2, $rl->check('203.0.113.9'));
        self::assertSame(2, $rl->check('203.0.113.9'));
    }

    public function testAnotherSourceIpIsNotAffected(): void
    {
        $rl = new ConnectionRateLimiter(2, 60);

        self::assertSame(0, $rl->check('203.0.113.1'));
        self::assertSame(0, $rl->check('203.0.113.1'));
        self::assertSame(1, $rl->check('203.0.113.1')); // over limit

        // A different IP has its own fresh window.
        self::assertSame(0, $rl->check('203.0.113.2'));
        self::assertSame(0, $rl->check('203.0.113.2'));
        self::assertSame(1, $rl->check('203.0.113.2'));
    }

    public function testWindowExpiryResetsTheCounterLikeTelnet(): void
    {
        $rl = new ConnectionRateLimiter(2, 1); // 1-second window

        self::assertSame(0, $rl->check('192.0.2.50'));
        self::assertSame(0, $rl->check('192.0.2.50'));
        self::assertSame(1, $rl->check('192.0.2.50')); // rejected in window 1

        sleep(2); // let the window expire

        // Next connection starts a brand-new window.
        self::assertSame(0, $rl->check('192.0.2.50'));
        self::assertSame(0, $rl->check('192.0.2.50'));
        self::assertSame(1, $rl->check('192.0.2.50'));
    }

    public function testCleanRemovesExpiredEntriesAndFlushesSuppressedCount(): void
    {
        $messages = [];
        $rl = new ConnectionRateLimiter(1, 1, function (string $m) use (&$messages) {
            $messages[] = $m;
        });

        self::assertSame(0, $rl->check('192.0.2.77'));
        self::assertSame(1, $rl->check('192.0.2.77')); // logged rejection
        self::assertSame(2, $rl->check('192.0.2.77')); // suppressed (+1)
        self::assertSame(2, $rl->check('192.0.2.77')); // suppressed (+1)
        self::assertSame(1, $rl->trackedIpCount());

        sleep(2);
        $rl->clean();

        self::assertSame(0, $rl->trackedIpCount(), 'expired entry should be dropped');
        self::assertCount(1, $messages);
        self::assertStringContainsString('2 additional rejection(s) from 192.0.2.77 suppressed', $messages[0]);
    }

    public function testMaxOfZeroDisablesLimitingEntirely(): void
    {
        $rl = new ConnectionRateLimiter(0, 60);
        for ($i = 0; $i < 50; $i++) {
            self::assertSame(0, $rl->check('203.0.113.250'));
        }
        self::assertFalse($rl->isEnabled());
        self::assertSame(0, $rl->trackedIpCount());
    }

    public function testDefaultsMatchTheTelnetPolicyNumbers(): void
    {
        // Telnet: TELNET_RATE_LIMIT_MAX=5, TELNET_RATE_LIMIT_WINDOW=60.
        $rl = new ConnectionRateLimiter(5, 60);
        self::assertTrue($rl->isEnabled());
        for ($i = 1; $i <= 5; $i++) {
            self::assertSame(0, $rl->check('198.51.100.42'));
        }
        self::assertSame(1, $rl->check('198.51.100.42'));
    }

    public function testNonPositiveWindowFallsBackToTheSafeDefault(): void
    {
        // A misconfigured 0/negative window must not defeat the limiter.
        $rl = new ConnectionRateLimiter(2, 0);
        self::assertSame(0, $rl->check('192.0.2.9'));
        self::assertSame(0, $rl->check('192.0.2.9'));
        self::assertSame(1, $rl->check('192.0.2.9')); // still enforced immediately
    }
}
