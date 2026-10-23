<?php

/**
 * scripts/binkp_poll.php --all polls every uplink through
 * BinkpClient::pollAllUplinks(). An uplink whose host is busy with another
 * BinkP session must be reported as deferred, not as a failed poll.
 */

use BinktermPHP\Binkp\Protocol\BinkpClient;
use BinktermPHP\Binkp\Protocol\HostLockBusyException;
use PHPUnit\Framework\TestCase;

final class BinkpBulkPollDeferralTest extends TestCase
{
    public function testBusyUplinkIsDeferredAndOtherFailuresStayFailures(): void
    {
        $config = new class {
            public function getEnabledUplinks()
            {
                return [['address' => '999:1/1'], ['address' => '999:1/2']];
            }
            public function getOutboundPath()
            {
                return sys_get_temp_dir();
            }
            public function getUplinkByAddress($address)
            {
                return ['address' => $address, 'hostname' => 'hub.invalid', 'port' => 24554];
            }
        };
        $lines = [];
        $logger = new class($lines) {
            private array $lines;
            public function __construct(array &$lines)
            {
                $this->lines = &$lines;
            }
            public function log($level, $message, $context = []): void
            {
                $this->lines[] = "{$level} {$message}";
            }
        };
        $client = new class($config, $logger) extends BinkpClient {
            public function connect($address, $hostname = null, $port = null, $password = null, bool $forceAnonymous = false)
            {
                if ($address === '999:1/1') {
                    throw new HostLockBusyException('Host hub.invalid:24554 is busy with another BinkP session; poll deferred');
                }
                throw new \Exception('Failed to connect to hub.invalid:24554: refused');
            }
        };

        $results = $client->pollAllUplinks();

        self::assertTrue($results['999:1/1']['deferred']);
        self::assertFalse($results['999:1/1']['success']);
        self::assertArrayNotHasKey('deferred', $results['999:1/2']);
        self::assertNotEmpty(preg_grep('/^WARNING .*Poll of 999:1\/1 deferred/', $lines));
        self::assertEmpty(preg_grep('/^ERROR .*999:1\/1/', $lines));
        self::assertNotEmpty(preg_grep('/^ERROR .*Failed to poll 999:1\/2/', $lines));
    }
}
