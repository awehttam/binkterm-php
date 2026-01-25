#!/usr/bin/php
<?php

chdir(__DIR__."/../");

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Binkp\Protocol\BinkpClient;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Binkp\Logger;

function showUsage()
{
    echo "Usage: php binkp_poll.php [options] [address]\n";
    echo "Options:\n";
    echo "  --all             Poll all configured uplinks\n";
    echo "  --test            Test connection without polling\n";
    echo "  --hostname=HOST   Override hostname for connection\n";
    echo "  --port=PORT       Override port for connection\n";
    echo "  --password=PASS   Override password for connection\n";
    echo "  --log-level=LEVEL Log level: DEBUG, INFO, WARNING, ERROR, CRITICAL\n";
    echo "  --log-file=FILE   Log file path (default: " . \BinktermPHP\Config::getLogPath('binkp_poll.log') . ")\n";
    echo "  --no-console      Disable console logging\n";
    echo "  --quiet           Minimal output\n";
    echo "  --help            Show this help message\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php binkp_poll.php --all\n";
    echo "  php binkp_poll.php 1:123/456\n";
    echo "  php binkp_poll.php --test --hostname=bbs.example.com 1:123/456\n";
    echo "\n";
}

function parseArgs($argv)
{
    $args = [];
    $positional = [];
    
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        if (strpos($arg, '--') === 0) {
            if (strpos($arg, '=') !== false) {
                list($key, $value) = explode('=', substr($arg, 2), 2);
                $args[$key] = $value;
            } else {
                $args[substr($arg, 2)] = true;
            }
        } else {
            $positional[] = $arg;
        }
    }
    
    return [$args, $positional];
}

function formatResult($result, $quiet = false)
{
    if ($quiet) {
        return $result['success'] ? 'OK' : 'FAIL';
    }
    
    if ($result['success']) {
        $output = "SUCCESS\n";
        if (isset($result['remote_address'])) {
            $output .= "  Remote: {$result['remote_address']}\n";
        }
        if (isset($result['files_sent']) && !empty($result['files_sent'])) {
            $output .= "  Files sent: " . implode(', ', $result['files_sent']) . "\n";
        }
        if (isset($result['files_received']) && !empty($result['files_received'])) {
            $output .= "  Files received: " . implode(', ', $result['files_received']) . "\n";
        }
        if (isset($result['connect_time'])) {
            $output .= "  Connection time: " . number_format($result['connect_time'], 3) . "s\n";
        }
        return $output;
    } else {
        return "FAILED: " . ($result['error'] ?? 'Unknown error') . "\n";
    }
}

list($args, $positional) = parseArgs($argv);

if (isset($args['help'])) {
    showUsage();
    exit(0);
}

try {
    $config = BinkpConfig::getInstance();

    $logLevel = isset($args['log-level']) ? $args['log-level'] : 'INFO';
    $logFile = isset($args['log-file']) ? $args['log-file'] : \BinktermPHP\Config::getLogPath('binkp_poll.log');
    $logToConsole = !isset($args['no-console']);
    $quiet = isset($args['quiet']);
    
    if ($quiet) {
        $logToConsole = false;
    }
    
    $logger = new Logger($logFile, $logLevel, $logToConsole);
    $client = new BinkpClient($config, $logger);
    
    if (isset($args['all'])) {
        if (!$quiet) echo "Polling all configured uplinks...\n";
        
        $results = $client->pollAllUplinks();
        
        foreach ($results as $address => $result) {
            if ($quiet) {
                echo "{$address}: " . formatResult($result, true) . "\n";
            } else {
                echo "\n=== {$address} ===\n";
                echo formatResult($result);
            }
        }
        
        $successCount = count(array_filter($results, function($r) { return $r['success']; }));
        $totalCount = count($results);
        
        if (!$quiet) {
            echo "\nSummary: {$successCount}/{$totalCount} successful\n";
        }
        
        exit($successCount === $totalCount ? 0 : 1);
        
    } elseif (!empty($positional)) {
        $address = $positional[0];
        
        if (isset($args['test'])) {
            if (!$quiet) echo "Testing connection to {$address}...\n";

            $uplink = $config->getUplinkByAddress($address);
            $hostname = isset($args['hostname']) ? $args['hostname'] : ($uplink['hostname'] ?? null);
            $port = isset($args['port']) ? (int) $args['port'] : ($uplink['port'] ?? 24554);
            
            if (!$hostname) {
                echo "Error: No hostname specified and no uplink configuration found\n";
                exit(1);
            }
            
            $result = $client->testConnection($hostname, $port);
            
            if ($quiet) {
                echo formatResult($result, true) . "\n";
            } else {
                echo formatResult($result);
            }
            
            exit($result['success'] ? 0 : 1);
            
        } else {
            if (!$quiet) echo "Polling {$address}...\n";
            
            $hostname = isset($args['hostname']) ? $args['hostname'] : null;
            $port = isset($args['port']) ? (int) $args['port'] : null;
            $password = isset($args['password']) ? $args['password'] : null;
            
            $result = $client->connect($address, $hostname, $port, $password);
            
            if ($quiet) {
                echo formatResult($result, true) . "\n";
            } else {
                echo formatResult($result);
            }
            
            exit($result['success'] ? 0 : 1);
        }
        
    } else {
        echo "Error: No address specified and --all not used\n";
        showUsage();
        exit(1);
    }
    
} catch (Exception $e) {
    if (isset($quiet) && $quiet) {
        echo "ERROR\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
    exit(1);
}