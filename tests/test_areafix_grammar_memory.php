<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\AreaFixManager;
use BinktermPHP\AreaFix\AreaFixParser;
use BinktermPHP\Database;

echo "=======================================================\n";
echo "AreaFix Per-Uplink Grammar Memory Test Suite (Improvement 6)\n";
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

// --------------------------------------------------------------------------
// Part 1: AreaFixParser::parseWithTier() — no database needed
// --------------------------------------------------------------------------

$parser = new AreaFixParser();

echo "1. Testing that parseWithTier() reports the correct tier per grammar:\n";

$mysticBody = <<<BODY
Command: +SYS_GEN [R=100]
 Result: Echo will now be exported
BODY;
$result = $parser->parseWithTier($mysticBody);
assertCondition(
    $result['tier'] === AreaFixParser::TIER_MYSTIC_BLOCKS && count($result['areas']) === 1,
    'Mystic Command/Result block reports TIER_MYSTIC_BLOCKS',
    json_encode($result)
);

$freeformBody = <<<BODY
LVLY_TEST  Some plain two-column description
--- SBBSecho 3.20
BODY;
$result = $parser->parseWithTier($freeformBody);
assertCondition(
    $result['tier'] === AreaFixParser::TIER_FREEFORM && count($result['areas']) === 1,
    'Bare "TAG  Description" list reports TIER_FREEFORM',
    json_encode($result)
);

echo "\n1b. Testing getKnownTierIds() lists every built-in tier plus the freeform fallback:\n";

$knownTiers = $parser->getKnownTierIds();
$expectedBuiltins = [
    AreaFixParser::TIER_MYSTIC_BLOCKS,
    AreaFixParser::TIER_DELIMITED_TABLE,
    AreaFixParser::TIER_COLUMNAR_TABLE,
    AreaFixParser::TIER_QUOTED_ADDRESS_LIST,
    AreaFixParser::TIER_FLAGGED_DOTTED_QUOTED_LIST,
    AreaFixParser::TIER_FREEFORM,
];
$missing = array_diff($expectedBuiltins, $knownTiers);
assertCondition(
    empty($missing),
    'getKnownTierIds() includes every built-in tier and the freeform fallback',
    json_encode($knownTiers)
);

echo "\n2. Testing that an unmatched body reports a null tier:\n";

$noise = "This is just a sentence with WITHIN and EACH ABILITY words in it, nothing structural at all here.";
$result = $parser->parseWithTier($noise);
assertCondition(
    $result['tier'] === null && empty($result['areas']),
    'Non-structural prose reports tier=null and no areas',
    json_encode($result)
);

echo "\n3. Testing preferredTier reordering never changes which tier wins:\n";

// The freeform body only matches TIER_FREEFORM regardless of what's preferred;
// giving a bogus/inapplicable preferred tier must not change the outcome.
$result = $parser->parseWithTier($freeformBody, null, AreaFixParser::TIER_MYSTIC_BLOCKS);
assertCondition(
    $result['tier'] === AreaFixParser::TIER_FREEFORM && count($result['areas']) === 1,
    'A preferred tier that does not match the body falls through to the tier that actually matches',
    json_encode($result)
);

$result = $parser->parseWithTier($mysticBody, null, AreaFixParser::TIER_FREEFORM);
assertCondition(
    $result['tier'] === AreaFixParser::TIER_MYSTIC_BLOCKS && count($result['areas']) === 1,
    'A preferred tier is only a reordering hint, never forces a match',
    json_encode($result)
);

// --------------------------------------------------------------------------
// Part 2: AreaFixManager per-uplink memory — requires a database
// --------------------------------------------------------------------------

// Fictitious uplink/domain, isolated from any real configured network, so
// this test can freely insert and delete rows without touching production data.
const TEST_UPLINK = '999:1/1';
const TEST_DOMAIN = 'grammarmemorytest';
const TEST_ROBOT = 'areafix';

try {
    $db = Database::getInstance()->getPdo();
} catch (\Throwable $e) {
    echo "\n  (Skipped remaining tests: database unavailable - " . $e->getMessage() . ")\n";
    echo "\n=======================================================\n";
    echo "Results: {$passed} passed, {$failed} failed\n";
    echo "=======================================================\n";
    exit($failed > 0 ? 1 : 0);
}

function cleanupTestMemory(\PDO $db): void {
    $stmt = $db->prepare('DELETE FROM areafix_grammar_memory WHERE domain = ?');
    $stmt->execute([TEST_DOMAIN]);
}

cleanupTestMemory($db);

try {
    $af = new AreaFixManager();

    echo "\n4. Testing that a never-synced uplink has no remembered tier:\n";
    $tier = $af->getRememberedTier(TEST_UPLINK, TEST_DOMAIN, TEST_ROBOT);
    assertCondition($tier === null, 'getRememberedTier() returns null before any sync is recorded');

    echo "\n5. Testing that rememberTier() stores and getRememberedTier() retrieves it:\n";
    $af->rememberTier(TEST_UPLINK, TEST_DOMAIN, TEST_ROBOT, AreaFixParser::TIER_MYSTIC_BLOCKS);
    $tier = $af->getRememberedTier(TEST_UPLINK, TEST_DOMAIN, TEST_ROBOT);
    assertCondition($tier === AreaFixParser::TIER_MYSTIC_BLOCKS, 'Remembered tier round-trips', (string)$tier);

    echo "\n6. Testing that rememberTier() upserts (a later confirmed sync updates the remembered tier):\n";
    $af->rememberTier(TEST_UPLINK, TEST_DOMAIN, TEST_ROBOT, AreaFixParser::TIER_FREEFORM);
    $tier = $af->getRememberedTier(TEST_UPLINK, TEST_DOMAIN, TEST_ROBOT);
    assertCondition($tier === AreaFixParser::TIER_FREEFORM, 'A second confirmed sync overwrites the previously remembered tier', (string)$tier);

    echo "\n7. Testing that rememberTier(null) is a no-op and never erases a known-good tier:\n";
    $af->rememberTier(TEST_UPLINK, TEST_DOMAIN, TEST_ROBOT, null);
    $tier = $af->getRememberedTier(TEST_UPLINK, TEST_DOMAIN, TEST_ROBOT);
    assertCondition($tier === AreaFixParser::TIER_FREEFORM, 'Passing a null tier does not clear the remembered tier', (string)$tier);

    echo "\n8. Testing that memory is scoped per uplink+domain+robot (a different robot on the same uplink/domain is independent):\n";
    $af->rememberTier(TEST_UPLINK, TEST_DOMAIN, 'filefix', AreaFixParser::TIER_DELIMITED_TABLE);
    $areafixTier = $af->getRememberedTier(TEST_UPLINK, TEST_DOMAIN, TEST_ROBOT);
    $filefixTier = $af->getRememberedTier(TEST_UPLINK, TEST_DOMAIN, 'filefix');
    assertCondition(
        $areafixTier === AreaFixParser::TIER_FREEFORM && $filefixTier === AreaFixParser::TIER_DELIMITED_TABLE,
        'areafix and filefix robots on the same uplink/domain remember independent tiers',
        json_encode(['areafix' => $areafixTier, 'filefix' => $filefixTier])
    );
} finally {
    cleanupTestMemory($db);
}

echo "\n=======================================================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "=======================================================\n";

exit($failed > 0 ? 1 : 0);
