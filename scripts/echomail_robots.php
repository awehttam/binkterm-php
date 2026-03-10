#!/usr/bin/env php
<?php

/**
 * echomail_robots.php - Run echomail robot processors
 *
 * Usage:
 *   php scripts/echomail_robots.php [options]
 *
 * Options:
 *   --robot-id=N   Run only the robot with this ID
 *   --dry-run      Show what would be processed without making changes
 *   --quiet        Suppress output (exit code still reflects errors)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;
use BinktermPHP\Robots\EchomailRobotRunner;

// Parse arguments
$robotId = null;
$dryRun  = false;
$quiet   = false;

foreach ($argv as $arg) {
    if (preg_match('/^--robot-id=(\d+)$/', $arg, $m)) {
        $robotId = (int)$m[1];
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--quiet') {
        $quiet = true;
    }
}

if ($dryRun && !$quiet) {
    echo "[DRY RUN] No changes will be written.\n";
}

try {
    $db = Database::getInstance()->getPdo();
} catch (\Exception $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

if ($dryRun) {
    // In dry-run mode, wrap everything in a transaction and roll back
    $db->beginTransaction();
}

$runner  = new EchomailRobotRunner($db);
$results = $runner->run($robotId);

if (!$quiet) {
    if (empty($results)) {
        echo "No robots found" . ($robotId !== null ? " with ID {$robotId}" : "") . ".\n";
    }

    foreach ($results as $r) {
        $status = $r['error'] !== null ? 'ERROR' : 'OK';
        echo sprintf(
            "[%s] Robot #%d (%s): examined=%d processed=%d%s\n",
            $status,
            $r['robot_id'],
            $r['name'],
            $r['examined'],
            $r['processed'],
            $r['error'] !== null ? ' error=' . $r['error'] : ''
        );
    }
}

if ($dryRun) {
    $db->rollBack();
    if (!$quiet) {
        echo "[DRY RUN] Transaction rolled back — no changes saved.\n";
    }
}

// Exit non-zero if any robot had an error
$hasErrors = array_filter($results, fn($r) => $r['error'] !== null);
exit($hasErrors ? 1 : 0);
