<?php

namespace BinktermPHP\Realtime;

/**
 * Publishes lightweight wake-up notifications for realtime consumers.
 */
interface EventPublisherInterface
{
    /**
     * Publish a transport-level notification.
     */
    public function publish(string $channel, string $payload): void;
}
