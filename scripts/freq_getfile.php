#!/usr/bin/env php
<?php

/**
 * freq_getfile.php - Request a file from a remote binkp node via FREQ (M_GET).
 *
 * The remote node must have the file available for FREQ. Received files are
 * written to the configured inbound directory.
 *
 * Usage:
 *   php scripts/freq_getfile.php [options] <address> <filename> [filename2 ...]
 *
 * Arguments:
 *   address           FTN address of the node to request from (e.g. 3:770/220 or 3:770/220@fidonet)
 *   filename          Filename or magic name to request (e.g. NZINTFAQ)
 *                     Multiple filenames may be listed to request more than one file.
 *
 * Options:
 *   --password=PASS   Area password required by the remote node
 *   --hostname=HOST   Override hostname (skip nodelist/DNS lookup)
 *   --port=PORT       Override port (default 24554)
 *   --log-level=LVL   Log level: DEBUG, INFO, WARNING, ERROR (default INFO)
 *   --log-file=FILE   Log file path (default: data/logs/freq_getfile.log)
 *   --no-console      Suppress console output
 *   --help            Show this help
 *
 * Examples:
 *   php scripts/freq_getfile.php 3:770/220@fidonet NZINTFAQ
 *   php scripts/freq_getfile.php --password=SECRET 1:123/456 MYFILE.ZIP
 *   php scripts/freq_getfile.php 1:123/456 FILES ALLFILES
 */

chdir(__DIR__ . '/../');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Binkp\Protocol\BinkpClient;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Binkp\Logger;

// ---------------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------------

function showUsage(): void
{
    echo <<<USAGE
Usage: php scripts/freq_getfile.php [options] <address> <filename> [filename2 ...]

Arguments:
  address           FTN address of the remote node (e.g. 3:770/220 or 3:770/220@fidonet)
  filename          Filename or magic name to request (e.g. NZINTFAQ)
                    Multiple filenames may be listed.

Options:
  --password=PASS   Area password required by the remote node
  --hostname=HOST   Override hostname (bypass nodelist/DNS lookup)
  --port=PORT       Override port (default 24554)
  --log-level=LVL   DEBUG, INFO, WARNING, ERROR (default INFO)
  --log-file=FILE   Log file path
  --no-console      Suppress console output
  --help            Show this help

Examples:
  php scripts/freq_getfile.php 3:770/220@fidonet NZINTFAQ
  php scripts/freq_getfile.php --password=SECRET 1:123/456 MYFILE.ZIP
  php scripts/freq_getfile.php 1:123/456 FILES ALLFILES

USAGE;
}

/**
 * Parse $argv into named options and positional arguments.
 *
 * @return array{opts: array<string,string|true>, args: string[]}
 */
function parseArgs(array $argv): array
{
    $opts = [];
    $args = [];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (str_starts_with($arg, '--')) {
            if (str_contains($arg, '=')) {
                [$key, $value] = explode('=', substr($arg, 2), 2);
                $opts[$key] = $value;
            } else {
                $opts[substr($arg, 2)] = true;
            }
        } else {
            $args[] = $arg;
        }
    }

    return ['opts' => $opts, 'args' => $args];
}

// ---------------------------------------------------------------------------
// Normalize FTN address: strip @domain suffix, validate basic format
// ---------------------------------------------------------------------------

/**
 * Strip @domain suffix from an FTN address and validate that zone:net/node
 * format is present.
 *
 * @throws \InvalidArgumentException if the address format is unrecognisable
 */
function normalizeAddress(string $address): string
{
    // Strip @domain (e.g. @fidonet, @fsxnet)
    if (str_contains($address, '@')) {
        $address = explode('@', $address, 2)[0];
    }

    // Validate: must look like zone:net/node or zone:net/node.point
    if (!preg_match('/^\d+:\d+\/\d+(\.\d+)?$/', $address)) {
        throw new \InvalidArgumentException(
            "Invalid FTN address format: '{$address}'. Expected zone:net/node (e.g. 3:770/220)"
        );
    }

    return $address;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

['opts' => $opts, 'args' => $args] = parseArgs($argv);

if (isset($opts['help'])) {
    showUsage();
    exit(0);
}

if (count($args) < 2) {
    fwrite(STDERR, "Error: address and at least one filename are required.\n\n");
    showUsage();
    exit(1);
}

$rawAddress = array_shift($args);
$filenames  = $args;   // one or more filenames / magic names
$password   = isset($opts['password']) ? (string)$opts['password'] : null;
$hostname   = isset($opts['hostname']) ? (string)$opts['hostname'] : null;
$port       = isset($opts['port'])     ? (int)$opts['port']        : null;
$logLevel   = isset($opts['log-level']) ? strtoupper((string)$opts['log-level']) : 'INFO';
$logFile    = isset($opts['log-file'])
    ? (string)$opts['log-file']
    : \BinktermPHP\Config::getLogPath('freq_getfile.log');
$noConsole  = isset($opts['no-console']);

// Validate and normalise address
try {
    $address = normalizeAddress($rawAddress);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

$logger = new Logger($logFile, $logLevel, !$noConsole);
$logger->log('INFO', "FREQ request: node={$address}, files=" . implode(', ', $filenames));

try {
    $config = BinkpConfig::getInstance();
    $client = new BinkpClient($config, $logger);

    // Queue each requested filename
    foreach ($filenames as $filename) {
        $client->addFreqRequest($filename, $password);
        $logger->log('INFO', "Queued FREQ: {$filename}" . ($password !== null ? " (with password)" : ''));
    }

    // Connect — BinkpClient resolves hostname via nodelist / binkp_zone if needed
    $result = $client->connect($address, $hostname, $port);

    if ($result['success']) {
        $received = $result['files_received'] ?? [];
        if (!empty($received)) {
            $logger->log('INFO', "Session complete. Files received: " . implode(', ', $received));
            echo "Received " . count($received) . " file(s):\n";
            foreach ($received as $f) {
                echo "  {$f}\n";
            }
        } else {
            $logger->log('WARNING', "Session complete but no files were received.");
            echo "Session complete — no files received.\n";
            echo "The remote may not have the requested file, or it may require a password.\n";
        }
        exit(0);
    } else {
        $error = $result['error'] ?? 'unknown error';
        $logger->log('ERROR', "Session failed: {$error}");
        fwrite(STDERR, "Session failed: {$error}\n");
        exit(1);
    }

} catch (\Exception $e) {
    $logger->log('ERROR', "FREQ failed: " . $e->getMessage());
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
