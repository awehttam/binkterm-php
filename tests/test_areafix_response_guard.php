<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\AreaFixManager;

echo "=======================================================\n";
echo "AreaFix Response Guard & Area List Parsing Test\n";
echo "=======================================================\n\n";

$af = new AreaFixManager();
$passed = 0;
$failed = 0;

function assertCondition(bool $condition, string $testName): void {
    global $passed, $failed;
    if ($condition) {
        echo "  ✓ PASS: {$testName}\n";
        $passed++;
    } else {
        echo "  ✗ FAIL: {$testName}\n";
        $failed++;
    }
}

// --------------------------------------------------------------------------
// Test 1: Real Area List Responses (should be accepted)
// --------------------------------------------------------------------------
echo "1. Testing detection of valid area lists:\n";

$mysticListBody = <<<BODY
Your AREAFIX request has been processed

Command: %LIST
 Result: List of all areas:
   TQW_ADS                                  BBS Adverts
   TQW_BBSGEN                               General BBS Chat
   TQW_BOT                                  roBOT output
   TQW_DOCKER                               Homelab Chat
BODY;

assertCondition(
    $af->isAreaListResponse('AREAFIX response', $mysticListBody) === true,
    'Accept Mystic BBS %LIST response with generic subject'
);

$parsedMystic = $af->parseResponseText($mysticListBody, '%LIST');
assertCondition(
    count($parsedMystic) === 4 && $parsedMystic[0]['name'] === 'TQW_ADS',
    'Parse areas from Mystic BBS %LIST correctly'
);

// --------------------------------------------------------------------------
// Test 2: Mystic Help Text Responses (should be rejected)
// --------------------------------------------------------------------------
echo "\n2. Testing rejection of help texts:\n";

$mysticHelpBody = <<<BODY
Your AREAFIX request has been processed

Command: %HELP
 Result: Help included at end of message

 .----------------------------.
 | tqwNet CA HUB AreaFix Help |-------------------------------------------------+
 `----------------------------'

  What is AreaFix?
  ----------------

  AreaFix is an automated response system which provides echomail nodes the
  ability to "self-service" their configuration.  Generally speaking, you will
  use AreaFix to link and unlink echomail areas for export to you.

  AreaFix Commands
  ----------------
  %ALL  [search]         The ALL command will either subscribe or remove all
  %HELP                  Return this help message
BODY;

assertCondition(
    $af->isAreaListResponse('AREAFIX response', $mysticHelpBody) === false,
    'Reject Mystic BBS %HELP text response'
);

// --------------------------------------------------------------------------
// Test 3: Rescan Status Receipts without Area Lists (should be rejected)
// --------------------------------------------------------------------------
echo "\n3. Testing rejection of rescan receipts:\n";

$mysticRescanBody = <<<BODY
Your AREAFIX request has been processed

Command: %RESCAN [365]
 Result: Rescanned 0 messages in 0 bases
BODY;

assertCondition(
    $af->isAreaListResponse('AREAFIX response', $mysticRescanBody) === false,
    'Reject Mystic BBS %RESCAN receipt'
);

$mysticUnlinkedRescanBody = <<<BODY
Your AREAFIX request has been processed

Command: %UNLINKED
 Result: List of all unlinked areas:
Command: =TQW_TEST [D=1]
 Result: Rescanned 1 messages
BODY;

assertCondition(
    $af->isAreaListResponse('AREAFIX response', $mysticUnlinkedRescanBody) === false,
    'Reject %UNLINKED with rescan result when no unlinked areas exist'
);

// --------------------------------------------------------------------------
// Test 4: Unknown Echo Area / Command Errors (should be rejected)
// --------------------------------------------------------------------------
echo "\n4. Testing rejection of command errors:\n";

$mysticErrorBody = <<<BODY
Your FILEFIX request has been processed

Command: %RESCAN [365]
 Result: Unknown echo area
BODY;

assertCondition(
    $af->isAreaListResponse('FILEFIX response', $mysticErrorBody) === false,
    'Reject Unknown echo area error response'
);

// --------------------------------------------------------------------------
// Test 5: Ignored Tag List (ensure words from help text are never treated as tags)
// --------------------------------------------------------------------------
echo "\n5. Testing that prose words are filtered from freeform parsing:\n";

$proseSample = <<<BODY
Within the return Netmail response message.
Each line of the message content should consist of a single command.
Ability to self-service configuration.
After this command has been received.
Elements to limit the results.
BODY;

$parsedProse = $af->parseResponseText($proseSample, '%LIST');
assertCondition(
    empty($parsedProse),
    'Reject prose sentences and do not create areas for WITHIN, EACH, ABILITY, AFTER, ELEMENTS'
);

// --------------------------------------------------------------------------
// Summary
// --------------------------------------------------------------------------
echo "\n-------------------------------------------------------\n";
echo "Results: {$passed} passed, {$failed} failed.\n";
echo "=======================================================\n";

exit($failed > 0 ? 1 : 0);
