#!/usr/bin/env php
<?php

/**
 * freq_pickup.php - Connect to a remote node to pick up queued FREQ files
 *
 * Use this when you have sent a FREQ request to a node that cannot reach you
 * via crashmail. The remote system will queue the files for you; run this
 * script to connect outbound and collect them.
 *
 * Usage:
 *   php scripts/freq_pickup.php <ftn-address> [options]
 *
 * Examples:
 *   php scripts/freq_pickup.php 1:123/456
 *   php scripts/freq_pickup.php 1:123/456 --hostname=bbs.example.com
 *   php scripts/freq_pickup.php 1:123/456 --hostname=bbs.example.com --port=24554
 *   php scripts/freq_pickup.php 1:123/456 --password=secret
 */

chdir(__DIR__ . '/../');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Binkp\Protocol\BinkpClient;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Binkp\Logger;
use BinktermPHP\Nodelist\NodelistManager;

// ---------------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------------

function parseArgs(array $argv): array
{
    $opts = [];
    $positional = [];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (strpos($arg, '--') === 0) {
            if (strpos($arg, '=') !== false) {
                [$key, $value] = explode('=', substr($arg, 2), 2);
                $opts[$key] = $value;
            } else {
                $opts[substr($arg, 2)] = true;
            }
        } else {
            $positional[] = $arg;
        }
    }

    return [$opts, $positional];
}

function showUsage(): void
{
    echo "Usage: php scripts/freq_pickup.php <ftn-address> [options]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --hostname=HOST   Hostname or IP to connect to (auto-resolved from nodelist if omitted)\n";
    echo "  --port=PORT       Port number (default: 24554)\n";
    echo "  --password=PASS   Session password (default: none)\n";
    echo "  --log-level=LVL   DEBUG, INFO, WARNING, ERROR (default: INFO)\n";
    echo "  --help            Show this help\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php scripts/freq_pickup.php 1:123/456\n";
    echo "  php scripts/freq_pickup.php 1:123/456 --hostname=bbs.example.com\n";
    echo "  php scripts/freq_pickup.php 1:123/456 --hostname=bbs.example.com --port=24554 --password=secret\n";
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

[$opts, $positional] = parseArgs($argv);

if (isset($opts['help']) || empty($positional)) {
    showUsage();
    exit(empty($positional) ? 1 : 0);
}

$address  = $positional[0];
$hostname = $opts['hostname'] ?? null;
$port     = isset($opts['port']) ? (int)$opts['port'] : 24554;
$password = $opts['password'] ?? '';
$logLevel = $opts['log-level'] ?? 'INFO';

// Validate FTN address format
if (!preg_match('/^\d+:\d+\/\d+(\.\d+)?$/', $address)) {
    echo "Error: '{$address}' is not a valid FTN address (expected zone:net/node or zone:net/node.point)\n";
    exit(1);
}

try {
    $config = BinkpConfig::getInstance();
    $logger = new Logger(\BinktermPHP\Config::getLogPath('binkp_poll.log'), $logLevel, true);
    $db     = \BinktermPHP\Database::getInstance()->getPdo();

    // ------------------------------------------------------------------
    // Resolve hostname from nodelist if not supplied
    // ------------------------------------------------------------------
    if (!$hostname) {
        echo "Looking up {$address} in nodelist...\n";
        $nodelistManager = new NodelistManager();
        $routeInfo = (new \BinktermPHP\Crashmail\CrashmailService())->resolveDestination($address);
        if (!empty($routeInfo['hostname'])) {
            $hostname = $routeInfo['hostname'];
            $port     = $routeInfo['port'] ?? $port;
            $sysName  = $routeInfo['system_name'] ?? '';
            echo "Resolved: {$hostname}:{$port}" . ($sysName ? " ({$sysName})" : '') . "\n";
        } else {
            echo "Error: Cannot resolve hostname for {$address}.\n";
            echo "The node may not have an IBN/INA flag in the nodelist.\n";
            echo "Use --hostname=<host> to specify the address manually.\n";
            exit(1);
        }
    }

    // ------------------------------------------------------------------
    // Show what's waiting for us on the remote (from our local outbound)
    // This is informational — freq_outbound is on the REMOTE system.
    // We just show any packets we have queued outbound for that address.
    // ------------------------------------------------------------------
    $outboundPath = $config->getOutboundPath();
    $outboundPkts = glob($outboundPath . '/*.pkt') ?: [];

    echo "\nConnecting to {$address} at {$hostname}:{$port}...\n";
    if (!empty($outboundPkts)) {
        echo "Note: " . count($outboundPkts) . " outbound packet(s) will also be sent during this session.\n";
    }
    echo "\n";

    // ------------------------------------------------------------------
    // Connect — BinkpSession::sendFreqFiles() runs at session start and
    // will collect any files the remote has queued for our address.
    // ------------------------------------------------------------------
    $client = new BinkpClient($config, $logger);
    $result = $client->connect($address, $hostname, $port, $password);

    echo "\n";
    if ($result['success']) {
        $received = $result['files_received'] ?? [];
        $sent     = $result['files_sent']     ?? [];

        if (!empty($received)) {
            echo "Files received (" . count($received) . "):\n";
            foreach ($received as $f) {
                echo "  {$f}\n";
            }
        } else {
            echo "No files received. The remote may not have any queued for you,\n";
            echo "or your FREQ has not been processed yet.\n";
        }

        if (!empty($sent)) {
            echo "\nFiles sent (" . count($sent) . "):\n";
            foreach ($sent as $f) {
                echo "  {$f}\n";
            }
        }

        echo "\nSession completed successfully.\n";
        exit(0);
    } else {
        echo "Session failed: " . ($result['error'] ?? 'unknown error') . "\n";
        exit(1);
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
