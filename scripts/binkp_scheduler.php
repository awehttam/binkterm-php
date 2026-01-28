#!/usr/bin/php
<?php

chdir(__DIR__."/../");

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Binkp\Connection\Scheduler;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Binkp\Logger;

function showUsage()
{
    echo "Usage: php binkp_scheduler.php [options]\n";
    echo "Options:\n";
    echo "  --interval=SECONDS   Polling interval in seconds (default: 60)\n";
    echo "  --log-level=LEVEL    Log level: DEBUG, INFO, WARNING, ERROR, CRITICAL\n";
    echo "  --log-file=FILE      Log file path (default: " . \BinktermPHP\Config::getLogPath('binkp_scheduler.log') . ")\n";
    echo "  --no-console         Disable console logging\n";
    echo "  --daemon             Run as daemon (detach from terminal)\n";
    echo "  --pid-file=FILE      Write PID file\n";
    echo "  --once               Run once and exit\n";
    echo "  --status             Show schedule status and exit\n";
    echo "  --help               Show this help message\n";
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

function daemonize()
{
    $pid = pcntl_fork();
    
    if ($pid == -1) {
        die("Could not fork process\n");
    } elseif ($pid) {
        echo "Scheduler started with PID: $pid\n";
        exit(0);
    }
    
    if (posix_setsid() == -1) {
        die("Could not detach from terminal\n");
    }
    
    chdir('/');
    umask(0);
    
    fclose(STDIN);
    fclose(STDOUT);
    fclose(STDERR);
}

$args = parseArgs($argv);
$pidFile = $args['pid-file'] ?? (__DIR__ . '/../data/run/binkp_scheduler.pid');

if (isset($args['help'])) {
    showUsage();
    exit(0);
}

try {
    $config = BinkpConfig::getInstance();
    
    $logLevel = isset($args['log-level']) ? $args['log-level'] : 'INFO';
    $logFile = isset($args['log-file']) ? $args['log-file'] : \BinktermPHP\Config::getLogPath('binkp_scheduler.log');
    $logToConsole = !isset($args['no-console']);
    $interval = isset($args['interval']) ? (int) $args['interval'] : 60;
    
    $logger = new Logger($logFile, $logLevel, $logToConsole);
    $scheduler = new Scheduler($config, $logger);
    
    if (isset($args['status'])) {
        $status = $scheduler->getScheduleStatus();
        
        echo "=== POLLING SCHEDULE STATUS ===\n";
        foreach ($status as $address => $info) {
            $dueStatus = $info['due_now'] ? 'DUE NOW' : 'Scheduled';
            $enabledStatus = $info['enabled'] ? 'Enabled' : 'Disabled';
            
            echo "\n{$address}:\n";
            echo "  Schedule: {$info['schedule']}\n";
            echo "  Status: {$enabledStatus} / {$dueStatus}\n";
            echo "  Last poll: {$info['last_poll']}\n";
            echo "  Next poll: {$info['next_poll']}\n";
        }
        exit(0);
    }
    
    if (isset($args['daemon']) && function_exists('pcntl_fork')) {
        $logger->setLogToConsole(false);
        daemonize();
    }

    if ($pidFile) {
        $pidDir = dirname($pidFile);
        if (!is_dir($pidDir)) {
            @mkdir($pidDir, 0755, true);
        }
        @file_put_contents($pidFile, (string)getmypid());
    }
    
    $logger->info("Starting Binkp scheduler daemon...");
    $logger->info("Polling interval: {$interval} seconds");
    
    $uplinks = $config->getUplinks();
    $logger->info("Configured uplinks: " . count($uplinks));
    
    foreach ($uplinks as $uplink) {
        $status = ($uplink['enabled'] ?? true) ? 'enabled' : 'disabled';
        $schedule = $uplink['poll_schedule'] ?? '0 */4 * * *';
        $logger->info("  - {$uplink['address']} [{$status}] ({$schedule})");
    }
    
    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGTERM, function() use ($logger) {
            $logger->info("Received SIGTERM, shutting down...");
            exit(0);
        });
        
        pcntl_signal(SIGINT, function() use ($logger) {
            $logger->info("Received SIGINT, shutting down...");
            exit(0);
        });
    }
    
    if (isset($args['once'])) {
        $logger->info("Running scheduled polls once...");
        $results = $scheduler->processScheduledPolls();
        
        if (!empty($results)) {
            $successCount = count(array_filter($results, function($r) { return $r['success']; }));
            $totalCount = count($results);
            $logger->info("Processed {$totalCount} scheduled polls ({$successCount} successful)");
        } else {
            $logger->info("No scheduled polls due at this time");
        }
        
        $outboundResults = $scheduler->pollIfOutbound();
        if (!empty($outboundResults)) {
            $successCount = count(array_filter($outboundResults, function($r) { return $r['success']; }));
            $totalCount = count($outboundResults);
            $logger->info("Processed outbound poll for {$totalCount} uplinks ({$successCount} successful)");
        }
        
        exit(0);
    }
    
    $scheduler->runDaemon($interval);
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
