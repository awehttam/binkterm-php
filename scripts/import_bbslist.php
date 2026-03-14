#!/usr/bin/env php
<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
 *
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the
 * following conditions are met:
 *
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 *
 */

/**
 * import_bbslist.php — Import a BBS list from a ZIP file containing bbslist.csv.
 *
 * Usage:
 *   php scripts/import_bbslist.php <path-to-zip>
 *   php scripts/import_bbslist.php --help
 *
 * File area rule example (match any .zip file in a specific area):
 *   pattern:  /bbslist.*\.zip$/i
 *   script:   php %basedir%/scripts/import_bbslist.php %filepath%
 *
 * CSV format (bbslist.csv inside the zip):
 *   bbsName, bbsSysop, newLogin, TelnetAddress, bbsPort, sshPort,
 *   WebAddress, location, Modem, software
 *
 * Fields not stored: newLogin (caller login alias), Modem (phone number).
 * Entries are upserted by BBS name (case-insensitive). No deletions are performed.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\BbsDirectory;
use BinktermPHP\Database;

// ── Argument parsing ──────────────────────────────────────────────────────────

$args = array_slice($argv, 1);

if (empty($args) || in_array('--help', $args) || in_array('-h', $args)) {
    echo <<<HELP
Usage: php scripts/import_bbslist.php <path-to-zip> [options]

Imports a BBS list from a ZIP archive containing bbslist.csv into the
BBS directory. Entries are upserted by name; no deletions are performed.

Arguments:
  <path-to-zip>   Path to the ZIP file to import

Options:
  --dry-run       Parse and display rows without writing to the database
  --quiet         Suppress per-row output (summary still printed)
  --help, -h      Show this help

CSV columns expected inside bbslist.csv:
  bbsName, bbsSysop, newLogin, TelnetAddress, bbsPort, sshPort,
  WebAddress, location, Modem, software

File area rule example:
  pattern: /bbslist.*\\.zip\$/i
  script:  php %basedir%/scripts/import_bbslist.php %filepath%

Exit codes:
  0  Success
  1  Error (missing file, bad zip, no CSV found, database error)

HELP;
    exit(0);
}

$zipPath = null;
$dryRun  = false;
$quiet   = false;

foreach ($args as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--quiet') {
        $quiet = true;
    } elseif (!str_starts_with($arg, '--')) {
        $zipPath = $arg;
    }
}

if ($zipPath === null) {
    fwrite(STDERR, "Error: no ZIP file path provided. Use --help for usage.\n");
    exit(1);
}

if (!file_exists($zipPath)) {
    fwrite(STDERR, "Error: file not found: {$zipPath}\n");
    exit(1);
}

// ── Open ZIP ──────────────────────────────────────────────────────────────────

$zip = new ZipArchive();
$opened = $zip->open($zipPath);
if ($opened !== true) {
    fwrite(STDERR, "Error: could not open ZIP file (code {$opened}): {$zipPath}\n");
    exit(1);
}

$csvContent = $zip->getFromName('bbslist.csv');
if ($csvContent === false) {
    // Try case-insensitive search
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (strtolower(basename($stat['name'])) === 'bbslist.csv') {
            $csvContent = $zip->getFromIndex($i);
            break;
        }
    }
}
$zip->close();

if ($csvContent === false || $csvContent === '') {
    fwrite(STDERR, "Error: bbslist.csv not found inside {$zipPath}\n");
    exit(1);
}

// ── Parse CSV ─────────────────────────────────────────────────────────────────

// Write to a temp file so fgetcsv can process it reliably
$tmpFile = tempnam(sys_get_temp_dir(), 'bbslist_');
file_put_contents($tmpFile, $csvContent);
$fh = fopen($tmpFile, 'r');

// Read header row
$header = fgetcsv($fh);
if ($header === false) {
    fclose($fh);
    unlink($tmpFile);
    fwrite(STDERR, "Error: bbslist.csv appears to be empty\n");
    exit(1);
}

// Normalize header names (trim whitespace)
$header = array_map('trim', $header);

// Build a column-index map for case-insensitive header lookup
$colIndex = [];
foreach ($header as $i => $col) {
    $colIndex[strtolower($col)] = $i;
}

/**
 * Get a value from a CSV row by column name (case-insensitive), or null if absent/empty.
 */
$col = function(array $row, string $name) use ($colIndex): ?string {
    $key = strtolower($name);
    if (!isset($colIndex[$key])) {
        return null;
    }
    $val = trim($row[$colIndex[$key]] ?? '');
    return $val !== '' ? $val : null;
};

// ── Import ────────────────────────────────────────────────────────────────────

if (!$dryRun) {
    $db        = Database::getInstance()->getPdo();
    $directory = new BbsDirectory($db);
}

$rowNum    = 0;
$inserted  = 0;
$updated   = 0;
$skipped   = 0;
$errors    = 0;

while (($row = fgetcsv($fh)) !== false) {
    $rowNum++;

    $name = $col($row, 'bbsName');
    if ($name === null) {
        if (!$quiet) {
            echo "  Row {$rowNum}: SKIP (empty bbsName)\n";
        }
        $skipped++;
        continue;
    }

    $entry = [
        'name'        => $name,
        'sysop'       => $col($row, 'bbsSysop'),
        'telnet_host' => $col($row, 'TelnetAddress'),
        'telnet_port' => $col($row, 'bbsPort'),
        'ssh_port'    => $col($row, 'sshPort'),
        'website'     => $col($row, 'WebAddress'),
        'location'    => $col($row, 'location'),
        'software'    => $col($row, 'software'),
        // newLogin (caller login alias) and Modem (phone number) are not stored
    ];

    if ($dryRun) {
        if (!$quiet) {
            echo sprintf(
                "  Row %d: %s | sysop=%s | %s:%s | ssh=%s | %s | %s\n",
                $rowNum,
                $entry['name'],
                $entry['sysop'] ?? '(none)',
                $entry['telnet_host'] ?? '(none)',
                $entry['telnet_port'] ?? '23',
                $entry['ssh_port'] ?? '(none)',
                $entry['location'] ?? '(none)',
                $entry['software'] ?? '(none)'
            );
        }
        $inserted++; // count as "would insert/update"
        continue;
    }

    try {
        $directory->upsertByName($entry);
        if (!$quiet) {
            echo "  Row {$rowNum}: upserted '{$name}'\n";
        }
        $inserted++;
    } catch (\Throwable $e) {
        fwrite(STDERR, "  Row {$rowNum}: ERROR upserting '{$name}': " . $e->getMessage() . "\n");
        $errors++;
    }
}

fclose($fh);
unlink($tmpFile);

// ── Summary ───────────────────────────────────────────────────────────────────

$action = $dryRun ? 'Would process' : 'Processed';
echo sprintf(
    "%s %d rows: %d upserted, %d skipped, %d errors\n",
    $action, $rowNum, $inserted, $skipped, $errors
);

exit($errors > 0 ? 1 : 0);
