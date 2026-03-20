#!/usr/bin/env php
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
    echo "  --queued-only     Only connect to uplink if queued packets are available\n";
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
    // Non-quiet callers should use logResult() instead.
    return '';
}

function logResult($result, $logger, $address = null)
{
    $prefix = $address ? "[{$address}] " : '';
    if ($result['success']) {
        $logger->log('INFO', $prefix . 'SUCCESS');
        if (isset($result['remote_address'])) {
            $logger->log('INFO', $prefix . "  Remote: {$result['remote_address']}");
        }
        if (isset($result['auth_method'])) {
            $logger->log('INFO', $prefix . "  Auth: {$result['auth_method']}");
        }
        if (isset($result['files_sent']) && !empty($result['files_sent'])) {
            $logger->log('INFO', $prefix . '  Files sent: ' . implode(', ', $result['files_sent']));
        }
        if (isset($result['files_received']) && !empty($result['files_received'])) {
            $logger->log('INFO', $prefix . '  Files received: ' . implode(', ', $result['files_received']));
        }
        if (isset($result['connect_time'])) {
            $logger->log('INFO', $prefix . '  Connection time: ' . number_format($result['connect_time'], 3) . 's');
        }
    } else {
        $logger->log('ERROR', $prefix . 'FAILED: ' . ($result['error'] ?? 'Unknown error'));
    }
}

list($args, $positional) = parseArgs($argv);

if (isset($args['help'])) {
    showUsage();
    exit(0);
}

try {
    $config = BinkpConfig::getInstance();
    $queued_only=false;
    $logLevel = isset($args['log-level']) ? $args['log-level'] : 'INFO';
    $logFile = isset($args['log-file']) ? $args['log-file'] : \BinktermPHP\Config::getLogPath('binkp_poll.log');
    if(isset($args['queued-only'])){
        $queued_only=true;
    }
    $logToConsole = !isset($args['no-console']);
    $quiet = isset($args['quiet']);
    
    if ($quiet) {
        $logToConsole = false;
    }
    
    $logger = new Logger($logFile, $logLevel, $logToConsole);
    $client = new BinkpClient($config, $logger);

    /**
     * Route any FREQ response files received during a session.
     */
    $routeFreqResponses = function(array $result) use ($logger): void {
        if (empty($result['success']) || empty($result['files_received']) || empty($result['remote_address'])) {
            return;
        }
        try {
            $db     = \BinktermPHP\Database::getInstance()->getPdo();
            $router = new \BinktermPHP\Freq\FreqResponseRouter($db, $logger);
            $router->routeReceivedFiles($result['remote_address'], $result['files_received']);
        } catch (\Exception $e) {
            $logger->log('WARNING', "FREQ response routing failed: " . $e->getMessage());
        }
    };
    
    if (isset($args['all'])) {
        if (!$quiet) $logger->log('INFO', 'Polling all configured uplinks...');

        $results = $client->pollAllUplinks($queued_only);
        
        foreach ($results as $address => $result) {
            $routeFreqResponses($result);
            if ($quiet) {
                echo "{$address}: " . formatResult($result, true) . "\n";
            } else {
                logResult($result, $logger, $address);
            }
        }
        
        $successCount = count(array_filter($results, function($r) { return $r['success']; }));
        $totalCount = count($results);
        
        if (!$quiet) {
            $logger->log('INFO', "Summary: {$successCount}/{$totalCount} successful");
        }
        
        exit($successCount === $totalCount ? 0 : 1);
        
    } elseif (!empty($positional)) {
        $address = $positional[0];
        
        if (isset($args['test'])) {
            if (!$quiet) $logger->log('INFO', "Testing connection to {$address}...");

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
                logResult($result, $logger, $address);
            }

            exit($result['success'] ? 0 : 1);

        } else {
            if (!$quiet) $logger->log('INFO', "Polling {$address}...");
            
            $hostname = isset($args['hostname']) ? $args['hostname'] : null;
            $port = isset($args['port']) ? (int) $args['port'] : null;
            $password = isset($args['password']) ? $args['password'] : null;
            
            $result = $client->connect($address, $hostname, $port, $password);
            $routeFreqResponses($result);

            if ($quiet) {
                echo formatResult($result, true) . "\n";
            } else {
                logResult($result, $logger, $address);
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