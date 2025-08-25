<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Binkp\Protocol\BinkpClient;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Binkp\Logger;

function showUsage()
{
    echo "Usage: php debug_binkp.php [address]\n";
    echo "Debug connection issues with detailed logging\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php debug_binkp.php 1:153/149\n";
    echo "\n";
}

if ($argc < 2) {
    showUsage();
    exit(1);
}

$address = $argv[1];

try {
    $config = BinkpConfig::getInstance();
    
    // Enable debug logging
    $logger = new Logger('data/logs/binkp_debug.log', Logger::LEVEL_DEBUG, true);
    $client = new BinkpClient($config, $logger);
    
    echo "=== BINKP DEBUG SESSION ===\n";
    echo "Target address: {$address}\n";
    
    $uplink = $config->getUplinkByAddress($address);
    if (!$uplink) {
        echo "ERROR: No uplink configuration found for {$address}\n";
        exit(1);
    }
    
    echo "Uplink configuration:\n";
    echo "  Hostname: {$uplink['hostname']}\n";
    echo "  Port: {$uplink['port']}\n";
    echo "  Password: " . (empty($uplink['password']) ? 'NOT SET' : 'CONFIGURED') . "\n";
    echo "  Enabled: " . ($uplink['enabled'] ? 'YES' : 'NO') . "\n";
    echo "\n";
    
    echo "System configuration:\n";
    echo "  Our address: {$config->getSystemAddress()}\n";
    echo "  Sysop: {$config->getSystemSysop()}\n";
    echo "  Hostname: {$config->getSystemHostname()}\n";
    echo "\n";
    
    echo "=== TESTING CONNECTION ===\n";
    
    // First test basic connectivity
    echo "Testing TCP connection...\n";
    $testResult = $client->testConnection($uplink['hostname'], $uplink['port'], 10);
    
    if ($testResult['success']) {
        echo "✓ TCP connection successful ({$testResult['connect_time']}s)\n";
    } else {
        echo "✗ TCP connection failed: {$testResult['error']}\n";
        exit(1);
    }
    
    echo "\n=== ATTEMPTING BINKP HANDSHAKE ===\n";
    
    // Now try the full binkp connection
    try {
        $result = $client->connect($address);
        
        if ($result['success']) {
            echo "✓ Binkp connection successful!\n";
            echo "  Remote address: {$result['remote_address']}\n";
            echo "  Files sent: " . count($result['files_sent']) . "\n";
            echo "  Files received: " . count($result['files_received']) . "\n";
            
            if (!empty($result['files_sent'])) {
                echo "  Sent files: " . implode(', ', $result['files_sent']) . "\n";
            }
            
            if (!empty($result['files_received'])) {
                echo "  Received files: " . implode(', ', $result['files_received']) . "\n";
            }
        } else {
            echo "✗ Binkp connection failed: {$result['error']}\n";
        }
        
    } catch (\Exception $e) {
        echo "✗ Exception during binkp connection: " . $e->getMessage() . "\n";
    }
    
    echo "\n=== DEBUG LOG TAIL ===\n";
    echo "Last 20 lines from debug log:\n";
    $logs = $logger->getRecentLogs(20);
    foreach ($logs as $log) {
        echo $log . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}