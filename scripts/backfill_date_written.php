#!/usr/bin/env php
<?php
/**
 * Backfill date_written for messages with incorrect TZUTC parsing
 *
 * This script fixes messages where TZUTC offset was not applied due to missing sign.
 * It re-parses the date_written field by applying the TZUTC offset from kludge_lines.
 *
 * Usage: php backfill_date_written.php [--limit=N] [--dry-run] [--echomail] [--netmail]
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

echo "=== Date Written Backfill Script ===\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no changes will be made)" : "LIVE") . "\n";
if ($limit) {
    echo "Limit: $limit messages per type\n";
}
echo "\n";

$db = Database::getInstance()->getPdo();

/**
 * Extract TZUTC offset from kludge lines
 */
function extractTzutcOffset($kludgeLines)
{
    if (empty($kludgeLines)) {
        return null;
    }

    $lines = explode("\n", $kludgeLines);
    foreach ($lines as $line) {
        $line = trim($line);
        // Check for TZUTC kludge (starts with \x01 or ^A)
        if (preg_match('/^\x01TZUTC:\s*(.+)$/i', $line, $matches)) {
            $tzutcLine = trim($matches[1]);
            // TZUTC format: "+HHMM", "-HHMM", or "HHMM" (e.g., "+0800", "-0500", "1100")
            if (preg_match('/^([+-])?(\d{2})(\d{2})/', $tzutcLine, $tzMatches)) {
                $sign = $tzMatches[1] ?? '+'; // Default to + if no sign provided (Fidonet convention)
                $hours = (int)$tzMatches[2];
                $minutes = (int)$tzMatches[3];
                $totalMinutes = ($hours * 60) + $minutes;
                return ($sign === '+') ? $totalMinutes : -$totalMinutes;
            }
        }
    }

    return null;
}

/**
 * Apply TZUTC offset to convert from sender's local time to UTC
 */
function applyTzutcOffset($dateString, $tzutcOffsetMinutes)
{
    if ($tzutcOffsetMinutes === null) {
        return $dateString;
    }

    try {
        // The raw date from the message is in the sender's local timezone
        // TZUTC tells us the offset from UTC (+0800 means UTC+8, -0500 means UTC-5)
        // To convert to UTC, we need to subtract the offset
        $dt = new \DateTime($dateString, new \DateTimeZone('UTC'));
        $dt->modify("-{$tzutcOffsetMinutes} minutes"); // Convert from sender's timezone to UTC
        return $dt->format('Y-m-d H:i:s');
    } catch (\Exception $e) {
        return $dateString; // Return original date if offset application fails
    }
}

/**
 * Process echomail messages
 */
if ($processEchomail) {
    echo "Processing echomail messages...\n";
    echo str_repeat("-", 60) . "\n";

    // Get messages with kludge_lines that might have TZUTC
    $query = "SELECT id, date_written, kludge_lines FROM echomail WHERE kludge_lines IS NOT NULL AND kludge_lines LIKE '%TZUTC:%'";
    if ($limit) {
        $query .= " LIMIT $limit";
    }
    $stmt = $db->query($query);
    $messages = $stmt->fetchAll();

    echo "Found " . count($messages) . " echomail messages with TZUTC\n\n";

    $updated = 0;
    $unchanged = 0;
    $noOffset = 0;

    foreach ($messages as $msg) {
        $tzutcOffset = extractTzutcOffset($msg['kludge_lines']);

        if ($tzutcOffset === null) {
            $noOffset++;
            continue;
        }

        // Apply offset to current date_written
        $correctedDate = applyTzutcOffset($msg['date_written'], $tzutcOffset);

        // Check if date would actually change
        if ($correctedDate === $msg['date_written']) {
            $unchanged++;
            continue;
        }

        // Show the change
        if ($dryRun || $updated < 10) { // Show first 10 changes
            echo "ID {$msg['id']}:\n";
            echo "  TZUTC Offset: " . ($tzutcOffset >= 0 ? '+' : '') . sprintf("%04d", abs($tzutcOffset / 60) * 100 + abs($tzutcOffset % 60)) . " ({$tzutcOffset} minutes)\n";
            echo "  Old: {$msg['date_written']}\n";
            echo "  New: {$correctedDate}\n";
            echo "\n";
        }

        if (!$dryRun) {
            $updateStmt = $db->prepare("UPDATE echomail SET date_written = ? WHERE id = ?");
            $updateStmt->execute([$correctedDate, $msg['id']]);
        }
        $updated++;

        if ($updated % 100 == 0 && !$dryRun) {
            echo "  Processed $updated messages...\n";
        }
    }

    echo "\nEchomail Results:\n";
    echo "  Would update: $updated\n";
    echo "  Already correct: $unchanged\n";
    echo "  No TZUTC offset: $noOffset\n";
    echo "\n";
}

/**
 * Process netmail messages
 */
if ($processNetmail) {
    echo "Processing netmail messages...\n";
    echo str_repeat("-", 60) . "\n";

    // Get messages with kludge_lines that might have TZUTC
    $query = "SELECT id, date_written, kludge_lines FROM netmail WHERE kludge_lines IS NOT NULL AND kludge_lines LIKE '%TZUTC:%'";
    if ($limit) {
        $query .= " LIMIT $limit";
    }
    $stmt = $db->query($query);
    $messages = $stmt->fetchAll();

    echo "Found " . count($messages) . " netmail messages with TZUTC\n\n";

    $updated = 0;
    $unchanged = 0;
    $noOffset = 0;

    foreach ($messages as $msg) {
        $tzutcOffset = extractTzutcOffset($msg['kludge_lines']);

        if ($tzutcOffset === null) {
            $noOffset++;
            continue;
        }

        // Apply offset to current date_written
        $correctedDate = applyTzutcOffset($msg['date_written'], $tzutcOffset);

        // Check if date would actually change
        if ($correctedDate === $msg['date_written']) {
            $unchanged++;
            continue;
        }

        // Show the change
        if ($dryRun || $updated < 10) { // Show first 10 changes
            echo "ID {$msg['id']}:\n";
            echo "  TZUTC Offset: " . ($tzutcOffset >= 0 ? '+' : '') . sprintf("%04d", abs($tzutcOffset / 60) * 100 + abs($tzutcOffset % 60)) . " ({$tzutcOffset} minutes)\n";
            echo "  Old: {$msg['date_written']}\n";
            echo "  New: {$correctedDate}\n";
            echo "\n";
        }

        if (!$dryRun) {
            $updateStmt = $db->prepare("UPDATE netmail SET date_written = ? WHERE id = ?");
            $updateStmt->execute([$correctedDate, $msg['id']]);
        }
        $updated++;

        if ($updated % 100 == 0 && !$dryRun) {
            echo "  Processed $updated messages...\n";
        }
    }

    echo "\nNetmail Results:\n";
    echo "  Would update: $updated\n";
    echo "  Already correct: $unchanged\n";
    echo "  No TZUTC offset: $noOffset\n";
    echo "\n";
}

echo "=== Backfill Complete ===\n";
if ($dryRun) {
    echo "This was a dry run. Run without --dry-run to apply changes.\n";
}
