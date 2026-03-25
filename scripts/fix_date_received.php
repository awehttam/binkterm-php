#!/usr/bin/env php
<?php
/**
 * fix_date_received.php
 *
 * Sets date_received = date_written for echomail in one or more echo areas.
 * Useful after a %RESCAN import where all messages land with the same
 * date_received (import time) instead of their original send date.
 *
 * Only rows where date_written is non-NULL and not in the future are updated.
 *
 * Usage:
 *   php scripts/fix_date_received.php <echoarea_id> [echoarea_id ...]
 *   php scripts/fix_date_received.php --tag <tag> [tag ...]
 *   php scripts/fix_date_received.php --domain <domain> [domain ...]
 *   php scripts/fix_date_received.php --all
 *
 * Options:
 *   --dry-run        Show what would be updated without making changes
 *   --all            Apply to every echo area
 *   --tag <tag>      Match areas by tag name instead of numeric id
 *   --domain <name>  Apply to all echo areas belonging to the given domain(s)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;

// ── Argument parsing ──────────────────────────────────────────────────────────

$args    = array_slice($argv, 1);
$dryRun   = false;
$all      = false;
$byTag    = false;
$byDomain = false;
$targets  = [];

$i = 0;
while ($i < count($args)) {
    $a = $args[$i];
    if ($a === '--dry-run') {
        $dryRun = true;
    } elseif ($a === '--all') {
        $all = true;
    } elseif ($a === '--tag') {
        $byTag = true;
        $byDomain = false;
    } elseif ($a === '--domain') {
        $byDomain = true;
        $byTag = false;
    } elseif (str_starts_with($a, '--')) {
        fwrite(STDERR, "Unknown option: $a\n");
        exit(1);
    } else {
        $targets[] = $a;
    }
    $i++;
}

if (!$all && empty($targets)) {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php scripts/fix_date_received.php <echoarea_id> [id ...]\n");
    fwrite(STDERR, "  php scripts/fix_date_received.php --tag <tag> [tag ...]\n");
    fwrite(STDERR, "  php scripts/fix_date_received.php --domain <domain> [domain ...]\n");
    fwrite(STDERR, "  php scripts/fix_date_received.php --all\n");
    fwrite(STDERR, "\nOptions:\n");
    fwrite(STDERR, "  --dry-run        Show changes without applying them\n");
    fwrite(STDERR, "  --all            Apply to every echo area\n");
    fwrite(STDERR, "  --tag            Match areas by tag name instead of numeric id\n");
    fwrite(STDERR, "  --domain <name>  Apply to all areas belonging to the given domain(s)\n");
    exit(1);
}

// ── Database ──────────────────────────────────────────────────────────────────

$db = Database::getInstance()->getPdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ── Resolve area IDs ──────────────────────────────────────────────────────────

if ($all) {
    $stmt = $db->query("SELECT id, tag FROM echoareas ORDER BY id");
    $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($byDomain) {
    $placeholders = implode(',', array_fill(0, count($targets), '?'));
    $stmt = $db->prepare(
        "SELECT id, tag FROM echoareas WHERE LOWER(domain) IN ($placeholders) ORDER BY domain, id"
    );
    $stmt->execute(array_map('strtolower', $targets));
    $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($areas)) {
        fwrite(STDERR, "No echo areas found for domain(s): " . implode(', ', $targets) . "\n");
        exit(1);
    }
    echo "Found " . count($areas) . " echo area(s) across domain(s): " . implode(', ', $targets) . "\n\n";
} elseif ($byTag) {
    $placeholders = implode(',', array_fill(0, count($targets), '?'));
    $stmt = $db->prepare(
        "SELECT id, tag FROM echoareas WHERE LOWER(tag) IN ($placeholders) ORDER BY id"
    );
    $stmt->execute(array_map('strtolower', $targets));
    $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $foundTags = array_column($areas, 'tag');
    foreach ($targets as $t) {
        if (!in_array(strtoupper($t), array_map('strtoupper', $foundTags))) {
            fwrite(STDERR, "Warning: echo area tag not found: $t\n");
        }
    }
} else {
    // Numeric IDs
    foreach ($targets as $t) {
        if (!ctype_digit($t)) {
            fwrite(STDERR, "Error: '$t' is not a numeric id. Use --tag to match by name.\n");
            exit(1);
        }
    }
    $placeholders = implode(',', array_fill(0, count($targets), '?'));
    $stmt = $db->prepare(
        "SELECT id, tag FROM echoareas WHERE id IN ($placeholders) ORDER BY id"
    );
    $stmt->execute($targets);
    $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $foundIds = array_column($areas, 'id');
    foreach ($targets as $t) {
        if (!in_array((int)$t, array_map('intval', $foundIds))) {
            fwrite(STDERR, "Warning: echo area id not found: $t\n");
        }
    }
}

if (empty($areas)) {
    fwrite(STDERR, "No matching echo areas found.\n");
    exit(1);
}

// ── Process each area ─────────────────────────────────────────────────────────

if ($dryRun) {
    echo "[DRY RUN] No changes will be written.\n\n";
}

$totalUpdated = 0;

foreach ($areas as $area) {
    $areaId  = (int)$area['id'];
    $areaTag = $area['tag'];

    // Count rows that would be affected
    $countStmt = $db->prepare("
        SELECT COUNT(*) FROM echomail
        WHERE echoarea_id = ?
          AND date_written IS NOT NULL
          AND date_written <= NOW()
          AND date_written IS DISTINCT FROM date_received
    ");
    $countStmt->execute([$areaId]);
    $count = (int)$countStmt->fetchColumn();

    if ($count === 0) {
        echo "[$areaTag] No rows to update.\n";
        continue;
    }

    if ($dryRun) {
        echo "[$areaTag] Would update $count row(s): date_received = date_written\n";
        $totalUpdated += $count;
        continue;
    }

    $updateStmt = $db->prepare("
        UPDATE echomail
        SET date_received = date_written
        WHERE echoarea_id = ?
          AND date_written IS NOT NULL
          AND date_written <= NOW()
          AND date_written IS DISTINCT FROM date_received
    ");
    $updateStmt->execute([$areaId]);
    $affected = $updateStmt->rowCount();

    echo "[$areaTag] Updated $affected row(s).\n";
    $totalUpdated += $affected;
}

echo "\nTotal: $totalUpdated row(s) " . ($dryRun ? 'would be ' : '') . "updated.\n";
