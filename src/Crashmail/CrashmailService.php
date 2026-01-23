<?php
/**
 * Crashmail Service
 *
 * Handles queueing and delivery of crashmail (immediate delivery netmail).
 * Crashmail bypasses normal hub routing and attempts direct delivery.
 */

namespace BinktermPHP\Crashmail;

use BinktermPHP\Database;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Nodelist\NodelistManager;
use BinktermPHP\Binkp\SessionLogger;

class CrashmailService
{
    private $db;
    private $config;
    private $nodelistManager;

    // FidoNet message attribute flags
    const ATTR_PRIVATE = 0x0001;
    const ATTR_CRASH = 0x0002;
    const ATTR_RECEIVED = 0x0004;
    const ATTR_SENT = 0x0008;
    const ATTR_FILE_ATTACH = 0x0010;
    const ATTR_IN_TRANSIT = 0x0020;
    const ATTR_ORPHAN = 0x0040;
    const ATTR_KILL_SENT = 0x0080;
    const ATTR_LOCAL = 0x0100;
    const ATTR_HOLD = 0x0200;
    const ATTR_FILE_REQUEST = 0x0800;
    const ATTR_RETURN_RECEIPT = 0x1000;
    const ATTR_IS_RETURN_RECEIPT = 0x2000;
    const ATTR_AUDIT_REQUEST = 0x4000;
    const ATTR_FILE_UPDATE_REQ = 0x8000;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
        $this->config = BinkpConfig::getInstance();
        $this->nodelistManager = new NodelistManager();
    }

    /**
     * Queue a netmail message for crash delivery
     *
     * @param int $netmailId The netmail ID to queue
     * @return bool Success
     */
    public function queueCrashmail(int $netmailId): bool
    {
        if (!$this->config->getCrashmailEnabled()) {
            error_log("[CRASHMAIL] Crashmail is disabled in configuration");
            return false;
        }

        // Get the netmail
        $stmt = $this->db->prepare("SELECT * FROM netmail WHERE id = ?");
        $stmt->execute([$netmailId]);
        $netmail = $stmt->fetch();

        if (!$netmail) {
            error_log("[CRASHMAIL] Netmail ID {$netmailId} not found");
            return false;
        }

        // Check if already queued
        $stmt = $this->db->prepare("SELECT id FROM crashmail_queue WHERE netmail_id = ?");
        $stmt->execute([$netmailId]);
        if ($stmt->fetch()) {
            error_log("[CRASHMAIL] Netmail ID {$netmailId} already queued");
            return true; // Already queued is not an error
        }

        // Resolve destination
        $routeInfo = $this->resolveDestination($netmail['to_address']);

        $stmt = $this->db->prepare("
            INSERT INTO crashmail_queue
            (netmail_id, destination_address, destination_host, destination_port, max_attempts)
            VALUES (?, ?, ?, ?, ?)
        ");

        $success = $stmt->execute([
            $netmailId,
            $netmail['to_address'],
            $routeInfo['hostname'] ?? null,
            $routeInfo['port'] ?? $this->config->getCrashmailFallbackPort(),
            $this->config->getCrashmailMaxAttempts()
        ]);

        if ($success) {
            error_log("[CRASHMAIL] Queued netmail ID {$netmailId} for crash delivery to {$netmail['to_address']}");
        }

        return $success;
    }

    /**
     * Resolve destination address to connection info
     *
     * @param string $address FTN address
     * @return array Connection info with hostname, port, password, source
     */
    public function resolveDestination(string $address): array
    {
        // First check if we have an uplink configured for this address
        $uplink = $this->config->getUplinkByAddress($address);
        if ($uplink && !empty($uplink['hostname'])) {
            return [
                'hostname' => $uplink['hostname'],
                'port' => $uplink['port'] ?? 24554,
                'password' => $uplink['password'] ?? '',
                'source' => 'uplink'
            ];
        }

        // Check for destination in uplink routing
        $routeUplink = $this->config->getUplinkForDestination($address);
        if ($routeUplink && !empty($routeUplink['hostname'])) {
            // Route through uplink, not direct
            return [
                'hostname' => $routeUplink['hostname'],
                'port' => $routeUplink['port'] ?? 24554,
                'password' => $routeUplink['password'] ?? '',
                'source' => 'route'
            ];
        }

        // Check nodelist if enabled
        if ($this->config->getCrashmailUseNodelist()) {
            $nodeInfo = $this->nodelistManager->getCrashRouteInfo($address);
            if ($nodeInfo && !empty($nodeInfo['hostname'])) {
                return [
                    'hostname' => $nodeInfo['hostname'],
                    'port' => $nodeInfo['port'] ?? 24554,
                    'password' => '',  // Insecure delivery for nodelist lookups
                    'source' => 'nodelist',
                    'system_name' => $nodeInfo['system_name'] ?? '',
                    'sysop_name' => $nodeInfo['sysop_name'] ?? ''
                ];
            }
        }

        return [
            'hostname' => null,
            'port' => $this->config->getCrashmailFallbackPort(),
            'password' => '',
            'source' => 'unknown'
        ];
    }

    /**
     * Process the crashmail queue
     *
     * @param int $limit Maximum items to process
     * @return array Results with processed, success, failed, deferred counts
     */
    public function processQueue(int $limit = 10): array
    {
        $results = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'deferred' => 0
        ];

        if (!$this->config->getCrashmailEnabled()) {
            return $results;
        }

        // Get pending items ready for attempt
        $stmt = $this->db->prepare("
            SELECT cq.*,
                   n.from_address, n.to_address, n.from_name, n.to_name,
                   n.subject, n.message_text, n.date_written, n.attributes,
                   n.message_id, n.kludge_lines
            FROM crashmail_queue cq
            JOIN netmail n ON cq.netmail_id = n.id
            WHERE cq.status IN ('pending', 'attempting')
            AND cq.next_attempt_at <= CURRENT_TIMESTAMP
            AND cq.attempts < cq.max_attempts
            ORDER BY cq.created_at
            LIMIT ?
        ");
        $stmt->execute([$limit]);

        while ($item = $stmt->fetch()) {
            $results['processed']++;

            $success = $this->attemptDelivery($item);

            if ($success) {
                $results['success']++;
            } elseif ($item['attempts'] + 1 >= $item['max_attempts']) {
                $results['failed']++;
            } else {
                $results['deferred']++;
            }
        }

        return $results;
    }

    /**
     * Attempt delivery of a single crashmail
     *
     * @param array $queueItem Queue item with netmail data
     * @return bool Success
     */
    public function attemptDelivery(array $queueItem): bool
    {
        $queueId = $queueItem['id'];

        // Mark as attempting
        $this->updateQueueStatus($queueId, 'attempting');

        // Check if we have a destination
        if (empty($queueItem['destination_host'])) {
            // Try to resolve again
            $routeInfo = $this->resolveDestination($queueItem['destination_address']);
            if (empty($routeInfo['hostname'])) {
                $this->markFailed($queueId, "Cannot resolve destination: {$queueItem['destination_address']}");
                return false;
            }
            $queueItem['destination_host'] = $routeInfo['hostname'];
            $queueItem['destination_port'] = $routeInfo['port'];

            // Update the queue with resolved info
            $stmt = $this->db->prepare("
                UPDATE crashmail_queue SET destination_host = ?, destination_port = ?
                WHERE id = ?
            ");
            $stmt->execute([$routeInfo['hostname'], $routeInfo['port'], $queueId]);
        }

        try {
            // Attempt binkp connection and delivery
            $success = $this->deliverViaBinkp($queueItem);

            if ($success) {
                $this->markSent($queueId);
                return true;
            } else {
                $this->markRetry($queueId, "Delivery failed - no error details");
                return false;
            }

        } catch (\Exception $e) {
            $this->markRetry($queueId, $e->getMessage());
            return false;
        }
    }

    /**
     * Deliver crashmail via binkp connection
     *
     * @param array $queueItem Queue item with netmail data
     * @return bool Success
     */
    private function deliverViaBinkp(array $queueItem): bool
    {
        $host = $queueItem['destination_host'];
        $port = $queueItem['destination_port'] ?? 24554;
        $destAddress = $queueItem['destination_address'];

        error_log("[CRASHMAIL] Attempting delivery to {$destAddress} via {$host}:{$port}");

        // Start session logging
        $sessionLogger = new SessionLogger();
        $sessionLogger->startSession($destAddress, null, 'crash_outbound', false);

        try {
            // Create TCP connection
            $socket = @stream_socket_client(
                "tcp://{$host}:{$port}",
                $errno,
                $errstr,
                30 // 30 second timeout
            );

            if (!$socket) {
                throw new \Exception("Connection failed: {$errstr} ({$errno})");
            }

            stream_set_timeout($socket, 60);

            // Use BinkpClient for the session
            $client = new \BinktermPHP\Binkp\Protocol\BinkpClient();

            // Get password for destination if we have one
            $routeInfo = $this->resolveDestination($destAddress);
            $password = $routeInfo['password'] ?? '';

            // If no password and insecure not allowed, fail
            if (empty($password) && !$this->config->getCrashmailAllowInsecure()) {
                fclose($socket);
                throw new \Exception("No password for destination and insecure delivery disabled");
            }

            // Perform the delivery
            // Note: This is a simplified version. Full implementation would use
            // the BinkpSession class to handle the protocol properly.
            $success = $this->performCrashDelivery($socket, $queueItem, $password);

            fclose($socket);

            $sessionLogger->incrementStat('messages_sent', 1);
            $sessionLogger->endSession($success ? 'success' : 'failed');

            return $success;

        } catch (\Exception $e) {
            error_log("[CRASHMAIL] Delivery error: " . $e->getMessage());
            $sessionLogger->endSession('failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Perform the actual crash delivery over an established socket
     *
     * @param resource $socket Connected socket
     * @param array $queueItem Queue item with netmail data
     * @param string $password Session password (empty for insecure)
     * @return bool Success
     */
    private function performCrashDelivery($socket, array $queueItem, string $password): bool
    {
        // This is a placeholder for the full binkp protocol implementation
        // In a complete implementation, this would:
        // 1. Create a BinkpSession in originator mode
        // 2. Perform handshake with password (or insecure)
        // 3. Create a packet containing just this netmail
        // 4. Send the packet
        // 5. Wait for confirmation
        // 6. End session cleanly

        // For now, we'll use the existing BinkpClient infrastructure
        // This requires the BinkpClient to have a method for single-message delivery

        error_log("[CRASHMAIL] TODO: Implement full binkp crash delivery");
        error_log("[CRASHMAIL] Would deliver to: {$queueItem['destination_address']}");
        error_log("[CRASHMAIL] Subject: {$queueItem['subject']}");

        // Return false to indicate not yet implemented
        // When implemented, return true on successful delivery
        return false;
    }

    /**
     * Update queue status
     */
    private function updateQueueStatus(int $queueId, string $status): void
    {
        $stmt = $this->db->prepare("
            UPDATE crashmail_queue SET status = ?, last_attempt_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$status, $queueId]);
    }

    /**
     * Mark queue item as sent
     */
    private function markSent(int $queueId): void
    {
        $stmt = $this->db->prepare("
            UPDATE crashmail_queue
            SET status = 'sent', sent_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$queueId]);

        // Also update the netmail attributes to mark as sent
        $stmt = $this->db->prepare("
            UPDATE netmail SET attributes = attributes | ?, is_sent = TRUE
            WHERE id = (SELECT netmail_id FROM crashmail_queue WHERE id = ?)
        ");
        $stmt->execute([self::ATTR_SENT, $queueId]);

        error_log("[CRASHMAIL] Queue item {$queueId} marked as sent");
    }

    /**
     * Mark queue item for retry
     */
    private function markRetry(int $queueId, string $error): void
    {
        $retryMinutes = $this->config->getCrashmailRetryInterval();

        $stmt = $this->db->prepare("
            UPDATE crashmail_queue
            SET status = 'pending',
                attempts = attempts + 1,
                error_message = ?,
                next_attempt_at = CURRENT_TIMESTAMP + INTERVAL '{$retryMinutes} minutes'
            WHERE id = ?
        ");
        $stmt->execute([$error, $queueId]);

        error_log("[CRASHMAIL] Queue item {$queueId} scheduled for retry: {$error}");
    }

    /**
     * Mark queue item as permanently failed
     */
    private function markFailed(int $queueId, string $error): void
    {
        $stmt = $this->db->prepare("
            UPDATE crashmail_queue
            SET status = 'failed',
                attempts = attempts + 1,
                error_message = ?
            WHERE id = ?
        ");
        $stmt->execute([$error, $queueId]);

        error_log("[CRASHMAIL] Queue item {$queueId} permanently failed: {$error}");
    }

    /**
     * Set crash flag on a netmail message
     *
     * @param int $netmailId Netmail ID
     * @return bool Success
     */
    public function setCrashFlag(int $netmailId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE netmail SET attributes = attributes | ?
            WHERE id = ?
        ");
        return $stmt->execute([self::ATTR_CRASH, $netmailId]);
    }

    /**
     * Check if a netmail has the crash flag
     *
     * @param int $netmailId Netmail ID
     * @return bool
     */
    public function hasCrashFlag(int $netmailId): bool
    {
        $stmt = $this->db->prepare("SELECT attributes FROM netmail WHERE id = ?");
        $stmt->execute([$netmailId]);
        $result = $stmt->fetch();

        if (!$result) {
            return false;
        }

        return ($result['attributes'] & self::ATTR_CRASH) !== 0;
    }

    /**
     * Cancel a queued crashmail
     *
     * @param int $queueId Queue ID
     * @return bool Success
     */
    public function cancelCrashmail(int $queueId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM crashmail_queue WHERE id = ? AND status != 'sent'");
        return $stmt->execute([$queueId]) && $stmt->rowCount() > 0;
    }

    /**
     * Retry a failed crashmail
     *
     * @param int $queueId Queue ID
     * @return bool Success
     */
    public function retryCrashmail(int $queueId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE crashmail_queue
            SET status = 'pending',
                attempts = 0,
                error_message = NULL,
                next_attempt_at = CURRENT_TIMESTAMP
            WHERE id = ? AND status = 'failed'
        ");
        return $stmt->execute([$queueId]) && $stmt->rowCount() > 0;
    }

    // ========================================
    // Queue Statistics and Queries
    // ========================================

    /**
     * Get queue statistics
     *
     * @return array Statistics
     */
    public function getQueueStats(): array
    {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) FILTER (WHERE status = 'pending') as pending,
                COUNT(*) FILTER (WHERE status = 'attempting') as attempting,
                COUNT(*) FILTER (WHERE status = 'sent' AND sent_at > NOW() - INTERVAL '24 hours') as sent_24h,
                COUNT(*) FILTER (WHERE status = 'failed') as failed,
                COUNT(*) as total
            FROM crashmail_queue
        ");
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Get queue items
     *
     * @param string|null $status Filter by status
     * @param int $limit Maximum items
     * @return array Queue items
     */
    public function getQueueItems(?string $status = null, int $limit = 50): array
    {
        $where = $status ? "WHERE cq.status = ?" : "";
        $params = $status ? [$status, $limit] : [$limit];

        $stmt = $this->db->prepare("
            SELECT cq.*, n.from_name, n.to_name, n.subject
            FROM crashmail_queue cq
            JOIN netmail n ON cq.netmail_id = n.id
            {$where}
            ORDER BY cq.created_at DESC
            LIMIT ?
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Clean up old sent items from queue
     *
     * @param int $daysToKeep Days to keep sent items
     * @return int Number deleted
     */
    public function cleanupOldItems(int $daysToKeep = 7): int
    {
        $stmt = $this->db->prepare("
            DELETE FROM crashmail_queue
            WHERE status = 'sent'
            AND sent_at < NOW() - INTERVAL '{$daysToKeep} days'
        ");
        $stmt->execute();
        return $stmt->rowCount();
    }

    // ========================================
    // FLAGS Kludge Helpers
    // ========================================

    /**
     * Parse FLAGS kludge line to attributes integer
     *
     * @param string $flags FLAGS kludge content
     * @return int Attributes bitmask
     */
    public static function parseFlagsKludge(string $flags): int
    {
        $attributes = 0;
        $flagMap = [
            'PVT' => self::ATTR_PRIVATE,
            'CRA' => self::ATTR_CRASH,
            'DIR' => self::ATTR_CRASH,
            'IMM' => self::ATTR_CRASH,
            'RCV' => self::ATTR_RECEIVED,
            'SNT' => self::ATTR_SENT,
            'FIL' => self::ATTR_FILE_ATTACH,
            'TRS' => self::ATTR_IN_TRANSIT,
            'ORP' => self::ATTR_ORPHAN,
            'K/S' => self::ATTR_KILL_SENT,
            'LOC' => self::ATTR_LOCAL,
            'HLD' => self::ATTR_HOLD,
            'FRQ' => self::ATTR_FILE_REQUEST,
            'RRQ' => self::ATTR_RETURN_RECEIPT,
            'CFM' => self::ATTR_IS_RETURN_RECEIPT,
            'ARQ' => self::ATTR_AUDIT_REQUEST,
        ];

        foreach (explode(' ', strtoupper($flags)) as $flag) {
            $flag = trim($flag);
            if (isset($flagMap[$flag])) {
                $attributes |= $flagMap[$flag];
            }
        }

        return $attributes;
    }

    /**
     * Generate FLAGS kludge from attributes integer
     *
     * @param int $attributes Attributes bitmask
     * @return string FLAGS kludge content
     */
    public static function generateFlagsKludge(int $attributes): string
    {
        $flags = [];

        if ($attributes & self::ATTR_PRIVATE) $flags[] = 'PVT';
        if ($attributes & self::ATTR_CRASH) $flags[] = 'CRA';
        if ($attributes & self::ATTR_RECEIVED) $flags[] = 'RCV';
        if ($attributes & self::ATTR_SENT) $flags[] = 'SNT';
        if ($attributes & self::ATTR_FILE_ATTACH) $flags[] = 'FIL';
        if ($attributes & self::ATTR_IN_TRANSIT) $flags[] = 'TRS';
        if ($attributes & self::ATTR_ORPHAN) $flags[] = 'ORP';
        if ($attributes & self::ATTR_KILL_SENT) $flags[] = 'K/S';
        if ($attributes & self::ATTR_LOCAL) $flags[] = 'LOC';
        if ($attributes & self::ATTR_HOLD) $flags[] = 'HLD';
        if ($attributes & self::ATTR_FILE_REQUEST) $flags[] = 'FRQ';
        if ($attributes & self::ATTR_RETURN_RECEIPT) $flags[] = 'RRQ';
        if ($attributes & self::ATTR_IS_RETURN_RECEIPT) $flags[] = 'CFM';
        if ($attributes & self::ATTR_AUDIT_REQUEST) $flags[] = 'ARQ';

        return implode(' ', $flags);
    }

    /**
     * Get human-readable attribute names
     *
     * @param int $attributes Attributes bitmask
     * @return array Attribute names
     */
    public static function getAttributeNames(int $attributes): array
    {
        $names = [];

        if ($attributes & self::ATTR_PRIVATE) $names[] = 'Private';
        if ($attributes & self::ATTR_CRASH) $names[] = 'Crash';
        if ($attributes & self::ATTR_RECEIVED) $names[] = 'Received';
        if ($attributes & self::ATTR_SENT) $names[] = 'Sent';
        if ($attributes & self::ATTR_FILE_ATTACH) $names[] = 'File Attached';
        if ($attributes & self::ATTR_IN_TRANSIT) $names[] = 'In Transit';
        if ($attributes & self::ATTR_ORPHAN) $names[] = 'Orphan';
        if ($attributes & self::ATTR_KILL_SENT) $names[] = 'Kill/Sent';
        if ($attributes & self::ATTR_LOCAL) $names[] = 'Local';
        if ($attributes & self::ATTR_HOLD) $names[] = 'Hold';
        if ($attributes & self::ATTR_FILE_REQUEST) $names[] = 'File Request';
        if ($attributes & self::ATTR_RETURN_RECEIPT) $names[] = 'Return Receipt Requested';
        if ($attributes & self::ATTR_IS_RETURN_RECEIPT) $names[] = 'Is Return Receipt';
        if ($attributes & self::ATTR_AUDIT_REQUEST) $names[] = 'Audit Request';

        return $names;
    }
}
