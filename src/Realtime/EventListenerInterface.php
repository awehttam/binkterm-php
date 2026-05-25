<?php

namespace BinktermPHP\Realtime;

/**
 * Waits for wake-up notifications from the realtime transport.
 */
interface EventListenerInterface
{
    /**
     * Subscribe to a transport channel.
     */
    public function listen(string $channel): bool;

    /**
     * Wait for notifications and return their payloads.
     *
     * @return list<string>
     */
    public function wait(int $timeoutMs): array;

    /**
     * Whether the underlying transport connection is healthy.
     */
    public function isHealthy(): bool;

    /**
     * Reconnect the underlying transport and re-subscribe to the active channel.
     */
    public function reconnect(): bool;

    /**
     * Close the underlying transport connection.
     */
    public function close(): void;
}
