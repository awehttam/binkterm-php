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
 *   --cursor=N     Override last_processed_echomail_id for the robot before running;
 *                  requires --robot-id; use 0 to reprocess from the beginning
 *   --dry-run      Show what would be processed without making changes (implies --debug)
 *   --debug        Print per-message decode details to help diagnose parsing issues
 *   --quiet        Suppress output (exit code still reflects errors)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;
use BinktermPHP\Robots\EchomailRobotRunner;

// Parse arguments
$robotId = null;
$cursor  = null;
$dryRun  = false;
$debug   = false;
$quiet   = false;

foreach ($argv as $arg) {
    if (preg_match('/^--robot-id=(\d+)$/', $arg, $m)) {
        $robotId = (int)$m[1];
    } elseif (preg_match('/^--cursor=(\d+)$/', $arg, $m)) {
        $cursor = (int)$m[1];
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
        $debug  = true;  // dry-run always implies debug
    } elseif ($arg === '--debug') {
        $debug = true;
    } elseif ($arg === '--quiet') {
        $quiet = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo <<<'HELP'
Usage: php scripts/echomail_robots.php [options]

Runs one or all enabled echomail robot rules. Each robot watches an echo area
for matching messages and dispatches them to a configured processor.

Options:
  --robot-id=N   Run only the robot with this ID (default: run all enabled)
  --cursor=N     Override last_processed_echomail_id before running; requires
                 --robot-id. Use 0 to reprocess all messages from the start.
                 The new cursor is persisted unless --dry-run is also given.
  --dry-run      Process messages but roll back all DB changes at the end;
                 implies --debug so you can inspect what would be written
  --debug        Print per-message decode details (raw body, decoded lines,
                 extracted fields) to help diagnose parsing problems
  --quiet        Suppress all output; exit code reflects errors (for cron)
  --help, -h     Show this help and exit

Exit codes:
  0  All robots ran without errors
  1  One or more robots encountered an error

Examples:
  # Run all enabled robots (normal cron usage)
  php scripts/echomail_robots.php --quiet

  # Test a specific robot without writing anything
  php scripts/echomail_robots.php --robot-id=1 --dry-run

  # Debug a robot's message parsing live
  php scripts/echomail_robots.php --robot-id=1 --debug

  # Reprocess all messages for a robot from the beginning
  php scripts/echomail_robots.php --robot-id=1 --cursor=0

  # Preview what would be processed starting from a specific message ID
  php scripts/echomail_robots.php --robot-id=1 --cursor=500 --dry-run

HELP;
        exit(0);
    }
}

if ($cursor !== null && $robotId === null) {
    fwrite(STDERR, "Error: --cursor requires --robot-id.\n");
    exit(1);
}

if ($dryRun && !$quiet) {
    echo "[DRY RUN] No changes will be written.\n";
}
if ($debug && !$dryRun && !$quiet) {
    echo "[DEBUG] Per-message decode output enabled.\n";
}

try {
    $db = Database::getInstance()->getPdo();
} catch (\Exception $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

if ($dryRun) {
    // In dry-run mode, wrap everything in a transaction and roll back
    // (includes any cursor update so it's also rolled back)
    $db->beginTransaction();
}

// Override cursor position if requested
if ($cursor !== null) {
    $stmt = $db->prepare("UPDATE echomail_robots SET last_processed_echomail_id = ? WHERE id = ?");
    $stmt->execute([$cursor, $robotId]);
    if (!$quiet) {
        echo "Cursor for robot #{$robotId} set to {$cursor}.\n";
    }
}

$runner = new EchomailRobotRunner($db);

if ($debug && !$quiet) {
    $runner->setDebugCallback(function (string $line) {
        echo $line . "\n";
    });
}

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
