<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\AreaFixManager;
use BinktermPHP\AreaFix\AreaFixParser;
use BinktermPHP\Database;

echo "=======================================================\n";
echo "AreaFix Sync: No uplink_address Override Test (#467)\n";
echo "=======================================================\n\n";

$passed = 0;
$failed = 0;

function assertCondition(bool $condition, string $testName, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  PASS: {$testName}\n";
        $passed++;
    } else {
        echo "  FAIL: {$testName}\n";
        if ($details !== '') {
            echo "        Details: {$details}\n";
        }
        $failed++;
    }
}

// Fictitious uplink/domains, isolated from any real configured network.
const TEST_UPLINK = '999:1/1';
const TEST_DOMAIN = 'nouplinkovrtest';
const OTHER_DOMAIN = 'nouplinkovrother';

try {
    $db = Database::getInstance()->getPdo();
} catch (\Throwable $e) {
    echo "  (Skipped: database unavailable - " . $e->getMessage() . ")\n";
    exit(0);
}

function cleanupTestAreas(\PDO $db): void {
    $stmt = $db->prepare('DELETE FROM echoareas WHERE domain IN (?, ?)');
    $stmt->execute([TEST_DOMAIN, OTHER_DOMAIN]);
}

function insertArea(\PDO $db, string $tag, string $domain, ?string $uplink, bool $isActive): void {
    $stmt = $db->prepare(
        'INSERT INTO echoareas (tag, domain, uplink_address, description, is_active, color)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$tag, $domain, $uplink, 'Test area', $isActive ? 'true' : 'false', '#28a745']);
}

function fetchArea(\PDO $db, string $tag, string $domain): ?array {
    $stmt = $db->prepare('SELECT uplink_address, is_active FROM echoareas WHERE tag = ? AND domain = ?');
    $stmt->execute([$tag, $domain]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

function isActive(?array $row): bool {
    return $row !== null && in_array($row['is_active'], [true, 't', 'true', 1, '1'], true);
}

cleanupTestAreas($db);

try {
    $af = new AreaFixManager();
    $sub = AreaFixParser::ACTION_SUBSCRIBE;

    // 1. New area must have no uplink_address override
    echo "1. A newly created area has no override address:\n";
    $af->syncSubscribedAreas(TEST_UPLINK, TEST_DOMAIN, [
        ['name' => 'NUO_NEW', 'description' => 'New area', 'action' => $sub, 'is_subscribed' => true],
    ], false, 'areafix');
    $row = fetchArea($db, 'NUO_NEW', TEST_DOMAIN);
    assertCondition(
        $row !== null && $row['uplink_address'] === null,
        'uplink_address is NULL on a newly created area',
        'Got: ' . var_export($row, true)
    );

    // 2. Existing area without override stays that way; existing override preserved
    echo "\n2. Existing areas keep their override state:\n";
    insertArea($db, 'NUO_EMPTY', TEST_DOMAIN, null, false);
    insertArea($db, 'NUO_SET', TEST_DOMAIN, '999:2/2', true);
    $af->syncSubscribedAreas(TEST_UPLINK, TEST_DOMAIN, [
        ['name' => 'NUO_EMPTY', 'description' => 'x', 'action' => $sub, 'is_subscribed' => true],
        ['name' => 'NUO_SET', 'description' => 'x', 'action' => $sub, 'is_subscribed' => true],
    ], false, 'areafix');
    $empty = fetchArea($db, 'NUO_EMPTY', TEST_DOMAIN);
    $set = fetchArea($db, 'NUO_SET', TEST_DOMAIN);
    assertCondition(
        $empty !== null && $empty['uplink_address'] === null && isActive($empty),
        'An existing area with no override is activated but not given an override',
        'Got: ' . var_export($empty, true)
    );
    assertCondition(
        $set !== null && $set['uplink_address'] === '999:2/2',
        'An existing sysop-set override is preserved',
        'Got: ' . var_export($set, true)
    );

    // 3. deactivateMissing scopes by domain + tag, regardless of uplink_address
    echo "\n3. Deactivate-missing is scoped by domain and tag:\n";
    insertArea($db, 'NUO_KEEP', TEST_DOMAIN, null, true);
    insertArea($db, 'NUO_DROP_NULL', TEST_DOMAIN, null, true);
    insertArea($db, 'NUO_DROP_OVR', TEST_DOMAIN, '999:2/2', true);
    insertArea($db, 'NUO_OTHER_DOMAIN', OTHER_DOMAIN, null, true);
    $summary = $af->syncSubscribedAreas(TEST_UPLINK, TEST_DOMAIN, [
        ['name' => 'NUO_KEEP', 'description' => 'x', 'action' => $sub, 'is_subscribed' => true],
    ], true, 'areafix');
    assertCondition(
        isActive(fetchArea($db, 'NUO_KEEP', TEST_DOMAIN)),
        'A tag in the hub list stays active'
    );
    assertCondition(
        !isActive(fetchArea($db, 'NUO_DROP_NULL', TEST_DOMAIN)),
        'A tag missing from the hub list is deactivated even with no override address'
    );
    assertCondition(
        !isActive(fetchArea($db, 'NUO_DROP_OVR', TEST_DOMAIN)),
        'A tag missing from the hub list is deactivated when it has an override address'
    );
    assertCondition(
        isActive(fetchArea($db, 'NUO_OTHER_DOMAIN', OTHER_DOMAIN)),
        'An area in a different domain is never deactivated'
    );

    // 4. Preview agrees with the sync
    echo "\n4. Preview lists the same deactivations:\n";
    insertArea($db, 'NUO_PREV_NULL', TEST_DOMAIN, null, true);
    $preview = $af->previewSync(TEST_UPLINK, TEST_DOMAIN, [
        ['name' => 'NUO_KEEP', 'description' => 'x', 'action' => $sub, 'is_subscribed' => true],
    ], true, 'areafix');
    $item = current(array_filter($preview, fn($a) => $a['name'] === 'NUO_PREV_NULL'));
    assertCondition(
        $item && $item['status'] === 'deactivate',
        'An active area missing from the hub list is previewed as deactivate',
        'Got: ' . var_export($item, true)
    );
} finally {
    cleanupTestAreas($db);
}

echo "\n-------------------------------------------------------\n";
echo "Results: {$passed} passed, {$failed} failed.\n";
echo "=======================================================\n";

exit($failed > 0 ? 1 : 0);
