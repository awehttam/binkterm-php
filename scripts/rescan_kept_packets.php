#!/usr/bin/env php
<?php

/**
 * rescan_kept_packets.php
 *
 * Scans the kept packet directory for messages not present in the database
 * and optionally reimports them.
 *
 * Also supports --preview mode which runs both the production null-terminated
 * parser and the experimental fixed-width parser side by side, showing field
 * values and MSGIDs from each without importing anything. Use this to validate
 * the fixed-width parser before enabling it in production.
 *
 * Usage:
 *   php scripts/rescan_kept_packets.php [options]
 *
 * Options:
 *   --reimport       Import missing messages found during the scan.
 *                    date_received is set to the .pkt file's mtime.
 *   --dir=PATH       Override the kept packet directory (default: data/inbound/keep).
 *   --file=PATH      Scan a single specific .pkt file instead of a directory.
 *   --dry-run        Report only; do not import even if --reimport is given.
 *   --verbose        Show details for every message checked, not just missing ones.
 *   --preview        Show field values from both parsers for packets with missing
 *                    messages. Does not import. Use to validate the fixed-width parser.
 *   --help           Show this help text.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;
use BinktermPHP\BinkdProcessor;

// ---------------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------------
$opts = [
    'reimport' => false,
    'dry-run'  => false,
    'verbose'  => false,
    'preview'  => false,
    'dir'      => null,
    'file'     => null,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--reimport')        { $opts['reimport'] = true; }
    elseif ($arg === '--dry-run')     { $opts['dry-run']  = true; }
    elseif ($arg === '--verbose')     { $opts['verbose']  = true; }
    elseif ($arg === '--preview')     { $opts['preview']  = true; }
    elseif ($arg === '--help')        { showHelp(); exit(0); }
    elseif (str_starts_with($arg, '--dir='))  { $opts['dir']  = substr($arg, 6); }
    elseif (str_starts_with($arg, '--file=')) { $opts['file'] = substr($arg, 7); }
    else {
        fwrite(STDERR, "Unknown option: $arg\n");
        showHelp();
        exit(1);
    }
}

function showHelp(): void
{
    global $argv;
    echo "Usage: {$argv[0]} [--reimport] [--dry-run] [--verbose] [--dir=PATH] [--file=PATH]\n";
    echo "\n";
    echo "  --reimport     Import messages missing from the database.\n";
    echo "                 date_received is set to the .pkt file modification time.\n";
    echo "  --dry-run      Report without importing (overrides --reimport).\n";
    echo "  --verbose      Show all messages checked, not just missing ones.\n";
    echo "  --preview      For packets with missing messages, show what the fixed-width\n";
    echo "                 parser would import (field values, MSGID) without importing.\n";
    echo "  --dir=PATH     Kept packet directory (default: data/inbound/keep).\n";
    echo "  --file=PATH    Scan a single specific .pkt file.\n";
}

$baseDir = dirname(__DIR__);
$db = Database::getInstance()->getPdo();

// ---------------------------------------------------------------------------
// Discover .pkt files — either a single file or a directory tree
// ---------------------------------------------------------------------------
$packets = [];

if ($opts['file'] !== null) {
    $filePath = $opts['file'];
    if (!file_exists($filePath)) {
        fwrite(STDERR, "File not found: $filePath\n");
        exit(1);
    }
    if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== 'pkt') {
        fwrite(STDERR, "File does not appear to be a .pkt file: $filePath\n");
        exit(1);
    }
    $packets[] = realpath($filePath);
} else {
    $keepDir = $opts['dir'] ?? ($baseDir . '/data/inbound/keep');
    if (!is_dir($keepDir)) {
        fwrite(STDERR, "Kept packet directory not found: $keepDir\n");
        exit(1);
    }
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($keepDir));
    foreach ($iter as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'pkt') {
            $packets[] = $file->getPathname();
        }
    }
    sort($packets);
}

$scanTarget = $opts['file'] ?? ($opts['dir'] ?? ($baseDir . '/data/inbound/keep'));

if (empty($packets)) {
    echo "No .pkt files found in $scanTarget\n";
    exit(0);
}

echo "Found " . count($packets) . " packet(s) in $scanTarget\n";

// ---------------------------------------------------------------------------
// Per-packet scan
// ---------------------------------------------------------------------------
$totalMissing  = 0;
$totalFound    = 0;
$affectedPkts  = 0;
$reimportStats = ['imported' => 0, 'failed' => 0];

foreach ($packets as $pktPath) {
    $pktName  = basename($pktPath);
    $pktMtime = filemtime($pktPath);
    $pktDate  = date('Y-m-d H:i:s', $pktMtime); // UTC for display (server stores UTC)

    $messages = scanPacket($pktPath);
    if ($messages === null) {
        echo "[$pktName] ERROR: Could not parse packet — skipping\n";
        continue;
    }

    $missing = [];
    foreach ($messages as $msg) {
        $inDb = messageExistsInDb($db, $msg);
        if ($inDb) {
            $totalFound++;
            if ($opts['verbose']) {
                echo "[$pktName] OK   " . formatMsgSummary($msg) . "\n";
            }
        } else {
            $totalMissing++;
            $missing[] = $msg;
            echo "[$pktName] MISS " . formatMsgSummary($msg) . "\n";
        }
    }

    if (!empty($missing)) {
        $affectedPkts++;
        echo "  -> " . count($missing) . " missing / " . count($messages) . " total"
           . " | pkt mtime: $pktDate UTC\n";

        if ($opts['preview']) {
            $fwMessages  = scanPacket($pktPath, 'fixed-width');
            $gdMessages  = scanPacket($pktPath, 'gap-detect');
            $count = max(count($messages), count($fwMessages ?? []), count($gdMessages ?? []));
            echo "  -> Parser comparison (null-term / fixed-width / gap-detect):\n";
            $fields = ['area', 'fromName', 'toName', 'subject', 'msgid'];
            for ($i = 0; $i < $count; $i++) {
                $nt = $messages[$i]           ?? null;
                $fw = ($fwMessages ?? [])[$i] ?? null;
                $gd = ($gdMessages ?? [])[$i] ?? null;
                $hasDiff = false;
                foreach ($fields as $f) {
                    $ntv = $nt[$f] ?? '(missing)';
                    $fwv = $fw[$f] ?? '(missing)';
                    $gdv = $gd[$f] ?? '(missing)';
                    if ($ntv !== $fwv || $ntv !== $gdv || $opts['verbose']) {
                        if (!$hasDiff) {
                            echo sprintf("     #%d\n", $i + 1);
                            $hasDiff = true;
                        }
                        $marker = ($ntv !== $fwv || $ntv !== $gdv) ? ' ***' : '';
                        echo sprintf("        %-10s nt=%-30s fw=%-30s gd=%s%s\n",
                            $f . ':',
                            mb_substr(json_encode($ntv), 0, 30),
                            mb_substr(json_encode($fwv), 0, 30),
                            mb_substr(json_encode($gdv), 0, 30),
                            $marker);
                    }
                }
                if ($hasDiff) {
                    $allAgree = true;
                    foreach ($fields as $f) {
                        if (($nt[$f] ?? null) !== ($gd[$f] ?? null)) { $allAgree = false; break; }
                    }
                    echo $allAgree ? "        (nt and gd agree)\n" : "        ^^^ PARSERS DIFFER\n";
                }
            }
        }

        if ($opts['reimport'] && !$opts['dry-run']) {
            $receivedDate = gmdate('Y-m-d H:i:s', $pktMtime);
            echo "  -> Reimporting packet with date_received=$receivedDate …\n";
            try {
                $processor = new BinkdProcessor();
                $processor->receivedDateOverride = $receivedDate;
                $result = $processor->processKeptPacket($pktPath);
                $reimportStats['imported'] += $result['imported'];
                $reimportStats['failed']   += $result['failed'];
                echo "  -> Done: {$result['imported']} imported, {$result['failed']} failed\n";
            } catch (\Exception $e) {
                echo "  -> FAILED: " . $e->getMessage() . "\n";
            }
        } elseif ($opts['reimport'] && $opts['dry-run']) {
            echo "  -> (dry-run: would reimport)\n";
        }

        echo "\n";
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo str_repeat('-', 60) . "\n";
echo "Packets scanned : " . count($packets) . "\n";
echo "Packets affected: $affectedPkts\n";
echo "Messages found  : $totalFound\n";
echo "Messages missing: $totalMissing\n";

if ($opts['reimport'] && !$opts['dry-run']) {
    echo "Messages imported: {$reimportStats['imported']}\n";
    echo "Import failures  : {$reimportStats['failed']}\n";
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Parse a .pkt file and return an array of message summaries for DB lookup.
 *
 * @param string $mode  'null-term' (default), 'fixed-width', or 'gap-detect'
 * @return array[]|null  Array of message arrays, or null on parse failure.
 */
