<?php

declare(strict_types=1);

use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Binkp\Logger;
use BinktermPHP\MessageHandler;
use PHPUnit\Framework\TestCase;

/**
 * MessageHandler::getEchoareaUplink() must never route an echoarea to an
 * uplink of a different network. When no uplink is configured for the
 * area's domain it returns false (the spool and relay paths already treat
 * that as "do not export"), instead of falling back to the global default
 * uplink and leaking the message into another network's packet.
 */
final class EchomailUplinkFailClosedTest extends TestCase
{
    private ReflectionProperty $configInstance;
    private mixed $originalConfig;

    protected function setUp(): void
    {
        $this->configInstance = new ReflectionProperty(BinkpConfig::class, 'instance');
        $this->configInstance->setAccessible(true);
        $this->originalConfig = $this->configInstance->getValue();

        $config = (new ReflectionClass(BinkpConfig::class))->newInstanceWithoutConstructor();
        $property = new ReflectionProperty(BinkpConfig::class, 'config');
        $property->setAccessible(true);
        $property->setValue($config, ['uplinks' => [
            ['address' => '999:1/1', 'domain' => 'alphanet', 'default' => true, 'enabled' => true],
            ['address' => '998:2/2', 'domain' => 'betanet', 'enabled' => true],
        ]]);
        $this->configInstance->setValue(null, $config);
    }

    protected function tearDown(): void
    {
        $this->configInstance->setValue(null, $this->originalConfig);
    }

    public function testAreaOnItsOwnNetworkUsesThatNetworksUplink(): void
    {
        self::assertSame('998:2/2', $this->handler(null)->getEchoareaUplink('BETA.CHAT', 'betanet'));
    }

    public function testStoredUplinkAddressIsStillHonoured(): void
    {
        self::assertSame('998:2/2', $this->handler('998:2/2')->getEchoareaUplink('BETA.CHAT', 'betanet'));
    }

    public function testNetworkWithoutUplinkDoesNotFallBackToTheDefaultUplink(): void
    {
        $logger = new EchomailUplinkCapturingLogger();
        $uplink = $this->handler(null, $logger)->getEchoareaUplink('GAMMA.CHAT', 'gammanet');

        self::assertFalse($uplink, 'must not route gammanet mail through the alphanet default uplink');
        self::assertNotEmpty($logger->warnings, 'the missing route must be logged');
        self::assertStringContainsString('gammanet', $logger->warnings[0]);
    }

    public function testAreaWithoutDomainHasNoUplink(): void
    {
        self::assertFalse($this->handler(null)->getEchoareaUplink('LOCAL.CHAT', ''));
    }

    private function handler(?string $storedUplink, ?Logger $logger = null): MessageHandler
    {
        $handler = (new ReflectionClass(MessageHandler::class))->newInstanceWithoutConstructor();
        $db = new ReflectionProperty(MessageHandler::class, 'db');
        $db->setAccessible(true);
        $db->setValue($handler, new EchomailUplinkAreaPdo($storedUplink));
        $log = new ReflectionProperty(MessageHandler::class, 'logger');
        $log->setAccessible(true);
        $log->setValue($handler, $logger ?? new EchomailUplinkCapturingLogger());

        return $handler;
    }
}

final class EchomailUplinkCapturingLogger extends Logger
{
    /** @var list<string> */
    public array $warnings = [];

    public function __construct()
    {
    }

    public function warning($message, $context = [])
    {
        $this->warnings[] = (string)$message;
    }

    public function error($message, $context = [])
    {
    }
}

final class EchomailUplinkAreaPdo extends PDO
{
    public function __construct(private ?string $storedUplink)
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new EchomailUplinkAreaStatement($this->storedUplink);
    }
}

final class EchomailUplinkAreaStatement extends PDOStatement
{
    public function __construct(private ?string $storedUplink)
    {
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return ['uplink_address' => $this->storedUplink];
    }
}
