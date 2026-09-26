<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\AreaFix\AreaFixParser;

echo "=======================================================\n";
echo "Data-Driven AreaFix Grammar Test Suite (Improvement 5)\n";
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

$configPath = __DIR__ . '/../config/areafix_grammars.json';
$backupPath = $configPath . '.test-backup';
$hadExistingConfig = file_exists($configPath);
if ($hadExistingConfig) {
    copy($configPath, $backupPath);
}

function writeGrammarsConfig(string $configPath, array $grammars): void {
    file_put_contents($configPath, json_encode($grammars, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

try {
    // --------------------------------------------------------------------------
    // Test 1: A well-formed configured grammar with status-driven actions
    // --------------------------------------------------------------------------
    echo "1. Testing a well-formed data-driven grammar:\n";

    writeGrammarsConfig($configPath, [
        [
            'id'             => 'xyz_area_manager',
            'enabled'        => true,
            'header_pattern' => 'XYZ AreaManager',
            'row_pattern'    => '^TAG:\s*(?<tag>[A-Za-z0-9_\-.]+)\s+STATUS:\s*(?<status>\S+)\s+DESC:\s*(?<description>.*)$',
            'default_action' => 'available',
            // Anchored so "unlinked" (which contains "linked" as a substring)
            // isn't shadowed by the broader "linked" rule.
            'status_rules'   => [
                ['pattern' => '^unlinked$', 'action' => 'unsubscribe'],
                ['pattern' => '^linked$', 'action' => 'subscribe'],
            ],
        ],
    ]);

    $body = <<<BODY
XYZ AreaManager v1.0 Area Report
TAG: FOOBAR STATUS: linked DESC: Foo Bar Discussion
TAG: BAZBAZ STATUS: unlinked DESC: Baz Baz Archive
BODY;

    $parser = new AreaFixParser();
    $parsed = $parser->parse($body);

    assertCondition(count($parsed) === 2, 'Configured grammar parses both rows', 'Got ' . count($parsed) . ' areas');

    $byTag = [];
    foreach ($parsed as $area) {
        $byTag[$area['name']] = $area;
    }

    assertCondition(
        isset($byTag['FOOBAR']) && $byTag['FOOBAR']['action'] === AreaFixParser::ACTION_SUBSCRIBE && $byTag['FOOBAR']['is_subscribed'] === true,
        'Row with "linked" status maps to ACTION_SUBSCRIBE',
        json_encode($byTag['FOOBAR'] ?? null)
    );
    assertCondition(
        isset($byTag['FOOBAR']) && $byTag['FOOBAR']['description'] === 'Foo Bar Discussion',
        'Description captured from named group'
    );
    assertCondition(
        isset($byTag['BAZBAZ']) && $byTag['BAZBAZ']['action'] === AreaFixParser::ACTION_UNSUBSCRIBE && $byTag['BAZBAZ']['is_subscribed'] === false,
        'Row with "unlinked" status maps to ACTION_UNSUBSCRIBE',
        json_encode($byTag['BAZBAZ'] ?? null)
    );

    // --------------------------------------------------------------------------
    // Test 2: Disabled grammar is never applied
    // --------------------------------------------------------------------------
    echo "\n2. Testing that a disabled grammar is skipped:\n";

    writeGrammarsConfig($configPath, [
        [
            'id'             => 'xyz_area_manager',
            'enabled'        => false,
            'header_pattern' => 'XYZ AreaManager',
            'row_pattern'    => '^TAG:\s*(?<tag>[A-Za-z0-9_\-.]+)\s+STATUS:\s*(?<status>\S+)\s+DESC:\s*(?<description>.*)$',
        ],
    ]);

    $parser2 = new AreaFixParser();
    $parsed2 = $parser2->parse($body);
    assertCondition(count($parsed2) === 0, 'Disabled grammar produces no matches (falls through to freeform, which also finds none here)', json_encode($parsed2));

    // --------------------------------------------------------------------------
    // Test 3: A grammar with an invalid regex is skipped, not fatal
    // --------------------------------------------------------------------------
    echo "\n3. Testing that an invalid regex in a grammar doesn't crash the parser:\n";

    writeGrammarsConfig($configPath, [
        [
            'id'             => 'broken_grammar',
            'enabled'        => true,
            'header_pattern' => 'XYZ AreaManager',
            'row_pattern'    => '(unterminated_group',
        ],
    ]);

    $parser3 = new AreaFixParser();
    $exceptionThrown = false;
    try {
        $parsed3 = $parser3->parse($body);
    } catch (\Throwable $e) {
        $exceptionThrown = true;
        $parsed3 = [];
    }
    assertCondition(!$exceptionThrown, 'Invalid regex does not throw');
    assertCondition(count($parsed3) === 0, 'Invalid regex grammar yields no matches rather than a partial/incorrect one');

    // --------------------------------------------------------------------------
    // Test 4: Built-in grammars still take priority over configured grammars
    // --------------------------------------------------------------------------
    echo "\n4. Testing that built-in grammars are still tried before configured ones:\n";

    writeGrammarsConfig($configPath, [
        [
            'id'             => 'catch_all',
            'enabled'        => true,
            'header_pattern' => 'Command:',
            'row_pattern'    => '^(?<tag>NEVER_MATCHES_ANYTHING)$',
        ],
    ]);

    $mysticBody = <<<BODY
Command: +SYS_GEN [R=100]
 Result: Echo will now be exported
BODY;

    $parser4 = new AreaFixParser();
    $parsed4 = $parser4->parse($mysticBody);
    assertCondition(
        count($parsed4) === 1 && $parsed4[0]['name'] === 'SYS_GEN' && $parsed4[0]['action'] === AreaFixParser::ACTION_SUBSCRIBE,
        'Built-in Mystic grammar still wins even though a configured grammar\'s header_pattern also matches',
        json_encode($parsed4)
    );

    // --------------------------------------------------------------------------
    // Test 5: A missing config file falls back to the shipped .example file,
    // whose sample grammar ships disabled, so behavior is unaffected.
    // --------------------------------------------------------------------------
    echo "\n5. Testing that a missing config file falls back to areafix_grammars.json.example (shipped disabled):\n";

    if (file_exists($configPath)) {
        unlink($configPath);
    }
    $parser5 = new AreaFixParser();
    $parsed5 = $parser5->parse($body);
    assertCondition(count($parsed5) === 0, 'Example grammar ships disabled, so XYZ format falls through to freeform (which rejects "TAG:" prefixed rows)', json_encode($parsed5));

    // --------------------------------------------------------------------------
    // Test 6: If the .example file's grammar were enabled, the fallback would
    // actually apply it (confirms the fallback reads and uses the file, not
    // just that it exists).
    // --------------------------------------------------------------------------
    echo "\n6. Testing that an enabled grammar in areafix_grammars.json.example is actually used as a fallback:\n";

    $examplePath = $configPath . '.example';
    $exampleBackupPath = $examplePath . '.test-backup';
    $hadExistingExample = file_exists($examplePath);
    if ($hadExistingExample) {
        copy($examplePath, $exampleBackupPath);
    }

    try {
        writeGrammarsConfig($examplePath, [
            [
                'id'             => 'xyz_area_manager',
                'enabled'        => true,
                'header_pattern' => 'XYZ AreaManager',
                'row_pattern'    => '^TAG:\s*(?<tag>[A-Za-z0-9_\-.]+)\s+STATUS:\s*(?<status>\S+)\s+DESC:\s*(?<description>.*)$',
            ],
        ]);

        $parser6 = new AreaFixParser();
        $parsed6 = $parser6->parse($body);
        assertCondition(count($parsed6) === 2, 'Enabled example grammar is loaded and applied when the real config file is absent', json_encode($parsed6));
    } finally {
        if ($hadExistingExample) {
            copy($exampleBackupPath, $examplePath);
            unlink($exampleBackupPath);
        } elseif (file_exists($examplePath)) {
            unlink($examplePath);
        }
    }

} finally {
    if ($hadExistingConfig) {
        copy($backupPath, $configPath);
        unlink($backupPath);
    } elseif (file_exists($configPath)) {
        unlink($configPath);
    }
}

echo "\n=======================================================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "=======================================================\n";

exit($failed > 0 ? 1 : 0);
