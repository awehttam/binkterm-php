<?php

declare(strict_types=1);

use BinktermPHP\Config;
use PHPUnit\Framework\TestCase;

/**
 * Operator CLI: scripts/user-manager.php create / delete.
 *
 * Drives the REAL script as a subprocess (it dispatches at require time, so
 * it cannot be loaded into the test process) against the dedicated,
 * disposable binktermphp_test database, selected through the DB_NAME
 * process environment the script's Config already honours.
 *
 * Regression: the SQLite -> PostgreSQL migration left an integer literal for
 * users.is_active (BOOLEAN) in the create INSERT, so every operator create
 * failed with SQLSTATE[42804] "column is_active is of type boolean but
 * expression is of type integer" until 2026-09-18.
 */
final class UserManagerCreateTest extends TestCase
{
    private const TEST_DATABASE_NAME = 'binktermphp_test';

    private static ?\PDO $testPdo = null;

    /** @var list<string> usernames this test created (deleted in tearDown) */
    private array $usernames = [];

    protected function tearDown(): void
    {
        foreach ($this->usernames as $username) {
            $this->purge($username);
        }
        $this->usernames = [];
    }

    public function testCreateInsertsAnActiveNonAdminUserWithSettingsAndRole(): void
    {
        $username = $this->uniqueUsername();
        $password = 'Proof-Pass-' . bin2hex(random_bytes(4));

        [$exit, $out] = $this->runCli(['create', $username, '--real-name=UM Test', '--email=' . $username . '@example.invalid', '--password=' . $password, '--non-interactive']);

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('User created successfully', $out);

        $user = $this->fetchUser($username);
        self::assertNotNull($user, 'users row must exist');
        self::assertTrue($user['is_active'], 'is_active must be TRUE (the boolean the integer literal broke)');
        self::assertFalse($user['is_admin']);
        self::assertSame('UM Test', $user['real_name']);
        self::assertSame($username . '@example.invalid', $user['email']);
        self::assertTrue(password_verify($password, $user['password_hash']), 'stored hash must verify the supplied password');

        $settings = self::db()->prepare('SELECT timezone, messages_per_page, theme FROM user_settings WHERE user_id = ?');
        $settings->execute([$user['id']]);
        self::assertSame(
            ['timezone' => 'America/Los_Angeles', 'messages_per_page' => 25, 'theme' => 'light'],
            $settings->fetch(\PDO::FETCH_ASSOC),
            'default user_settings row must be created'
        );

        $role = self::db()->query("SELECT id FROM user_roles WHERE name = 'user'")->fetchColumn();
        if ($role !== false) {
            self::assertSame((int)$role, (int)$user['role_id'], 'non-admin create assigns the user role');
        }
    }

    public function testCreateWithAdminFlagSetsIsAdmin(): void
    {
        $username = $this->uniqueUsername();

        [$exit, $out] = $this->runCli(['create', $username, '--admin', '--password=Proof-Pass-admin', '--non-interactive']);

        self::assertSame(0, $exit, $out);
        $user = $this->fetchUser($username);
        self::assertNotNull($user);
        self::assertTrue($user['is_admin']);
        self::assertTrue($user['is_active']);
    }

    public function testDuplicateUsernameIsRejectedWithoutTouchingTheExistingRow(): void
    {
        $username = $this->uniqueUsername();
        [$exit] = $this->runCli(['create', $username, '--password=Proof-Pass-first', '--non-interactive']);
        self::assertSame(0, $exit);
        $before = $this->fetchUser($username);

        [$exit, $out] = $this->runCli(['create', $username, '--password=Proof-Pass-second', '--non-interactive']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('already exists', $out);
        self::assertSame(1, $this->countUsers($username));
        self::assertSame($before['password_hash'], $this->fetchUser($username)['password_hash'], 'existing row must be untouched');
    }

    public function testFailedInsertLeavesNoPartialUserOrSettings(): void
    {
        // users.username is VARCHAR(50): an over-long name fails the first
        // INSERT inside the script's transaction. Nothing may be left behind.
        $username = $this->uniqueUsername() . str_repeat('x', 60);
        $this->usernames[] = $username;

        [$exit, $out] = $this->runCli(['create', $username, '--password=Proof-Pass-long', '--non-interactive']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Failed to create user', $out);
        self::assertSame(0, $this->countUsers($username));
        self::assertSame(0, (int)self::db()->query(
            'SELECT COUNT(*) FROM user_settings WHERE user_id NOT IN (SELECT id FROM users)'
        )->fetchColumn(), 'no orphaned user_settings rows');
    }

    public function testDeleteWithConfirmRemovesUserAndSettings(): void
    {
        $username = $this->uniqueUsername();
        [$exit] = $this->runCli(['create', $username, '--password=Proof-Pass-del', '--non-interactive']);
        self::assertSame(0, $exit);
        $id = (int)$this->fetchUser($username)['id'];

        [$exit, $out] = $this->runCli(['delete', $username, '--confirm', '--non-interactive']);

        self::assertSame(0, $exit, $out);
        self::assertSame(0, $this->countUsers($username));
        $settings = self::db()->prepare('SELECT COUNT(*) FROM user_settings WHERE user_id = ?');
        $settings->execute([$id]);
        self::assertSame(0, (int)$settings->fetchColumn());
    }

    /**
     * @param list<string> $args
     * @return array{0:int,1:string} exit code, combined stdout+stderr
     */
    private function runCli(array $args): array
    {
        $cmd = 'DB_NAME=' . escapeshellarg(self::TEST_DATABASE_NAME) . ' ' . escapeshellcmd(PHP_BINARY)
            . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/user-manager.php')
            . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        $out = (string)shell_exec($cmd . '; echo "__EXIT:$?"');
        if (!preg_match('/__EXIT:(\d+)\s*$/', $out, $m)) {
            self::fail("could not read exit status from: {$out}");
        }
        return [(int)$m[1], trim(substr($out, 0, (int)strrpos($out, '__EXIT:')))];
    }

    private function uniqueUsername(): string
    {
        $username = 'zzumtest' . bin2hex(random_bytes(4));
        $this->usernames[] = $username;
        return $username;
    }

    /** @return array<string,mixed>|null */
    private function fetchUser(string $username): ?array
    {
        $stmt = self::db()->prepare('SELECT id, username, real_name, email, password_hash, is_active, is_admin, role_id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function countUsers(string $username): int
    {
        $stmt = self::db()->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $stmt->execute([$username]);
        return (int)$stmt->fetchColumn();
    }

    private function purge(string $username): void
    {
        $stmt = self::db()->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return;
        }
        foreach (['user_settings', 'user_sessions', 'sessions'] as $table) {
            self::db()->prepare("DELETE FROM {$table} WHERE user_id = ?")->execute([$id]);
        }
        self::db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }

    private static function db(): \PDO
    {
        if (self::$testPdo instanceof \PDO) {
            return self::$testPdo;
        }
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            Config::env('DB_HOST', 'localhost'),
            Config::env('DB_PORT', '5432'),
            self::TEST_DATABASE_NAME
        );
        $pdo = new \PDO($dsn, Config::env('DB_USER', 'postgres'), Config::env('DB_PASS', ''), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $actual = (string)$pdo->query('SELECT current_database()')->fetchColumn();
        if ($actual !== self::TEST_DATABASE_NAME) {
            throw new \RuntimeException("Refusing to run mutating user tests against non-test database: {$actual}");
        }
        self::$testPdo = $pdo;
        return $pdo;
    }
}
