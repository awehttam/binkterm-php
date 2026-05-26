<?php

namespace BinktermPHP\Realtime;

/**
 * Housekeeping operations for the sse_events table.
 */
interface SseEventMaintenanceInterface
{
    /**
     * Delete stale rows from the realtime event buffer.
     */
    public function pruneOldEvents(): void;
}
