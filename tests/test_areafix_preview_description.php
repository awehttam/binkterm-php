<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\AreaFixManager;
use BinktermPHP\AreaFix\AreaFixParser;
use BinktermPHP\Database;

echo "=======================================================\n";
echo "AreaFix Preview: Description Change Detection Test\n";
echo "=======================================================\n\n";

$passed = 0;
$failed = 0;

function assertCondition(bool $condition, string $testName, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  ✓ PASS: {$testName}\n";
        $passed++;
    } else {
        echo "  ✗ FAIL: {$testName}\n";
        if ($details !== '') {
            echo "         Details: {$details}\n";
        }
        $failed++;
    }
}

// Fictitious uplink/domain, isolated from any real configured network, so
// this test can freely insert and delete rows without touching production data.
const TEST_UPLINK = '999:1/1';
const TEST_DOMAIN = 'previewdesctest';

try {
    $db = Database::getInstance()->getPdo();
} catch (\Throwable $e) {
    echo "  (Skipped: database unavailable — " . $e->getMessage() . ")\n";
    exit(0);
}

function cleanupTestAreas(\PDO $db): void {
    $stmt = $db->prepare('DELETE FROM echoareas WHERE domain = ?');
    $stmt->execute([TEST_DOMAIN]);
}

function insertTestArea(\PDO $db, string $tag, ?string $description, bool $isActive): void {
    $stmt = $db->prepare(
        'INSERT INTO echoareas (tag, domain, uplink_address, description, is_active, color)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$tag, TEST_DOMAIN, TEST_UPLINK, $description, $isActive ? 'true' : 'false', '#28a745']);
}

cleanupTestAreas($db);

try {
    $af = new AreaFixManager();

    // --------------------------------------------------------------------------
    // Test 1: New area — description will always be set on creation
    // --------------------------------------------------------------------------
    echo "1. Testing a brand-new area:\n";

    $preview = $af->previewSync(TEST_UPLINK, TEST_DOMAIN, [
        ['name' => 'PDT_NEW', 'description' => 'A brand new area', 'action' => AreaFixParser::ACTION_SUBSCRIBE, 'is_subscribed' => true],
    ], false, 'areafix');

    $item = current(array_filter($preview, fn($a) => $a['name'] === 'PDT_NEW'));
    assertCondition(
        $item && $item['status'] === 'new' && $item['description_will_change'] === true && $item['current_description'] === null,
        'A new area is status=new with description_will_change=true and no current_description',
        'Got: ' . var_export($item, true)
    );

    // --------------------------------------------------------------------------
    // Test 2: Existing area with a real (non-placeholder) description — must
    // NOT be flagged for a description change, protecting a sysop's own edit
    // --------------------------------------------------------------------------
    echo "\n2. Testing an existing area with a real, sysop-set description:\n";

    insertTestArea($db, 'PDT_REAL_DESC', 'Sysop-curated description', true);

    $preview = $af->previewSync(TEST_UPLINK, TEST_DOMAIN, [
        ['name' => 'PDT_REAL_DESC', 'description' => 'Different description from hub', 'action' => AreaFixParser::ACTION_SUBSCRIBE, 'is_subscribed' => true],
    ], false, 'areafix');

    $item = current(array_filter($preview, fn($a) => $a['name'] === 'PDT_REAL_DESC'));
    assertCondition(
        $item && $item['status'] === 'unchanged' && $item['description_will_change'] === false
            && $item['current_description'] === 'Sysop-curated description',
        'An existing non-placeholder description is never flagged for change, even if the hub sends a different one',
        'Got: ' . var_export($item, true)
    );
    assertCondition(
        $item && $item['description_differs'] === true,
        'The mismatch is still surfaced via description_differs, even though it will not be applied',
        'Got: ' . var_export($item, true)
    );

    // Same area, but the hub's reply happens to match the local description
    // exactly — description_differs must not fire on a non-difference.
    $previewSame = $af->previewSync(TEST_UPLINK, TEST_DOMAIN, [
        ['name' => 'PDT_REAL_DESC', 'description' => 'Sysop-curated description', 'action' => AreaFixParser::ACTION_SUBSCRIBE, 'is_subscribed' => true],
    ], false, 'areafix');
    $itemSame = current(array_filter($previewSame, fn($a) => $a['name'] === 'PDT_REAL_DESC'));
    assertCondition(
        $itemSame && $itemSame['description_will_change'] === false && $itemSame['description_differs'] === false,
        'description_differs is false when the hub\'s description matches the local one exactly'
    );

    // --------------------------------------------------------------------------
    // Test 3: Existing area with a placeholder description — the hub's real
    // description WILL be applied, and the preview must say so
    // --------------------------------------------------------------------------
    echo "\n3. Testing an existing area with a placeholder description:\n";

    insertTestArea($db, 'PDT_PLACEHOLDER', 'Auto-created from TIC file', true);

    $preview = $af->previewSync(TEST_UPLINK, TEST_DOMAIN, [
        ['name' => 'PDT_PLACEHOLDER', 'description' => 'Real description from hub', 'action' => AreaFixParser::ACTION_SUBSCRIBE, 'is_subscribed' => true],
    ], false, 'areafix');

    $item = current(array_filter($preview, fn($a) => $a['name'] === 'PDT_PLACEHOLDER'));
    assertCondition(
        $item && $item['status'] === 'unchanged' && $item['description_will_change'] === true
            && $item['current_description'] === 'Auto-created from TIC file',
        'A placeholder description is flagged for change even though activation status is unchanged',
        'Got: ' . var_export($item, true)
    );
    assertCondition(
        $item && $item['description_differs'] === false,
        'description_differs stays false when description_will_change is already true (no redundant/conflicting signal)',
        'Got: ' . var_export($item, true)
    );

    // --------------------------------------------------------------------------
    // Test 4: Reactivating an inactive area with a placeholder description —
    // both the status and the description change should be visible together
    // --------------------------------------------------------------------------
    echo "\n4. Testing a reactivated area that also gets its description filled in:\n";

    insertTestArea($db, 'PDT_REACTIVATE', null, false);

    $preview = $af->previewSync(TEST_UPLINK, TEST_DOMAIN, [
        ['name' => 'PDT_REACTIVATE', 'description' => 'Filled in on reactivation', 'action' => AreaFixParser::ACTION_SUBSCRIBE, 'is_subscribed' => true],
    ], false, 'areafix');

    $item = current(array_filter($preview, fn($a) => $a['name'] === 'PDT_REACTIVATE'));
    assertCondition(
        $item && $item['status'] === 'reactivate' && $item['description_will_change'] === true,
        'A reactivated area with a NULL local description is also flagged for a description change',
        'Got: ' . var_export($item, true)
    );

    // --------------------------------------------------------------------------
    // Test 5: Unsubscribing never touches the description
    // --------------------------------------------------------------------------
    echo "\n5. Testing that deactivation never flags a description change:\n";

    insertTestArea($db, 'PDT_UNSUB', 'Auto-created placeholder', true);

    $preview = $af->previewSync(TEST_UPLINK, TEST_DOMAIN, [
        ['name' => 'PDT_UNSUB', 'description' => 'Should be ignored', 'action' => AreaFixParser::ACTION_UNSUBSCRIBE, 'is_subscribed' => false],
    ], false, 'areafix');

    $item = current(array_filter($preview, fn($a) => $a['name'] === 'PDT_UNSUB'));
    assertCondition(
        $item && $item['status'] === 'deactivate' && $item['description_will_change'] === false,
        'Deactivating an area never flags a description change, even with a placeholder description present',
        'Got: ' . var_export($item, true)
    );

    // --------------------------------------------------------------------------
    // Test 6: syncSubscribedAreas() without force_descriptions leaves a real
    // description untouched (unchanged from pre-existing behavior)
    // --------------------------------------------------------------------------
    echo "\n6. Testing syncSubscribedAreas() default behavior (no force):\n";

    insertTestArea($db, 'PDT_SYNC_NOFORCE', 'Original sysop description', true);

    $af->syncSubscribedAreas(TEST_UPLINK, TEST_DOMAIN, [
        ['name' => 'PDT_SYNC_NOFORCE', 'description' => 'Description from hub', 'action' => AreaFixParser::ACTION_SUBSCRIBE, 'is_subscribed' => true],
    ], false, 'areafix');

    $stmt = $db->prepare('SELECT description FROM echoareas WHERE tag = ? AND domain = ?');
    $stmt->execute(['PDT_SYNC_NOFORCE', TEST_DOMAIN]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    assertCondition(
        $row && $row['description'] === 'Original sysop description',
        'Without force_descriptions, a real local description survives a sync with a differing hub description',
        'Got: ' . var_export($row['description'] ?? null, true)
    );

    // --------------------------------------------------------------------------
    // Test 7: syncSubscribedAreas() with force_descriptions=true overwrites a
    // real description when the sysop has explicitly selected the area
    // --------------------------------------------------------------------------
    echo "\n7. Testing syncSubscribedAreas() with force_descriptions=true:\n";

    insertTestArea($db, 'PDT_SYNC_FORCE', 'Original sysop description', true);

    $af->syncSubscribedAreas(TEST_UPLINK, TEST_DOMAIN, [
        ['name' => 'PDT_SYNC_FORCE', 'description' => 'Description from hub', 'action' => AreaFixParser::ACTION_SUBSCRIBE, 'is_subscribed' => true],
    ], false, 'areafix', false, true);

    $stmt = $db->prepare('SELECT description FROM echoareas WHERE tag = ? AND domain = ?');
    $stmt->execute(['PDT_SYNC_FORCE', TEST_DOMAIN]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    assertCondition(
        $row && $row['description'] === 'Description from hub',
        'With force_descriptions=true, an explicitly-selected area\'s description is overwritten even though it was a real, non-placeholder value',
        'Got: ' . var_export($row['description'] ?? null, true)
    );
} finally {
    cleanupTestAreas($db);
}

echo "\n-------------------------------------------------------\n";
echo "Results: {$passed} passed, {$failed} failed.\n";
echo "=======================================================\n";

exit($failed > 0 ? 1 : 0);
