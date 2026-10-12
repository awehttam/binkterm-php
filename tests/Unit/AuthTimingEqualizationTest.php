<?php

declare(strict_types=1);

use BinktermPHP\Auth;
use PHPUnit\Framework\TestCase;

/**
 * Auth::authenticateCredentials() must not reveal whether a username exists
 * through response time: an unknown username has to pay the same password
 * verification cost as a wrong password for a real account.
 */
final class AuthTimingEqualizationTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';

    private string $storedHash;

    protected function setUp(): void
    {
        // Stored the way the application stores passwords.
        $this->storedHash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
    }

    public function testCorrectCredentialsStillAuthenticate(): void
    {
        $user = $this->authWithUser($this->userRow())->authenticateCredentials('alice', self::PASSWORD);

        self::assertIsArray($user);
        self::assertSame('alice', $user['username']);
    }

    public function testWrongPasswordAndUnknownUserBothReturnFalse(): void
    {
        self::assertFalse($this->authWithUser($this->userRow())->authenticateCredentials('alice', 'wrong'));
        self::assertFalse($this->authWithUser(false)->authenticateCredentials('nobody', 'wrong'));
    }

    public function testDummyHashMatchesRuntimeDefaultAlgorithmAndCost(): void
    {
        $dummy = Auth::dummyPasswordHash();
        $dummyInfo = password_get_info($dummy);
        $storedInfo = password_get_info($this->storedHash);

        self::assertSame($storedInfo['algo'], $dummyInfo['algo']);
        self::assertSame($storedInfo['options'], $dummyInfo['options']);
        self::assertFalse(password_needs_rehash($dummy, PASSWORD_DEFAULT));
        self::assertSame($dummy, Auth::dummyPasswordHash(), 'built once, reused');
    }

    public function testDummyHashMatchesNoPassword(): void
    {
        $dummy = Auth::dummyPasswordHash();

        foreach (['', 'password', self::PASSWORD, 'dummy-not-a-credential'] as $candidate) {
            self::assertFalse(password_verify($candidate, $dummy));
        }
    }

    public function testUnknownUsernameCostsAboutAsMuchAsWrongPassword(): void
    {
        $known = $this->authWithUser($this->userRow());
        $unknown = $this->authWithUser(false);

        // Warm up (first call builds the dummy hash).
        $unknown->authenticateCredentials('nobody', 'x');

        $wrongPassword = [];
        $unknownUser = [];
        for ($i = 0; $i < 5; $i++) {
            $wrongPassword[] = $this->elapsedMs(fn () => $known->authenticateCredentials('alice', 'nope-' . $i));
            $unknownUser[] = $this->elapsedMs(fn () => $unknown->authenticateCredentials('nobody', 'nope-' . $i));
        }

        $ratio = $this->median($unknownUser) / $this->median($wrongPassword);

        // Without the fix the unknown path is orders of magnitude faster
        // (no hash work at all); a cost mismatch would push the ratio to ~4x
        // or ~0.25x per bcrypt cost step of 2.
        self::assertGreaterThan(0.6, $ratio, sprintf('unknown/wrong ratio %.2f', $ratio));
        self::assertLessThan(1.6, $ratio, sprintf('unknown/wrong ratio %.2f', $ratio));
    }

    private function userRow(): array
    {
        return [
            'id' => 7,
            'username' => 'alice',
            'real_name' => 'Alice',
            'password_hash' => $this->storedHash,
        ];
    }

    private function authWithUser(array|false $row): Auth
    {
        $auth = (new ReflectionClass(Auth::class))->newInstanceWithoutConstructor();
        $db = new ReflectionProperty(Auth::class, 'db');
        $db->setAccessible(true);
        $db->setValue($auth, new AuthTimingPdoStub($row));

        return $auth;
    }

    private function elapsedMs(callable $fn): float
    {
        $start = hrtime(true);
        $fn();

        return (hrtime(true) - $start) / 1e6;
    }

    private function median(array $values): float
    {
        sort($values);
        $n = count($values);

        return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }
}

final class AuthTimingPdoStub
{
    public function __construct(private array|false $row)
    {
    }

    public function prepare(string $sql): AuthTimingStatementStub
    {
        return new AuthTimingStatementStub($this->row);
    }
}

final class AuthTimingStatementStub
{
    public function __construct(private array|false $row)
    {
    }

    public function execute(array $params = []): bool
    {
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT): array|false
    {
        return $this->row;
    }
}
