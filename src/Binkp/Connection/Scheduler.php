<?php

namespace Binktest\Binkp\Connection;

use Binktest\Binkp\Config\BinkpConfig;
use Binktest\Binkp\Protocol\BinkpClient;

class Scheduler
{
    private $config;
    private $logger;
    private $client;
    private $lastPollTimes;
    
    public function __construct($config = null, $logger = null)
    {
        $this->config = $config ?: BinkpConfig::getInstance();
        $this->logger = $logger;
        $this->client = new BinkpClient($this->config, $this->logger);
        $this->lastPollTimes = [];
    }
    
    public function setLogger($logger)
    {
        $this->logger = $logger;
        $this->client->setLogger($logger);
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
        
        foreach ($uplinks as $uplink) {
            $address = $uplink['address'];
            $schedule = $uplink['poll_schedule'] ?? '0 */4 * * *';
            
            if ($this->isScheduleDue($schedule, $address)) {
                $pollsDue[] = $uplink;
            }
        }
        
        return $pollsDue;
    }
    
    public function processScheduledPolls()
    {
        $pollsDue = $this->checkScheduledPolls();
        $results = [];
        
        foreach ($pollsDue as $uplink) {
            $address = $uplink['address'];
            
            try {
                $this->log("Scheduled poll starting for: {$address}");
                $result = $this->client->pollUplink($address);
                $this->lastPollTimes[$address] = time();
                $results[$address] = $result;
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
            return [];
        }
        
        $this->log("Found " . count($files) . " outbound files, triggering poll");
        
        $uplinks = $this->config->getEnabledUplinks();
        $results = [];
        
        foreach ($uplinks as $uplink) {
            $address = $uplink['address'];
            
            try {
                $result = $this->client->pollUplink($address);
                $this->lastPollTimes[$address] = time();
                $results[$address] = $result;
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
        
        return $this->parseCronExpression($cronExpression, $now, $lastPoll);
    }
    
    private function parseCronExpression($cronExpression, $now, $lastCheck)
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
        
        $lastTime = [
            'minute' => (int) date('i', $lastCheck),
            'hour' => (int) date('G', $lastCheck),
            'day' => (int) date('j', $lastCheck),
            'month' => (int) date('n', $lastCheck),
            'weekday' => (int) date('w', $lastCheck)
        ];
        
        return $this->matchesCronField($minute, $currentTime['minute']) &&
               $this->matchesCronField($hour, $currentTime['hour']) &&
               $this->matchesCronField($day, $currentTime['day']) &&
               $this->matchesCronField($month, $currentTime['month']) &&
               $this->matchesCronField($weekday, $currentTime['weekday']) &&
               !($this->matchesCronField($minute, $lastTime['minute']) &&
                 $this->matchesCronField($hour, $lastTime['hour']) &&
                 $this->matchesCronField($day, $lastTime['day']) &&
                 $this->matchesCronField($month, $lastTime['month']) &&
                 $this->matchesCronField($weekday, $lastTime['weekday']));
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
    
    public function runDaemon($interval = 60)
    {
        $this->log("Scheduler daemon started (interval: {$interval}s)");
        
        while (true) {
            try {
                $results = $this->processScheduledPolls();
                if (!empty($results)) {
                    $this->log("Processed " . count($results) . " scheduled polls");
                }
                
                $outboundResults = $this->pollIfOutbound();
                if (!empty($outboundResults)) {
                    $this->log("Processed outbound poll for " . count($outboundResults) . " uplinks");
                }
                
            } catch (\Exception $e) {
                $this->log("Scheduler error: " . $e->getMessage(), 'ERROR');
            }
            
            sleep($interval);
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
                'last_poll' => $lastPoll ? date('Y-m-d H:i:s', $lastPoll) : 'Never',
                'next_poll' => $nextPoll ? date('Y-m-d H:i:s', $nextPoll) : 'Unknown',
                'due_now' => $this->isScheduleDue($schedule, $address)
            ];
        }
        
        return $status;
    }
}