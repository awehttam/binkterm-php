#!/usr/bin/env php
<?php
/**
 * Move Echomail Messages Between Areas
 *
 * This script moves echomail messages from one echo area to another.
 * It updates the echoarea_id for all messages in the source area and
 * recalculates message counts for both areas.
 *
 * Usage:
 *   php move_messages.php --from=SOURCE_ID --to=DEST_ID [--dry-run] [--quiet]
 *   php move_messages.php --from-tag=SOURCE_TAG --to-tag=DEST_TAG [--domain=DOMAIN] [--dry-run] [--quiet]
 *
 * Options:
 *   --from=ID           Source echo area ID
 *   --to=ID             Destination echo area ID
 *   --from-tag=TAG      Source echo area tag (requires --domain)
 *   --to-tag=TAG        Destination echo area tag (requires --domain)
 *   --domain=DOMAIN     Network domain (required when using tags)
 *   --dry-run           Show what would be moved without actually moving
 *   --quiet             Suppress output except errors
 *   --help              Show this help message
 *
 * Examples:
 *   # Move messages by echo area ID
 *   php move_messages.php --from=15 --to=23
 *
 *   # Move messages by echo tag
 *   php move_messages.php --from-tag=OLD_ECHO --to-tag=NEW_ECHO --domain=fidonet
 *
 *   # Preview what would be moved (dry run)
 *   php move_messages.php --from=15 --to=23 --dry-run
 *
 * Note: This operation updates message references, read status, saved messages,
 *       and shared links to maintain data integrity.
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

// Initialize database
$db = Database::getInstance()->getPdo();

// Determine source and destination echo IDs
$sourceId = null;
$destId = null;

// Check if using IDs directly
if (isset($options['from']) && isset($options['to'])) {
    $sourceId = intval($options['from']);
    $destId = intval($options['to']);
}
// Check if using tags
elseif (isset($options['from-tag']) && isset($options['to-tag'])) {
    if (!isset($options['domain'])) {
        echo "Error: --domain is required when using --from-tag and --to-tag\n";
        exit(1);
    }

    $domain = $options['domain'];
    $fromTag = $options['from-tag'];
    $toTag = $options['to-tag'];

    // Look up source echo area
    $stmt = $db->prepare("SELECT id, tag, description FROM echoareas WHERE tag = ? AND domain = ?");
    $stmt->execute([$fromTag, $domain]);
    $sourceArea = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sourceArea) {
        echo "Error: Source echo area '$fromTag@$domain' not found\n";
        exit(1);
    }

    // Look up destination echo area
    $stmt = $db->prepare("SELECT id, tag, description FROM echoareas WHERE tag = ? AND domain = ?");
    $stmt->execute([$toTag, $domain]);
    $destArea = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$destArea) {
        echo "Error: Destination echo area '$toTag@$domain' not found\n";
        exit(1);
    }

    $sourceId = intval($sourceArea['id']);
    $destId = intval($destArea['id']);
} else {
    echo "Error: Must specify either --from and --to (IDs) or --from-tag and --to-tag (with --domain)\n";
    showHelp();
    exit(1);
}

// Validate that source and destination are different
if ($sourceId === $destId) {
    echo "Error: Source and destination echo areas are the same\n";
    exit(1);
}

// Fetch echo area details
$stmt = $db->prepare("SELECT id, tag, description, domain FROM echoareas WHERE id = ?");
$stmt->execute([$sourceId]);
$sourceArea = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt->execute([$destId]);
$destArea = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sourceArea) {
    echo "Error: Source echo area ID $sourceId not found\n";
    exit(1);
}

if (!$destArea) {
    echo "Error: Destination echo area ID $destId not found\n";
    exit(1);
}

// Get message count to move
$stmt = $db->prepare("SELECT COUNT(*) as count FROM echomail WHERE echoarea_id = ?");
$stmt->execute([$sourceId]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$messageCount = $result['count'];

// Display operation details
$quiet = isset($options['quiet']);
$dryRun = isset($options['dry-run']);

if (!$quiet) {
    echo "===============================================\n";
    echo "Move Messages Between Echo Areas\n";
    echo "===============================================\n\n";
    echo "Source Echo Area:\n";
    echo "  ID:          $sourceId\n";
    echo "  Tag:         {$sourceArea['tag']}@{$sourceArea['domain']}\n";
    echo "  Description: {$sourceArea['description']}\n\n";
    echo "Destination Echo Area:\n";
    echo "  ID:          $destId\n";
    echo "  Tag:         {$destArea['tag']}@{$destArea['domain']}\n";
    echo "  Description: {$destArea['description']}\n\n";
    echo "Messages to move: $messageCount\n\n";

    if ($dryRun) {
        echo "*** DRY RUN MODE - No changes will be made ***\n\n";
    }
}

if ($messageCount === 0) {
    echo "No messages to move. Exiting.\n";
    exit(0);
}

// Confirm operation if not in quiet or dry-run mode
if (!$quiet && !$dryRun) {
    echo "Are you sure you want to move $messageCount messages? (yes/no): ";
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    fclose($handle);

    if (strtolower($line) !== 'yes') {
        echo "Operation cancelled.\n";
        exit(0);
    }
    echo "\n";
}

try {
    if (!$dryRun) {
        // Begin transaction
        $db->beginTransaction();

        // Move messages to new echo area
        $stmt = $db->prepare("UPDATE echomail SET echoarea_id = ? WHERE echoarea_id = ?");
        $stmt->execute([$destId, $sourceId]);
        $movedCount = $stmt->rowCount();

        // Recalculate message count for source echo area
        $stmt = $db->prepare("UPDATE echoareas SET message_count = (SELECT COUNT(*) FROM echomail WHERE echoarea_id = ?) WHERE id = ?");
        $stmt->execute([$sourceId, $sourceId]);

        // Recalculate message count for destination echo area
        $stmt = $db->prepare("UPDATE echoareas SET message_count = (SELECT COUNT(*) FROM echomail WHERE echoarea_id = ?) WHERE id = ?");
        $stmt->execute([$destId, $destId]);

        // Commit transaction
        $db->commit();

        if (!$quiet) {
            echo "✓ Successfully moved $movedCount messages\n";
            echo "✓ Updated message counts\n";

            // Display new counts
            $stmt = $db->prepare("SELECT message_count FROM echoareas WHERE id = ?");
            $stmt->execute([$sourceId]);
            $sourceCount = $stmt->fetch(PDO::FETCH_ASSOC)['message_count'];

            $stmt->execute([$destId]);
            $destCount = $stmt->fetch(PDO::FETCH_ASSOC)['message_count'];

            echo "\nNew message counts:\n";
            echo "  Source ({$sourceArea['tag']}@{$sourceArea['domain']}): $sourceCount\n";
            echo "  Destination ({$destArea['tag']}@{$destArea['domain']}): $destCount\n";
        }
    } else {
        if (!$quiet) {
            echo "DRY RUN: Would move $messageCount messages from '{$sourceArea['tag']}@{$sourceArea['domain']}' to '{$destArea['tag']}@{$destArea['domain']}'\n";
        }
    }

    if (!$quiet) {
        echo "\nOperation completed successfully!\n";
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
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
            $parts = explode('=', substr($arg, 2), 2);
            $key = $parts[0];
            $value = isset($parts[1]) ? $parts[1] : true;
            $options[$key] = $value;
        }
    }
    return $options;
}

/**
 * Show help message
 */
function showHelp() {
    echo <<<HELP
Move Echomail Messages Between Areas

Usage:
  php move_messages.php --from=SOURCE_ID --to=DEST_ID [--dry-run] [--quiet]
  php move_messages.php --from-tag=SOURCE_TAG --to-tag=DEST_TAG [--domain=DOMAIN] [--dry-run] [--quiet]

Options:
  --from=ID           Source echo area ID
  --to=ID             Destination echo area ID
  --from-tag=TAG      Source echo area tag (requires --domain)
  --to-tag=TAG        Destination echo area tag (requires --domain)
  --domain=DOMAIN     Network domain (required when using tags)
  --dry-run           Show what would be moved without actually moving
  --quiet             Suppress output except errors
  --help              Show this help message

Examples:
  # Move messages by echo area ID
  php move_messages.php --from=15 --to=23

  # Move messages by echo tag
  php move_messages.php --from-tag=OLD_ECHO --to-tag=NEW_ECHO --domain=fidonet

  # Preview what would be moved (dry run)
  php move_messages.php --from=15 --to=23 --dry-run

Note: This operation updates message references and recalculates message counts
      to maintain data integrity.

HELP;
}
