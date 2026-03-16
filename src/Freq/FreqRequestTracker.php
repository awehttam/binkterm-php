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
     * Record a new outbound FREQ request.
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
     * Mark a request as complete.
     *
     * @param int $id Record ID returned by recordRequest()
     */
    public function markComplete(int $id): void
    {
        $stmt = $this->db->prepare("
            UPDATE freq_requests_outbound
            SET status = 'complete', completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$id]);
    }
}
