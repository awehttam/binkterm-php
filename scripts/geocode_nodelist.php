#!/usr/bin/env php
<?php

/**
 * Geocode nodelist entries using the shared BBS directory geocode cache.
 *
 * Reads the location field from the nodelist table and resolves coordinates
 * via the Nominatim API (1 req/sec rate limit). Already-cached locations are
 * resolved instantly without an API call.
 *
 * Usage:
 *   php scripts/geocode_nodelist.php [--limit=N] [--force] [--dry-run]
 *
 * Options:
 *   --limit=N   Process at most N nodes (default: all pending)
 *   --force     Re-geocode nodes that already have coordinates
 *   --dry-run   Show what would be processed without writing changes
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\BbsDirectoryGeocoder;
use BinktermPHP\Database;

$options = getopt('', ['limit:', 'force', 'dry-run', 'help']);

if (isset($options['help'])) {
    echo "Usage: php scripts/geocode_nodelist.php [--limit=N] [--force] [--dry-run]\n";
    echo "\n";
    echo "Geocodes nodelist entries that have a location string but no coordinates.\n";
    echo "Uses the shared geocode_cache table so cached locations\n";
    echo "are resolved instantly without hitting the Nominatim API.\n";
    echo "\n";
    echo "Options:\n";
    echo "  --limit=N   Process at most N nodes (default: all pending)\n";
    echo "  --force     Re-geocode nodes that already have coordinates\n";
    echo "  --dry-run   Show what would be processed without writing changes\n";
    exit(0);
}

$limit  = isset($options['limit']) ? max(1, (int)$options['limit']) : null;
$force  = isset($options['force']);
$dryRun = isset($options['dry-run']);

echo "=== Nodelist Geocoder ===\n";
echo "Mode:  " . ($dryRun ? 'DRY RUN' : 'LIVE') . "\n";
echo "Force: " . ($force ? 'yes (re-geocode all)' : 'no (skip existing)') . "\n";
if ($limit !== null) {
    echo "Limit: $limit\n";
}
echo "\n";

try {
    $db = Database::getInstance()->getPdo();
    $geocoder = new BbsDirectoryGeocoder();

    if (!$geocoder->isEnabled()) {
        fwrite(STDERR, "Geocoding is disabled (BBS_DIRECTORY_GEOCODING_ENABLED=false).\n");
        exit(1);
    }

    // Fetch nodes with a location that need geocoding
    $whereClause = $force
        ? "WHERE location IS NOT NULL AND location != '' AND location != '-Unpublished-'"
        : "WHERE location IS NOT NULL AND location != '' AND location != '-Unpublished-'
             AND (latitude IS NULL OR longitude IS NULL)";

    $limitClause = $limit !== null ? "LIMIT $limit" : '';

    $stmt = $db->query("
        SELECT id, zone, net, node, point, system_name, location, latitude, longitude
        FROM nodelist
        $whereClause
        ORDER BY id ASC
        $limitClause
    ");
    $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total    = count($nodes);
    $geocoded = 0;
    $cached   = 0;
    $skipped  = 0;
    $failed   = 0;

    echo "Nodes to process: $total\n\n";

    if ($total === 0) {
        echo "Nothing to do.\n";
        exit(0);
    }

    $updateStmt = $dryRun ? null : $db->prepare("
        UPDATE nodelist SET latitude = ?, longitude = ? WHERE id = ?
    ");

    foreach ($nodes as $row) {
        $address  = "{$row['zone']}:{$row['net']}/{$row['node']}.{$row['point']}";
        $location = trim((string)$row['location']);

        $coords = $geocoder->geocodeLocation($location);

        if ($coords === null) {
            $failed++;
            printf("[FAIL]   %s | %s -> no result\n", $address, $location);
            continue;
        }

        if ($coords['latitude'] === null || $coords['longitude'] === null) {
            $skipped++;
            printf("[SKIP]   %s | %s -> not geocodable\n", $address, $location);
            continue;
        }

        $lat = $coords['latitude'];
        $lon = $coords['longitude'];

        // Detect whether this came from cache (geocoder handles it internally;
        // we just note it was fast if the existing DB value already matches)
        $fromCache = ($row['latitude'] !== null &&
                      abs((float)$row['latitude'] - $lat) < 0.000001 &&
                      abs((float)$row['longitude'] - $lon) < 0.000001);

        if ($fromCache) {
            $cached++;
        } else {
            $geocoded++;
        }

        printf("[%s] %s | %s -> %.6f, %.6f\n",
            $fromCache ? 'CACHE' : 'OK   ',
            $address,
            $location,
            $lat,
            $lon
        );

        if (!$dryRun && $updateStmt) {
            $updateStmt->execute([$lat, $lon, (int)$row['id']]);
        }
    }

    echo "\n";
    echo "Total processed: $total\n";
    echo "Geocoded (API):  $geocoded\n";
    echo "Resolved (cache): $cached\n";
    echo "Skipped (no coords): $skipped\n";
    echo "Failed: $failed\n";

    if ($dryRun) {
        echo "\nNo database changes were made (dry run).\n";
    }

    exit(0);

} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
