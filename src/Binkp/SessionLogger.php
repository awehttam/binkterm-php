<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
 * 
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the 
 * following conditions are met:
 * 
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 * 
 */

/**
 * Binkp Session Logger
 *
 * Logs all binkp sessions for audit trail and monitoring.
 * Tracks secure, insecure, and crash outbound sessions.
 */

namespace BinktermPHP\Binkp;

use BinktermPHP\Database;
use BinktermPHP\Binkp\Config\BinkpConfig;

class SessionLogger
{
    private const REALTIME_PROGRESS_THROTTLE_SECONDS = 0.75;

    private $db;
    private $sessionId;
    private $startTime;
    private ?float $lastRealtimeEmitAt;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
        $this->sessionId = null;
        $this->startTime = null;
        $this->lastRealtimeEmitAt = null;
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
        bool $isInbound,
        ?int $processId = null,
        ?string $logFile = null
    ): int {
        $this->startTime = microtime(true);

        $stmt = $this->db->prepare("
            INSERT INTO binkp_session_log
            (remote_address, remote_ip, session_type, is_inbound, status, process_id, log_file)
            VALUES (?, ?::inet, ?, ?::boolean, 'active', ?, ?)
            RETURNING id
        ");
        // Cast boolean to PostgreSQL-compatible string
        $isInboundStr = $isInbound ? 'true' : 'false';
        $stmt->execute([
            $remoteAddress,
            $remoteIp,
            $sessionType,
            $isInboundStr,
            $processId ?? getmypid(),
            $logFile !== null ? basename($logFile) : null,
        ]);
        $this->sessionId = (int)$stmt->fetchColumn();
        $this->emitRealtimeUpdate('started', true);
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
        $this->emitRealtimeUpdate('stats');
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
        $this->emitRealtimeUpdate('progress');
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
        $this->emitRealtimeUpdate('ended', true);
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
        $this->emitRealtimeUpdate('address', true);
    }

    public function setRemoteIp(string $remoteIp): void
    {
        if (!$this->sessionId || filter_var($remoteIp, FILTER_VALIDATE_IP) === false) {
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE binkp_session_log SET remote_ip = ?::inet
            WHERE id = ?
        ");
        $stmt->execute([$remoteIp, $this->sessionId]);
        $this->emitRealtimeUpdate('address', true);
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
        $this->emitRealtimeUpdate('type', true);
    }

    /**
     * Set the authentication method used for this session
     *
     * @param string $method 'plaintext' or 'cram-md5'
     */
    public function setAuthMethod(string $method): void
    {
        if (!$this->sessionId) {
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE binkp_session_log SET auth_method = ?
            WHERE id = ?
        ");
        $stmt->execute([$method, $this->sessionId]);
        $this->emitRealtimeUpdate('auth', true);
    }

    private function emitRealtimeUpdate(string $reason, bool $force = false): void
    {
        if (!$this->sessionId) {
            return;
        }

        $now = microtime(true);
        if (
            !$force &&
            $this->lastRealtimeEmitAt !== null &&
            ($now - $this->lastRealtimeEmitAt) < self::REALTIME_PROGRESS_THROTTLE_SECONDS
        ) {
            return;
        }

        $payload = $this->buildRealtimePayload($reason);
        if ($payload === null) {
            return;
        }

        try {
            \BinktermPHP\Realtime\BinkStream::emit($this->db, 'binkp_session', $payload, null, true);
            $this->lastRealtimeEmitAt = $now;
        } catch (\Throwable $e) {
            // Realtime delivery is best-effort only; never break a session write.
        }
    }

    private function buildRealtimePayload(string $reason): ?array
    {
        if (!$this->sessionId) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT *,
                   EXTRACT(EPOCH FROM (COALESCE(ended_at, CURRENT_TIMESTAMP) - started_at)) AS duration_seconds
            FROM binkp_session_log
            WHERE id = ?
        ");
        $stmt->execute([$this->sessionId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return [
            'reason' => $reason,
            'session' => [
                'id' => (int)$row['id'],
                'remote_address' => $row['remote_address'] !== null ? (string)$row['remote_address'] : null,
                'remote_ip' => $row['remote_ip'] !== null ? (string)$row['remote_ip'] : null,
                'process_id' => isset($row['process_id']) ? (int)$row['process_id'] : null,
                'log_file' => $row['log_file'] !== null ? (string)$row['log_file'] : null,
                'session_type' => (string)$row['session_type'],
                'is_inbound' => (bool)$row['is_inbound'],
                'status' => (string)$row['status'],
                'auth_method' => $row['auth_method'] !== null ? (string)$row['auth_method'] : null,
                'messages_received' => (int)($row['messages_received'] ?? 0),
                'messages_sent' => (int)($row['messages_sent'] ?? 0),
                'files_received' => (int)($row['files_received'] ?? 0),
                'files_sent' => (int)($row['files_sent'] ?? 0),
                'bytes_received' => (int)($row['bytes_received'] ?? 0),
                'bytes_sent' => (int)($row['bytes_sent'] ?? 0),
                'started_at' => $row['started_at'] !== null ? (string)$row['started_at'] : null,
                'ended_at' => $row['ended_at'] !== null ? (string)$row['ended_at'] : null,
                'duration_seconds' => isset($row['duration_seconds']) ? (float)$row['duration_seconds'] : 0.0,
                'error_message' => $row['error_message'] !== null ? (string)$row['error_message'] : null,
            ],
        ];
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
            $where[] = '(remote_address LIKE ? OR remote_ip::text LIKE ?)';
            $needle = '%' . $filters['remote_address'] . '%';
            $params[] = $needle;
            $params[] = $needle;
        }

        if (!empty($filters['is_inbound'])) {
            $where[] = 'is_inbound = ?';
            $params[] = $filters['is_inbound'] === 'true';
        }

        if (!empty($filters['process_id'])) {
            $where[] = 'process_id = ?';
            $params[] = (int)$filters['process_id'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $params[] = $limit;

        $stmt = $db->prepare("
            SELECT *,
                   files_received AS messages_received,
                   files_sent AS messages_sent,
                   EXTRACT(EPOCH FROM (COALESCE(ended_at, CURRENT_TIMESTAMP) - started_at)) as duration_seconds
            FROM binkp_session_log
            {$whereClause}
            ORDER BY started_at DESC
            LIMIT ?
        ");
        $stmt->execute($params);

        return array_map([self::class, 'enrichSessionRow'], $stmt->fetchAll());
    }

    public static function getSessionById(int $id): ?array
    {
        $db = Database::getInstance()->getPdo();

        $stmt = $db->prepare("
            SELECT *,
                   files_received AS messages_received,
                   files_sent AS messages_sent,
                   EXTRACT(EPOCH FROM (COALESCE(ended_at, CURRENT_TIMESTAMP) - started_at)) AS duration_seconds
            FROM binkp_session_log
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? self::enrichSessionRow($row) : null;
    }

    /**
     * Add derived display fields for session consumers.
     *
     * @param array $row
     * @return array
     */
    private static function enrichSessionRow(array $row): array
    {
        $remoteAddress = trim((string)($row['remote_address'] ?? ''));
        $remoteDomain = '';

        if ($remoteAddress !== '') {
            $atPos = strrpos($remoteAddress, '@');
            if ($atPos !== false && $atPos > 0 && $atPos < strlen($remoteAddress) - 1) {
                $remoteDomain = substr($remoteAddress, $atPos + 1);
            } else {
                try {
                    $resolved = BinkpConfig::getInstance()->getDomainByAddress($remoteAddress);
                    if ($resolved !== false) {
                        $remoteDomain = (string)$resolved;
                    }
                } catch (\Throwable $e) {
                    $remoteDomain = '';
                }
            }
        }

        $row['remote_domain'] = $remoteDomain !== '' ? $remoteDomain : null;
        return $row;
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

        $periodConfig = self::getPeriodConfig($period);
        $interval = $periodConfig['interval'];

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
                COUNT(*) FILTER (WHERE auth_method = 'cram-md5') as cram_md5_sessions,
                COUNT(*) FILTER (WHERE auth_method = 'plaintext') as plaintext_sessions,
                COALESCE(SUM(files_received), 0) as total_messages_received,
                COALESCE(SUM(files_sent), 0) as total_messages_sent,
                COALESCE(SUM(files_received), 0) as total_files_received,
                COALESCE(SUM(files_sent), 0) as total_files_sent,
                COALESCE(SUM(bytes_received), 0) as total_bytes_received,
                COALESCE(SUM(bytes_sent), 0) as total_bytes_sent
            FROM binkp_session_log
            WHERE started_at > NOW() - INTERVAL '{$interval}'
        ");
        $stmt->execute();

        $stats = $stmt->fetch();
        if (!is_array($stats)) {
            $stats = [];
        }

        $stats['timeline'] = self::getTrafficTimeline($period);
        $stats['uplink_connections'] = self::getUplinkConnectionCounts($period);

        return $stats;
    }

    /**
     * @return array{interval:string,bucket_interval:string,bucket_count:int,label_format:string,label_key:string,group_by:string}
     */
    private static function getPeriodConfig(string $period): array
    {
        return match($period) {
            'week' => [
                'interval' => '7 days',
                'bucket_interval' => '1 day',
                'bucket_count' => 7,
                'label_format' => 'Dy',
                'label_key' => 'day_label',
                'group_by' => 'day',
            ],
            'month' => [
                'interval' => '30 days',
                'bucket_interval' => '1 day',
                'bucket_count' => 30,
                'label_format' => 'Mon DD',
                'label_key' => 'day_label',
                'group_by' => 'day',
            ],
            default => [
                'interval' => '24 hours',
                'bucket_interval' => '1 hour',
                'bucket_count' => 24,
                'label_format' => 'HH24:00',
                'label_key' => 'hour_label',
                'group_by' => 'hour',
            ],
        };
    }

    public static function getTrafficTimeline(string $period = 'day'): array
    {
        $db = Database::getInstance()->getPdo();
        $periodConfig = self::getPeriodConfig($period);
        $bucketStart = $periodConfig['group_by'] === 'hour'
            ? "date_trunc('hour', NOW()) - INTERVAL '23 hours'"
            : "date_trunc('day', NOW()) - INTERVAL '" . ($periodConfig['bucket_count'] - 1) . " days'";
        $bucketEnd = $periodConfig['group_by'] === 'hour'
            ? "date_trunc('hour', NOW())"
            : "date_trunc('day', NOW())";
        $joinExpression = $periodConfig['group_by'] === 'hour'
            ? "date_trunc('hour', log.started_at)"
            : "date_trunc('day', log.started_at)";

        $stmt = $db->query("
            SELECT
                to_char(bucket.time_bucket, '{$periodConfig['label_format']}') AS {$periodConfig['label_key']},
                EXTRACT(EPOCH FROM bucket.time_bucket) AS bucket_epoch,
                COALESCE(SUM(log.files_received), 0) AS messages_received,
                COALESCE(SUM(log.files_sent), 0) AS messages_sent
            FROM generate_series(
                {$bucketStart},
                {$bucketEnd},
                INTERVAL '{$periodConfig['bucket_interval']}'
            ) AS bucket(time_bucket)
            LEFT JOIN binkp_session_log log
                ON {$joinExpression} = bucket.time_bucket
            GROUP BY bucket.time_bucket
            ORDER BY bucket.time_bucket ASC
        ");

        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        return array_map(static function ($row) {
            return [
                'label' => (string)($row['hour_label'] ?? $row['day_label'] ?? ''),
                'bucket_epoch' => isset($row['bucket_epoch']) ? (int)$row['bucket_epoch'] : 0,
                'messages_received' => (int)($row['messages_received'] ?? 0),
                'messages_sent' => (int)($row['messages_sent'] ?? 0),
            ];
        }, $rows);
    }

    public static function getUplinkConnectionCounts(string $period = 'day'): array
    {
        $periodConfig = self::getPeriodConfig($period);
        $uplinkAddresses = [];

        try {
            $config = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            foreach ($config->getEnabledUplinks() as $uplink) {
                $address = trim((string)($uplink['address'] ?? ''));
                if ($address !== '') {
                    $uplinkAddresses[$address] = $address;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        if ($uplinkAddresses === []) {
            return [];
        }

        $db = Database::getInstance()->getPdo();
        $placeholders = implode(',', array_fill(0, count($uplinkAddresses), '?'));
        $params = array_values($uplinkAddresses);
        $params[] = $periodConfig['interval'];

        $stmt = $db->prepare("
            SELECT remote_address, COUNT(*) AS connection_count
            FROM binkp_session_log
            WHERE remote_address IN ({$placeholders})
              AND started_at > NOW() - CAST(? AS interval)
            GROUP BY remote_address
            ORDER BY remote_address ASC
        ");
        $stmt->execute($params);

        $rows = $stmt->fetchAll();
        $counts = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $counts[(string)$row['remote_address']] = (int)($row['connection_count'] ?? 0);
            }
        }

        $result = [];
        foreach ($uplinkAddresses as $address) {
            $result[] = [
                'label' => $address,
                'count' => (int)($counts[$address] ?? 0),
            ];
        }

        return $result;
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

