<?php

namespace BinktermPHP\Freq;

/**
 * Tracks outbound FREQ requests (files we have requested from remote nodes).
 *
 * When freq_getfile.php sends a .req file or M_GET request, a record is
 * persisted here so that a subsequent binkp session (inbound or outbound)
 * can route received files to the correct user's private area.
 */
class FreqRequestTracker
{
    public function __construct(private \PDO $db) {}

    /**
     * Record a new outbound FREQ request. Leaves attempts at its default (0)
     * and last_attempt_at unset — callers must follow up with
     * recordAttempt() immediately before actually connecting, so that
     * "attempts" always reflects real connection attempts, whether the row
     * was just created here or attached to later via --request-id.
     *
     * @param string   $nodeAddress    FTN address of the remote node
     * @param string[] $requestedFiles List of filenames / magic names requested
     * @param int      $userId         ID of the user who initiated the request
     * @param string   $mode           'req' (Bark .req file) or 'mget' (M_GET)
     * @return int New record ID
     */
    public function recordRequest(string $nodeAddress, array $requestedFiles, int $userId, string $mode = 'req'): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO freq_requests_outbound (node_address, requested_files, user_id, mode)
            VALUES (?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([$nodeAddress, json_encode($requestedFiles), $userId, $mode]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Record an additional attempt (initial send via --request-id=, or a
     * scheduled retry) against an existing request row, without inserting a
     * duplicate row.
     *
     * @param int $id Record ID
     */
    public function recordAttempt(int $id): void
    {
        $stmt = $this->db->prepare("
            UPDATE freq_requests_outbound
            SET last_attempt_at = NOW(), attempts = attempts + 1
            WHERE id = ?
        ");
        $stmt->execute([$id]);
    }

    /**
     * Find all pending requests for a given node address.
     *
     * Returns oldest first so that when two users requested the same filename
     * from the same node, the earlier request takes priority.
     *
     * @param  string  $nodeAddress FTN address of the remote node
     * @return array[] Rows from freq_requests_outbound (may be empty)
     */
    public function findPendingForNode(string $nodeAddress): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM freq_requests_outbound
            WHERE node_address = ? AND status = 'pending'
            ORDER BY created_at ASC
        ");
        $stmt->execute([$nodeAddress]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Find pending requests that are due for a retry: still under the
     * attempts cap and whose last attempt was more than $intervalSeconds ago
     * (or that have never been attempted).
     *
     * @return array[] Rows from freq_requests_outbound (may be empty)
     */
    public function findRetryEligible(int $maxAttempts, int $intervalSeconds): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM freq_requests_outbound
            WHERE status = 'pending'
              AND attempts < ?
              AND (last_attempt_at IS NULL OR last_attempt_at <= NOW() - (? || ' seconds')::interval)
            ORDER BY node_address, created_at ASC
        ");
        $stmt->execute([$maxAttempts, $intervalSeconds]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Find pending requests that have exhausted the attempts cap and should
     * be transitioned to 'failed'.
     *
     * @return array[] Rows from freq_requests_outbound (may be empty)
     */
    public function findAttemptsExhausted(int $maxAttempts): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM freq_requests_outbound
            WHERE status = 'pending' AND attempts >= ?
        ");
        $stmt->execute([$maxAttempts]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Count a user's currently in-flight ('pending') requests, used to
     * enforce a per-user concurrency cap on new submissions.
     */
    public function countPendingForUser(int $userId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM freq_requests_outbound
            WHERE user_id = ? AND status = 'pending'
        ");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Mark a request as complete.
     *
     * @param int         $id               Record ID returned by recordRequest()
     * @param int|null    $fileId           files.id of the routed response file, if known
     *                                      (lets the UI link the filename to the file viewer)
     * @param string|null $resolvedFilename When set, overwrites requested_files with this
     *                                      single actual filename — used when the request was
     *                                      made by magic name (e.g. ALLFILES) so the remote's
     *                                      expanded/real filename is recorded and displayed
     *                                      instead of the magic name that was requested.
     */
    public function markComplete(int $id, ?int $fileId = null, ?string $resolvedFilename = null): void
    {
        $stmt = $this->db->prepare("
            UPDATE freq_requests_outbound
            SET status = 'complete', completed_at = NOW(), file_id = COALESCE(?, file_id),
                requested_files = COALESCE(?, requested_files)
            WHERE id = ?
        ");
        $stmt->execute([
            $fileId,
            $resolvedFilename !== null ? json_encode([$resolvedFilename]) : null,
            $id,
        ]);
    }

    /**
     * Mark a request as permanently failed after exhausting the attempts cap.
     *
     * @param int $id Record ID
     */
    public function markFailed(int $id): void
    {
        $stmt = $this->db->prepare("
            UPDATE freq_requests_outbound
            SET status = 'failed'
            WHERE id = ?
        ");
        $stmt->execute([$id]);
    }

    /**
     * Fetch a single request row by ID.
     *
     * @return array|null Row from freq_requests_outbound, or null if not found
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM freq_requests_outbound WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Delete a request row. Does not touch the routed response file (if
     * any) sitting in the user's private file area — this only removes the
     * tracking row. Safe to call on a 'pending' row: a freq_getfile.php
     * process or scheduled retry already in flight for it will simply find
     * no matching row on its next recordAttempt()/markComplete() call.
     *
     * @return bool True if a row was deleted
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM freq_requests_outbound WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
