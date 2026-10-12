<?php

/**
 * A scheduled or outbound poll that finds the remote host busy with another
 * BinkP session exits with HostLockBusyException::EXIT_CODE. The scheduler
 * must report it as deferred (logged as a warning, not an error), and must
 * not process packets for a poll that never dialed.
 *
 * The Scheduler is built without its constructor (which opens the database)
 * and given a stub config and a stub admin daemon client.
 */

use BinktermPHP\Binkp\Connection\Scheduler;
use BinktermPHP\Binkp\Protocol\HostLockBusyException;
use PHPUnit\Framework\TestCase;

final class SchedulerHostBusyDeferralTest extends TestCase
{
    private function makeScheduler(int $pollExitCode, ?array &$logLines, ?object &$client): Scheduler
    {
        $client = new class($pollExitCode) {
            public array $calls = [];
            public function __construct(private int $exitCode)
            {
            }
            public function binkPollSync(string $address): array
            {
                $this->calls[] = "binkPollSync {$address}";
                return ['exit_code' => $this->exitCode, 'stdout' => '', 'stderr' => ''];
            }
            public function processPackets(): array
            {
                $this->calls[] = 'processPackets';
                return ['exit_code' => 0];
            }
        };
        $config = new class {
            public function getEnabledUplinks()
            {
                return [['address' => '999:1/1', 'poll_schedule' => '* * * * *']];
            }
        };
        $logLines = [];
        $logger = new class($logLines) {
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

        $scheduler = (new \ReflectionClass(Scheduler::class))->newInstanceWithoutConstructor();
        foreach ([
            'config' => $config,
            'logger' => $logger,
            'client' => $client,
            'lastPollTimes' => [],
            'lastOutboundPollTimes' => [],
            'iterationPolledAddresses' => [],
        ] as $name => $value) {
            $property = new \ReflectionProperty(Scheduler::class, $name);
            $property->setAccessible(true);
            $property->setValue($scheduler, $value);
        }
        return $scheduler;
    }

    public function testBusyHostIsReportedAsDeferredNotFailed(): void
    {
        $scheduler = $this->makeScheduler(HostLockBusyException::EXIT_CODE, $log, $client);

        $results = $scheduler->processScheduledPolls();

        self::assertFalse($results['999:1/1']['success']);
        self::assertTrue($results['999:1/1']['deferred']);
        self::assertSame(['binkPollSync 999:1/1'], $client->calls, 'no packet processing for a poll that never dialed');
        self::assertNotEmpty(preg_grep('/^WARNING .*Scheduled poll deferred for 999:1\/1/', $log));
        self::assertEmpty(preg_grep('/^ERROR/', $log));
    }

    public function testOrdinaryPollFailureIsNotReportedAsDeferred(): void
    {
        $scheduler = $this->makeScheduler(1, $log, $client);

        $results = $scheduler->processScheduledPolls();

        self::assertFalse($results['999:1/1']['success']);
        self::assertFalse($results['999:1/1']['deferred']);
        self::assertEmpty(preg_grep('/deferred/', $log));
    }
}
