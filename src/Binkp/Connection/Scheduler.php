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


namespace BinktermPHP\Binkp\Connection;

use BinktermPHP\Advertising;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Admin\AdminDaemonClient;
use BinktermPHP\Crashmail\CrashmailService;
use BinktermPHP\Database;
use BinktermPHP\Hub\HubNodeManager;

class Scheduler
{
    /**
     * Must match BinkpSession::HUB_OUTBOUND_MAX_ATTEMPTS - the two caps
     * gate the same hub_node_outbound.attempts value from different sides
     * (deciding whether to dial out vs. deciding what to send once connected).
     */
    private const HUB_OUTBOUND_MAX_ATTEMPTS = 10;

    /** @var array<string,int> Unix timestamps of last outbound-triggered polls by uplink */
    private $lastOutboundPollTimes;
    /** @var array<string,bool> Whether an uplink had outbound work on the previous scan */
    private $outboundQueueActiveStates;
    private $config;
    private $logger;
    private $client;
    private $lastPollTimes;
    private $crashmailService;
    private $db;
    /** @var int Unix timestamp of last crashmail poll run */
    private $lastCrashmailPoll = 0;
    /**
     * Uplink addresses that received a scheduled poll in the current daemon loop
     * iteration.  Reset at the top of each iteration so pollIfOutbound() skips
     * same-iteration duplicates (binkp sessions are bidirectional) without
     * permanently blocking outbound delivery between iterations.
     *
     * @var array<string,bool>
     */
    private $iterationPolledAddresses = [];
    /** Minimum seconds between scheduled crashmail polls */
    const CRASHMAIL_POLL_INTERVAL = 300;

