#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Binkp\Protocol\BinkpClient;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Binkp\Connection\Scheduler;
use BinktermPHP\Binkp\Queue\InboundQueue;
use BinktermPHP\Binkp\Queue\OutboundQueue;

function showUsage()
{
    echo "Usage: php binkp_status.php [options]\n";
    echo "Options:\n";
    echo "  --uplinks         Show uplink status\n";
    echo "  --schedule        Show polling schedule status\n";
    echo "  --queues          Show queue status\n";
    echo "  --daemons         Show daemon status\n";
    echo "  --config          Show configuration\n";
    echo "  --all             Show all status information (default)\n";
    echo "  --json            Output in JSON format\n";
    echo "  --help            Show this help message\n";
    echo "\n";
}

function parseArgs($argv)
{
    $args = [];
    
    foreach ($argv as $arg) {
        if (strpos($arg, '--') === 0) {
            if (strpos($arg, '=') !== false) {
                list($key, $value) = explode('=', substr($arg, 2), 2);
                $args[$key] = $value;
            } else {
                $args[substr($arg, 2)] = true;
            }
        }
    }
    
    return $args;
}

function formatBytes($bytes)
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    
    return number_format($bytes, 2) . ' ' . $units[$i];
}

function showUplinkStatus($config, $client, $json = false)
{
    $status = $client->getUplinkStatus();
    
    if ($json) {
        return ['uplinks' => $status];
    }
    
    echo "=== UPLINK STATUS ===\n";
    
    if (empty($status)) {
        echo "No uplinks configured.\n";
        return [];
    }
    
    foreach ($status as $address => $uplink) {
        $enabled = ($uplink['enabled'] ?? true) ? 'Enabled' : 'Disabled';
        $connStatus = isset($uplink['success']) ? 
            ($uplink['success'] ? 'Online' : 'Offline') : 'Unknown';
        
        echo "\n{$address}:\n";
        echo "  Hostname: {$uplink['hostname']}\n";
        echo "  Port: " . ($uplink['port'] ?? 24554) . "\n";
        echo "  Status: {$enabled} / {$connStatus}\n";
        
        if (isset($uplink['connect_time'])) {
            echo "  Response time: " . number_format($uplink['connect_time'] * 1000, 1) . "ms\n";
        }
        
        if (isset($uplink['error'])) {
            echo "  Error: {$uplink['error']}\n";
        }
        
        if (isset($uplink['last_test'])) {
            echo "  Last test: {$uplink['last_test']}\n";
        }
    }
    
    return ['uplinks' => $status];
}

function showScheduleStatus($config, $scheduler, $json = false)
{
    $status = $scheduler->getScheduleStatus();
    
    if ($json) {
        return ['schedule' => $status];
    }
    
    echo "\n=== POLLING SCHEDULE ===\n";
    
    if (empty($status)) {
        echo "No scheduled polls configured.\n";
        return ['schedule' => []];
    }
    
    foreach ($status as $address => $info) {
        $dueStatus = $info['due_now'] ? 'DUE NOW' : 'Scheduled';
        $enabledStatus = $info['enabled'] ? 'Enabled' : 'Disabled';
        
        echo "\n{$address}:\n";
        echo "  Schedule: {$info['schedule']}\n";
        echo "  Status: {$enabledStatus} / {$dueStatus}\n";
        echo "  Last poll: {$info['last_poll']}\n";
        echo "  Next poll: {$info['next_poll']}\n";
    }
    
    return ['schedule' => $status];
}

function showQueueStatus($inboundQueue, $outboundQueue, $json = false)
{
    $inboundStats = $inboundQueue->getStats();
    $outboundStats = $outboundQueue->getStats();
    
    if ($json) {
        return [
            'inbound_queue' => $inboundStats,
            'outbound_queue' => $outboundStats
        ];
    }
    
    echo "\n=== QUEUE STATUS ===\n";
    
    echo "\nInbound Queue:\n";
    echo "  Pending files: {$inboundStats['pending_files']}\n";
    echo "  Error files: {$inboundStats['error_files']}\n";
    echo "  Path: {$inboundStats['inbound_path']}\n";
    
    echo "\nOutbound Queue:\n";
    echo "  Pending files: {$outboundStats['pending_files']}\n";
    echo "  Total messages: {$outboundStats['total_messages']}\n";
    echo "  Total size: " . formatBytes($outboundStats['total_size']) . "\n";
    echo "  Path: {$outboundStats['outbound_path']}\n";
    
    return [
        'inbound_queue' => $inboundStats,
        'outbound_queue' => $outboundStats
    ];
}