function scanPacket(string $path, string $mode = 'null-term'): ?array
{
    $handle = fopen($path, 'rb');
    if (!$handle) {
        return null;
    }

    // Skip 58-byte packet header
    $header = fread($handle, 58);
    if (strlen($header) < 58) {
        fclose($handle);
        return null;
    }

    $messages = [];

    while (!feof($handle)) {
        $typeRaw = fread($handle, 2);
        if (strlen($typeRaw) < 2) {
            break;
        }
        $type = unpack('v', $typeRaw)[1];

        if ($type === 0) {
            break; // End of packet
        }
        if ($type !== 2) {
            break; // Unexpected type — stop
        }

        // Fixed 12-byte message header (origNode, destNode, origNet, destNet, attr, cost)
        $fixedHeader = fread($handle, 12);
        if (strlen($fixedHeader) < 12) {
            break;
        }

        if ($mode === 'fixed-width') {
            $dateTime = readFixedWidth($handle, 20);
            $toName   = readFixedWidth($handle, 36);
            $fromName = readFixedWidth($handle, 36);
            $subject  = readFixedWidth($handle, 72);
        } elseif ($mode === 'gap-detect') {
            [$dateTime, $toName, $fromName, $subject] = readFieldsGapDetect($handle);
        } else {
            $dateTime = readFixed($handle);
            $toName   = readFixed($handle);
            $fromName = readFixed($handle);
            $subject  = readFixed($handle);
        }

        // Variable-length null-terminated message body
        $body = '';
        while (($ch = fread($handle, 1)) !== false && $ch !== '' && ord($ch) !== 0) {
            $body .= $ch;
        }

        // Extract AREA: tag (first line of body for echomail)
        $area   = null;
        $msgid  = null;
        $lines  = preg_split('/[\r\n]+/', $body);
        if (isset($lines[0]) && str_starts_with($lines[0], 'AREA:')) {
            $area = strtoupper(trim(substr($lines[0], 5)));
        }
        foreach ($lines as $line) {
            if (str_starts_with($line, "\x01MSGID:")) {
                $msgid = trim(substr($line, 7));
                break;
            }
        }

        $messages[] = [
            'msgid'    => $msgid,
            'area'     => $area,
            'fromName' => $fromName,
            'toName'   => $toName,
            'subject'  => $subject,
            'dateTime' => $dateTime,
        ];
    }

    fclose($handle);
    return $messages;
}

