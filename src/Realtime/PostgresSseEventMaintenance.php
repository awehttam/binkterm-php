<?php

namespace BinktermPHP\Realtime;

use PDO;

/**
 * PostgreSQL-backed housekeeping for the sse_events table.
 */
class PostgresSseEventMaintenance implements SseEventMaintenanceInterface
{
    private PDO $db;
    private int $retentionSeconds;

    public function __construct(PDO $db, int $retentionSeconds = 3600)
    {
        $this->db = $db;
        $this->retentionSeconds = $retentionSeconds;
    }

    /**
     * Delete stale rows older than the configured retention window.
     */
    public function pruneOldEvents(): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $this->retentionSeconds);
        $stmt = $this->db->prepare('DELETE FROM sse_events WHERE created_at < ?');
        $stmt->execute([$cutoff]);
    }
}
