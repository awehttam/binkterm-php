#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;

$db = Database::getInstance()->getPdo();

$sql = <<<SQL
SELECT
    ea.id,
    ea.tag,
    ea.message_count AS stored_count,
    COUNT(em.id)     AS actual_count,
    COUNT(em.id) - ea.message_count AS drift
FROM echoareas ea
LEFT JOIN echomail em ON em.echoarea_id = ea.id
GROUP BY ea.id, ea.tag, ea.message_count
ORDER BY ABS(COUNT(em.id) - ea.message_count) DESC, ea.tag
SQL;

$rows = $db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

$mismatches = array_filter($rows, fn($r) => (int)$r['drift'] !== 0);

if (empty($mismatches)) {
    echo "All echoarea message_count values are correct.\n";
    exit(0);
}

$tagLen   = max(3, ...array_map(fn($r) => strlen($r['tag']),  $mismatches));
$idLen    = max(2, ...array_map(fn($r) => strlen((string)$r['id']), $mismatches));

printf(
    "%-{$idLen}s  %-{$tagLen}s  %10s  %10s  %10s\n",
    'ID', 'TAG', 'STORED', 'ACTUAL', 'DRIFT'
);
echo str_repeat('-', $idLen + $tagLen + 38) . "\n";

foreach ($mismatches as $r) {
    printf(
        "%-{$idLen}s  %-{$tagLen}s  %10d  %10d  %+10d\n",
        $r['id'],
        $r['tag'],
        (int)$r['stored_count'],
        (int)$r['actual_count'],
        (int)$r['drift']
    );
}

echo "\n" . count($mismatches) . " echoarea(s) with mismatched message_count (out of " . count($rows) . " total).\n";
exit(1);
