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
 * Rows where date_written already equals date_received are skipped.
 *
 * Usage:
 *   php scripts/fix_date_received.php <echoarea_id> [echoarea_id ...]
 *   php scripts/fix_date_received.php --tag <tag> [tag ...]
 *   php scripts/fix_date_received.php --domain <domain> [domain ...]
 *   php scripts/fix_date_received.php --tag <tag> --domain <domain>
 *   php scripts/fix_date_received.php --all
 *
 * --tag and --domain may be combined: only areas matching both filters are
 * updated. --domain alone applies to all areas in that domain. --tag alone
 * applies to every area with that tag across all domains.
 *
 * Options:
 *   --dry-run        Show what would be updated without making changes
 *   --all            Apply to every echo area
 *   --tag <tag>      Filter by tag name (repeatable)
 *   --domain <name>  Filter by domain (repeatable)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;

// ── Argument parsing ──────────────────────────────────────────────────────────

$args    = array_slice($argv, 1);
$dryRun  = false;
$all     = false;
$tags    = [];
$domains = [];
$ids     = [];
$mode    = null; // current collection target: 'tag', 'domain', or null (ids)

foreach ($args as $a) {
    if ($a === '--dry-run') {
        $dryRun = true;
        $mode   = null;
    } elseif ($a === '--all') {
        $all  = true;
        $mode = null;
    } elseif ($a === '--tag') {
        $mode = 'tag';
    } elseif ($a === '--domain') {
        $mode = 'domain';
    } elseif (str_starts_with($a, '--')) {
        fwrite(STDERR, "Unknown option: $a\n");
        exit(1);
    } else {
        if ($mode === 'tag') {
            $tags[] = $a;
        } elseif ($mode === 'domain') {
            $domains[] = $a;
        } else {
            $ids[] = $a;
        }
    }
}

$hasFilter = $all || !empty($ids) || !empty($tags) || !empty($domains);

if (!$hasFilter) {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php scripts/fix_date_received.php <echoarea_id> [id ...]\n");
    fwrite(STDERR, "  php scripts/fix_date_received.php --tag <tag> [tag ...]\n");
    fwrite(STDERR, "  php scripts/fix_date_received.php --domain <domain> [domain ...]\n");
    fwrite(STDERR, "  php scripts/fix_date_received.php --tag <tag> --domain <domain>\n");
    fwrite(STDERR, "  php scripts/fix_date_received.php --all\n");
    fwrite(STDERR, "\nOptions:\n");
    fwrite(STDERR, "  --dry-run        Show changes without applying them\n");
    fwrite(STDERR, "  --all            Apply to every echo area\n");
    fwrite(STDERR, "  --tag <tag>      Filter by tag (repeatable; combinable with --domain)\n");
    fwrite(STDERR, "  --domain <name>  Filter by domain (repeatable; combinable with --tag)\n");
    exit(1);
}

if (!empty($ids)) {
    foreach ($ids as $id) {
        if (!ctype_digit($id)) {
            fwrite(STDERR, "Error: '$id' is not a numeric id. Use --tag to match by name.\n");
            exit(1);
        }
    }
}

// ── Database ──────────────────────────────────────────────────────────────────

$db = Database::getInstance()->getPdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ── Resolve areas ─────────────────────────────────────────────────────────────

$areas = [];

if ($all) {
    $stmt  = $db->query("SELECT id, tag, domain FROM echoareas ORDER BY domain, tag");
    $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif (!empty($ids)) {
    $ph    = implode(',', array_fill(0, count($ids), '?'));
    $stmt  = $db->prepare("SELECT id, tag, domain FROM echoareas WHERE id IN ($ph) ORDER BY id");
    $stmt->execute($ids);
    $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($ids as $id) {
        if (!in_array((int)$id, array_map('intval', array_column($areas, 'id')))) {
            fwrite(STDERR, "Warning: echo area id not found: $id\n");
        }
    }
} else {
    // Tag and/or domain filter — build WHERE dynamically
    $conditions = [];
    $params     = [];

    if (!empty($tags)) {
        $ph           = implode(',', array_fill(0, count($tags), '?'));
        $conditions[] = "LOWER(tag) IN ($ph)";
        $params       = array_merge($params, array_map('strtolower', $tags));
    }

    if (!empty($domains)) {
        $ph           = implode(',', array_fill(0, count($domains), '?'));
        $conditions[] = "LOWER(domain) IN ($ph)";
        $params       = array_merge($params, array_map('strtolower', $domains));
    }

    $where = implode(' AND ', $conditions);
    $stmt  = $db->prepare("SELECT id, tag, domain FROM echoareas WHERE $where ORDER BY domain, tag");
    $stmt->execute($params);
    $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($tags)) {
        $foundTags = array_map('strtolower', array_column($areas, 'tag'));
        foreach ($tags as $t) {
            if (!in_array(strtolower($t), $foundTags)) {
                fwrite(STDERR, "Warning: no areas found for tag: $t\n");
            }
        }
    }
}

if (empty($areas)) {
    fwrite(STDERR, "No matching echo areas found.\n");
    exit(1);
}

// ── Process ───────────────────────────────────────────────────────────────────

if ($dryRun) {
    echo "[DRY RUN] No changes will be written.\n\n";
}

echo "Processing " . count($areas) . " echo area(s)...\n\n";

$totalUpdated = 0;

foreach ($areas as $area) {
    $areaId    = (int)$area['id'];
    $label     = $area['tag'] . ($area['domain'] ? '@' . $area['domain'] : '');

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
        echo "[$label] No rows to update.\n";
        continue;
    }

    if ($dryRun) {
        echo "[$label] Would update $count row(s).\n";
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

    echo "[$label] Updated $affected row(s).\n";
    $totalUpdated += $affected;
}

echo "\nTotal: $totalUpdated row(s) " . ($dryRun ? 'would be ' : '') . "updated.\n";
