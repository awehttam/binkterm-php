<?php

namespace BinktermPHP\Binkp\Connection;

use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Admin\AdminDaemonClient;
use BinktermPHP\Crashmail\CrashmailService;
use BinktermPHP\Database;

class Scheduler
{
    private $config;
    private $logger;
    private $client;
    private $lastPollTimes;
    private $crashmailService;
    private $db;
    
    public function __construct($config = null, $logger = null)
    {
        $this->config = $config ?: BinkpConfig::getInstance();
        $this->logger = $logger;
        $this->client = new AdminDaemonClient();
        $this->lastPollTimes = [];
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
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    public function pollIfOutbound()
    {
        $outboundPath = $this->config->getOutboundPath();
        $files = glob($outboundPath . '/*.pkt');
        
        if (empty($files)) {
            $this->log("No outbound packets found, skipping outbound poll", 'DEBUG');
            return [];
        }
        
        $this->log("Found " . count($files) . " outbound files, triggering poll");
        
        $uplinks = $this->config->getEnabledUplinks();
        $results = [];
        
        foreach ($uplinks as $uplink) {
            $address = $uplink['address'];
            
            try {
                $pollResult = $this->client->binkPoll($address);
                $pollSuccess = ($pollResult['exit_code'] ?? 1) === 0;
                $processResult = null;

                if ($pollSuccess) {
                    $this->log("Outbound packet processing starting for: {$address}");
                    $processResult = $this->client->processPackets();
                    $pollSuccess = ($processResult['exit_code'] ?? 1) === 0;
                }

                $this->lastPollTimes[$address] = time();
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
                    'error' => $e->getMessage()
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
        $parts = explode(' ', $cronExpression);
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

    private function keepDbAlive(): void
    {
        try {
            $this->db->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->log("Database keepalive failed: " . $e->getMessage(), 'ERROR');
            $this->refreshDatabaseConnection();
        }
    }

    private function refreshDatabaseConnection(): void
    {
        try {
            $this->db = Database::reconnect()->getPdo();
            $this->crashmailService = new CrashmailService();
            $this->log("Database connection refreshed", 'DEBUG');
        } catch (\Throwable $e) {
            $this->log("Database reconnect failed: " . $e->getMessage(), 'ERROR');
        }
    }
    
    public function runDaemon($interval = 60)
    {
        $this->log("Scheduler daemon started (interval: {$interval}s)");
        
        while (true) {
            try {
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

                if (!$this->config->getCrashmailEnabled()) {
                    $this->log("Crashmail disabled, skipping crashmail poll", 'DEBUG');
                } else {
                    try {
                        $stats = $this->crashmailService->getQueueStats();
                        $pending = (int)($stats['pending'] ?? 0);
                        $attempting = (int)($stats['attempting'] ?? 0);
                        $totalPending = $pending + $attempting;

                        if ($totalPending === 0) {
                            $this->log("No crashmail queued, skipping crashmail poll", 'DEBUG');
                        } else {
                            $this->log("Crashmail poll starting", 'DEBUG');
                            $crashmailResult = $this->client->crashmailPoll();
                            $crashmailSuccess = ($crashmailResult['exit_code'] ?? 1) === 0;
                            if ($crashmailSuccess) {
                                $this->log("Crashmail poll completed");
                            } else {
                                $this->log("Crashmail poll failed", 'ERROR');
                            }
                        }
                    } catch (\Throwable $e) {
                        $this->log("Crashmail queue check failed: " . $e->getMessage(), 'ERROR');
                    }
                }
                
            } catch (\Exception $e) {
                $this->log("Scheduler error: " . $e->getMessage(), 'ERROR');
            }
            
            sleep($interval);
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
            print_r($timeData);
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
                'last_poll' => $lastPoll ? date('Y-m-d H:i:s', $lastPoll) : 'Never',
                'next_poll' => $nextPoll ? date('Y-m-d H:i:s', $nextPoll) : 'Unknown',
                'due_now' => $this->isScheduleDue($schedule, $address)
            ];
        }
        
        return $status;
    }
}
