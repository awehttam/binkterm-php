<?php

use BinktermPHP\Binkp\Logger;
use BinktermPHP\Database;
use BinktermPHP\PacketBbs\PacketBbsGateway;
use BinktermPHP\PacketBbs\PacketBbsTextRenderer;
use PHPUnit\Framework\TestCase;

class PacketBbsCredentialLoggingTest extends TestCase
{
    private array $messages = [];

    private function gateway(PDO $db): PacketBbsGateway
    {
        $logger = $this->getMockBuilder(Logger::class)->disableOriginalConstructor()
            ->onlyMethods(['info', 'warning'])->getMock();
        foreach (['info', 'warning'] as $level) {
            $logger->method($level)->willReturnCallback(function ($message): void {
                $this->messages[] = $message;
            });
        }
        $gateway = (new ReflectionClass(PacketBbsGateway::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($gateway, 'db'))->setValue($gateway, $db);
        (new ReflectionProperty($gateway, 'logger'))->setValue($gateway, $logger);
        return $gateway;
    }

    public function testInboundLoggingNeverIncludesCredentialInput(): void
    {
        $db = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $db->method('prepare')->willReturn($stmt);
        $stmt->method('fetch')->willReturn(false);
        $gateway = $this->gateway($db);

        foreach ([
            'LOGIN username 123456', 'L username 123456',
            " \tlogin\tusername\t123456\n", 'L 123456',
            'LOGIN 123456 extra', 'LOGIN username 123456 extra',
            'LOGINusername123456', '123456', 'PASSWORD username 123456',
        ] as $command) {
            $this->messages = [];
            $gateway->handleCommand('sender-test', 'meshcore', $command, 'bridge-test');
            $logs = implode("\n", $this->messages);
            $this->assertStringNotContainsString('123456', $logs);
            $this->assertStringNotContainsString('username', $logs);
            $this->assertStringContainsString('node=sender-test bridge=bridge-test if=meshcore', $logs);
        }
    }

    public function testOrdinaryCommandsRemainIdentifiableWithoutArguments(): void
    {
        $db = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $db->method('prepare')->willReturn($stmt);
        $stmt->method('fetch')->willReturn(false);
        $gateway = $this->gateway($db);

        foreach (['HELP', 'BU', 'WHO', 'LOGIN', 'L'] as $verb) {
            $this->messages = [];
            $gateway->handleCommand('sender-test', 'meshcore', strtolower($verb) . ' private-argument', 'bridge-test');
            $this->assertStringContainsString('cmd=' . $verb, $this->messages[0]);
            $this->assertStringNotContainsString('private-argument', $this->messages[0]);
        }
    }

    public function testLoginFailureLogsDoNotEchoMisplacedCredentialAsUsername(): void
    {
        $instance = new ReflectionProperty(Database::class, 'instance');
        $previous = $instance->getValue();
        try {
            foreach ([0, 5] as $failures) {
                $db = $this->createMock(PDO::class);
                $stmt = $this->createMock(PDOStatement::class);
                $db->method('prepare')->willReturn($stmt);
                $stmt->method('fetchColumn')->willReturn($failures);
                $stmt->method('fetch')->willReturn(false);
                $database = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
                (new ReflectionProperty(Database::class, 'pdo'))->setValue($database, $db);
                $instance->setValue(null, $database);
                $gateway = $this->gateway($db);
                $this->messages = [];
                (new ReflectionMethod($gateway, 'handleLogin'))->invoke(
                    $gateway, [], 'sender-test', '123456 extra', 'meshcore', new PacketBbsTextRenderer('meshcore')
                );
                $this->assertNotEmpty($this->messages);
                $this->assertStringNotContainsString('123456', implode("\n", $this->messages));
            }
        } finally {
            $instance->setValue(null, $previous);
        }
    }
}
