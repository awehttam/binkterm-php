<?php

namespace BinktermPHP\Realtime;

interface LoopServiceInterface
{
    public function getName(): string;

    public function start(): void;

    /**
     * @return array<int, resource>
     */
    public function getReadSockets(): array;

    /**
     * @return array<int, resource>
     */
    public function getWriteSockets(): array;

    /**
     * @param resource $socket
     */
    public function handleReadableSocket($socket): void;

    /**
     * @param resource $socket
     */
    public function handleWritableSocket($socket): void;

    public function tick(): void;

    public function stop(): void;
}