/**
 * Read a null-terminated string field.
 * Matches the parser BinkdProcessor uses for import so MSGID comparisons align.
 */
function readFixed($handle): string
{
    $string = '';
    while (($char = fread($handle, 1)) !== false && $char !== '' && ord($char) !== 0) {
        $string .= $char;
    }
    return $string;
}

/**
 * Read an FTS-0001 fixed-width string field (experimental fixed-width parser).
 * Reads exactly $len bytes, trims at the first null, and seeks back if bytes
 * after the null are non-zero (non-padded mailer whose next field follows immediately).
 */
function readFixedWidth($handle, int $len): string
{
    $raw = fread($handle, $len);
    if ($raw === false || $raw === '') {
        return '';
    }
    $pos = strpos($raw, "\0");
    if ($pos === false) {
        return $raw;
    }
    $afterNull = substr($raw, $pos + 1);
    if ($afterNull !== '' && ltrim($afterNull, "\0") !== '') {
        fseek($handle, -strlen($afterNull), SEEK_CUR);
    }
    return substr($raw, 0, $pos);
}

/**
 * Read all four FTS-0001 fixed-size string fields using gap-detection.
 *
 * Reads each field null-terminated, then checks whether the bytes between the
 * consumed position and the 164-byte field block boundary are all zeros. If so,
 * they are zero-padding from a spec-compliant mailer and are consumed. If any
 * byte is non-zero, they are body content (non-padded mailer) and are left in
 * the stream. Returns [dateTime, toName, fromName, subject].
 */
