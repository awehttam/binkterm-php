#!/usr/bin/env php
<?php
/**
 * Post a file to a file area from the command line.
 *
 * Runs the standard upload path (FileAreaManager::uploadFileFromPath) including
 * validation, deduplication, and TIC distribution to uplinks when the area is
 * configured for it.
 *
 * Usage:
 *   php post_file.php <file> <area-tag> <description> [--domain=DOMAIN] [--long-desc=TEXT] [--user=USERNAME]
 *
 * Arguments:
 *   file          Path to the file to upload
 *   area-tag      Tag of the destination file area (e.g. NEWFILES)
 *   description   Short description (displayed in file listings)
 *
 * Options:
 *   --domain=     Domain of the file area (default: fidonet)
 *   --long-desc=  Long description (multi-line text appended to listing)
 *   --user=       Username to record as uploader (default: sysop)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;
use BinktermPHP\FileAreaManager;
use BinktermPHP\TicFileGenerator;

// ---------------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------------

/**
 * Print usage and exit.
 */
function printUsage(): void
{
    echo "Usage: php post_file.php <file> <area-tag> <description> [--domain=DOMAIN] [--long-desc=TEXT] [--user=USERNAME]\n";
    echo "\n";
    echo "Arguments:\n";
    echo "  file          Path to the file to upload\n";
    echo "  area-tag      Tag of the destination file area (e.g. NEWFILES)\n";
    echo "  description   Short description\n";
    echo "\n";
    echo "Options:\n";
    echo "  --domain=     Domain of the file area (default: fidonet)\n";
    echo "  --long-desc=  Long description\n";
    echo "  --user=       Username to record as uploader (default: sysop)\n";
}

/**
 * Parse argv into a structured args array.
 *
 * @param array $argv
 * @return array
 */
function parseArgs(array $argv): array
{
    $args = [
        'file'      => null,
        'area_tag'  => null,
        'description' => null,
        'domain'    => 'fidonet',
        'long_desc' => '',
        'user'      => 'sysop',
    ];

    $positional = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--domain=')) {
            $args['domain'] = substr($arg, strlen('--domain='));
        } elseif (str_starts_with($arg, '--long-desc=')) {
            $args['long_desc'] = substr($arg, strlen('--long-desc='));
        } elseif (str_starts_with($arg, '--user=')) {
            $args['user'] = substr($arg, strlen('--user='));
        } elseif (!str_starts_with($arg, '--')) {
            $positional[] = $arg;
        }
    }

    $args['file']        = $positional[0] ?? null;
    $args['area_tag']    = isset($positional[1]) ? strtoupper($positional[1]) : null;
    $args['description'] = $positional[2] ?? null;

    return $args;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$args = parseArgs($argv);

if (!$args['file'] || !$args['area_tag'] || !$args['description']) {
    printUsage();
    exit(1);
}

$filePath = realpath($args['file']);
if ($filePath === false || !is_file($filePath)) {
    echo "Error: File not found: {$args['file']}\n";
    exit(1);
}

$db      = Database::getInstance()->getPdo();
$manager = new FileAreaManager();

// Look up the file area
$fileArea = $manager->getFileAreaByTag($args['area_tag'], $args['domain']);
if (!$fileArea) {
    echo "Error: File area '{$args['area_tag']}' not found for domain '{$args['domain']}'\n";
    exit(1);
}

if (!$fileArea['is_active']) {
    echo "Error: File area '{$args['area_tag']}' is inactive\n";
    exit(1);
}

// Copy the source to a temp path so uploadFileFromPath can move it safely
// (the method moves — not copies — the source into area storage)
$tmpPath = sys_get_temp_dir() . '/' . basename($filePath);
if (!copy($filePath, $tmpPath)) {
    echo "Error: Failed to copy file to temp location\n";
    exit(1);
}

try {
    $fileId = $manager->uploadFileFromPath(
        (int)$fileArea['id'],
        $tmpPath,
        $args['description'],
        $args['long_desc'],
        $args['user']
    );

    echo "File added: ID {$fileId}, area {$args['area_tag']}\n";

    // Trigger TIC distribution — createTicFilesForUplinks skips automatically
    // for local, private, and areas with no configured uplinks.
    if (empty($fileArea['is_local']) && empty($fileArea['is_private'])) {
        $fileRecord = $manager->getFileById($fileId);
        if ($fileRecord) {
            $ticGenerator = new TicFileGenerator();
            $createdTics  = $ticGenerator->createTicFilesForUplinks($fileRecord, $fileArea);

            if (count($createdTics) > 0) {
                echo "TIC distribution: " . count($createdTics) . " TIC file(s) queued for outbound\n";
                foreach ($createdTics as $ticPath) {
                    echo "  " . basename($ticPath) . "\n";
                }
            } else {
                echo "TIC distribution: skipped (no uplinks configured for domain '{$fileArea['domain']}')\n";
            }
        }
    } else {
        echo "TIC distribution: skipped (area is local or private)\n";
    }

    exit(0);
} catch (\Throwable $e) {
    // Clean up temp file if move never happened
    if (file_exists($tmpPath)) {
        @unlink($tmpPath);
    }
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
