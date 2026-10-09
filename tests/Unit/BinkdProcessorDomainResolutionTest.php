<?php

use BinktermPHP\BinkdProcessor;
use BinktermPHP\Binkp\Config\BinkpConfig;
use PHPUnit\Framework\TestCase;

final class BinkdProcessorDomainResolutionTest extends TestCase
{
    private ReflectionProperty $configInstance;
    private mixed $originalConfigInstance;

    protected function setUp(): void
    {
        $this->configInstance = new ReflectionProperty(BinkpConfig::class, 'instance');
        $this->configInstance->setAccessible(true);
        $this->originalConfigInstance = $this->configInstance->getValue();
    }

    protected function tearDown(): void
    {
        $this->configInstance->setValue(null, $this->originalConfigInstance);
    }

    public function testMessageOutsideRoutingPatternInheritsConfiguredPacketUplinkDomain(): void
    {
        $config = (new ReflectionClass(BinkpConfig::class))->newInstanceWithoutConstructor();

        $this->setPrivateProperty($config, 'config', [
            'uplinks' => [[
                'address' => '999:154/10',
                'domain' => 'examplenet',
                'networks' => ['999:104/*'],
                'enabled' => true,
            ]],
        ]);

        $this->configInstance->setValue(null, $config);

        // Sanity-check the condition that caused the production failure:
        // the message author's address is not covered by this uplink's
        // outbound routing pattern.
        self::assertFalse($config->getDomainByAddress('999:229/426'));

        $processor = (new ReflectionClass(BinkdProcessor::class))
            ->newInstanceWithoutConstructor();

        $processor->useFixedWidthParser = false;
        $processor->useGapDetectParser = false;
        $processor->shadowGapDetectParser = false;

        $message = $this->messageRecord(
            origNet: 229,
            origNode: 426,
            destNet: 154,
            destNode: 10,
            body: "AREA:TEST\r\x01CHRS: CP437 2\rHello from elsewhere in FidoNet\r"
        );

        $handle = fopen('php://memory', 'r+b');
        self::assertIsResource($handle);

        fwrite($handle, $message);
        rewind($handle);

        try {
            $method = new ReflectionMethod(BinkdProcessor::class, 'readMessage');
            $method->setAccessible(true);

            $result = $method->invoke(
                $processor,
                $handle,
                [
                    'origZone' => 999,
                    'origNet' => 154,
                    'origNode' => 10,
                    'destZone' => 999,
                    'destNet' => 154,
                    'destNode' => 10,
                    'packet_name' => 'domain-regression.pkt',
                ]
            );
        } finally {
            fclose($handle);
        }

        self::assertIsArray($result);
        self::assertSame('999:229/426', $result['origAddr']);
        self::assertSame('examplenet', $result['domain']);
    }

    private function messageRecord(
        int $origNet,
        int $origNode,
        int $destNet,
        int $destNode,
        string $body
    ): string {
        $record = pack('v', 2);

        $record .= pack(
            'vvvvvv',
            $origNode,
            $destNode,
            $origNet,
            $destNet,
            0,
            0
        );

        foreach ([
            '30 Aug 26  12:00:00',
            'All',
            'Remote User',
            'Domain regression',
        ] as $field) {
            $record .= $field . "\0";
        }

        return $record . $body . "\0";
    }

    private function setPrivateProperty(
        object $object,
        string $property,
        mixed $value
    ): void {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }
}
