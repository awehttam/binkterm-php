<?php

namespace BinktermPHP;

/**
 * Tracks the last run of each registration-screening list-refresh job
 * (currently just the Tor exit list) so the binkp scheduler can gate them to a
 * fixed interval without an external cron entry.
 *
 * Backed by the screening_list_refresh table.
 *
 * @see docs/proposals/NewUserScreeningIPAddress.md
 */
class ScreeningListRefresh
{
    /** Minutes to wait before retrying a list that has never successfully populated. */
    private const EMPTY_RETRY_MINUTES = 15;

    /**
     * Whether the named list is due for a refresh.
     *
     * - Never attempted, or the cache is currently empty and the last attempt
     *   was more than EMPTY_RETRY_MINUTES ago (or there was no attempt): due.
     *   This makes a missing list download promptly on daemon start rather than
     *   waiting a full interval.
     * - Otherwise: due when the last *successful* refresh was more than
     *   $intervalHours ago.
     *
     * @param bool $cacheEmpty Pass true when the backing cache table has no rows,
     *                         so a stale "last run" timestamp cannot suppress the
     *                         download of a list that is actually missing.
     */
    public static function isDue(\PDO $db, string $listName, int $intervalHours, bool $cacheEmpty = false): bool
    {
        try {
            $stmt = $db->prepare("
                SELECT last_run_at, last_success_at
                FROM screening_list_refresh
                WHERE list_name = ?
            ");
            $stmt->execute([$listName]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            $neverSucceeded = !$row || $row['last_success_at'] === null;

            if ($cacheEmpty || $neverSucceeded) {
                if (!$row || $row['last_run_at'] === null) {
                    return true;
                }
                $check = $db->prepare("SELECT (?::timestamptz < NOW() - (? || ' minutes')::interval)");
                $check->execute([$row['last_run_at'], self::EMPTY_RETRY_MINUTES]);
                return (bool)$check->fetchColumn();
            }

            $check = $db->prepare("SELECT (?::timestamptz < NOW() - (? || ' hours')::interval)");
            $check->execute([$row['last_success_at'], max(1, $intervalHours)]);
            return (bool)$check->fetchColumn();
        } catch (\Throwable $e) {
            getServerLogger()->warning('ScreeningListRefresh::isDue failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function markStarted(\PDO $db, string $listName): void
    {
        try {
            $stmt = $db->prepare("
                INSERT INTO screening_list_refresh (list_name, last_run_at, last_status)
                VALUES (?, NOW(), 'running')
                ON CONFLICT (list_name) DO UPDATE
                SET last_run_at = NOW(), last_status = 'running', last_error = NULL
            ");
            $stmt->execute([$listName]);
        } catch (\Throwable $e) {
            getServerLogger()->warning('ScreeningListRefresh::markStarted failed: ' . $e->getMessage());
        }
    }

    public static function markFinished(
        \PDO $db,
        string $listName,
        string $status,
        ?string $error = null,
        ?int $entryCount = null
    ): void {
        try {
            $stmt = $db->prepare("
                UPDATE screening_list_refresh
                SET last_status = ?,
                    last_error = ?,
                    last_success_at = CASE WHEN ? = 'ok' THEN NOW() ELSE last_success_at END,
                    entry_count = COALESCE(?, entry_count)
                WHERE list_name = ?
            ");
            $stmt->execute([$status, $error, $status, $entryCount, $listName]);
        } catch (\Throwable $e) {
            getServerLogger()->warning('ScreeningListRefresh::markFinished failed: ' . $e->getMessage());
        }
    }
}
