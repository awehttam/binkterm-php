<?php

namespace BinktermPHP\Realtime;

use BinktermPHP\Database;
use PDO;
use PDOException;

/**
 * PostgreSQL-backed housekeeping for the sse_events table.
 */
class PostgresSseEventMaintenance implements SseEventMaintenanceInterface
{
    /** @var array<string, mixed> */
    private array $databaseConfig;
    private int $retentionSeconds;
    private bool $useUtcTimezone;
    private ?PDO $db = null;

    /**
     * @param array<string, mixed> $databaseConfig
     */
    public function __construct(array $databaseConfig, int $retentionSeconds = 3600, bool $useUtcTimezone = true)
    {
        $this->databaseConfig = $databaseConfig;
        $this->retentionSeconds = $retentionSeconds;
        $this->useUtcTimezone = $useUtcTimezone;
    }

    /**
     * Delete stale rows older than the configured retention window.
     */
    public function pruneOldEvents(): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $this->retentionSeconds);
        $this->executePrune($cutoff);
    }

    private function executePrune(string $cutoff): void
    {
        try {
            $stmt = $this->getConnection()->prepare('DELETE FROM sse_events WHERE created_at < ?');
            $stmt->execute([$cutoff]);
        } catch (PDOException $e) {
            $this->db = null;

            $stmt = $this->getConnection()->prepare('DELETE FROM sse_events WHERE created_at < ?');
            $stmt->execute([$cutoff]);
        }
    }

    private function getConnection(): PDO
    {
        if ($this->db instanceof PDO) {
            return $this->db;
        }

        $platform = Database::getPlatform($this->databaseConfig);
        $pdo = new PDO(
            $platform->createDsn($this->databaseConfig),
            (string)$this->databaseConfig['username'],
            (string)$this->databaseConfig['password'],
            $this->databaseConfig['options'] ?? []
        );
        $platform->initializeSession($pdo, $this->useUtcTimezone);

        $this->db = $pdo;
        return $pdo;
    }
}
