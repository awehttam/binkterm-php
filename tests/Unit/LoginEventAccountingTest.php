<?php

declare(strict_types=1);

use BinktermPHP\ActivityTracker;
use BinktermPHP\Database;
use PHPUnit\Framework\TestCase;

/**
 * Each successful interactive login records exactly one TYPE_LOGIN event,
 * tagged with the service it arrived on.
 *
 * POST /api/auth/login is the login boundary for Web, Telnet and SSH, so it
 * records the event (with $service and the caller IP); the terminal session
 * must not record a second one. PacketBBS logs in through TOTP rather than
 * that route, so it records its own single event and stamps last_login.
 */
final class LoginEventAccountingTest extends TestCase
{
    private mixed $originalDatabase = null;
    private ?LoginEventCapturingPdo $pdo = null;

    protected function setUp(): void
    {
        $instance = new ReflectionProperty(Database::class, 'instance');
        $instance->setAccessible(true);
        $this->originalDatabase = $instance->getValue();

        $database = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        $pdo = new ReflectionProperty(Database::class, 'pdo');
        $pdo->setAccessible(true);
        $this->pdo = new LoginEventCapturingPdo();
        $pdo->setValue($database, $this->pdo);
        $instance->setValue(null, $database);
    }

    protected function tearDown(): void
    {
        $instance = new ReflectionProperty(Database::class, 'instance');
        $instance->setAccessible(true);
        $instance->setValue(null, $this->originalDatabase);
    }

    public function testTrackLoginRecordsOneTaggedLoginRow(): void
    {
        ActivityTracker::trackLogin(42, 'ssh', '192.0.2.10');

        self::assertCount(1, $this->pdo->inserts);
        [$userId, $type, $objectId, $objectName, $meta] = $this->pdo->inserts[0];
        self::assertSame(42, $userId);
        self::assertSame(ActivityTracker::TYPE_LOGIN, $type);
        self::assertNull($objectId);
        self::assertSame('ssh', $objectName);
        self::assertSame(['ip' => '192.0.2.10'], json_decode((string)$meta, true));
    }

    public function testTrackLoginWithoutIpStoresNoMeta(): void
    {
        ActivityTracker::trackLogin(42, 'packetbbs');

        self::assertCount(1, $this->pdo->inserts);
        self::assertSame('packetbbs', $this->pdo->inserts[0][3]);
        self::assertNull($this->pdo->inserts[0][4]);
    }

    public function testLoginRouteRecordsTheEventWithServiceAndClientIp(): void
    {
        $route = $this->between(
            file_get_contents(__DIR__ . '/../../routes/api-routes.php'),
            "SimpleRouter::post('/auth/login'",
            "SimpleRouter::post('/auth/logout'"
        );

        self::assertStringContainsString('ActivityTracker::trackLogin($userId, $service, Auth::resolveClientIp())', $route);
        self::assertStringNotContainsString('ActivityTracker::TYPE_LOGIN', $route);
    }

    public function testTerminalSessionDoesNotRecordASecondLoginEvent(): void
    {
        $source = file_get_contents(__DIR__ . '/../../telnet/src/BbsSession.php');

        self::assertStringNotContainsString('ActivityTracker::TYPE_LOGIN', $source);
        self::assertStringNotContainsString('trackLogin(', $source);
    }

    public function testPacketBbsLoginStampsLastLoginAndRecordsOneEvent(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/PacketBbs/PacketBbsGateway.php');

        self::assertSame(1, substr_count($source, "ActivityTracker::trackLogin((int)\$user['id'], 'packetbbs')"));
        self::assertStringContainsString("updateLastLogin((int)\$user['id'])", $source);
        self::assertTrue((new ReflectionMethod(\BinktermPHP\Auth::class, 'updateLastLogin'))->isPublic());
    }

    private function between(string $haystack, string $start, string $end): string
    {
        $from = strpos($haystack, $start);
        self::assertNotFalse($from, "marker not found: {$start}");
        $to = strpos($haystack, $end, $from);
        self::assertNotFalse($to, "marker not found: {$end}");

        return substr($haystack, $from, $to - $from);
    }
}

final class LoginEventCapturingPdo extends PDO
{
    /** @var list<array> */
    public array $inserts = [];

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new LoginEventCapturingStatement($this);
    }
}

final class LoginEventCapturingStatement extends PDOStatement
{
    public function __construct(private LoginEventCapturingPdo $pdo)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->pdo->inserts[] = $params ?? [];

        return true;
    }
}
