#!/usr/bin/env php
<?php
/**
 * Backfill reply_to_id for existing messages
 *
 * This script processes existing echomail and netmail messages that have
 * null reply_to_id, extracts REPLY from their kludge_lines, and populates
 * reply_to_id by looking up the parent message.
 *
 * Usage: php backfill_reply_to_id.php [--limit=N] [--dry-run]
 *
 * Options:
 *   --limit=N    Process only N messages (useful for testing)
 *   --dry-run    Show what would be done without making changes
 *   --echomail   Process only echomail
 *   --netmail    Process only netmail
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;

// Parse command line options
$options = getopt('', ['limit:', 'dry-run', 'echomail', 'netmail']);
$limit = isset($options['limit']) ? (int)$options['limit'] : null;
$dryRun = isset($options['dry-run']);
$processEchomail = isset($options['echomail']) || (!isset($options['netmail']));
$processNetmail = isset($options['netmail']) || (!isset($options['echomail']));

echo "=== Reply-To-ID Backfill Script ===\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no changes will be made)" : "LIVE") . "\n";
if ($limit) {
    echo "Limit: $limit messages per type\n";
}
echo "\n";

$db = Database::getInstance()->getPdo();

/**
 * Extract REPLY MSGID from kludge lines
 */
function extractReplyFromKludge($kludgeLines)
{
    if (empty($kludgeLines)) {
        return null;
    }

    // Look for REPLY: line in kludge
    $lines = explode("\n", $kludgeLines);
    foreach ($lines as $line) {
        $line = trim($line);
        // Check for REPLY kludge (starts with \x01 or ^A)
        if (preg_match('/^\x01REPLY:\s*(.+)$/i', $line, $matches)) {
            return trim($matches[1]);
        }
        // Also handle ^A notation (visible ^A character)
        if (preg_match('/^\^AREPLY:\s*(.+)$/i', $line, $matches)) {
            return trim($matches[1]);
        }
        // Also handle plain REPLY: without control character
        if (preg_match('/^REPLY:\s*(.+)$/i', $line, $matches)) {
            return trim($matches[1]);
        }
    }

    return null;
}

/**
 * Process echomail messages
 */
if ($processEchomail) {
    echo "Processing echomail messages...\n";
    echo str_repeat("-", 60) . "\n";

    // Get messages with null reply_to_id
    $query = "SELECT id, message_id, echoarea_id, kludge_lines FROM echomail WHERE reply_to_id IS NULL AND kludge_lines IS NOT NULL";
    if ($limit) {
        $query .= " LIMIT $limit";
    }
    $stmt = $db->query($query);
    $messages = $stmt->fetchAll();

    echo "Found " . count($messages) . " echomail messages to process\n\n";

    $updated = 0;
    $notFound = 0;
    $noReply = 0;

    foreach ($messages as $msg) {
        $replyMsgId = extractReplyFromKludge($msg['kludge_lines']);

        if (!$replyMsgId) {
            $noReply++;
            continue;
        }

        // Look up parent message by message_id within same echoarea
        $parentStmt = $db->prepare("SELECT id FROM echomail WHERE message_id = ? AND echoarea_id = ? LIMIT 1");
        $parentStmt->execute([$replyMsgId, $msg['echoarea_id']]);
        $parent = $parentStmt->fetch();

        if ($parent) {
            if (!$dryRun) {
                $updateStmt = $db->prepare("UPDATE echomail SET reply_to_id = ? WHERE id = ?");
                $updateStmt->execute([$parent['id'], $msg['id']]);
            }
            $updated++;

            if ($updated % 100 == 0) {
                echo "  Processed $updated messages...\n";
            }
        } else {
            $notFound++;
        }
    }

    echo "\nEchomail Results:\n";
    echo "  Updated: $updated\n";
    echo "  Parent not found: $notFound\n";
    echo "  No REPLY kludge: $noReply\n";
    echo "\n";
}

/**
 * Process netmail messages
 */
if ($processNetmail) {
    echo "Processing netmail messages...\n";
    echo str_repeat("-", 60) . "\n";

    // Get messages with null reply_to_id
    $query = "SELECT id, message_id, kludge_lines FROM netmail WHERE reply_to_id IS NULL AND kludge_lines IS NOT NULL";
    if ($limit) {
        $query .= " LIMIT $limit";
    }
    $stmt = $db->query($query);
    $messages = $stmt->fetchAll();

    echo "Found " . count($messages) . " netmail messages to process\n\n";

    $updated = 0;
    $notFound = 0;
    $noReply = 0;

    foreach ($messages as $msg) {
        $replyMsgId = extractReplyFromKludge($msg['kludge_lines']);

        if (!$replyMsgId) {
            $noReply++;
            continue;
        }

        // Look up parent message by message_id (no echoarea restriction for netmail)
        $parentStmt = $db->prepare("SELECT id FROM netmail WHERE message_id = ? LIMIT 1");
        $parentStmt->execute([$replyMsgId]);
        $parent = $parentStmt->fetch();

        if ($parent) {
            if (!$dryRun) {
                $updateStmt = $db->prepare("UPDATE netmail SET reply_to_id = ? WHERE id = ?");
                $updateStmt->execute([$parent['id'], $msg['id']]);
            }
            $updated++;

            if ($updated % 100 == 0) {
                echo "  Processed $updated messages...\n";
            }
        } else {
            $notFound++;
        }
    }

    echo "\nNetmail Results:\n";
    echo "  Updated: $updated\n";
    echo "  Parent not found: $notFound\n";
    echo "  No REPLY kludge: $noReply\n";
    echo "\n";
}

echo "=== Backfill Complete ===\n";
if ($dryRun) {
    echo "This was a dry run. Run without --dry-run to apply changes.\n";
}
