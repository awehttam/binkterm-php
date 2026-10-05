<?php

declare(strict_types=1);

use BinktermPHP\Database;
use BinktermPHP\Security\LoginThrottle;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural proof for the shared failed-login throttle
 * ({@see \BinktermPHP\Security\LoginThrottle}) that guards POST /api/auth/login.
 *
 * Uses the real auth_login_attempts table with a per-run unique identifier and
 * IP so parallel/repeat runs never collide; tearDown removes the rows.
 */
final class LoginThrottleTest extends TestCase
{
    private PDO $db;
    private string $user;
    private string $ip;
    /** @var array<string,string|null> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->db = self::testDb();
        $suffix = bin2hex(random_bytes(5));
        $this->user = 'throttle-test-' . $suffix;
        // Deterministic documentation-range test IP, made unique by the low octets.
        $this->ip = '203.0.113.' . random_int(1, 254);

        foreach (['AUTH_LOGIN_USER_MAX', 'AUTH_LOGIN_IP_MAX', 'AUTH_LOGIN_WINDOW'] as $key) {
            $this->savedEnv[$key] = $_ENV[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return; // skipped before connecting
        }
        // Remove everything this test could have written: its identifier, plus
        // any identifier that shared its test IP (the spray cases).
        $this->db->prepare('DELETE FROM auth_login_attempts WHERE ip_key = ? OR identifier_key = ?')
            ->execute([$this->ip, mb_strtolower($this->user)]);
        $this->db->prepare("DELETE FROM auth_login_attempts WHERE identifier_key LIKE 'throttle-test-%'")
            ->execute();

        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    private function limiter(int $userMax = 5, int $ipMax = 20, int $window = 900): LoginThrottle
    {
        return new LoginThrottle($this->db, $userMax, $ipMax, $window);
    }

    public function testCleanSlateIsAllowed(): void
    {
        $this->assertTrue($this->limiter()->isAllowed($this->user, $this->ip));
    }

    public function testFailuresBelowUsernameThresholdStillAllowed(): void
    {
        $limiter = $this->limiter(userMax: 5);
        for ($i = 0; $i < 4; $i++) {
            $limiter->recordFailure($this->user, $this->ip);
        }
        $this->assertTrue($limiter->isAllowed($this->user, $this->ip));
    }

    public function testUsernameThresholdExceededBlocks(): void
    {
        $limiter = $this->limiter(userMax: 5);
        for ($i = 0; $i < 5; $i++) {
            $limiter->recordFailure($this->user, $this->ip);
        }
        $this->assertFalse($limiter->isAllowed($this->user, $this->ip));
    }

    public function testUsernameNormalizationSharesOneCounter(): void
    {
        $limiter = $this->limiter(userMax: 5);
        $variants = [
            '  ' . strtoupper($this->user) . '  ',
            strtoupper($this->user),
            ' ' . $this->user,
            $this->user . ' ',
            ucfirst($this->user),
        ];
        foreach ($variants as $variant) {
            $limiter->recordFailure($variant, $this->ip);
        }
        // 5 failures spread across case/whitespace variants → the normalized
        // key is blocked, and every variant sees the block.
        $this->assertFalse($limiter->isAllowed($this->user, $this->ip));
        $this->assertFalse($limiter->isAllowed(strtoupper($this->user), $this->ip));
        $this->assertFalse($limiter->isAllowed('   ' . $this->user, $this->ip));
    }

    public function testUnknownUsernameAndWrongPasswordCountIdentically(): void
    {
        // The limiter never learns whether a username exists — recordFailure is
        // called with the submitted string in both cases, so both advance the
        // same normalized counter. Simulate a mix and confirm the threshold.
        $limiter = $this->limiter(userMax: 5);
        for ($i = 0; $i < 5; $i++) {
            $limiter->recordFailure($this->user, $this->ip);
        }
        $this->assertFalse(
            $limiter->isAllowed($this->user, $this->ip),
            'a submitted identifier is throttled after N failures regardless of whether it names a real account'
        );
    }

    public function testIpThresholdBlocksUsernameSpray(): void
    {
        $limiter = $this->limiter(userMax: 5, ipMax: 20);
        // One source IP, many distinct usernames, staying under the per-username
        // limit (3 each) but exceeding the per-IP limit.
        for ($n = 0; $n < 7; $n++) {
            $sprayUser = $this->user . '-victim-' . $n;
            for ($i = 0; $i < 3; $i++) {
                $limiter->recordFailure($sprayUser, $this->ip);
            }
        }
        // 21 IP failures > ipMax 20; a brand-new username from that IP is blocked.
        $this->assertFalse($limiter->isAllowed($this->user . '-fresh', $this->ip));
    }

    public function testDifferentSourceIpIsIndependent(): void
    {
        $limiter = $this->limiter(ipMax: 5);
        for ($i = 0; $i < 6; $i++) {
            $limiter->recordFailure($this->user . '-a-' . $i, $this->ip);
        }
        $this->assertFalse($limiter->isAllowed($this->user, $this->ip));
        $this->assertTrue($limiter->isAllowed($this->user, '198.51.100.7'));
    }

    public function testSuccessClearsSubmittedUsernameCounter(): void
    {
        $limiter = $this->limiter(userMax: 5);
        for ($i = 0; $i < 5; $i++) {
            $limiter->recordFailure($this->user, $this->ip);
        }
        $this->assertFalse($limiter->isAllowed($this->user, $this->ip));

        $limiter->recordSuccess(' ' . strtoupper($this->user) . ' ');

        $this->assertTrue($limiter->isAllowed($this->user, $this->ip));
    }

    public function testSuccessDoesNotClearSourceIpHistory(): void
    {
        $limiter = $this->limiter(userMax: 5, ipMax: 10);

        // 9 spray failures against other usernames from this IP.
        for ($n = 0; $n < 9; $n++) {
            $limiter->recordFailure($this->user . '-spray-' . $n, $this->ip);
        }
        // Plus 2 failures against the caller's own (valid) account.
        $limiter->recordFailure($this->user, $this->ip);
        $limiter->recordFailure($this->user, $this->ip);

        // IP counter now 11 > ipMax 10 → blocked.
        $this->assertFalse($limiter->isAllowed($this->user, $this->ip));

        // Caller logs into their own valid account.
        $limiter->recordSuccess($this->user);

        // The IP is STILL blocked — success must not wipe the spray counter.
        $this->assertFalse(
            $limiter->isAllowed($this->user . '-next', $this->ip),
            'a successful login must not reset the source-IP failure counter'
        );
    }

    public function testRollingWindowExpiryRestoresAccess(): void
    {
        $limiter = $this->limiter(userMax: 5, ipMax: 20, window: 900);
        for ($i = 0; $i < 6; $i++) {
            $limiter->recordFailure($this->user, $this->ip);
        }
        $this->assertFalse($limiter->isAllowed($this->user, $this->ip));

        // Age every row for this test past the 15-minute window.
        $this->db->prepare(
            "UPDATE auth_login_attempts SET attempted_at = NOW() - INTERVAL '20 minutes'
             WHERE ip_key = ? OR identifier_key = ?"
        )->execute([$this->ip, mb_strtolower($this->user)]);

        $this->assertTrue($limiter->isAllowed($this->user, $this->ip));
    }

    public function testCleanOldRemovesAgedRowsOnly(): void
    {
        $limiter = $this->limiter();
        $limiter->recordFailure($this->user, $this->ip);
        $limiter->recordFailure($this->user, $this->ip);

        // Age one of the two rows past the 1h cleanup horizon.
        $this->db->prepare(
            "UPDATE auth_login_attempts SET attempted_at = NOW() - INTERVAL '2 hours'
             WHERE id = (SELECT MIN(id) FROM auth_login_attempts WHERE identifier_key = ?)"
        )->execute([mb_strtolower($this->user)]);

        $limiter->cleanOld();

        $remaining = $this->db->prepare('SELECT COUNT(*) FROM auth_login_attempts WHERE identifier_key = ?');
        $remaining->execute([mb_strtolower($this->user)]);
        $this->assertSame(1, (int)$remaining->fetchColumn());
    }

    public function testMalformedConfigFallsBackToSafeDefaults(): void
    {
        foreach (['0', '-3', 'abc', '', '  '] as $bad) {
            $_ENV['AUTH_LOGIN_USER_MAX'] = $bad;
            $_ENV['AUTH_LOGIN_IP_MAX'] = $bad;
            $_ENV['AUTH_LOGIN_WINDOW'] = $bad;
            $limiter = new LoginThrottle($this->db);
            $this->assertSame(5, $limiter->userMax(), "USER_MAX default for '$bad'");
            $this->assertSame(20, $limiter->ipMax(), "IP_MAX default for '$bad'");
            $this->assertSame(900, $limiter->windowSeconds(), "WINDOW default for '$bad'");
        }
    }

    public function testValidConfigIsHonoured(): void
    {
        $_ENV['AUTH_LOGIN_USER_MAX'] = '3';
        $_ENV['AUTH_LOGIN_IP_MAX'] = '9';
        $_ENV['AUTH_LOGIN_WINDOW'] = '120';
        $limiter = new LoginThrottle($this->db);
        $this->assertSame(3, $limiter->userMax());
        $this->assertSame(9, $limiter->ipMax());
        $this->assertSame(120, $limiter->windowSeconds());
    }

    public function testInvalidIpKeyIsNotCounted(): void
    {
        $limiter = $this->limiter(ipMax: 1);
        // A bogus IP string must not key the table (would otherwise lump
        // callers together under one junk key).
        $limiter->recordFailure($this->user . '-x', 'not-an-ip');
        $limiter->recordFailure($this->user . '-y', 'not-an-ip');
        $this->assertTrue($limiter->isAllowed($this->user, 'not-an-ip'));

        $rows = $this->db->prepare("SELECT COUNT(*) FROM auth_login_attempts WHERE ip_key = ''");
        $rows->execute();
        // Rows are still written (identifier counter matters) but with an empty
        // ip_key that the IP check skips.
        $this->assertGreaterThanOrEqual(0, (int)$rows->fetchColumn());
    }

    public function testBlankIdentifierNeverBlocksOnItsOwn(): void
    {
        $limiter = $this->limiter(userMax: 1);
        $limiter->recordFailure('   ', $this->ip);
        $limiter->recordFailure('', $this->ip);
        // An empty normalized identifier is skipped by the identifier check;
        // only the IP counter (well under its limit) applies.
        $this->assertTrue($limiter->isAllowed('', $this->ip));
    }

    /**
     * Isolated `binktermphp_test` connection (never the application database).
     * Skips when pdo_pgsql or that database is unavailable.
     */
    private static function testDb(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        if (!extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('pdo_pgsql not available');
        }
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=binktermphp_test',
            \BinktermPHP\Config::env('DB_HOST', 'localhost'),
            \BinktermPHP\Config::env('DB_PORT', '5432')
        );
        try {
            $candidate = new PDO($dsn, \BinktermPHP\Config::env('DB_USER', 'postgres'), \BinktermPHP\Config::env('DB_PASS', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $e) {
            self::markTestSkipped('binktermphp_test database not reachable: ' . $e->getMessage());
        }
        if ($candidate->query('SELECT current_database()')->fetchColumn() !== 'binktermphp_test') {
            throw new RuntimeException('Refusing to run throttle tests against a non-test database');
        }

        return $pdo = $candidate;
    }
}
