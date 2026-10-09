<?php

declare(strict_types=1);

use BinktermPHP\Database;
use BinktermPHP\TelnetServer\BbsSession;
use PHPUnit\Framework\TestCase;

// telnet/src/ classes are not Composer-autoloaded (see telnet/CLAUDE.md).
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalBoxRenderer.php';
require_once __DIR__ . '/../../telnet/src/BbsSession.php';

/**
 * A live Telnet/SSH session must end once its web auth session is revoked
 * (revoke session / revoke all / password reset / expiry): the idle-aware
 * input readers re-validate it at most every 30 seconds and disconnect when
 * Auth::validateSession() no longer accepts it. No real database: a stub PDO
 * answers validateSession()'s lookup.
 */
final class TerminalSessionRevocationTest extends TestCase
{
    private mixed $originalDatabase;
    private ?RevocationProbePdo $pdo = null;

    protected function setUp(): void
    {
        $instance = new ReflectionProperty(Database::class, 'instance');
        $instance->setAccessible(true);
        $this->originalDatabase = $instance->getValue();
    }

    protected function tearDown(): void
    {
        $instance = new ReflectionProperty(Database::class, 'instance');
        $instance->setAccessible(true);
        $instance->setValue(null, $this->originalDatabase);
    }

    public function testRevokedSessionDisconnectsAtTheNextInputWait(): void
    {
        [$session, $server, $client, $state] = $this->loggedIn(sessionValid: false, checkedSecondsAgo: 31);

        $result = $this->invoke($session, 'readTelnetLineWithTimeout', [$server, &$state]);

        self::assertSame([null, false, true], $result);
        self::assertStringContainsString('signed out elsewhere', $this->drain($client));
        self::assertTrue($session->authSessionRevoked(), 'revocation is sticky');
    }

    public function testValidSessionKeepsGoingAndIsRecheckedAtMostEvery30Seconds(): void
    {
        [$session, $server, $client, $state] = $this->loggedIn(sessionValid: true, checkedSecondsAgo: 31);

        $first = $session->readKeyWithTimeout($server, $state, 10);
        self::assertFalse($first[2], 'valid session must not disconnect');
        self::assertSame(1, $this->pdo->lookups);

        $session->readKeyWithTimeout($server, $state, 10);
        $this->invoke($session, 'readTelnetKeyWithTimeout', [$server, &$state]);
        self::assertSame(1, $this->pdo->lookups, 'no re-validation within 30 seconds');
    }

    public function testNoLookupBeforeLogin(): void
    {
        [$session, $server, $client, $state] = $this->wired();
        $this->installDatabase(false);

        self::assertFalse($session->authSessionRevoked());
        self::assertSame(0, $this->pdo->lookups);
    }

    public function testDatabaseErrorIsNotTreatedAsRevocation(): void
    {
        [$session] = $this->loggedIn(sessionValid: true, checkedSecondsAgo: 31);
        $this->pdo->fail = true;

        self::assertFalse($session->authSessionRevoked());
    }

    public function testDoorRelayLoopLeavesTheDoorWhenRevoked(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../telnet/src/DoorHandler.php');
        $loop = substr($source, (int)strpos($source, 'private function relayLoop('));

        self::assertStringContainsString('$this->server->authSessionRevoked()', substr($loop, 0, 1500));
    }

    private function loggedIn(bool $sessionValid, int $checkedSecondsAgo): array
    {
        [$session, $server, $client, $state] = $this->wired();
        $this->installDatabase($sessionValid);
        foreach (['authSessionId' => 'sess-1', 'authSessionCheckedAt' => time() - $checkedSecondsAgo] as $name => $value) {
            $property = new ReflectionProperty(BbsSession::class, $name);
            $property->setAccessible(true);
            $property->setValue($session, $value);
        }

        return [$session, $server, $client, $state];
    }

    private function wired(): array
    {
        [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($server, false);
        stream_set_blocking($client, false);
        $state = [
            'input_echo' => true, 'cols' => 80, 'rows' => 24, 'locale' => 'en', 'pushback' => '',
            'last_activity' => time(), 'idle_warned' => false,
            'idle_warning_timeout' => 300, 'idle_disconnect_timeout' => 420,
        ];

        return [new BbsSession($server, 'http://127.0.0.1', false, false, false, false), $server, $client, $state];
    }

    private function installDatabase(bool $sessionValid): void
    {
        $this->pdo = new RevocationProbePdo($sessionValid);
        $database = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        $pdo = new ReflectionProperty(Database::class, 'pdo');
        $pdo->setAccessible(true);
        $pdo->setValue($database, $this->pdo);
        $instance = new ReflectionProperty(Database::class, 'instance');
        $instance->setAccessible(true);
        $instance->setValue(null, $database);
    }

    private function invoke(BbsSession $session, string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod($session, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($session, $args);
    }

    private function drain($client): string
    {
        $out = '';
        while (($chunk = @fread($client, 8192)) !== false && $chunk !== '') {
            $out .= $chunk;
        }

        return $out;
    }
}

final class RevocationProbePdo extends PDO
{
    public int $lookups = 0;
    public bool $fail = false;

    public function __construct(private bool $sessionValid)
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->fail) {
            throw new RuntimeException('database unavailable');
        }
        if (str_contains($query, 'FROM user_sessions s')) {
            $this->lookups++;
            return new RevocationProbeStatement($this->sessionValid ? ['user_id' => 1, 'username' => 'alice'] : false);
        }

        return new RevocationProbeStatement(false);
    }
}

final class RevocationProbeStatement extends PDOStatement
{
    public function __construct(private array|false $row)
    {
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->row;
    }
}
