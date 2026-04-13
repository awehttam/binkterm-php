#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * echomail_charsetreport.php - Display message_charset statistics per network
 *
 * Queries the echomail table and groups message counts by network (domain)
 * and message_charset, so you can see which charsets are in use per network.
 * NULL and empty charsets are reported as "(none)".
 *
 * Usage:
 *   php scripts/echomail_charsetreport.php
 *   php scripts/echomail_charsetreport.php --domain=fidonet
 *   php scripts/echomail_charsetreport.php --sort=charset
 *   php scripts/echomail_charsetreport.php --sort=count
 */

chdir(__DIR__ . '/../');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;

// --- Parse arguments ---

$options    = getopt('', ['domain:', 'sort:', 'help']);
$filterDomain = isset($options['domain']) ? trim($options['domain']) : null;
$sortMode   = isset($options['sort']) ? strtolower(trim($options['sort'])) : 'count';

if (isset($options['help'])) {
    echo "Usage: php scripts/echomail_charsetreport.php [options]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --domain=NAME   Only show a specific network domain (e.g. fidonet, fsxnet)\n";
    echo "  --sort=count    Sort charsets by message count descending (default)\n";
    echo "  --sort=charset  Sort charsets alphabetically\n";
    echo "  --help          Show this help\n";
    exit(0);
}

if (!in_array($sortMode, ['count', 'charset'], true)) {
    fwrite(STDERR, "Error: --sort must be 'count' or 'charset'\n");
    exit(1);
}

// --- Query ---

$db = Database::getInstance()->getPdo();

$sql = "
    SELECT
        COALESCE(NULLIF(ea.domain, ''), '(unknown)') AS network,
        COALESCE(NULLIF(e.message_charset, ''), '(none)') AS charset,
        COUNT(*) AS msg_count
    FROM echomail e
    JOIN echoareas ea ON ea.id = e.echoarea_id
";

$params = [];
if ($filterDomain !== null) {
    $sql .= " WHERE ea.domain = ?";
    $params[] = $filterDomain;
}

$sql .= "
    GROUP BY network, charset
    ORDER BY network, msg_count DESC, charset
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "No echomail data found";
    if ($filterDomain !== null) {
        echo " for domain '{$filterDomain}'";
    }
    echo ".\n";
    exit(0);
}

// --- Organise by network ---

$byNetwork = [];
$totalPerNetwork = [];

foreach ($rows as $row) {
    $net     = $row['network'];
    $charset = $row['charset'];
    $count   = (int)$row['msg_count'];

    $byNetwork[$net][$charset] = $count;
    $totalPerNetwork[$net] = ($totalPerNetwork[$net] ?? 0) + $count;
}

// Sort networks by total message count descending
arsort($totalPerNetwork);

// --- Display ---

$grandTotal = array_sum($totalPerNetwork);

echo "=== Echomail Charset Report ===\n";
echo "Networks: " . count($byNetwork) . "  |  Total messages: " . number_format($grandTotal) . "\n";
if ($filterDomain !== null) {
    echo "Filter: domain = '{$filterDomain}'\n";
}
echo "\n";

foreach ($totalPerNetwork as $network => $netTotal) {
    $charsets = $byNetwork[$network];

    // Apply requested sort
    if ($sortMode === 'charset') {
        ksort($charsets);
    } else {
        arsort($charsets);
    }

    $pct = $grandTotal > 0 ? round(($netTotal / $grandTotal) * 100, 1) : 0;

    echo str_repeat('─', 50) . "\n";
    printf("Network: %-20s  %s messages (%s%%)\n",
        $network,
        number_format($netTotal),
        $pct
    );
    echo str_repeat('─', 50) . "\n";

    // Column widths
    $maxCharset = max(array_map('strlen', array_keys($charsets)));
    $maxCharset = max($maxCharset, 7); // min width for "Charset" header

    $barWidth = 30;

    printf("  %-{$maxCharset}s  %10s  %6s  %s\n", 'Charset', 'Messages', 'Pct', 'Bar');
    printf("  %s  %s  %s  %s\n",
        str_repeat('-', $maxCharset),
        str_repeat('-', 10),
        str_repeat('-', 6),
        str_repeat('-', $barWidth)
    );

    foreach ($charsets as $charset => $count) {
        $rowPct  = $netTotal > 0 ? ($count / $netTotal) : 0;
        $rowPctDisplay = round($rowPct * 100, 1);
        $barLen  = (int)round($rowPct * $barWidth);
        $bar     = str_repeat('#', $barLen);

        printf("  %-{$maxCharset}s  %10s  %5.1f%%  %s\n",
            $charset,
            number_format($count),
            $rowPctDisplay,
            $bar
        );
    }

    echo "\n";
}

echo str_repeat('═', 50) . "\n";
printf("Grand total: %s messages across %d network(s)\n",
    number_format($grandTotal),
    count($byNetwork)
);