function showConfig($config, $json = false)
{
    $fullConfig = $config->getFullConfig();
    
    if ($json) {
        return ['config' => $fullConfig];
    }
    
    echo "\n=== CONFIGURATION ===\n";
    
    echo "\nSystem:\n";
    echo "  Address: {$fullConfig['system']['address']}\n";
    echo "  Sysop: {$fullConfig['system']['sysop']}\n";
    echo "  Location: {$fullConfig['system']['location']}\n";
    echo "  Hostname: {$fullConfig['system']['hostname']}\n";
    
    echo "\nBinkp:\n";
    echo "  Port: {$fullConfig['binkp']['port']}\n";
    echo "  Timeout: {$fullConfig['binkp']['timeout']}s\n";
    echo "  Max connections: {$fullConfig['binkp']['max_connections']}\n";
    echo "  Bind address: {$fullConfig['binkp']['bind_address']}\n";
    echo "  Inbound path: {$fullConfig['binkp']['inbound_path']}\n";
    echo "  Outbound path: {$fullConfig['binkp']['outbound_path']}\n";
    
    echo "\nUplinks: " . count($fullConfig['uplinks']) . " configured\n";
    
    return ['config' => $fullConfig];
}

function showDaemonStatus($json = false)
{
    $runDir = __DIR__ . '/../data/run';
    $pidFiles = [
        'admin_daemon' => $runDir . '/admin_daemon.pid',
        'binkp_scheduler' => $runDir . '/binkp_scheduler.pid',
        'binkp_server' => $runDir . '/binkp_server.pid'
    ];

    $status = [];

    foreach ($pidFiles as $name => $pidFile) {
        $pid = null;
        $running = false;

        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            if ($pid !== '' && is_numeric($pid)) {
                $running = function_exists('posix_kill') ? @posix_kill((int)$pid, 0) : false;
            }
        }

        $status[$name] = [
            'pid_file' => $pidFile,
            'pid' => $pid ?: null,
            'running' => $running
        ];
    }

    if ($json) {
        return ['daemons' => $status];
    }

    echo "\n=== DAEMON STATUS ===\n";
    foreach ($status as $name => $info) {
        $state = $info['running'] ? 'RUNNING' : 'STOPPED';
        $pidLabel = $info['pid'] ?: 'n/a';
        echo "\n{$name}:\n";
        echo "  PID file: {$info['pid_file']}\n";
        echo "  PID: {$pidLabel}\n";
        echo "  Status: {$state}\n";
    }

    return ['daemons' => $status];
}

$args = parseArgs($argv);

if (isset($args['help'])) {
    showUsage();
    exit(0);
}

$json = isset($args['json']);
$showAll = isset($args['all']) || (!isset($args['uplinks']) && !isset($args['schedule']) && !isset($args['queues']) && !isset($args['config']) && !isset($args['daemons']));

try {
    $config = BinkpConfig::getInstance();
    $client = new BinkpClient($config);
    $scheduler = new Scheduler($config);
    $inboundQueue = new InboundQueue($config);
    $outboundQueue = new OutboundQueue($config);
    
    $output = [];
    
    if ($showAll || isset($args['config'])) {
        $result = showConfig($config, $json);
        $output = array_merge($output, $result);
    }
    
    if ($showAll || isset($args['uplinks'])) {
        $result = showUplinkStatus($config, $client, $json);
        $output = array_merge($output, $result);
    }
    
    if ($showAll || isset($args['schedule'])) {
        $result = showScheduleStatus($config, $scheduler, $json);
        $output = array_merge($output, $result);
    }
    
    if ($showAll || isset($args['queues'])) {
        $result = showQueueStatus($inboundQueue, $outboundQueue, $json);
        $output = array_merge($output, $result);
    }

    if ($showAll || isset($args['daemons'])) {
        $result = showDaemonStatus($json);
        $output = array_merge($output, $result);
    }
    
    if ($json) {
        echo json_encode($output, JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "\n";
    }
    
} catch (Exception $e) {
    if ($json) {
        echo json_encode(['error' => $e->getMessage()]) . "\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
    exit(1);
}
