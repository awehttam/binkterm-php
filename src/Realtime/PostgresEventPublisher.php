<?php

namespace BinktermPHP\Realtime;

use PDO;

/**
 * PostgreSQL LISTEN/NOTIFY publisher for BinkStream wake-up events.
 */
class PostgresEventPublisher implements EventPublisherInterface
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Publish a PostgreSQL NOTIFY payload to the requested channel.
     */
    public function publish(string $channel, string $payload): void
    {
        $stmt = $this->db->prepare('SELECT pg_notify(:channel, :payload)');
        $stmt->bindValue(':channel', $channel, PDO::PARAM_STR);
        $stmt->bindValue(':payload', $payload, PDO::PARAM_STR);
        $stmt->execute();
    }
}
