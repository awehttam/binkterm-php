<?php

namespace BinktermPHP\Realtime;

use BinktermPHP\Binkp\Logger;

class MultiServiceDaemon
{
    /** @var array<int, LoopServiceInterface> */
    private array $services = [];
    private Logger $logger;
    private bool $running = false;

    /**
     * @param array<int, LoopServiceInterface> $services
     */
    public function __construct(array $services, Logger $logger)
    {
        $this->services = $services;
        $this->logger = $logger;
    }

    public function run(): void
    {
        foreach ($this->services as $service) {
            $service->start();
        }

        $this->running = true;

        try {
            while ($this->running) {
                $readMap = [];
                $writeMap = [];

                foreach ($this->services as $service) {
                    foreach ($service->getReadSockets() as $socket) {
                        $readMap[(int)$socket] = ['service' => $service, 'socket' => $socket];
                    }
                    foreach ($service->getWriteSockets() as $socket) {
                        $writeMap[(int)$socket] = ['service' => $service, 'socket' => $socket];
                    }
                }

                if ($readMap || $writeMap) {
                    $read = array_column($readMap, 'socket');
                    $write = array_column($writeMap, 'socket');
                    $except = null;
                    $changed = @stream_select($read, $write, $except, 0, 200000);
                    if ($changed === false) {
                        usleep(200000);
                    } else {
                        foreach ($read as $socket) {
                            $entry = $readMap[(int)$socket] ?? null;
                            if ($entry !== null) {
                                $entry['service']->handleReadableSocket($entry['socket']);
                            }
                        }

                        foreach ($write as $socket) {
                            $entry = $writeMap[(int)$socket] ?? null;
                            if ($entry !== null) {
                                $entry['service']->handleWritableSocket($entry['socket']);
                            }
                        }
                    }
                } else {
                    usleep(200000);
                }

                foreach ($this->services as $service) {
                    $service->tick();
                }
            }
        } finally {
            foreach ($this->services as $service) {
                try {
                    $service->stop();
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to stop realtime service cleanly', [
                        'service' => $service->getName(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }
}
