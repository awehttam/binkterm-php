<?php
/**
 * Binkp Session Logger
 *
 * Logs all binkp sessions for audit trail and monitoring.
 * Tracks secure, insecure, and crash outbound sessions.
 */

namespace BinktermPHP\Binkp;

use BinktermPHP\Database;

class SessionLogger
{
    private $db;
    private $sessionId;
    private $startTime;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
        $this->sessionId = null;
        $this->startTime = null;
    }

    /**
     * Start logging a new session
     *
     * @param string|null $remoteAddress FTN address of remote node
     * @param string|null $remoteIp IP address of remote node
     * @param string $sessionType 'secure', 'insecure', or 'crash_outbound'
     * @param bool $isInbound True if we received the connection
     * @return int The session log ID
     */
    public function startSession(
        ?string $remoteAddress,
        ?string $remoteIp,
        string $sessionType,
        bool $isInbound
    ): int {
        $this->startTime = microtime(true);

        $stmt = $this->db->prepare("
            INSERT INTO binkp_session_log
            (remote_address, remote_ip, session_type, is_inbound, status)
            VALUES (?, ?::inet, ?, ?, 'active')
            RETURNING id
        ");
        $stmt->execute([$remoteAddress, $remoteIp, $sessionType, $isInbound]);
        $this->sessionId = (int)$stmt->fetchColumn();
        return $this->sessionId;
    }

    /**
     * Update session statistics during transfer
     *
     * @param int $messagesReceived Number of messages received
     * @param int $messagesSent Number of messages sent
     * @param int $filesReceived Number of files received
     * @param int $filesSent Number of files sent
     * @param int $bytesReceived Bytes received
     * @param int $bytesSent Bytes sent
     */
    public function updateStats(
        int $messagesReceived = 0,
        int $messagesSent = 0,
        int $filesReceived = 0,
        int $filesSent = 0,
        int $bytesReceived = 0,
        int $bytesSent = 0
    ): void {
        if (!$this->sessionId) {
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE binkp_session_log SET
                messages_received = ?,
                messages_sent = ?,
                files_received = ?,
                files_sent = ?,
                bytes_received = ?,
                bytes_sent = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $messagesReceived,
            $messagesSent,
            $filesReceived,
            $filesSent,
            $bytesReceived,
            $bytesSent,
            $this->sessionId
        ]);
    }

    /**
     * Increment session statistics
     *
     * @param string $field Field to increment (messages_received, messages_sent, etc.)
     * @param int $amount Amount to add
     */
    public function incrementStat(string $field, int $amount = 1): void
    {
        if (!$this->sessionId) {
            return;
        }

        $allowedFields = [
            'messages_received',
            'messages_sent',
            'files_received',
            'files_sent',
            'bytes_received',
            'bytes_sent'
        ];

        if (!in_array($field, $allowedFields)) {
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE binkp_session_log SET {$field} = {$field} + ?
            WHERE id = ?
        ");
        $stmt->execute([$amount, $this->sessionId]);
    }

    /**
     * End the session with final status
     *
     * @param string $status 'success', 'failed', or 'rejected'
     * @param string|null $errorMessage Error message if failed
     */
    public function endSession(string $status, ?string $errorMessage = null): void
    {
        if (!$this->sessionId) {
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE binkp_session_log SET
                status = ?,
                error_message = ?,
                ended_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$status, $errorMessage, $this->sessionId]);
    }

    /**
     * Get the current session ID
     */
    public function getSessionId(): ?int
    {
        return $this->sessionId;
    }

    /**
     * Get session duration in seconds
     */
    public function getDuration(): float
    {
        if (!$this->startTime) {
            return 0.0;
        }
        return microtime(true) - $this->startTime;
    }

    /**
     * Update the remote address (if discovered after session start)
     */
    public function setRemoteAddress(string $address): void
    {
        if (!$this->sessionId) {
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE binkp_session_log SET remote_address = ?
            WHERE id = ?
        ");
        $stmt->execute([$address, $this->sessionId]);
    }

    /**
     * Change session type (e.g., from 'secure' to 'insecure' after auth)
     */
    public function setSessionType(string $sessionType): void
    {
        if (!$this->sessionId) {
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE binkp_session_log SET session_type = ?
            WHERE id = ?
        ");
        $stmt->execute([$sessionType, $this->sessionId]);
    }

    // ========================================
    // Static Query Methods
    // ========================================

    /**
     * Get recent sessions
     *
     * @param int $limit Maximum number of sessions to return
     * @param array $filters Optional filters (session_type, status, remote_address)
     * @return array
     */
    public static function getRecentSessions(int $limit = 50, array $filters = []): array
    {
        $db = Database::getInstance()->getPdo();

        $where = [];
        $params = [];

        if (!empty($filters['session_type'])) {
            $where[] = 'session_type = ?';
            $params[] = $filters['session_type'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['remote_address'])) {
            $where[] = 'remote_address LIKE ?';
            $params[] = '%' . $filters['remote_address'] . '%';
        }

        if (!empty($filters['is_inbound'])) {
            $where[] = 'is_inbound = ?';
            $params[] = $filters['is_inbound'] === 'true';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $params[] = $limit;

        $stmt = $db->prepare("
            SELECT *,
                   EXTRACT(EPOCH FROM (COALESCE(ended_at, CURRENT_TIMESTAMP) - started_at)) as duration_seconds
            FROM binkp_session_log
            {$whereClause}
            ORDER BY started_at DESC
            LIMIT ?
        ");
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Get session statistics for dashboard
     *
     * @param string $period 'hour', 'day', 'week', 'month'
     * @return array
     */
    public static function getSessionStats(string $period = 'day'): array
    {
        $db = Database::getInstance()->getPdo();

        $interval = match($period) {
            'hour' => '1 hour',
            'day' => '24 hours',
            'week' => '7 days',
            'month' => '30 days',
            default => '24 hours'
        };

        $stmt = $db->prepare("
            SELECT
                COUNT(*) as total_sessions,
                COUNT(*) FILTER (WHERE session_type = 'secure') as secure_sessions,
                COUNT(*) FILTER (WHERE session_type = 'insecure') as insecure_sessions,
                COUNT(*) FILTER (WHERE session_type = 'crash_outbound') as crash_sessions,
                COUNT(*) FILTER (WHERE status = 'success') as successful,
                COUNT(*) FILTER (WHERE status = 'failed') as failed,
                COUNT(*) FILTER (WHERE status = 'rejected') as rejected,
                COUNT(*) FILTER (WHERE is_inbound = TRUE) as inbound,
                COUNT(*) FILTER (WHERE is_inbound = FALSE) as outbound,
                COALESCE(SUM(messages_received), 0) as total_messages_received,
                COALESCE(SUM(messages_sent), 0) as total_messages_sent,
                COALESCE(SUM(files_received), 0) as total_files_received,
                COALESCE(SUM(files_sent), 0) as total_files_sent,
                COALESCE(SUM(bytes_received), 0) as total_bytes_received,
                COALESCE(SUM(bytes_sent), 0) as total_bytes_sent
            FROM binkp_session_log
            WHERE started_at > NOW() - INTERVAL '{$interval}'
        ");
        $stmt->execute();

        return $stmt->fetch();
    }

    /**
     * Count recent insecure sessions from an address (for rate limiting)
     *
     * @param string $remoteAddress FTN address to check
     * @param int $withinMinutes Time window in minutes
     * @return int
     */
    public static function countRecentInsecureSessions(string $remoteAddress, int $withinMinutes = 60): int
    {
        $db = Database::getInstance()->getPdo();

        $stmt = $db->prepare("
            SELECT COUNT(*) FROM binkp_session_log
            WHERE remote_address = ?
            AND session_type = 'insecure'
            AND started_at > NOW() - INTERVAL '{$withinMinutes} minutes'
        ");
        $stmt->execute([$remoteAddress]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Clean up old session logs
     *
     * @param int $daysToKeep Number of days of logs to retain
     * @return int Number of deleted records
     */
    public static function cleanupOldLogs(int $daysToKeep = 30): int
    {
        $db = Database::getInstance()->getPdo();

        $stmt = $db->prepare("
            DELETE FROM binkp_session_log
            WHERE started_at < NOW() - INTERVAL '{$daysToKeep} days'
        ");
        $stmt->execute();

        return $stmt->rowCount();
    }
}
