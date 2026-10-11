<?php

use BinktermPHP\BinkdProcessor;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Binkp\Logger;
use PHPUnit\Framework\TestCase;

final class BinkdProcessorHandleLifecycleTest extends TestCase
{
    private ReflectionProperty $configInstance;
    private mixed $originalConfigInstance;
    private ?string $packetPath = null;

    protected function setUp(): void
    {
        $this->configInstance = new ReflectionProperty(BinkpConfig::class, 'instance');
        $this->configInstance->setAccessible(true);
        $this->originalConfigInstance = $this->configInstance->getValue();
    }

    protected function tearDown(): void
    {
        $this->configInstance->setValue(null, $this->originalConfigInstance);

        if ($this->packetPath !== null && file_exists($this->packetPath)) {
            unlink($this->packetPath);
        }
    }

    public function testPacketPasswordMismatchIsRejectedWithoutDoubleClosingHandle(): void
    {
        $config = (new ReflectionClass(BinkpConfig::class))->newInstanceWithoutConstructor();
        $this->setPrivateProperty($config, 'config', [
            'uplinks' => [[
                'address' => '999:2/3',
                'pkt_password' => 'EXPECTED',
            ]],
        ]);
        $this->configInstance->setValue(null, $config);

        $packetLog = new BinkdProcessorPacketLogStub();
        $processor = (new ReflectionClass(BinkdProcessor::class))->newInstanceWithoutConstructor();
        $this->setPrivateProperty($processor, 'db', $packetLog);
        $this->setPrivateProperty($processor, 'config', $config);
        $this->setPrivateProperty($processor, 'logger', new Logger('/dev/null', 'INFO', false));

        $this->packetPath = tempnam(sys_get_temp_dir(), 'binkd-password-mismatch-');
        self::assertNotFalse($this->packetPath);
        file_put_contents($this->packetPath, $this->packetHeader('WRONG'));

        try {
            $processor->processPacket($this->packetPath);
            self::fail('Expected the packet password mismatch to reject the packet');
        } catch (Throwable $error) {
            self::assertInstanceOf(Exception::class, $error, 'Mismatch must not escape as an fclose TypeError');
            self::assertStringContainsString('Packet password mismatch', $error->getMessage());
        }

        self::assertSame(['pending', 'error'], $packetLog->statuses);
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }

    private function packetHeader(string $password): string
    {
        $header = pack(
            'vvvvvvvvvvvv',
            3,
            4,
            2026,
            7,
            30,
            12,
            0,
            0,
            0,
            2,
            2,
            5
        );
        $header .= pack('CC', 0xFE, 0);
        $header .= str_pad(substr($password, 0, 8), 8, "\0");
        $header .= pack('vv', 999, 999);
        $header .= pack('vv', 0, 0x0100);
        $header .= pack('CCv', 0xFE, 0, 0x0001);
        $header .= pack('vv', 999, 999);
        $header .= pack('vv', 0, 0);
        $header .= pack('V', 0);

        self::assertSame(58, strlen($header));
        return $header;
    }
}

final class BinkdProcessorPacketLogStub
{
    /** @var list<string> */
    public array $statuses = [];

    public function prepare(string $sql): BinkdProcessorPacketLogStatementStub
    {
        return new BinkdProcessorPacketLogStatementStub($this);
    }
}

final class BinkdProcessorPacketLogStatementStub
{
    public function __construct(private BinkdProcessorPacketLogStub $packetLog)
    {
    }

    public function execute(array $parameters): bool
    {
        $this->packetLog->statuses[] = $parameters[2] ?? 'error';
        return true;
    }
}
