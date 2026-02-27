#!/usr/bin/env php
<?php
/**
 * Echomail Maintenance Utility
 *
 * This script performs maintenance on echomail messages:
 * - Purge old messages over a certain age
 * - Delete oldest messages if maximum count exceeded
 * - Clean up related records (read status, saved messages, shared links, threading)
 * - Runs VACUUM ANALYZE on all affected tables to reclaim storage
 * - Works on per-echo basis
 *
 * Usage:
 *   php echomail_maintenance.php --echo=TAGNAME --domain=DOMAIN --max-age=90 [--dry-run] [--quiet]
 *   php echomail_maintenance.php --echo=all --domain=DOMAIN --max-count=1000 [--dry-run] [--quiet]
 *
 * Options:
 *   --echo=TAG          Echo area tag (use 'all' for all areas in domain)
 *   --domain=DOMAIN     Network domain (e.g., fidonet, fsxnet) - required
 *   --max-age=DAYS      Delete messages older than this many days
 *   --max-count=NUM     Keep only the newest NUM messages per echo (0 = delete all)
 *   --dry-run           Show what would be deleted without actually deleting
 *   --quiet             Suppress output except errors
 *   --help              Show this help message
 *
 * Examples:
 *   # Delete messages older than 90 days in FIDO_SYSOP echo
 *   php echomail_maintenance.php --echo=FIDO_SYSOP --domain=fidonet --max-age=90
 *
 *   # Keep only newest 500 messages in all fidonet echoes
 *   php echomail_maintenance.php --echo=all --domain=fidonet --max-count=500
 *
 *   # Preview what would be deleted (dry run)
 *   php echomail_maintenance.php --echo=all --domain=fsxnet --max-age=180 --dry-run
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;

// Parse command line arguments
$options = parseArguments($argv);

// Show help if requested
if (isset($options['help'])) {
    showHelp();
    exit(0);
}

// Validate required arguments
if (!isset($options['echo'])) {
    echo "Error: --echo parameter is required\n";
    showHelp();
    exit(1);
}

if (!isset($options['max-age']) && !isset($options['max-count'])) {
    echo "Error: At least one of --max-age or --max-count must be specified\n";
    showHelp();
    exit(1);
}

$echoTag = $options['echo'];

// Domain is always required
if (!isset($options['domain'])) {
    echo "Error: --domain is required\n";
    showHelp();
    exit(1);
}
$domain = $options['domain'];
$maxAge = isset($options['max-age']) ? (int)$options['max-age'] : null;
$maxCount = isset($options['max-count']) ? (int)$options['max-count'] : null;
$dryRun = isset($options['dry-run']);
$quiet = isset($options['quiet']);

// Validate numeric parameters
if ($maxAge !== null && $maxAge <= 0) {
    echo "Error: --max-age must be a positive number\n";
    exit(1);
}

if ($maxCount !== null && $maxCount < 0) {
    echo "Error: --max-count must be zero or a positive number\n";
    exit(1);
}

try {
    $db = Database::getInstance();
    $pdo = $db->getPdo();

    if (!$quiet) {
        echo "========================================\n";
        echo "Echomail Maintenance Utility\n";
        echo "========================================\n\n";

        if ($dryRun) {
            echo "*** DRY RUN MODE - No changes will be made ***\n\n";
        }
    }

    // Get list of echo areas to process
    $echoareas = getEchoareas($pdo, $echoTag, $domain);

    if (empty($echoareas)) {
        if ($echoTag === 'all') {
            echo "No echo areas found in database.\n";
        } else {
            echo "Echo area '$echoTag' not found.\n";
        }
        exit(1);
    }

    if (!$quiet) {
        echo "Processing " . count($echoareas) . " echo area(s)\n\n";
    }

    $startTime = microtime(true);
    $totalDeleted = 0;

    // Process each echo area
    foreach ($echoareas as $echoarea) {
        $deletedCount = processEchoarea(
            $pdo,
            $echoarea,
            $maxAge,
            $maxCount,
            $dryRun,
            $quiet
        );

        $totalDeleted += $deletedCount;
    }

    $endTime = microtime(true);
    $elapsedTime = $endTime - $startTime;


    // Run VACUUM on affected tables after deletions
    $vacuumTime = 0;
    if (!$dryRun && $totalDeleted > 0) {
        if (!$quiet) {
            echo "\n========================================\n";
            echo "Database Maintenance\n";
            echo "========================================\n";
            echo "Running VACUUM on affected tables to reclaim storage...\n";
        }

        $vacuumStart = microtime(true);
        $tables = ['echomail', 'message_read_status', 'saved_messages', 'shared_messages', 'message_links'];

        try {
            foreach ($tables as $table) {
                if (!$quiet) {
                    echo "  Vacuuming $table...\n";
                }
                $pdo->exec("VACUUM ANALYZE $table");
            }
            $vacuumTime = microtime(true) - $vacuumStart;

            if (!$quiet) {
                echo "✓ VACUUM completed in " . formatElapsedTime($vacuumTime) . "\n";
            }
        } catch (Exception $e) {
            if (!$quiet) {
                echo "⚠ Warning: VACUUM failed: " . $e->getMessage() . "\n";
            }
            error_log("Echomail maintenance VACUUM warning: " . $e->getMessage());
        }
    }

    if (!$quiet) {
        echo "\n========================================\n";
        echo "Summary\n";
        echo "========================================\n";
        echo "Total messages " . ($dryRun ? "would be deleted" : "deleted") . ": $totalDeleted\n";
        echo "Deletion time: " . formatElapsedTime($elapsedTime) . "\n";
        if ($vacuumTime > 0) {
            echo "VACUUM time: " . formatElapsedTime($vacuumTime) . "\n";
            echo "Total time: " . formatElapsedTime($elapsedTime + $vacuumTime) . "\n";
        }

        if ($dryRun) {
            echo "\nRun without --dry-run to actually delete messages.\n";
        } else {
            echo "\n✓ Maintenance completed successfully\n";
        }
    }

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    error_log("Echomail maintenance error: " . $e->getMessage());
    exit(1);
}

/**
 * Parse command line arguments
 */
