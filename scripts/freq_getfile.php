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
 * Received files that are not FidoNet infrastructure files (.pkt, .tic,
 * day-of-week bundles, etc.) are assumed to be the FREQ response and are
 * moved into the requesting user's private file area under an "incoming"
 * subdirectory.  All other received files are left in data/inbound/ for
 * process_packets to handle.
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
 *   --user=USERNAME   Username to store received files for (default: first admin)
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
 *   php scripts/freq_getfile.php --user=john 1:123/456 ALLFILES
 *   php scripts/freq_getfile.php --password=SECRET 1:123/456 MYFILE.ZIP
 *   php scripts/freq_getfile.php -g 1:123/456 ALLFILES        (M_GET mode)
 */

chdir(__DIR__ . '/../');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Binkp\Protocol\BinkpClient;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Binkp\Logger;
use BinktermPHP\Database;
use BinktermPHP\FileAreaManager;
use BinktermPHP\Freq\FreqRequestTracker;
use BinktermPHP\Freq\FreqResponseRouter;

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
  --user=USERNAME   Username to store received FREQ files for (default: first admin)
  --password=PASS   Area password required by the remote node
  --hostname=HOST   Override hostname (bypass nodelist/DNS lookup)
  --port=PORT       Override port (default 24554)
  --log-level=LVL   DEBUG, INFO, WARNING, ERROR (default INFO)
  --log-file=FILE   Log file path
  --no-console      Suppress console output
  --help            Show this help

Examples:
  php scripts/freq_getfile.php 3:770/220@fidonet NZINTFAQ
  php scripts/freq_getfile.php --user=john 1:123/456 ALLFILES
  php scripts/freq_getfile.php --password=SECRET 1:123/456 MYFILE.ZIP
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
            $opts[substr($arg, 1)] = true;
        } else {
            $args[] = $arg;
        }
    }

    return ['opts' => $opts, 'args' => $args];
}

/**
 * Strip @domain suffix from an FTN address and validate zone:net/node format.
 *
 * @throws \InvalidArgumentException
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

/**
 * Derive the conventional WaZOO/Bark .req filename from an FTN address.
 *
 * The traditional format is 8 uppercase hex digits: 4 for net + 4 for node.
 * e.g. 1:123/456 → net=123 (0x007B), node=456 (0x01C8) → "007B01C8.REQ"
 *
 * Most BinkP mailers (binkd etc.) recognise incoming .req files regardless
 * of name, but using the address-based name is the conventional approach.
 *
 * @param string $address FTN address (zone:net/node or zone:net/node.point)
 * @return string Filename such as "007B01C8.REQ"
 */
function reqFilenameForAddress(string $address): string
{
    // Parse zone:net/node(.point)
    if (!preg_match('/^(\d+):(\d+)\/(\d+)/', $address, $m)) {
        return 'FREQ' . uniqid() . '.REQ';
    }
    $net  = (int)$m[2];
    $node = (int)$m[3];
    return sprintf('%04X%04X.REQ', $net, $node);
}


/**
 * Resolve a username to a user record, or fall back to the first admin user.
 *
 * @return array{id:int,username:string}
 * @throws \Exception if no suitable user can be found
 */
function resolveUser(\PDO $db, ?string $username): array
{
    if ($username !== null) {
        $stmt = $db->prepare('SELECT id, username FROM users WHERE LOWER(username) = LOWER(?) AND is_active = TRUE');
        $stmt->execute([$username]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$user) {
            throw new \Exception("User not found or inactive: {$username}");
        }
        return $user;
    }

    // Default: first admin user (lowest id)
    $stmt = $db->query('SELECT id, username FROM users WHERE is_admin = TRUE AND is_active = TRUE ORDER BY id ASC LIMIT 1');
    $user = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$user) {
        throw new \Exception("No active admin user found to assign FREQ files to. Use --user=USERNAME.");
    }
    return $user;
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
$username   = isset($opts['user'])      ? (string)$opts['user']      : null;
$password   = isset($opts['password'])  ? (string)$opts['password']  : null;
$hostname   = isset($opts['hostname'])  ? (string)$opts['hostname']  : null;
$port       = isset($opts['port'])      ? (int)$opts['port']         : null;
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
$exitCode    = 0;

try {
    $config = BinkpConfig::getInstance();
    $client = new BinkpClient($config, $logger);

    if ($useGet) {
        foreach ($filenames as $filename) {
            $client->addFreqRequest($filename, $password);
            $logger->log('INFO', "Queued M_GET: {$filename}" . ($password !== null ? " (with password)" : ''));
        }
    } else {
        $reqContents = buildReqFileContents($filenames, $password);
        $reqTempFile = sys_get_temp_dir() . '/' . reqFilenameForAddress($address);
        if (file_put_contents($reqTempFile, $reqContents) === false) {
            throw new \RuntimeException("Failed to write .req file: {$reqTempFile}");
        }
        $client->addExtraFile($reqTempFile);
        $logger->log('INFO', "Created .req file: " . basename($reqTempFile));
        $logger->log('DEBUG', "Contents:\n{$reqContents}");
    }

    // Persist the request so a subsequent session can route the response files
    // to the correct user even if the remote fulfils the request asynchronously.
    $db      = Database::getInstance()->getPdo();
    $user    = resolveUser($db, $username);
    $tracker = new FreqRequestTracker($db);
    $tracker->recordRequest($address, $filenames, (int)$user['id'], $useGet ? 'mget' : 'req');
    $logger->log('INFO', "Recorded FREQ request for user: {$user['username']} (id={$user['id']})");

    $result = $client->connect($address, $hostname, $port);

    if (!$result['success']) {
        $error = $result['error'] ?? 'unknown error';
        $logger->log('ERROR', "Session failed: {$error}");
        fwrite(STDERR, "Session failed: {$error}\n");
        $exitCode = 1;
    } else {
        $received = $result['files_received'] ?? [];

        if (empty($received)) {
            $logger->log('WARNING', "Session complete but no files were received.");
            echo "Session complete — no files received.\n";
            if ($useGet) {
                echo "The remote may not support binkp M_GET FREQ, or the file is unavailable.\n";
            } else {
                echo "The remote may process the .req asynchronously — files will be routed on the next session.\n";
            }
        } else {
            $router = new FreqResponseRouter($db, $logger);
            $router->routeReceivedFiles($address, $received);
            echo "Session complete — received " . count($received) . " file(s).\n";
        }
    }

} catch (\Exception $e) {
    $logger->log('ERROR', "FREQ failed: " . $e->getMessage());
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($reqTempFile !== null && file_exists($reqTempFile)) {
        unlink($reqTempFile);
    }
}

exit($exitCode);
