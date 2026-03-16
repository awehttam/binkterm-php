#!/usr/bin/env php
<?php
/**
 * import_iso.php — Import files from an ISO-backed file area into the database.
 *
 * Usage:
 *   php scripts/import_iso.php --area=<area_id> [options]
 *
 * Options:
 *   --area=ID          File area ID to import into (required)
 *   --dry-run          Show what would be imported without writing to DB
 *   --update           Re-import and update descriptions for existing files
 *   --no-descriptions  Import with filename as description (skip FILES.BBS etc.)
 *   --dir=PATH         Only scan this subdirectory of the mount point
 *   --flat             Import all files without subfolder grouping
 *   --verbose          Print each file as it is processed
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;
use BinktermPHP\FileAreaManager;

$opts = getopt('', ['area:', 'dry-run', 'update', 'no-descriptions', 'dir:', 'flat', 'verbose']);

$areaId    = isset($opts['area']) ? (int)$opts['area'] : 0;
$dryRun    = array_key_exists('dry-run', $opts);
$update    = array_key_exists('update', $opts);
$filterDir = isset($opts['dir']) ? rtrim($opts['dir'], '/') : null;
$flat      = array_key_exists('flat', $opts);

if ($areaId <= 0) {
    fwrite(STDERR, "Error: --area=ID is required\n");
    exit(1);
}

if ($dryRun) {
    // Dry-run: replicate just enough to show what would be imported
    Database::getInstance()->getPdo();
    $manager    = new FileAreaManager();
    $area       = $manager->getFileAreaById($areaId);

    if (!$area) {
        fwrite(STDERR, "Error: File area {$areaId} not found\n");
        exit(1);
    }
    if (($area['area_type'] ?? 'normal') !== 'iso') {
        fwrite(STDERR, "Error: File area {$areaId} is not an ISO-backed area\n");
        exit(1);
    }

    $mountPoint = rtrim($area['iso_mount_point'] ?? '', '/\\');
    if (empty($mountPoint) || !is_dir($mountPoint)) {
        fwrite(STDERR, "Error: ISO area is not mounted (mount_point: '{$mountPoint}')\n");
        exit(1);
    }

    $scanRoot = $filterDir ? ($mountPoint . DIRECTORY_SEPARATOR . ltrim($filterDir, '/\\')) : $mountPoint;
    echo "DRY RUN — scanning {$scanRoot}\n\n";

    $count = 0;
    $it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) {
            echo '  ' . $file->getPathname() . "\n";
            $count++;
        }
    }
    echo "\nWould import up to {$count} file(s).\n";
    exit(0);
}

Database::getInstance()->getPdo();
$manager = new FileAreaManager();

try {
    $area = $manager->getFileAreaById($areaId);
    if ($area) {
        echo "Scanning: " . rtrim($area['iso_mount_point'] ?? '?', '/\\') . "\n";
        echo "Area ID:  {$areaId}\n\n";
    }

    $counters = $manager->importIsoFiles($areaId, $update, $filterDir, $flat);

    echo "\nDone.\n";
    echo "  Imported:       {$counters['imported']}\n";
    echo "  Updated:        {$counters['updated']}\n";
    echo "  Skipped:        {$counters['skipped']}\n";
    echo "  No description: {$counters['no_description']}\n";
    if ($counters['errors'] > 0) {
        echo "  Errors:         {$counters['errors']}\n";
    }
} catch (\Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
