#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\BbsDirectory;
use BinktermPHP\Database;

$options = getopt('', ['limit:', 'dry-run', 'help']);

if (isset($options['help'])) {
    echo "Usage: php scripts/backfill_bbs_directory_geocoding.php [--limit=N] [--dry-run]\n";
    echo "\n";
    echo "Backfills missing BBS Directory coordinates for entries that have a location set.\n";
    echo "\n";
    echo "Options:\n";
    echo "  --limit=N    Process at most N matching rows\n";
    echo "  --dry-run    Show how many rows would be updated without writing changes\n";
    exit(0);
}

$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : null;
$dryRun = isset($options['dry-run']);

echo "=== BBS Directory Geocoding Backfill ===\n";
echo "Mode: " . ($dryRun ? 'DRY RUN' : 'LIVE') . "\n";
if ($limit !== null) {
    echo "Limit: $limit\n";
}
echo "\n";

try {
    $db = Database::getInstance()->getPdo();
    $directory = new BbsDirectory($db);
    $result = $directory->backfillMissingCoordinates($limit, $dryRun);

    foreach ($result['rows'] as $row) {
        $prefix = '[' . strtoupper((string)$row['status']) . ']';
        $line = sprintf(
            "%s #%d %s",
            $prefix,
            (int)$row['id'],
            (string)$row['name']
        );

        if (!empty($row['location'])) {
            $line .= ' | ' . $row['location'];
        }

        if (isset($row['latitude'], $row['longitude'])) {
            $line .= sprintf(' -> %.6f, %.6f', (float)$row['latitude'], (float)$row['longitude']);
        }

        if (!empty($row['message'])) {
            $line .= ' | ' . $row['message'];
        }

        echo $line . "\n";
    }

    if ($result['selected'] > 0) {
        echo "\n";
    }

    echo "Rows selected: {$result['selected']}\n";
    echo "Rows geocoded: {$result['updated']}\n";
    echo "Rows skipped: {$result['skipped']}\n";
    echo "Rows failed: {$result['failed']}\n";

    if ($dryRun) {
        echo "\nNo database changes were made.\n";
    }

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