function parseArguments($argv) {
    $options = [];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];

        if (strpos($arg, '--') === 0) {
            $arg = substr($arg, 2);

            if (strpos($arg, '=') !== false) {
                list($key, $value) = explode('=', $arg, 2);
                $options[$key] = $value;
            } else {
                // Flag without value
                $options[$arg] = true;
            }
        }
    }

    return $options;
}

/**
 * Show help message
 */
function showHelp() {
    global $argv;
    $script = basename($argv[0]);

    echo "\nEchomail Maintenance Utility\n";
    echo "============================\n\n";
    echo "This script deletes echomail messages and cleans up related records from:\n";
    echo "  - message_read_status, saved_messages, shared_messages, message_links\n";
    echo "Then runs VACUUM ANALYZE on all affected tables.\n\n";
    echo "Usage:\n";
    echo "  php $script --echo=TAGNAME --domain=DOMAIN --max-age=DAYS [options]\n";
    echo "  php $script --echo=all --domain=DOMAIN --max-count=NUM [options]\n\n";
    echo "Required:\n";
    echo "  --echo=TAG          Echo area tag (use 'all' for all areas in domain)\n";
    echo "  --domain=DOMAIN     Network domain (e.g., fidonet, fsxnet)\n\n";
    echo "At least one of:\n";
    echo "  --max-age=DAYS      Delete messages older than this many days\n";
    echo "  --max-count=NUM     Keep only the newest NUM messages per echo (0 = delete all)\n\n";
    echo "Optional:\n";
    echo "  --dry-run           Show what would be deleted without deleting\n";
    echo "  --quiet             Suppress output except errors\n";
    echo "  --help              Show this help message\n\n";
    echo "Examples:\n";
    echo "  # Delete messages older than 90 days in FIDO_SYSOP\n";
    echo "  php $script --echo=FIDO_SYSOP --domain=fidonet --max-age=90\n\n";
    echo "  # Keep only 500 newest messages in all fidonet echoes\n";
    echo "  php $script --echo=all --domain=fidonet --max-count=500\n\n";
    echo "  # Preview deletions in fsxnet (dry run)\n";
    echo "  php $script --echo=all --domain=fsxnet --max-age=180 --dry-run\n\n";
}

/**
 * Get echo areas to process
 */
