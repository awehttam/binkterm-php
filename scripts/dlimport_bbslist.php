#!/usr/bin/env php
<?php

/**
 * Download and import the current monthly Telnet BBS Guide list.
 *
 * Usage:
 *   php scripts/dlimport_bbslist.php [options]
 *
 * Options:
 *   --month=MM     Month to download (01-12). Defaults to current month.
 *   --year=YYYY    Year to download. Defaults to current year.
 *   --file=NAME    Specific archive filename, e.g. ibbs0526.zip.
 *   --dry-run      Pass --dry-run to import_bbslist.php.
 *   --quiet        Suppress normal output except errors.
 *   --help, -h     Show this help.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

const BBSLIST_BASE_URL = 'https://www.telnetbbsguide.com/bbslist/';

/**
 * Print script usage.
 */
function printUsage(): void
{
    echo <<<HELP
Usage: php scripts/dlimport_bbslist.php [options]

Downloads the monthly Telnet BBS Guide ZIP from:
  https://www.telnetbbsguide.com/bbslist/

By default, the archive name is generated from the current month and year:
  ibbsMMYY.zip

Options:
  --month=MM     Month to download (01-12). Defaults to current month.
  --year=YYYY    Year to download. Defaults to current year.
  --file=NAME    Specific archive filename, e.g. ibbs0526.zip.
  --dry-run      Download and parse without writing to the database.
  --quiet        Suppress normal output except errors.
  --help, -h     Show this help.

Examples:
  php scripts/dlimport_bbslist.php
  php scripts/dlimport_bbslist.php --file=ibbs0526.zip --dry-run
  php scripts/dlimport_bbslist.php --month=05 --year=2026 --quiet

Exit codes:
  0  Success
  1  Error

HELP;
}

/**
 * Write a message to stdout unless quiet mode is enabled.
 */
function output(string $message, bool $quiet = false): void
{
    if (!$quiet) {
        echo $message . PHP_EOL;
    }
}

/**
 * Download a URL to a local file.
 *
 * @throws RuntimeException when the download fails or returns suspicious data.
 */
function downloadFile(string $url, string $destination, bool $quiet = false): void
{
    output("Downloading: {$url}", $quiet);

    $context = stream_context_create([
        'http' => [
            'timeout' => 300,
            'user_agent' => 'BinktermPHP BBS List Importer',
            'follow_location' => true,
            'max_redirects' => 5,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $content = @file_get_contents($url, false, $context);
    if ($content === false) {
        $error = error_get_last();
        throw new RuntimeException('Download failed: ' . ($error['message'] ?? 'unknown error'));
    }

    $statusLine = $http_response_header[0] ?? '';
    if ($statusLine !== '' && preg_match('/^HTTP\/\S+\s+(\d+)/', $statusLine, $matches)) {
        $status = (int)$matches[1];
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Download failed with HTTP status {$status}");
        }
    }

    if (strlen($content) < 1000) {
        throw new RuntimeException('Downloaded file is too small: ' . strlen($content) . ' bytes');
    }

    $bytes = file_put_contents($destination, $content, LOCK_EX);
    if ($bytes === false) {
        throw new RuntimeException("Could not write downloaded file: {$destination}");
    }

    output('Downloaded ' . number_format($bytes) . ' bytes to ' . $destination, $quiet);
}

/**
 * Run import_bbslist.php with the downloaded ZIP file.
 *
 * @throws RuntimeException when the import script fails.
 */
function runImport(string $zipPath, bool $dryRun = false, bool $quiet = false): void
{
    $importScript = __DIR__ . '/import_bbslist.php';
    if (!file_exists($importScript)) {
        throw new RuntimeException("Import script not found: {$importScript}");
    }

    $cmd = sprintf(
        'php %s %s%s%s 2>&1',
        escapeshellarg($importScript),
        escapeshellarg($zipPath),
        $dryRun ? ' --dry-run' : '',
        $quiet ? ' --quiet' : ''
    );

    output('Running import_bbslist.php...', $quiet);

    $lines = [];
    $returnCode = 0;
    exec($cmd, $lines, $returnCode);

    if (!$quiet) {
        foreach ($lines as $line) {
            echo $line . PHP_EOL;
        }
    }

    if ($returnCode !== 0) {
        throw new RuntimeException("Import failed with exit code {$returnCode}: " . implode(PHP_EOL, $lines));
    }
}

$args = array_slice($argv, 1);
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    printUsage();
    exit(0);
}

$month = date('m');
$year = date('Y');
$fileName = null;
$dryRun = false;
$quiet = false;

foreach ($args as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }

    if ($arg === '--quiet' || $arg === '-q') {
        $quiet = true;
        continue;
    }

    if (str_starts_with($arg, '--month=')) {
        $month = substr($arg, strlen('--month='));
        continue;
    }

    if (str_starts_with($arg, '--year=')) {
        $year = substr($arg, strlen('--year='));
        continue;
    }

    if (str_starts_with($arg, '--file=')) {
        $fileName = substr($arg, strlen('--file='));
        continue;
    }

    fwrite(STDERR, "Unknown option: {$arg}\n");
    fwrite(STDERR, "Use --help for usage.\n");
    exit(1);
}

try {
    if ($fileName === null) {
        if (!preg_match('/^(0[1-9]|1[0-2])$/', $month)) {
            throw new InvalidArgumentException("Invalid month: {$month}");
        }

        if (!preg_match('/^\d{4}$/', $year)) {
            throw new InvalidArgumentException("Invalid year: {$year}");
        }

        $fileName = sprintf('ibbs%s%s.zip', $month, substr($year, -2));
    }

    if (!preg_match('/^ibbs\d{4}\.zip$/i', $fileName)) {
        throw new InvalidArgumentException("Invalid BBS list filename: {$fileName}");
    }

    $downloadDir = __DIR__ . '/../data/bbslist';
    if (!is_dir($downloadDir) && !mkdir($downloadDir, 0755, true)) {
        throw new RuntimeException("Could not create download directory: {$downloadDir}");
    }

    $zipPath = $downloadDir . DIRECTORY_SEPARATOR . strtolower($fileName);
    $url = BBSLIST_BASE_URL . strtolower($fileName);

    downloadFile($url, $zipPath, $quiet);
    runImport($zipPath, $dryRun, $quiet);

    output('BBS list download and import complete.', $quiet);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