function readFieldsGapDetect($handle): array
{
    $prePos = ftell($handle);

    $dateTime = readFixed($handle);
    $toName   = readFixed($handle);
    $fromName = readFixed($handle);
    $subject  = readFixed($handle);

    $consumed = ftell($handle) - $prePos;
    $expected = 20 + 36 + 36 + 72; // 164
    $gap      = $expected - $consumed;

    if ($gap > 0) {
        $gapBytes = fread($handle, $gap);
        if ($gapBytes !== false && ltrim($gapBytes, "\0") !== '') {
            // Non-zero bytes in gap — body content, not padding; seek back
            fseek($handle, -strlen($gapBytes), SEEK_CUR);
        }
        // All zeros — padding consumed correctly
    }

    return [$dateTime, $toName, $fromName, $subject];
}

/**
 * Strip bytes that would cause PostgreSQL UTF-8 encoding errors.
 * Packets may contain raw CP437/Latin-1 bytes; we only need these strings
 * for lookup comparison, not for storage.
 */
function sanitizeForDb(string $s): string
{
    // Replace invalid UTF-8 sequences with '?'
    $clean = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    // Strip control characters (except tab/newline) that Postgres rejects
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean);
}

/**
 * Check whether a scanned message exists in the database.
 * Matches by MSGID when available, otherwise by (from_name, subject, area/type).
 */
function messageExistsInDb(\PDO $db, array $msg): bool
{
    if (!empty($msg['msgid'])) {
        $msgid = sanitizeForDb($msg['msgid']);
        if ($msg['area'] !== null) {
            // Echomail: MSGID + echoarea tag
            $stmt = $db->prepare("
                SELECT 1 FROM echomail em
                JOIN echoareas ea ON ea.id = em.echoarea_id
                WHERE em.message_id = ?
                  AND UPPER(ea.tag) = UPPER(?)
                LIMIT 1
            ");
            $stmt->execute([$msgid, $msg['area']]);
        } else {
            // Netmail: MSGID only (no area)
            $stmt = $db->prepare("
                SELECT 1 FROM netmail WHERE message_id = ? LIMIT 1
            ");
            $stmt->execute([$msgid]);
        }
        return (bool) $stmt->fetchColumn();
    }

    // No MSGID — fall back to from_name + subject match (less reliable)
    $fromName = sanitizeForDb($msg['fromName']);
    $subject  = sanitizeForDb($msg['subject']);

    if ($msg['area'] !== null) {
        $stmt = $db->prepare("
            SELECT 1 FROM echomail em
            JOIN echoareas ea ON ea.id = em.echoarea_id
            WHERE em.from_name = ?
              AND em.subject   = ?
              AND UPPER(ea.tag) = UPPER(?)
            LIMIT 1
        ");
        $stmt->execute([$fromName, $subject, $msg['area']]);
    } else {
        $stmt = $db->prepare("
            SELECT 1 FROM netmail
            WHERE from_name = ? AND subject = ?
            LIMIT 1
        ");
        $stmt->execute([$fromName, $subject]);
    }
    return (bool) $stmt->fetchColumn();
}

/**
 * Format a one-line message summary for console output.
 */
function formatMsgSummary(array $msg): string
{
    $area     = $msg['area']  ? '[' . $msg['area'] . '] ' : '[netmail] ';
    $msgid    = $msg['msgid'] ? 'MSGID:' . sanitizeForDb($msg['msgid']) : '(no msgid)';
    $fromName = preg_replace('/[^\x20-\x7E]/', '?', $msg['fromName']);
    $subject  = preg_replace('/[^\x20-\x7E]/', '?', $msg['subject']);
    return sprintf('%s%-20s %-40s %s',
        $area,
        mb_substr($fromName, 0, 20),
        mb_substr($subject,  0, 40),
        $msgid
    );
}