function getEchoareas($pdo, $echoTag, $domain) {
    if ($echoTag === 'all') {
        $stmt = $pdo->prepare("
            SELECT id, tag, domain, description, message_count
            FROM echoareas
            WHERE is_active = TRUE AND domain = :domain
            ORDER BY tag
        ");
        $stmt->execute(['domain' => $domain]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT id, tag, domain, description, message_count
            FROM echoareas
            WHERE tag = :tag AND domain = :domain AND is_active = TRUE
        ");
        $stmt->execute(['tag' => $echoTag, 'domain' => $domain]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Process a single echo area
 */
function processEchoarea($pdo, $echoarea, $maxAge, $maxCount, $dryRun, $quiet) {
    $echoId = $echoarea['id'];
    $echoTag = $echoarea['tag'];
    $echoDomain = $echoarea['domain'] ?? 'fidonet';
    $currentCount = $echoarea['message_count'];

    if (!$quiet) {
        echo "Processing: $echoTag [$echoDomain]\n";
        echo "  Current messages: $currentCount\n";
    }

    $deletedCount = 0;
    $deletedByAge = 0;
    $deletedByCount = 0;

    // Delete by age if specified
    if ($maxAge !== null) {
        $deletedByAge = deleteByAge($pdo, $echoId, $maxAge, $dryRun, $quiet);
        $deletedCount += $deletedByAge;

        if (!$quiet && $deletedByAge > 0) {
            echo "  Deleted by age (>$maxAge days): $deletedByAge\n";
        }
    }

    // Delete by count if specified (after age deletion)
    // In dry-run mode with both parameters, we need to account for age deletions
    if ($maxCount !== null) {
        if ($dryRun && $maxAge !== null) {
            // In dry-run mode, simulate the remaining count after age deletion
            $remainingAfterAge = $currentCount - $deletedByAge;
            if ($remainingAfterAge > $maxCount) {
                $deletedByCount = $remainingAfterAge - $maxCount;
            }
        } else {
            $deletedByCount = deleteByCount($pdo, $echoId, $maxCount, $dryRun, $quiet);
        }

        $deletedCount += $deletedByCount;

        if (!$quiet && $deletedByCount > 0) {
            echo "  Deleted by count (keep $maxCount): $deletedByCount\n";
        }
    }

    // Update message count if not dry run
    if (!$dryRun && $deletedCount > 0) {
        updateMessageCount($pdo, $echoId);

        if (!$quiet) {
            $newCount = $currentCount - $deletedCount;
            echo "  New message count: $newCount\n";
        }
    }

    if (!$quiet) {
        if ($deletedCount > 0) {
            echo "  ✓ " . ($dryRun ? "Would delete" : "Deleted") . " $deletedCount message(s)\n";
        } else {
            echo "  ✓ No messages to delete\n";
        }
        echo "\n";
    }

    return $deletedCount;
}

/**
 * Delete messages older than specified days
 */
function deleteByAge($pdo, $echoId, $maxAge, $dryRun, $quiet) {
    // Calculate cutoff date
    $cutoffDate = new DateTime();
    $cutoffDate->modify("-$maxAge days");
    $cutoffDateStr = $cutoffDate->format('Y-m-d H:i:s');

    if ($dryRun) {
        // Count messages that would be deleted
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM echomail
            WHERE echoarea_id = :echoarea_id
            AND date_received < :cutoff_date
        ");
        $stmt->execute([
            'echoarea_id' => $echoId,
            'cutoff_date' => $cutoffDateStr
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$result['count'];
    } else {
        // Delete related records first, then echomail messages
        // Get list of message IDs that will be deleted
        $stmt = $pdo->prepare("
            SELECT id
            FROM echomail
            WHERE echoarea_id = :echoarea_id
            AND date_received < :cutoff_date
        ");
        $stmt->execute([
            'echoarea_id' => $echoId,
            'cutoff_date' => $cutoffDateStr
        ]);
        $messageIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($messageIds)) {
            return 0;
        }

        // Delete related records
        deleteRelatedRecords($pdo, $messageIds);

        // Now delete the echomail messages
        $stmt = $pdo->prepare("
            DELETE FROM echomail
            WHERE echoarea_id = :echoarea_id
            AND date_received < :cutoff_date
        ");
        $stmt->execute([
            'echoarea_id' => $echoId,
            'cutoff_date' => $cutoffDateStr
        ]);
        return $stmt->rowCount();
    }
}

/**
 * Delete oldest messages if count exceeds maximum
 */
function deleteByCount($pdo, $echoId, $maxCount, $dryRun, $quiet) {
    // First, check current count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM echomail
        WHERE echoarea_id = :echoarea_id
    ");
    $stmt->execute(['echoarea_id' => $echoId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentCount = (int)$result['count'];

    if ($currentCount <= $maxCount) {
        return 0; // Nothing to delete
    }

    $deleteCount = $currentCount - $maxCount;

    if ($dryRun) {
        return $deleteCount;
    } else {
        // Get list of message IDs to delete (oldest messages)
        $stmt = $pdo->prepare("
            SELECT id
            FROM echomail
            WHERE echoarea_id = :echoarea_id
            ORDER BY date_received ASC, id ASC
            LIMIT :delete_count
        ");
        $stmt->execute([
            'echoarea_id' => $echoId,
            'delete_count' => $deleteCount
        ]);
        $messageIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($messageIds)) {
            return 0;
        }

        // Delete related records first
        deleteRelatedRecords($pdo, $messageIds);

        // Now delete the echomail messages using CTE for better PostgreSQL performance
        $stmt = $pdo->prepare("
            WITH messages_to_delete AS (
                SELECT id
                FROM echomail
                WHERE echoarea_id = :echoarea_id
                ORDER BY date_received ASC, id ASC
                LIMIT :delete_count
            )
            DELETE FROM echomail
            WHERE id IN (SELECT id FROM messages_to_delete)
        ");
        $stmt->execute([
            'echoarea_id' => $echoId,
            'delete_count' => $deleteCount
        ]);
        return $stmt->rowCount();
    }
}

/**
 * Update the message count for an echo area
 */
function updateMessageCount($pdo, $echoId) {
    $stmt = $pdo->prepare("
        UPDATE echoareas
        SET message_count = (
            SELECT COUNT(*) FROM echomail WHERE echoarea_id = :echoarea_id
        )
        WHERE id = :echoarea_id
    ");
    $stmt->execute(['echoarea_id' => $echoId]);
}

/**
 * Delete related records for echomail messages
 * This handles cleanup of message_read_status, saved_messages, shared_messages, and message_links
 */
function deleteRelatedRecords($pdo, $messageIds) {
    if (empty($messageIds)) {
        return;
    }

    // For large batches, process in chunks to avoid query size limits
    $chunkSize = 1000;
    $chunks = array_chunk($messageIds, $chunkSize);

    foreach ($chunks as $chunk) {
        $placeholders = str_repeat('?,', count($chunk) - 1) . '?';

        // Delete from message_read_status
        $stmt = $pdo->prepare("
            DELETE FROM message_read_status
            WHERE message_type = 'echomail'
            AND message_id IN ($placeholders)
        ");
        $stmt->execute($chunk);

        // Delete from saved_messages
        $stmt = $pdo->prepare("
            DELETE FROM saved_messages
            WHERE message_type = 'echomail'
            AND message_id IN ($placeholders)
        ");
        $stmt->execute($chunk);

        // Delete from shared_messages
        $stmt = $pdo->prepare("
            DELETE FROM shared_messages
            WHERE message_type = 'echomail'
            AND message_id IN ($placeholders)
        ");
        $stmt->execute($chunk);

        // Delete from message_links
        $stmt = $pdo->prepare("
            DELETE FROM message_links
            WHERE message_type = 'echomail'
            AND message_id IN ($placeholders)
        ");
        $stmt->execute($chunk);
    }
}

/**
 * Format elapsed time in a human-readable format
 */
function formatElapsedTime($seconds) {
    if ($seconds < 1) {
        return number_format($seconds * 1000, 0) . " ms";
    } elseif ($seconds < 60) {
        return number_format($seconds, 2) . " seconds";
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;
        return sprintf("%d min %d sec", $minutes, $remainingSeconds);
    } else {
        $hours = floor($seconds / 3600);
        $remainingMinutes = floor(($seconds % 3600) / 60);
        $remainingSeconds = $seconds % 60;
        return sprintf("%d hr %d min %d sec", $hours, $remainingMinutes, $remainingSeconds);
    }
}
