#!/usr/bin/env php
<?php

/**
 * freq_getfile.php - Request a file from a remote binkp node via FREQ.
 *
 * Default mode: generates a .req file and sends it as a regular binkp file
 * transfer.  The remote system's FREQ handler processes the .req and sends
 * the requested files back in the same (or a subsequent) session.
 *
 * -g mode: sends binkp M_GET commands (live-session FREQ per FSP-1011).
 * Use this only when connecting to another BinktermPHP node or a system
 * known to support binkp M_GET FREQ natively.
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
 *   -g                Use binkp M_GET (live-session FREQ) instead of .req file
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
 *   php scripts/freq_getfile.php -g 1:123/456 ALLFILES        (M_GET mode)
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
  -g                Use binkp M_GET (live-session FREQ) instead of .req file
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
  php scripts/freq_getfile.php -g 1:123/456 ALLFILES        (M_GET / live-session FREQ)

USAGE;
}

/**
 * Parse $argv into named options and positional arguments.
 * Handles both --long=value and -x short flags.
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
        } elseif (str_starts_with($arg, '-') && strlen($arg) === 2) {
            // Short single-character flag (e.g. -g)
            $opts[substr($arg, 1)] = true;
        } else {
            $args[] = $arg;
        }
    }

    return ['opts' => $opts, 'args' => $args];
}

/**
 * Strip @domain suffix from an FTN address and validate that zone:net/node
 * format is present.
 *
 * @throws \InvalidArgumentException if the address format is unrecognisable
 */
function normalizeAddress(string $address): string
{
    if (str_contains($address, '@')) {
        $address = explode('@', $address, 2)[0];
    }

    if (!preg_match('/^\d+:\d+\/\d+(\.\d+)?$/', $address)) {
        throw new \InvalidArgumentException(
            "Invalid FTN address format: '{$address}'. Expected zone:net/node (e.g. 3:770/220)"
        );
    }

    return $address;
}

/**
 * Build a .req file for Bark-style FREQ.
 *
 * Format (FTS-0008): one filename per line, optional area password on its own
 * line prefixed with ! before the filenames that require it.
 *
 * @param  string[]    $filenames
 * @param  string|null $password
 * @return string      File contents
 */
function buildReqFileContents(array $filenames, ?string $password): string
{
    $lines = [];
    if ($password !== null && $password !== '') {
        $lines[] = '!' . $password;
    }
    foreach ($filenames as $fn) {
        $lines[] = $fn;
    }
    return implode("\r\n", $lines) . "\r\n";
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
$filenames  = $args;
$useGet     = isset($opts['g']);
$password   = isset($opts['password']) ? (string)$opts['password'] : null;
$hostname   = isset($opts['hostname']) ? (string)$opts['hostname'] : null;
$port       = isset($opts['port'])     ? (int)$opts['port']        : null;
$logLevel   = isset($opts['log-level']) ? strtoupper((string)$opts['log-level']) : 'INFO';
$logFile    = isset($opts['log-file'])
    ? (string)$opts['log-file']
    : \BinktermPHP\Config::getLogPath('freq_getfile.log');
$noConsole  = isset($opts['no-console']);

try {
    $address = normalizeAddress($rawAddress);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

$logger = new Logger($logFile, $logLevel, !$noConsole);
$mode   = $useGet ? 'M_GET (live-session)' : '.req file';
$logger->log('INFO', "FREQ request [{$mode}]: node={$address}, files=" . implode(', ', $filenames));

$reqTempFile = null;

try {
    $config = BinkpConfig::getInstance();
    $client = new BinkpClient($config, $logger);

    if ($useGet) {
        // M_GET mode: send binkp live-session FREQ commands
        foreach ($filenames as $filename) {
            $client->addFreqRequest($filename, $password);
            $logger->log('INFO', "Queued M_GET: {$filename}" . ($password !== null ? " (with password)" : ''));
        }
    } else {
        // .req file mode: write a Bark-style request file and send it as a
        // regular binkp file transfer.  The remote's FREQ handler processes it.
        $reqContents = buildReqFileContents($filenames, $password);
        $reqTempFile = sys_get_temp_dir() . '/freq_' . uniqid() . '.req';
        if (file_put_contents($reqTempFile, $reqContents) === false) {
            throw new \RuntimeException("Failed to write .req file: {$reqTempFile}");
        }
        $client->addExtraFile($reqTempFile);
        $logger->log('INFO', "Created .req file: " . basename($reqTempFile));
        $logger->log('DEBUG', "Contents:\n{$reqContents}");
    }

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
            if ($useGet) {
                echo "The remote may not support binkp M_GET FREQ, or the file is unavailable.\n";
            } else {
                echo "The remote may process the .req asynchronously — files may arrive on next poll.\n";
            }
        }
        $exitCode = 0;
    } else {
        $error = $result['error'] ?? 'unknown error';
        $logger->log('ERROR', "Session failed: {$error}");
        fwrite(STDERR, "Session failed: {$error}\n");
        $exitCode = 1;
    }

} catch (\Exception $e) {
    $logger->log('ERROR', "FREQ failed: " . $e->getMessage());
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    $exitCode = 1;
} finally {
    // Clean up temp .req file regardless of outcome
    if ($reqTempFile !== null && file_exists($reqTempFile)) {
        unlink($reqTempFile);
    }
}

exit($exitCode ?? 1);
