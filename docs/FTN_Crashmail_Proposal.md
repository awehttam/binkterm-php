# FTN Crashmail and Insecure Binkp Support Proposal

## Overview

This proposal covers support for FidoNet crashmail (immediate delivery netmail) and insecure binkp sessions, enabling BinktermPHP to participate in networks that allow unauthenticated mail pickup and direct node-to-node delivery.

**Crashmail** refers to netmail marked for immediate/direct delivery to the destination node, bypassing normal hub routing. The `Crash` attribute (0x0002) tells mailers to establish a direct connection to the destination.

**Insecure binkp sessions** allow nodes to connect and exchange mail without password authentication. This is common for:
- Crashmail delivery to nodes not in your uplink list
- Public mail pickup points
- Networks that don't require authentication
- Testing and development environments

---

## FidoNet Message Attributes

Current implementation only uses Private (0x0001). Full attribute support needed:

| Bit | Hex | Name | Description |
|-----|-----|------|-------------|
| 0 | 0x0001 | **Private** | Private netmail (already implemented) |
| 1 | 0x0002 | **Crash** | Send immediately/directly to destination |
| 2 | 0x0004 | **Received** | Message has been read |
| 3 | 0x0008 | **Sent** | Message has been sent |
| 4 | 0x0010 | **FileAttach** | File(s) attached to message |
| 5 | 0x0020 | **InTransit** | Message is in transit (not for us) |
| 6 | 0x0040 | **Orphan** | Destination unknown |
| 7 | 0x0080 | **KillSent** | Delete after sending |
| 8 | 0x0100 | **Local** | Message originated locally |
| 9 | 0x0200 | **Hold** | Hold for pickup (don't send) |
| 10 | 0x0400 | **Reserved** | Reserved |
| 11 | 0x0800 | **FileRequest** | File request message |
| 12 | 0x1000 | **ReturnReceipt** | Return receipt requested |
| 13 | 0x2000 | **IsReturnReceipt** | This is a return receipt |
| 14 | 0x4000 | **AuditRequest** | Audit trail requested |
| 15 | 0x8000 | **FileUpdateReq** | File update request |

### FLAGS Kludge Mapping

The FLAGS kludge line maps to these attributes:

| Flag | Attribute |
|------|-----------|
| PVT | Private (0x0001) |
| CRA | Crash (0x0002) |
| RCV | Received (0x0004) |
| SNT | Sent (0x0008) |
| K/S | KillSent (0x0080) |
| LOC | Local (0x0100) |
| HLD | Hold (0x0200) |
| FRQ | FileRequest (0x0800) |
| RRQ | ReturnReceipt (0x1000) |
| CFM | IsReturnReceipt (0x2000) |
| DIR | Direct (same as Crash) |
| IMM | Immediate (same as Crash) |

---

## Database Migration

**File:** `database/migrations/v1.7.1_add_crashmail_support.sql`

```sql
-- Insecure session allowlist - nodes that can connect without password
CREATE TABLE IF NOT EXISTS binkp_insecure_nodes (
    id SERIAL PRIMARY KEY,
    address VARCHAR(30) NOT NULL,
    description TEXT,
    allow_receive BOOLEAN DEFAULT TRUE,   -- Can receive mail from this node
    allow_send BOOLEAN DEFAULT FALSE,     -- Can send mail to this node (pickup)
    max_messages_per_session INTEGER DEFAULT 100,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_session_at TIMESTAMP,
    UNIQUE(address)
);

-- Crashmail queue - messages awaiting direct delivery
CREATE TABLE IF NOT EXISTS crashmail_queue (
    id SERIAL PRIMARY KEY,
    netmail_id INTEGER NOT NULL REFERENCES netmail(id) ON DELETE CASCADE,
    destination_address VARCHAR(30) NOT NULL,
    destination_host VARCHAR(255),        -- Resolved hostname/IP
    destination_port INTEGER DEFAULT 24554,
    status VARCHAR(20) DEFAULT 'pending', -- pending, attempting, sent, failed
    attempts INTEGER DEFAULT 0,
    max_attempts INTEGER DEFAULT 3,
    last_attempt_at TIMESTAMP,
    next_attempt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP
);

-- Session log for all binkp connections (audit trail)
CREATE TABLE IF NOT EXISTS binkp_session_log (
    id SERIAL PRIMARY KEY,
    remote_address VARCHAR(30),
    remote_ip INET,
    session_type VARCHAR(20) NOT NULL,    -- 'secure', 'insecure', 'crash_outbound'
    is_inbound BOOLEAN NOT NULL,
    messages_received INTEGER DEFAULT 0,
    messages_sent INTEGER DEFAULT 0,
    files_received INTEGER DEFAULT 0,
    files_sent INTEGER DEFAULT 0,
    bytes_received BIGINT DEFAULT 0,
    bytes_sent BIGINT DEFAULT 0,
    status VARCHAR(20) NOT NULL,          -- 'success', 'failed', 'rejected'
    error_message TEXT,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_crashmail_queue_pending ON crashmail_queue(status, next_attempt_at);
CREATE INDEX IF NOT EXISTS idx_crashmail_queue_destination ON crashmail_queue(destination_address);
CREATE INDEX IF NOT EXISTS idx_binkp_insecure_nodes_address ON binkp_insecure_nodes(address);
CREATE INDEX IF NOT EXISTS idx_binkp_session_log_remote ON binkp_session_log(remote_address, started_at);
CREATE INDEX IF NOT EXISTS idx_binkp_session_log_type ON binkp_session_log(session_type, started_at);
```

---

## Configuration

**File:** `config/binkp.json` (additions)

```json
{
    "security": {
        "allow_insecure_inbound": true,
        "allow_insecure_outbound": false,
        "insecure_inbound_receive_only": true,
        "require_allowlist_for_insecure": false,
        "max_insecure_sessions_per_hour": 10,
        "insecure_session_timeout": 60,
        "log_all_sessions": true
    },
    "crashmail": {
        "enabled": true,
        "max_attempts": 3,
        "retry_interval_minutes": 15,
        "use_nodelist_for_routing": true,
        "fallback_port": 24554,
        "allow_insecure_crash_delivery": true
    },
    "transit": {
        "allow_transit_mail": false,
        "transit_only_for_known_routes": true
    }
}
```

### Configuration Options Explained

| Option | Default | Description |
|--------|---------|-------------|
| `allow_insecure_inbound` | true | Accept connections without password |
| `allow_insecure_outbound` | false | Make outbound connections without password |
| `insecure_inbound_receive_only` | true | Insecure sessions can only deliver mail, not pick up |
| `require_allowlist_for_insecure` | false | Only allow insecure from nodes in allowlist |
| `max_insecure_sessions_per_hour` | 10 | Rate limit per remote address |
| `crashmail.enabled` | true | Enable crashmail processing |
| `crashmail.use_nodelist_for_routing` | true | Look up destination in nodelist |
| `allow_transit_mail` | false | Forward mail not addressed to us |

---

## Insecure Binkp Session Handling

### Session Flow for Insecure Inbound

```
Remote Node                          BinktermPHP Server
    |                                       |
    |-------- TCP Connect ----------------->|
    |                                       |
    |<------- M_NUL (SYS, VER, etc) --------|
    |-------- M_NUL (SYS, VER, etc) ------->|
    |                                       |
    |<------- M_ADR (our address) ----------|
    |-------- M_ADR (their address) ------->|
    |                                       |
    |-------- M_PWD (empty or "-") -------->|  <- Empty password = insecure
    |                                       |
    |         [Check insecure allowed]      |
    |         [Check allowlist if required] |
    |         [Check rate limits]           |
    |                                       |
    |<------- M_OK "insecure" --------------|  <- Indicate insecure session
    |                                       |
    |-------- [Send files] ---------------->|  <- Receive-only mode
    |                                       |
    |<------- M_EOB ------------------------|
    |-------- M_EOB ----------------------->|
```

### BinkpSession Modifications

**File:** `src/Binkp/Protocol/BinkpSession.php`

Add new properties:

```php
class BinkpSession
{
    // Existing properties...

    // New properties for insecure session support
    private $isInsecureSession = false;
    private $insecureReceiveOnly = true;
    private $sessionType = 'secure';  // 'secure', 'insecure', 'crash_outbound'
```

Add new methods:

```php
    /**
     * Check if this is an insecure (unauthenticated) session
     */
    public function isInsecureSession(): bool
    {
        return $this->isInsecureSession;
    }

    /**
     * Get the session type for logging
     */
    public function getSessionType(): string
    {
        return $this->sessionType;
    }

    /**
     * Handle authentication for insecure session request
     */
    private function handleInsecureAuth(): bool
    {
        // Check if insecure sessions allowed
        if (!$this->config->getAllowInsecureInbound()) {
            $this->log("Insecure session rejected - disabled in config", 'WARNING');
            return false;
        }

        // Check allowlist if required
        if ($this->config->getRequireAllowlistForInsecure()) {
            if (!$this->isNodeInInsecureAllowlist($this->remoteAddress)) {
                $this->log("Insecure session rejected - not in allowlist: {$this->remoteAddress}", 'WARNING');
                return false;
            }
        }

        // Check rate limits
        if (!$this->checkInsecureRateLimit()) {
            $this->log("Insecure session rejected - rate limit exceeded", 'WARNING');
            return false;
        }

        $this->isInsecureSession = true;
        $this->insecureReceiveOnly = $this->config->getInsecureReceiveOnly();
        $this->sessionType = 'insecure';
        $this->log("Insecure session accepted for {$this->remoteAddress}", 'INFO');
        return true;
    }

    /**
     * Check if node is in insecure allowlist
     */
    private function isNodeInInsecureAllowlist(string $address): bool
    {
        $db = \BinktermPHP\Database::getInstance()->getPdo();
        $stmt = $db->prepare("
            SELECT id FROM binkp_insecure_nodes
            WHERE address = ? AND is_active = TRUE
        ");
        $stmt->execute([$address]);
        return $stmt->fetch() !== false;
    }

    /**
     * Check rate limit for insecure sessions
     */
    private function checkInsecureRateLimit(): bool
    {
        $maxPerHour = $this->config->getMaxInsecureSessionsPerHour();
        if ($maxPerHour <= 0) {
            return true; // No limit
        }

        $db = \BinktermPHP\Database::getInstance()->getPdo();
        $stmt = $db->prepare("
            SELECT COUNT(*) as count FROM binkp_session_log
            WHERE remote_address = ?
            AND session_type = 'insecure'
            AND started_at > NOW() - INTERVAL '1 hour'
        ");
        $stmt->execute([$this->remoteAddress]);
        $result = $stmt->fetch();

        return ($result['count'] ?? 0) < $maxPerHour;
    }
```

Modify `validatePassword()`:

```php
    private function validatePassword($password)
    {
        // Check for empty password (insecure session request)
        if (empty($password) || $password === '-') {
            return $this->handleInsecureAuth();
        }

        // Normal password validation for secure sessions
        $expectedPassword = $this->getPasswordForRemote();
        $match = $password === $expectedPassword;

        if ($match) {
            $this->sessionType = 'secure';
        }

        return $match;
    }
```

Modify file sending to respect receive-only mode:

```php
    private function sendOutboundFiles()
    {
        // Don't send files in insecure receive-only mode
        if ($this->isInsecureSession && $this->insecureReceiveOnly) {
            $this->log("Insecure session - skipping outbound files (receive-only)", 'DEBUG');
            return;
        }

        // Existing file sending logic...
    }
```

---

## Crashmail Processing

### Outbound Crashmail Service

**New File:** `src/Crashmail/CrashmailService.php`

```php
<?php
namespace BinktermPHP\Crashmail;

use BinktermPHP\Database;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Nodelist\NodelistManager;

class CrashmailService
{
    private $db;
    private $config;
    private $nodelistManager;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
        $this->config = BinkpConfig::getInstance();
        $this->nodelistManager = new NodelistManager();
    }

    /**
     * Queue a netmail message for crash delivery
     */
    public function queueCrashmail(int $netmailId): bool
    {
        // Get the netmail
        $stmt = $this->db->prepare("SELECT * FROM netmail WHERE id = ?");
        $stmt->execute([$netmailId]);
        $netmail = $stmt->fetch();

        if (!$netmail) {
            return false;
        }

        // Resolve destination
        $routeInfo = $this->resolveDestination($netmail['to_address']);

        $stmt = $this->db->prepare("
            INSERT INTO crashmail_queue
            (netmail_id, destination_address, destination_host, destination_port)
            VALUES (?, ?, ?, ?)
            ON CONFLICT (netmail_id) DO UPDATE SET
                status = 'pending',
                attempts = 0,
                next_attempt_at = CURRENT_TIMESTAMP
        ");

        return $stmt->execute([
            $netmailId,
            $netmail['to_address'],
            $routeInfo['hostname'] ?? null,
            $routeInfo['port'] ?? 24554
        ]);
    }

    /**
     * Resolve destination address to connection info
     */
    public function resolveDestination(string $address): array
    {
        // First check if we have an uplink configured for this address
        $uplink = $this->config->getUplinkByAddress($address);
        if ($uplink) {
            return [
                'hostname' => $uplink['hostname'],
                'port' => $uplink['port'] ?? 24554,
                'password' => $uplink['password'] ?? '',
                'source' => 'uplink'
            ];
        }

        // Check nodelist if enabled
        if ($this->config->getCrashmailUseNodelist()) {
            $nodeInfo = $this->nodelistManager->getCrashRouteInfo($address);
            if ($nodeInfo && $nodeInfo['hostname']) {
                return [
                    'hostname' => $nodeInfo['hostname'],
                    'port' => $nodeInfo['port'] ?? 24554,
                    'password' => '',  // Insecure delivery
                    'source' => 'nodelist'
                ];
            }
        }

        return [
            'hostname' => null,
            'port' => 24554,
            'password' => '',
            'source' => 'unknown'
        ];
    }

    /**
     * Process the crashmail queue
     */
    public function processQueue(): array
    {
        $results = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'deferred' => 0
        ];

        // Get pending items ready for attempt
        $stmt = $this->db->prepare("
            SELECT cq.*, n.*
            FROM crashmail_queue cq
            JOIN netmail n ON cq.netmail_id = n.id
            WHERE cq.status IN ('pending', 'attempting')
            AND cq.next_attempt_at <= CURRENT_TIMESTAMP
            AND cq.attempts < cq.max_attempts
            ORDER BY cq.created_at
            LIMIT 10
        ");
        $stmt->execute();

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
        }

        try {
            // Attempt binkp connection
            $client = new \BinktermPHP\Binkp\Protocol\BinkpClient();
            $success = $client->connectAndDeliverCrash(
                $queueItem['destination_address'],
                $queueItem['destination_host'],
                $queueItem['destination_port'],
                $queueItem  // The netmail data
            );

            if ($success) {
                $this->markSent($queueId);
                return true;
            } else {
                $this->markRetry($queueId, "Delivery failed");
                return false;
            }

        } catch (\Exception $e) {
            $this->markRetry($queueId, $e->getMessage());
            return false;
        }
    }

    private function updateQueueStatus(int $queueId, string $status): void
    {
        $stmt = $this->db->prepare("
            UPDATE crashmail_queue SET status = ?, last_attempt_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$status, $queueId]);
    }

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
            UPDATE netmail SET attributes = attributes | 0x0008, is_sent = TRUE
            WHERE id = (SELECT netmail_id FROM crashmail_queue WHERE id = ?)
        ");
        $stmt->execute([$queueId]);
    }

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
    }

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
    }
}
```

### Crashmail Poller Script

**New File:** `scripts/crashmail_poll.php`

```php
#!/usr/bin/env php
<?php
/**
 * Crashmail Queue Processor
 *
 * Run via cron every 5 minutes:
 * */5 * * * * php /path/to/binkterm/scripts/crashmail_poll.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Crashmail\CrashmailService;

$service = new CrashmailService();
$results = $service->processQueue();

$timestamp = date('Y-m-d H:i:s');
echo "[{$timestamp}] Crashmail queue processed: ";
echo "total={$results['processed']}, ";
echo "success={$results['success']}, ";
echo "failed={$results['failed']}, ";
echo "deferred={$results['deferred']}\n";

exit($results['failed'] > 0 ? 1 : 0);
```

---

## Nodelist Integration for Crash Routing

**File:** `src/Nodelist/NodelistManager.php` (additions)

```php
    /**
     * Get connection info for crash delivery from nodelist
     *
     * @param string $address FTN address (e.g., "1:123/456")
     * @return array|null Connection info or null if not found
     */
    public function getCrashRouteInfo(string $address): ?array
    {
        $node = $this->getNodeByAddress($address);
        if (!$node) {
            return null;
        }

        $flags = is_string($node['flags'])
            ? json_decode($node['flags'], true)
            : ($node['flags'] ?? []);

        $hostname = null;
        $port = 24554;

        // Check for IBN (Internet BinkP Node) flag
        // Format: IBN or IBN:hostname or IBN:hostname:port
        if (isset($flags['IBN'])) {
            $ibn = $flags['IBN'];
            if ($ibn === true || $ibn === '1') {
                // Just IBN flag, need to use INA for hostname
                $hostname = $flags['INA'] ?? null;
            } elseif (strpos($ibn, ':') !== false) {
                $parts = explode(':', $ibn);
                $hostname = $parts[0];
                $port = isset($parts[1]) ? (int)$parts[1] : 24554;
            } else {
                $hostname = $ibn;
            }
        }
        // Check for INA (Internet Address) flag
        elseif (isset($flags['INA'])) {
            $hostname = $flags['INA'];
        }
        // Check for IP flag (legacy)
        elseif (isset($flags['IP'])) {
            $hostname = $flags['IP'];
        }

        if (!$hostname) {
            return null;
        }

        return [
            'address' => $address,
            'hostname' => $hostname,
            'port' => $port,
            'system_name' => $node['system_name'] ?? '',
            'sysop_name' => $node['sysop_name'] ?? '',
            'flags' => $flags,
            'source' => 'nodelist'
        ];
    }
```

---

## Incoming Crashmail Handling

**File:** `src/BinkdProcessor.php` (modifications)

```php
    /**
     * Store a netmail message, handling crash and transit flags
     */
    private function storeNetmail($message, $isInbound = true)
    {
        $attributes = $message['attributes'] ?? 0;

        // Check for crash flag
        $isCrash = ($attributes & 0x0002) !== 0;

        // Check if message is in-transit (not addressed to us)
        $isInTransit = ($attributes & 0x0020) !== 0;

        // Log crash mail receipt
        if ($isCrash) {
            error_log("[BINKD] Received crashmail from {$message['fromAddress']} to {$message['toAddress']}");
        }

        // Handle transit mail
        if ($isInTransit) {
            return $this->handleTransitMail($message);
        }

        // Check if this is addressed to one of our users
        $userId = $this->findUserByAddress($message['toAddress']);

        // If not for us and not transit, it might need forwarding
        if (!$userId && $this->shouldForwardMail($message)) {
            return $this->handleTransitMail($message);
        }

        // Normal netmail storage continues...
        // [existing code]
    }

    /**
     * Handle mail that needs to be forwarded (transit)
     */
    private function handleTransitMail($message)
    {
        $config = BinkpConfig::getInstance();

        if (!$config->getAllowTransitMail()) {
            error_log("[BINKD] Transit mail rejected - disabled: {$message['fromAddress']} -> {$message['toAddress']}");
            return false;
        }

        // Store temporarily and queue for outbound
        // Mark with InTransit flag
        $message['attributes'] = ($message['attributes'] ?? 0) | 0x0020;

        // Queue for outbound delivery
        // This could use crashmail queue or normal outbound depending on attributes
        $this->queueOutboundNetmail($message);

        error_log("[BINKD] Transit mail queued: {$message['fromAddress']} -> {$message['toAddress']}");
        return true;
    }

    /**
     * Determine if mail should be forwarded
     */
    private function shouldForwardMail($message): bool
    {
        // Check if destination is in our routing table
        // or if we're configured to forward for certain zones/nets
        return false; // Default: don't forward
    }
```

---

## MessageHandler Modifications

**File:** `src/MessageHandler.php` (additions)

```php
    /**
     * Create a netmail with crash flag
     */
    public function sendNetmailWithCrash(array $data): array
    {
        // Set crash attribute
        $data['attributes'] = ($data['attributes'] ?? 0x0001) | 0x0002;

        // Send normally
        $result = $this->sendNetmail($data);

        if ($result['success']) {
            // Queue for crash delivery
            $crashService = new \BinktermPHP\Crashmail\CrashmailService();
            $crashService->queueCrashmail($result['netmail_id']);
        }

        return $result;
    }

    /**
     * Parse FLAGS kludge and return attribute integer
     */
    public function parseFlagsKludge(string $flags): int
    {
        $attributes = 0;
        $flagMap = [
            'PVT' => 0x0001,
            'CRA' => 0x0002,
            'DIR' => 0x0002,
            'IMM' => 0x0002,
            'RCV' => 0x0004,
            'SNT' => 0x0008,
            'K/S' => 0x0080,
            'LOC' => 0x0100,
            'HLD' => 0x0200,
            'FRQ' => 0x0800,
            'RRQ' => 0x1000,
            'CFM' => 0x2000,
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
     * Generate FLAGS kludge from attributes
     */
    public function generateFlagsKludge(int $attributes): string
    {
        $flags = [];

        if ($attributes & 0x0001) $flags[] = 'PVT';
        if ($attributes & 0x0002) $flags[] = 'CRA';
        if ($attributes & 0x0004) $flags[] = 'RCV';
        if ($attributes & 0x0008) $flags[] = 'SNT';
        if ($attributes & 0x0080) $flags[] = 'K/S';
        if ($attributes & 0x0100) $flags[] = 'LOC';
        if ($attributes & 0x0200) $flags[] = 'HLD';
        if ($attributes & 0x0800) $flags[] = 'FRQ';
        if ($attributes & 0x1000) $flags[] = 'RRQ';
        if ($attributes & 0x2000) $flags[] = 'CFM';

        return implode(' ', $flags);
    }
```

---

## Session Logging

**New File:** `src/Binkp/SessionLogger.php`

```php
<?php
namespace BinktermPHP\Binkp;

use BinktermPHP\Database;

class SessionLogger
{
    private $db;
    private $sessionId;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    public function startSession(
        string $remoteAddress,
        string $remoteIp,
        string $sessionType,
        bool $isInbound
    ): int {
        $stmt = $this->db->prepare("
            INSERT INTO binkp_session_log
            (remote_address, remote_ip, session_type, is_inbound, status)
            VALUES (?, ?::inet, ?, ?, 'active')
            RETURNING id
        ");
        $stmt->execute([$remoteAddress, $remoteIp, $sessionType, $isInbound]);
        $this->sessionId = $stmt->fetchColumn();
        return $this->sessionId;
    }

    public function updateStats(
        int $messagesReceived,
        int $messagesSent,
        int $filesReceived,
        int $filesSent,
        int $bytesReceived,
        int $bytesSent
    ): void {
        if (!$this->sessionId) return;

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

    public function endSession(string $status, ?string $errorMessage = null): void
    {
        if (!$this->sessionId) return;

        $stmt = $this->db->prepare("
            UPDATE binkp_session_log SET
                status = ?,
                error_message = ?,
                ended_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$status, $errorMessage, $this->sessionId]);
    }
}
```

---

## UI Additions

### Compose Netmail - Crash Option

**File:** `templates/compose_netmail.twig` (additions)

```html
<div class="mb-3">
    <div class="form-check">
        <input type="checkbox" class="form-check-input" id="crashMail" name="crash_mail" value="1">
        <label class="form-check-label" for="crashMail">
            <i class="bi bi-lightning-charge text-warning"></i> Send as Crash Mail
        </label>
        <div class="form-text">
            Attempt immediate direct delivery to the destination node.
            Requires the destination to be reachable and accept connections.
        </div>
    </div>
</div>

<div class="mb-3">
    <div class="form-check">
        <input type="checkbox" class="form-check-input" id="holdMail" name="hold_mail" value="1">
        <label class="form-check-label" for="holdMail">
            <i class="bi bi-pause-circle text-secondary"></i> Hold for Pickup
        </label>
        <div class="form-text">
            Don't send this message until the recipient's system connects to pick it up.
        </div>
    </div>
</div>
```

### Netmail View - Attribute Badges

**File:** `templates/netmail_view.twig` (additions)

```html
<div class="message-attributes mb-2">
    {% if message.attributes & 0x0001 %}
        <span class="badge bg-primary" title="Private message">
            <i class="bi bi-lock"></i> Private
        </span>
    {% endif %}
    {% if message.attributes & 0x0002 %}
        <span class="badge bg-warning text-dark" title="Crash - Immediate delivery">
            <i class="bi bi-lightning-charge"></i> Crash
        </span>
    {% endif %}
    {% if message.attributes & 0x0008 %}
        <span class="badge bg-success" title="Message has been sent">
            <i class="bi bi-check-circle"></i> Sent
        </span>
    {% endif %}
    {% if message.attributes & 0x0080 %}
        <span class="badge bg-danger" title="Delete after sending">
            <i class="bi bi-trash"></i> Kill/Sent
        </span>
    {% endif %}
    {% if message.attributes & 0x0200 %}
        <span class="badge bg-secondary" title="Hold for pickup">
            <i class="bi bi-pause-circle"></i> Hold
        </span>
    {% endif %}
    {% if message.attributes & 0x0020 %}
        <span class="badge bg-info" title="In transit">
            <i class="bi bi-arrow-right-circle"></i> Transit
        </span>
    {% endif %}
</div>
```

### Admin - Insecure Nodes Management

**New File:** `templates/admin/insecure_nodes.twig`

```html
{% extends 'admin/layout.twig' %}

{% block content %}
<div class="container-fluid">
    <h2><i class="bi bi-shield-exclamation"></i> Insecure Node Allowlist</h2>

    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i>
        Nodes in this list can connect without password authentication.
        Use with caution - insecure sessions should typically be receive-only.
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-plus-circle"></i> Add Node
        </div>
        <div class="card-body">
            <form id="addNodeForm">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">FTN Address</label>
                        <input type="text" class="form-control" name="address"
                               placeholder="1:123/456" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control" name="description"
                               placeholder="Node description">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Max Messages</label>
                        <input type="number" class="form-control" name="max_messages"
                               value="100" min="1" max="1000">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus"></i> Add to Allowlist
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <i class="bi bi-list"></i> Allowed Nodes
        </div>
        <div class="card-body">
            <table class="table table-striped" id="nodesTable">
                <thead>
                    <tr>
                        <th>Address</th>
                        <th>Description</th>
                        <th>Receive</th>
                        <th>Send</th>
                        <th>Max Msgs</th>
                        <th>Last Session</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Populated via JavaScript -->
                </tbody>
            </table>
        </div>
    </div>
</div>
{% endblock %}
```

### Admin - Crashmail Queue

**New File:** `templates/admin/crashmail_queue.twig`

```html
{% extends 'admin/layout.twig' %}

{% block content %}
<div class="container-fluid">
    <h2><i class="bi bi-lightning-charge"></i> Crashmail Queue</h2>

    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-bg-warning">
                <div class="card-body">
                    <h5 class="card-title">Pending</h5>
                    <p class="card-text display-6" id="pendingCount">0</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-bg-info">
                <div class="card-body">
                    <h5 class="card-title">Attempting</h5>
                    <p class="card-text display-6" id="attemptingCount">0</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-bg-success">
                <div class="card-body">
                    <h5 class="card-title">Sent (24h)</h5>
                    <p class="card-text display-6" id="sentCount">0</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-bg-danger">
                <div class="card-body">
                    <h5 class="card-title">Failed</h5>
                    <p class="card-text display-6" id="failedCount">0</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list"></i> Queue Items</span>
            <button class="btn btn-sm btn-outline-primary" id="refreshQueue">
                <i class="bi bi-arrow-clockwise"></i> Refresh
            </button>
        </div>
        <div class="card-body">
            <table class="table table-striped" id="queueTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>To</th>
                        <th>Subject</th>
                        <th>Destination</th>
                        <th>Status</th>
                        <th>Attempts</th>
                        <th>Next Attempt</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Populated via JavaScript -->
                </tbody>
            </table>
        </div>
    </div>
</div>
{% endblock %}
```

### Admin - Session Log

**New File:** `templates/admin/binkp_sessions.twig`

```html
{% extends 'admin/layout.twig' %}

{% block content %}
<div class="container-fluid">
    <h2><i class="bi bi-journal-text"></i> Binkp Session Log</h2>

    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-funnel"></i> Filters
        </div>
        <div class="card-body">
            <form id="filterForm" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Session Type</label>
                    <select class="form-select" name="session_type">
                        <option value="">All</option>
                        <option value="secure">Secure</option>
                        <option value="insecure">Insecure</option>
                        <option value="crash_outbound">Crash Outbound</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="">All</option>
                        <option value="success">Success</option>
                        <option value="failed">Failed</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Remote Address</label>
                    <input type="text" class="form-control" name="remote_address"
                           placeholder="1:123/456">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <table class="table table-striped table-sm" id="sessionsTable">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Remote</th>
                        <th>IP</th>
                        <th>Type</th>
                        <th>Dir</th>
                        <th>Msgs In</th>
                        <th>Msgs Out</th>
                        <th>Status</th>
                        <th>Duration</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Populated via JavaScript -->
                </tbody>
            </table>
        </div>
    </div>
</div>
{% endblock %}
```

---

## API Routes

**File:** `routes/api-routes.php` (additions)

```php
// Insecure nodes management (admin only)
$router->get('/api/admin/insecure-nodes', 'AdminController@getInsecureNodes');
$router->post('/api/admin/insecure-nodes', 'AdminController@addInsecureNode');
$router->put('/api/admin/insecure-nodes/{id}', 'AdminController@updateInsecureNode');
$router->delete('/api/admin/insecure-nodes/{id}', 'AdminController@deleteInsecureNode');

// Crashmail queue management (admin only)
$router->get('/api/admin/crashmail-queue', 'AdminController@getCrashmailQueue');
$router->post('/api/admin/crashmail-queue/{id}/retry', 'AdminController@retryCrashmail');
$router->delete('/api/admin/crashmail-queue/{id}', 'AdminController@cancelCrashmail');
$router->get('/api/admin/crashmail-queue/stats', 'AdminController@getCrashmailStats');

// Session log (admin only)
$router->get('/api/admin/binkp-sessions', 'AdminController@getBinkpSessions');
$router->get('/api/admin/binkp-sessions/stats', 'AdminController@getBinkpSessionStats');

// User netmail with crash option
$router->post('/api/netmail/send-crash', 'MessageController@sendCrashNetmail');
```

---

## Files Summary

### New Files

| File | Purpose |
|------|---------|
| `database/migrations/v1.7.1_add_crashmail_support.sql` | Database schema |
| `src/Crashmail/CrashmailService.php` | Crashmail queue and delivery logic |
| `src/Binkp/SessionLogger.php` | Binkp session logging |
| `scripts/crashmail_poll.php` | CLI script for cron-based delivery |
| `templates/admin/insecure_nodes.twig` | Insecure allowlist management |
| `templates/admin/crashmail_queue.twig` | Crashmail queue viewer |
| `templates/admin/binkp_sessions.twig` | Session log viewer |

### Modified Files

| File | Changes |
|------|---------|
| `src/Binkp/Protocol/BinkpSession.php` | Insecure session handling |
| `src/Binkp/Config/BinkpConfig.php` | New config options |
| `src/BinkdProcessor.php` | Crash/transit mail handling |
| `src/MessageHandler.php` | FLAGS kludge parsing, crash sending |
| `src/Nodelist/NodelistManager.php` | Crash route resolution |
| `routes/api-routes.php` | New admin endpoints |
| `templates/compose_netmail.twig` | Crash/hold checkboxes |
| `templates/netmail_view.twig` | Attribute badges |
| `templates/admin/nav.twig` | New admin menu items |

---

## Security Considerations

### Insecure Sessions

1. **Receive-Only Default**: Insecure sessions only accept incoming mail by default
2. **Allowlist Option**: Optionally restrict to nodes in allowlist
3. **Rate Limiting**: Prevent abuse with configurable limits
4. **Full Logging**: Every insecure session logged with IP address
5. **No Echomail**: Insecure sessions should not exchange echomail
6. **Message Limits**: Cap messages per session to prevent flooding
7. **Admin Alerts**: Consider alerting on unusual insecure activity

### Crashmail

1. **Destination Verification**: Only attempt delivery to valid FTN addresses
2. **Retry Limits**: Prevent infinite retry loops
3. **Resource Limits**: Limit concurrent crash connections
4. **Nodelist Trust**: Only use nodelist for routing if from trusted source
5. **Password Preference**: Use password auth when available

### Transit Mail

1. **Disabled by Default**: Transit routing requires explicit enable
2. **Route Verification**: Only forward to known destinations
3. **Loop Prevention**: Check for routing loops via Path kludge
4. **Audit Trail**: Log all transit mail for troubleshooting

---

## Verification Steps

### Crashmail Testing

1. Enable crashmail in `config/binkp.json`
2. Compose netmail with "Crash" option checked
3. Verify message stored with attribute 0x0003 (Private + Crash)
4. Check `crashmail_queue` table for new entry
5. Run `php scripts/crashmail_poll.php`
6. Check logs for connection attempt to destination
7. If reachable: verify delivery and `sent` status
8. If unreachable: verify retry scheduled
9. After max attempts: verify `failed` status

### Insecure Session Testing

1. Enable insecure inbound in `config/binkp.json`
2. (Optional) Add test node to allowlist
3. From another system, connect with empty password
4. Verify `M_OK "insecure"` response received
5. Send test netmail packet
6. Verify message received and stored correctly
7. Verify file send requests are denied (receive-only)
8. Check `binkp_session_log` for insecure session record
9. Test rate limiting by exceeding configured limit

### Attribute Display Testing

1. Receive netmail with various FLAGS kludges
2. Verify attributes parsed and stored correctly
3. View netmail - verify correct badges displayed
4. Send netmail with Crash/Hold options
5. Verify FLAGS kludge generated in outbound packet

---

## References

- [FTS-0001](http://ftsc.org/docs/fts-0001.016) - FidoNet Packet Format
- [FSC-0053](http://ftsc.org/docs/fsc-0053.002) - FLAGS kludge
- [FTS-1026](http://ftsc.org/docs/fts-1026.001) - Binkp/1.0 Protocol
- [FSP-1011](http://ftsc.org/docs/fsp-1011.001) - Binkp/1.1 Extensions