    public function __construct($config = null, $logger = null)
    {
        $this->config = $config ?: BinkpConfig::getInstance();
        $this->logger = $logger;
        $this->client = new AdminDaemonClient();
        $this->lastPollTimes = [];
        $this->lastOutboundPollTimes = [];
        $this->outboundQueueActiveStates = [];
        $this->iterationPolledAddresses = [];
        $this->crashmailService = new CrashmailService();
        $this->db = Database::getInstance()->getPdo();
    }
    
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }
    
    public function log($message, $level = 'INFO')
    {
        if ($this->logger) {
            $this->logger->log($level, "[SCHEDULER] {$message}");
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] [{$level}] [SCHEDULER] {$message}\n";
        }
    }

    private function refreshConfig(): void
    {
        try {
            $this->config->reloadConfig();
        } catch (\Throwable $e) {
            $this->log("Failed to reload binkp configuration: " . $e->getMessage(), 'ERROR');
        }
    }
    
    public function checkScheduledPolls()
    {
        $uplinks = $this->config->getEnabledUplinks();
        $pollsDue = [];

        $this->log("Checking schedules for " . count($uplinks) . " uplinks", 'DEBUG');
        
        foreach ($uplinks as $uplink) {
            $address = $uplink['address'];
            $schedule = $uplink['poll_schedule'] ?? '0 */4 * * *';
            
            $isDue = $this->isScheduleDue($schedule, $address);
            $this->log("Schedule check: {$address} ({$schedule}) due=" . ($isDue ? 'yes' : 'no'), 'DEBUG');

            if ($isDue) {
                $pollsDue[] = $uplink;
            }
        }
        
        return $pollsDue;
    }
    
    public function processScheduledPolls()
    {
        $pollsDue = $this->checkScheduledPolls();
        $results = [];

        if (empty($pollsDue)) {
            $this->log("No scheduled polls due at this time", 'DEBUG');
        }
        
        foreach ($pollsDue as $uplink) {
            $address = $uplink['address'];
            
            try {
                $this->log("Scheduled poll starting for: {$address}");
                $pollResult = $this->client->binkPoll($address);
                $pollSuccess = ($pollResult['exit_code'] ?? 1) === 0;
                $processResult = null;

                if ($pollSuccess) {
                    $this->log("Scheduled packet processing starting for: {$address}");
                    $processResult = $this->client->processPackets();
                    $pollSuccess = ($processResult['exit_code'] ?? 1) === 0;
                }

                $this->lastPollTimes[$address] = time();
                $this->lastOutboundPollTimes[$address] = time();
                // Record that this uplink was polled in the current iteration so
                // pollIfOutbound() can skip it and avoid a duplicate connection.
                // The flag is reset at the top of each runDaemon() iteration.
                $this->iterationPolledAddresses[$address] = true;
                $results[$address] = [
                    'success' => $pollSuccess,
                    'poll_result' => $pollResult,
                    'process_packets' => $processResult
                ];
                $this->log("Scheduled poll completed for: {$address}");
                
            } catch (\Exception $e) {
                $this->log("Scheduled poll failed for {$address}: " . $e->getMessage(), 'ERROR');
                $results[$address] = [
                    'success' => false,
                    'error_code' => 'errors.binkp.uplink.poll_failed',
                    'error' => 'Failed to poll BinkP uplink'
                ];
            }
        }
        
        return $results;
    }

    public function processAdvertisingCampaigns(bool $dryRun = false): array
    {
        try {
            $advertising = new Advertising();
            $results = $advertising->processDueCampaigns(null, $dryRun);

            if ($results === []) {
                $this->log("No ad campaigns due at this time", 'DEBUG');
            } else {
                $successCount = count(array_filter($results, static fn(array $result): bool => ($result['status'] ?? '') === 'success'));
                $dryRunCount = count(array_filter($results, static fn(array $result): bool => ($result['status'] ?? '') === 'dry-run'));
                $failureCount = count(array_filter($results, static fn(array $result): bool => ($result['status'] ?? '') === 'failed'));
                $skippedCount = count(array_filter($results, static fn(array $result): bool => ($result['status'] ?? '') === 'skipped'));

                foreach ($results as $result) {
                    $campaign = (string)($result['campaign_name'] ?? ('Campaign #' . ($result['campaign_id'] ?? '?')));
                    $target = (string)($result['target'] ?? '-');
                    $ad = (string)($result['advertisement_title'] ?? '-');
                    $subject = (string)($result['subject'] ?? '-');
                    $status = (string)($result['status'] ?? 'unknown');
                    $scheduleTime = (string)($result['schedule_time_of_day'] ?? '-');
                    $scheduleTimezone = (string)($result['schedule_timezone'] ?? '-');
                    $scheduleSlotAt = (string)($result['schedule_slot_at'] ?? '-');
                    $scheduleLocal = $scheduleSlotAt;
                    if ($scheduleSlotAt !== '-' && $scheduleTimezone !== '-') {
                        try {
                            $scheduleLocal = (new \DateTimeImmutable($scheduleSlotAt))
                                ->setTimezone(new \DateTimeZone($scheduleTimezone))
                                ->format('D Y-m-d H:i T');
                        } catch (\Throwable $e) {
                            $scheduleLocal = $scheduleSlotAt;
                        }
                    }
                    $scheduleLabel = "schedule=\"{$scheduleTime} {$scheduleTimezone}\" local_slot=\"{$scheduleLocal}\"";

                    if ($status === 'failed') {
                        $error = (string)($result['error'] ?? 'Unknown error');
                        $this->log("Ad campaign failed: campaign=\"{$campaign}\" target=\"{$target}\" ad=\"{$ad}\" subject=\"{$subject}\" {$scheduleLabel} error=\"{$error}\"", 'ERROR');
                    } elseif ($status === 'success') {
                        $this->log("Ad campaign posted: campaign=\"{$campaign}\" target=\"{$target}\" ad=\"{$ad}\" subject=\"{$subject}\" {$scheduleLabel}", 'INFO');
                    } elseif ($status === 'dry-run') {
                        $this->log("Ad campaign dry-run: campaign=\"{$campaign}\" target=\"{$target}\" ad=\"{$ad}\" subject=\"{$subject}\" {$scheduleLabel}", 'INFO');
                    } elseif ($status === 'skipped') {
                        $reason = (string)($result['reason'] ?? 'No reason provided');
                        $this->log("Ad campaign skipped: campaign=\"{$campaign}\" target=\"{$target}\" {$scheduleLabel} reason=\"{$reason}\"", 'WARNING');
                    } else {
                        $this->log("Ad campaign result: campaign=\"{$campaign}\" target=\"{$target}\" {$scheduleLabel} status=\"{$status}\"", 'INFO');
                    }
                }

                $this->log("Processed " . count($results) . " ad campaign posts ({$successCount} success, {$dryRunCount} dry-run, {$failureCount} failed, {$skippedCount} skipped)");
            }

            return $results;
        } catch (\Throwable $e) {
            $this->log("Ad campaign processing error: " . $e->getMessage(), 'ERROR');
            return [];
        }
    }
    
    public function pollIfOutbound()
    {
        $outboundPath = $this->config->getOutboundPath();
        $files = array_merge(
            glob($outboundPath . '/*.pkt') ?: [],
            glob($outboundPath . '/*.PKT') ?: [],
            glob($outboundPath . '/*.tic') ?: [],
            glob($outboundPath . '/*.TIC') ?: []
        );
        
        if (empty($files)) {
            $this->outboundQueueActiveStates = [];
            $this->log("No outbound packets found, skipping outbound poll", 'DEBUG');
            return [];
        }
        
        $this->log("Found " . count($files) . " outbound files, checking which uplinks need polling");
        
        $uplinks = $this->config->getEnabledUplinks();
        $results = [];
        $uplinksToPoll = [];
        
        foreach ($uplinks as $uplink) {
            $address = $uplink['address'];

            $hasOutboundFiles = $this->hasOutboundFilesForUplink($uplink, $files);
            if (!$hasOutboundFiles) {
                $this->outboundQueueActiveStates[$address] = false;
                $this->log("No outbound files for uplink {$address}, skipping outbound poll", 'DEBUG');
                continue;
            }

            $hadOutboundFiles = $this->outboundQueueActiveStates[$address] ?? false;
            $this->outboundQueueActiveStates[$address] = true;

            // A scheduled poll already ran for this uplink in the current iteration.
            // Binkp sessions are bidirectional, so the outbound files should have
            // been transmitted during that connection — no second connection needed.
            if (!empty($this->iterationPolledAddresses[$address])) {
                $this->log("Uplink {$address} already polled this iteration, skipping outbound poll", 'DEBUG');
                continue;
            }

            $queueTimerSeconds = $this->config->getOutboundQueueTimerMinutes() * 60;
            $lastOutboundPoll = $this->lastOutboundPollTimes[$address] ?? 0;
            if ($hadOutboundFiles && $lastOutboundPoll > 0) {
                $elapsed = time() - $lastOutboundPoll;
                if ($elapsed < $queueTimerSeconds) {
                    $remaining = $queueTimerSeconds - $elapsed;
                    $this->log("Outbound files found for {$address} but queue timer has {$remaining}s remaining", 'DEBUG');
                    continue;
                }
            }

            $uplinksToPoll[] = $uplink;
        }

        if (empty($uplinksToPoll)) {
            $this->log("Outbound files found, but no uplinks currently require polling", 'DEBUG');
            return [];
        }

        foreach ($uplinksToPoll as $uplink) {
            $address = $uplink['address'];
            $this->log("Triggering outbound poll for uplink {$address}");
            
            try {
                $pollResult = $this->client->binkPoll($address);
                $pollSuccess = ($pollResult['exit_code'] ?? 1) === 0;
                $processResult = null;

                if ($pollSuccess) {
                    $this->log("Outbound packet processing starting for: {$address}");
                    $processResult = $this->client->processPackets();
                    $pollSuccess = ($processResult['exit_code'] ?? 1) === 0;
                }

                $this->lastOutboundPollTimes[$address] = time();
                $results[$address] = [
                    'success' => $pollSuccess,
                    'poll_result' => $pollResult,
                    'process_packets' => $processResult
                ];
                $this->log("Outbound poll completed for: {$address}");
                
            } catch (\Exception $e) {
                $this->log("Outbound poll failed for {$address}: " . $e->getMessage(), 'ERROR');
                $results[$address] = [
                    'success' => false,
                    'error_code' => 'errors.binkp.uplink.poll_failed',
                    'error' => 'Failed to poll BinkP uplink'
                ];
            }
        }
        
        return $results;
    }
    
    private function isScheduleDue($cronExpression, $address)
    {
        $lastPoll = $this->lastPollTimes[$address] ?? 0;
        $now = time();
        
        if ($now - $lastPoll < 60) {
            return false;
        }
        
        return $this->parseCronExpression($cronExpression, $now);
    }
    
    private function parseCronExpression($cronExpression, $now)
    {
        $parts = preg_split('/\s+/', trim($cronExpression), -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) !== 5) {
            $this->log("Invalid cron expression: {$cronExpression}", 'WARNING');
            return false;
        }
        
        list($minute, $hour, $day, $month, $weekday) = $parts;
        
        $currentTime = [
            'minute' => (int) date('i', $now),
            'hour' => (int) date('G', $now),
            'day' => (int) date('j', $now),
            'month' => (int) date('n', $now),
            'weekday' => (int) date('w', $now)
        ];

        return $this->matchesCronField($minute, $currentTime['minute']) &&
               $this->matchesCronField($hour, $currentTime['hour']) &&
               $this->matchesCronField($day, $currentTime['day']) &&
               $this->matchesCronField($month, $currentTime['month']) &&
               $this->matchesCronField($weekday, $currentTime['weekday']);
    }
    
    private function matchesCronField($cronField, $currentValue)
    {
        if ($cronField === '*') {
            return true;
        }
        
        if (strpos($cronField, '/') !== false) {
            list($range, $step) = explode('/', $cronField, 2);
            $step = (int) $step;
            
            if ($range === '*') {
                return ($currentValue % $step) === 0;
            }
            
            if (strpos($range, '-') !== false) {
                list($start, $end) = explode('-', $range, 2);
                return $currentValue >= (int) $start && 
                       $currentValue <= (int) $end && 
                       (($currentValue - (int) $start) % $step) === 0;
            }
            
            return ($currentValue % $step) === 0;
        }
        
        if (strpos($cronField, '-') !== false) {
            list($start, $end) = explode('-', $cronField, 2);
            return $currentValue >= (int) $start && $currentValue <= (int) $end;
        }
        
        if (strpos($cronField, ',') !== false) {
            $values = explode(',', $cronField);
            return in_array((string) $currentValue, $values);
        }
        
        return (int) $cronField === $currentValue;
    }

    private function hasOutboundFilesForUplink(array $uplink, array $files): bool
    {
        foreach ($files as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }

            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($extension === 'pkt') {
                $destAddr = $this->getPacketDestination($file);
                if ($destAddr !== null && $this->config->isDestinationForUplink($destAddr, $uplink)) {
                    return true;
                }
                continue;
            }

            if ($extension === 'tic') {
                $destAddr = $this->getTicDestination($file);
                if ($destAddr !== null && $this->config->isDestinationForUplink($destAddr, $uplink)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function getPacketDestination(string $filePath): ?string
    {
        $handle = @fopen($filePath, 'rb');
        if (!$handle) {
            $this->log("Cannot open outbound packet for destination lookup: " . basename($filePath), 'WARNING');
            return null;
        }

        $header = fread($handle, 58);
        fclose($handle);

        if (strlen($header) < 58) {
            $this->log("Outbound packet header too short: " . basename($filePath), 'WARNING');
            return null;
        }

        $data = unpack('vorigNode/vdestNode', substr($header, 0, 4));
        $netData = unpack('vorigNet/vdestNet', substr($header, 20, 4));
        if (!$data || !$netData) {
            $this->log("Failed to parse outbound packet header: " . basename($filePath), 'WARNING');
            return null;
        }

        $destZone = 1;
        if (strlen($header) >= 38) {
            $zoneData = unpack('vorigZone/vdestZone', substr($header, 34, 4));
            if ($zoneData && $zoneData['destZone'] > 0) {
                $destZone = $zoneData['destZone'];
            }
        }

        return $destZone . ':' . $netData['destNet'] . '/' . $data['destNode'];
    }

    private function getTicDestination(string $filePath): ?string
    {
        $content = @file_get_contents($filePath);
        if ($content === false) {
            $this->log("Cannot read outbound TIC for destination lookup: " . basename($filePath), 'WARNING');
            return null;
        }

        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            $line = trim($line);
            if (preg_match('/^To\s+(.+)$/i', $line, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    private function keepDbAlive(): void
    {
        try {
            $this->db->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->log("Database keepalive failed: " . $e->getMessage(), 'ERROR');
            if ($this->refreshDatabaseConnection()) {
                try {
                    $this->db->query('SELECT 1');
                    $this->log("Database keepalive recovered after reconnect", 'DEBUG');
                } catch (\Throwable $retryError) {
                    $this->log("Database keepalive retry failed: " . $retryError->getMessage(), 'ERROR');
                }
            }
        }
    }

    private function refreshDatabaseConnection(): bool
    {
        try {
            $this->db = Database::reconnect()->getPdo();
            $this->crashmailService = new CrashmailService();
            $this->log("Database connection refreshed", 'DEBUG');
            return true;
        } catch (\Throwable $e) {
            $this->log("Database reconnect failed: " . $e->getMessage(), 'ERROR');
        }
        return false;
    }
    
    public function runDaemon($interval = 60)
    {
        $this->log("Scheduler daemon started (interval: {$interval}s)");
        
        while (true) {
            try {
                $this->iterationPolledAddresses = [];
                $this->refreshConfig();
                $this->keepDbAlive();

                $results = $this->processScheduledPolls();
                if (!empty($results)) {
                    $this->log("Processed " . count($results) . " scheduled polls");
                }
                
                $outboundResults = $this->pollIfOutbound();
                if (!empty($outboundResults)) {
                    $this->log("Processed outbound poll for " . count($outboundResults) . " uplinks");
                }

                $this->processInboundIfNeeded();

                $this->runScheduledCrashmailPoll();
                $this->runScheduledHubNodePush();
                $this->processAdvertisingCampaigns();
                
            } catch (\Exception $e) {
                $this->log("Scheduler error: " . $e->getMessage(), 'ERROR');
            }
            
            sleep($interval);
        }
    }

    /**
     * Run the crashmail poll if enabled, there are pending items, and the
     * minimum interval (CRASHMAIL_POLL_INTERVAL seconds) has elapsed since
     * the last scheduled run.
     */
    private function runScheduledCrashmailPoll(): void
    {
        if (!$this->config->getCrashmailEnabled()) {
            $this->log("Crashmail disabled, skipping crashmail poll", 'DEBUG');
            return;
        }

        $now = time();
        $elapsed = $now - $this->lastCrashmailPoll;
        if ($elapsed < self::CRASHMAIL_POLL_INTERVAL) {
            $remaining = self::CRASHMAIL_POLL_INTERVAL - $elapsed;
            $this->log("Crashmail poll not due yet ({$remaining}s remaining)", 'DEBUG');
            return;
        }

        try {
            $stats = $this->crashmailService->getQueueStats();
            $totalPending = (int)($stats['pending'] ?? 0) + (int)($stats['attempting'] ?? 0);

            if ($totalPending === 0) {
                $this->log("No crashmail queued, skipping crashmail poll", 'DEBUG');
            } else {
                $this->log("Crashmail poll starting ({$totalPending} pending)");
                $result = $this->client->crashmailPoll();
                if (($result['exit_code'] ?? 1) === 0) {
                    $this->log("Crashmail poll completed");
                } else {
                    $this->log("Crashmail poll failed", 'ERROR');
                }
            }

            // Update timestamp regardless of whether there were items, so the
            // 5-minute window resets from the last check, not from the last delivery.
            $this->lastCrashmailPoll = $now;
        } catch (\Throwable $e) {
            $this->log("Crashmail poll error: " . $e->getMessage(), 'ERROR');
        }
    }

    /**
     * Push pending hub_node_outbound packets to any enabled, non-held,
     * push-eligible (allow_outbound, inet_host set) hub node that has
     * pending work, gated per-node by hub_nodes.push_poll_interval_minutes
     * (elapsed since hub_nodes.last_push_at). Applies to both node- and
     * point-type hub nodes - points are pull-only by default (no
     * inet_host), but a point given a routable inet_host (see the field's
     * help text on the downlink edit form) is push-eligible too.
     */
    private function runScheduledHubNodePush(): void
    {
        try {
            $db = Database::getInstance()->getPdo();
            // Failed rows are retried up to the same attempts cap as
            // BinkpSession::HUB_OUTBOUND_MAX_ATTEMPTS so a node whose only
            // work is a failed (e.g. interrupted) send still gets redialed,
            // not just nodes with fresh 'pending' rows.
            $stmt = $db->prepare("
                SELECT DISTINCT hn.id, hn.node_address
                FROM hub_nodes hn
                JOIN hub_node_outbound hno ON hno.hub_node_id = hn.id
                WHERE (hno.status = 'pending' OR (hno.status = 'failed' AND hno.attempts < ?))
                  AND hn.enabled = TRUE
                  AND hn.allow_outbound = TRUE
                  AND hn.hold_mail = FALSE
                  AND hn.inet_host IS NOT NULL
                  AND hn.inet_host <> ''
                  AND (hn.last_push_at IS NULL OR hn.last_push_at <= NOW() - (hn.push_poll_interval_minutes || ' minutes')::interval)
            ");
            $stmt->execute([self::HUB_OUTBOUND_MAX_ATTEMPTS]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            if (empty($rows)) {
                $this->log("No push-eligible hub nodes with pending outbound work", 'DEBUG');
            } else {
                $touchStmt = $db->prepare("UPDATE hub_nodes SET last_push_at = NOW() WHERE id = ?");
                foreach ($rows as $row) {
                    $address = $row['node_address'];
                    $this->log("Hub node push starting for {$address}");
                    $result = $this->client->binkPoll($address);
                    $touchStmt->execute([$row['id']]);
                    if (($result['exit_code'] ?? 1) === 0) {
                        $this->log("Hub node push completed for {$address}");
                    } else {
                        $this->log("Hub node push failed for {$address}", 'ERROR');
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->log("Hub node push check error: " . $e->getMessage(), 'ERROR');
        }
    }

    private function processInboundIfNeeded(): void
    {
        $inboundPath = $this->config->getInboundPath();
        $files = glob($inboundPath . '/*.pkt');

        if (empty($files)) {
            $this->log("No inbound packets found, skipping packet processing", 'DEBUG');
            return;
        }

        $this->log("Found " . count($files) . " inbound packets, processing", 'DEBUG');
        $processResult = $this->client->processPackets();
        $success = ($processResult['exit_code'] ?? 1) === 0;
        if ($success) {
            $this->log("Inbound packet processing completed");
        } else {
            $this->log("Inbound packet processing failed", 'ERROR');
        }
    }
    
    public function getNextScheduledPoll($address)
    {
        $uplink = $this->config->getUplinkByAddress($address);
        if (!$uplink) {
            return null;
        }
        
        $schedule = $uplink['poll_schedule'] ?? '0 */4 * * *';
        $lastPoll = $this->lastPollTimes[$address] ?? 0;
        
        return $this->getNextCronTime($schedule, $lastPoll ?: time());
    }
    
    private function getNextCronTime($cronExpression, $fromTime)
    {
        $parts = explode(' ', $cronExpression);
        if (count($parts) !== 5) {
            return null;
        }
        
        list($minute, $hour, $day, $month, $weekday) = $parts;
        
        for ($i = 0; $i < 60 * 24 * 7; $i++) {
            $checkTime = $fromTime + ($i * 60);
            
            $timeData = [
                'minute' => (int) date('i', $checkTime),
                'hour' => (int) date('G', $checkTime),
                'day' => (int) date('j', $checkTime),
                'month' => (int) date('n', $checkTime),
                'weekday' => (int) date('w', $checkTime)
            ];

            if ($this->matchesCronField($minute, $timeData['minute']) &&
                $this->matchesCronField($hour, $timeData['hour']) &&
                $this->matchesCronField($day, $timeData['day']) &&
                $this->matchesCronField($month, $timeData['month']) &&
                $this->matchesCronField($weekday, $timeData['weekday'])) {
                return $checkTime;
            }
        }
        
        return null;
    }
    
    public function getScheduleStatus()
    {
        $uplinks = $this->config->getUplinks();
        $status = [];
        
        foreach ($uplinks as $uplink) {
            $address = $uplink['address'];
            $schedule = $uplink['poll_schedule'] ?? '0 */4 * * *';
            $lastPoll = $this->lastPollTimes[$address] ?? 0;
            $nextPoll = $this->getNextScheduledPoll($address);
            
            $status[$address] = [
                'address' => $address,
                'schedule' => $schedule,
                'enabled' => $uplink['enabled'] ?? true,
                'last_poll' => $this->formatStatusTimestamp($lastPoll, 'Never'),
                'next_poll' => $this->formatStatusTimestamp($nextPoll, 'Unknown'),
                'due_now' => $this->isScheduleDue($schedule, $address)
            ];
        }
        
        return $status;
    }

    /**
     * Per-node push schedule status for hub_nodes (downlinks), analogous to
     * getScheduleStatus() for uplinks. Push eligibility mirrors the gating
     * in runScheduledHubNodePush(): enabled, allow_outbound, not held, and
     * a routable inet_host.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getHubNodeScheduleStatus(): array
    {
        $status = [];

        foreach ((new HubNodeManager($this->db))->getAll() as $hubNode) {
            $address = $hubNode['node_address'];
            $intervalMinutes = (int)$hubNode['push_poll_interval_minutes'];
            $pushEligible = $hubNode['enabled'] && $hubNode['allow_outbound'] && !$hubNode['hold_mail'] && !empty($hubNode['inet_host']);

            $lastPushAt = $hubNode['last_push_at'] ?? null;
            $lastPushTimestamp = $lastPushAt ? strtotime($lastPushAt) : 0;
            $nextPushTimestamp = $lastPushTimestamp > 0 ? $lastPushTimestamp + ($intervalMinutes * 60) : 0;

            $status[$address] = [
                'address' => $address,
                'node_type' => $hubNode['node_type'],
                'interval_minutes' => $intervalMinutes,
                'enabled' => $hubNode['enabled'],
                'push_eligible' => $pushEligible,
                'last_push' => $this->formatStatusTimestamp($lastPushTimestamp, 'Never'),
                'next_push' => $pushEligible ? $this->formatStatusTimestamp($nextPushTimestamp, 'Due now') : 'N/A (pull-only or disabled)',
                'due_now' => $pushEligible && ($lastPushTimestamp <= 0 || $nextPushTimestamp <= time()),
            ];
        }

        return $status;
    }

    private function formatStatusTimestamp(int $timestamp, string $fallback): string
    {
        if ($timestamp <= 0) {
            return $fallback;
        }

        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}

