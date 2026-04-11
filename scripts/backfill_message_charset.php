#!/usr/bin/env php
<?php
/**
 * backfill_message_charset.php - Populate message_charset from the CHRS kludge
 *
 * Finds echomail and netmail rows where message_charset IS NULL, extracts the
 * CHRS kludge from kludge_lines, normalises the value via
 * BinkpConfig::normalizeCharset(), and writes it back to message_charset.
 *
 * Rows with no kludge_lines or no CHRS kludge are left untouched.
 *
 * Runs as a DRY RUN by default. Pass --live to apply changes.
 *
 * Usage:
 *   php scripts/backfill_message_charset.php            # dry run
 *   php scripts/backfill_message_charset.php --live     # apply changes
 *   php scripts/backfill_message_charset.php --echomail # echomail only
 *   php scripts/backfill_message_charset.php --netmail  # netmail only
 *   php scripts/backfill_message_charset.php --limit=500
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;
use BinktermPHP\Binkp\Config\BinkpConfig;

$options        = getopt('', ['live', 'limit:', 'echomail', 'netmail']);
$dryRun         = !isset($options['live']);
$limit          = isset($options['limit']) ? (int)$options['limit'] : null;
$processEcho    = isset($options['echomail']) || !isset($options['netmail']);
$processNetmail = isset($options['netmail'])  || !isset($options['echomail']);

echo "=== message_charset Backfill ===\n";
echo "Mode:  " . ($dryRun ? "DRY RUN (pass --live to apply changes)" : "LIVE") . "\n";
if ($limit) {
    echo "Limit: $limit rows per table\n";
}
echo "\n";

$db = Database::getInstance()->getPdo();

/**
 * Extract the raw charset token from the CHRS kludge in kludge_lines.
 *
 * CHRS format: \x01CHRS: <charset> [<level>]
 *
 * @param string $kludgeLines
 * @return string|null Raw charset token (e.g. "IBMPC", "CP866"), or null if absent.
 */
function extractChrsCharset(string $kludgeLines): ?string
{
    foreach (explode("\n", $kludgeLines) as $line) {
        $line = trim($line);
        if (preg_match('/^\x01CHRS:\s*([A-Za-z0-9_\-]+)/i', $line, $m)) {
            return $m[1];
        }
    }
    return null;
}

/**
 * Process one table (echomail or netmail) and return counts.
 *
 * @param \PDO   $db
 * @param string $table   'echomail' or 'netmail'
 * @param int|null $limit
 * @param bool   $dryRun
 * @return array{found: int, updated: int, skipped: int}
 */
function processTable(\PDO $db, string $table, ?int $limit, bool $dryRun): array
{
    $sql = "SELECT id, kludge_lines FROM {$table}
            WHERE message_charset IS NULL
              AND kludge_lines IS NOT NULL";
    if ($limit) {
        $sql .= " LIMIT " . (int)$limit;
    }

    $rows    = $db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    $found   = count($rows);
    $updated = 0;
    $skipped = 0;

    echo "Processing {$table}...\n";
    echo str_repeat('-', 60) . "\n";
    echo "Found {$found} rows with null message_charset\n\n";

    $updateStmt = $dryRun ? null : $db->prepare(
        "UPDATE {$table} SET message_charset = ? WHERE id = ?"
    );

    foreach ($rows as $row) {
        $raw = extractChrsCharset($row['kludge_lines']);
        if ($raw === null) {
            $skipped++;
            continue;
        }

        $charset = BinkpConfig::normalizeCharset($raw);

        if ($dryRun) {
            echo "  [DRY RUN] id={$row['id']}  CHRS raw={$raw}  -> message_charset={$charset}\n";
        } else {
            $updateStmt->execute([$charset, $row['id']]);
        }
        $updated++;

        if ($updated % 500 === 0) {
            echo "  {$updated} rows processed...\n";
        }
    }

    echo "\n{$table} results:\n";
    echo "  Updated:        {$updated}\n";
    echo "  No CHRS kludge: {$skipped}\n\n";

    return ['found' => $found, 'updated' => $updated, 'skipped' => $skipped];
}

$totals = ['found' => 0, 'updated' => 0, 'skipped' => 0];

if ($processEcho) {
    $r = processTable($db, 'echomail', $limit, $dryRun);
    foreach ($totals as $k => $_) {
        $totals[$k] += $r[$k];
    }
}

if ($processNetmail) {
    $r = processTable($db, 'netmail', $limit, $dryRun);
    foreach ($totals as $k => $_) {
        $totals[$k] += $r[$k];
    }
}

echo "=== Totals ===\n";
echo "  Rows found:     {$totals['found']}\n";
echo "  Updated:        {$totals['updated']}\n";
echo "  No CHRS kludge: {$totals['skipped']}\n";

if ($dryRun) {
    echo "\nThis was a dry run. Pass --live to apply changes.\n";
}
