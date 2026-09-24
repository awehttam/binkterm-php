<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\AreaFixManager;
use BinktermPHP\AreaFix\AreaFixParser;

echo "=======================================================\n";
echo "Structural AreaFix Parser & Multi-Command Test Suite\n";
echo "=======================================================\n\n";

$af = new AreaFixManager();
$parser = new AreaFixParser();
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
// Test 1: Stacked Commands (Mystic BBS format - real SysOpNet pattern)
// --------------------------------------------------------------------------
echo "1. Testing stacked multi-command response:\n";

$stackedMystic = <<<BODY
Your AREAFIX request has been processed

Command: +SYS_GEN [R=100]
 Result: Echo will now be exported

Command: +SYS_ADMN [R=100]
 Result: Echo will now be exported

Command: -OLD_AREA
 Result: Echo will no longer be exported

Command: %RESCAN [R=100]
 Result: Rescanned 23 messages in 26 bases

Command: %LINKED
 Result: List of all linked areas:
  SYS_GEN                                  SysOp General Chat
  SYS_ADMN                                 SysOpNet Administration
  SYS_TST                                  Test Message Area
BODY;

$parsed = $parser->parse($stackedMystic);
assertCondition(
    count($parsed) === 4,
    'Extracts exactly 4 unique areas from stacked commands',
    'Found count: ' . count($parsed)
);

$tags = array_column($parsed, 'name');
assertCondition(
    in_array('SYS_GEN', $tags, true) && in_array('SYS_ADMN', $tags, true) && in_array('SYS_TST', $tags, true) && in_array('OLD_AREA', $tags, true),
    'Contains SYS_GEN, SYS_ADMN, SYS_TST, and OLD_AREA'
);

$oldArea = current(array_filter($parsed, fn($a) => $a['name'] === 'OLD_AREA'));
assertCondition(
    $oldArea && $oldArea['action'] === AreaFixParser::ACTION_UNSUBSCRIBE,
    'Correctly marks -OLD_AREA as ACTION_UNSUBSCRIBE'
);

$sysGen = current(array_filter($parsed, fn($a) => $a['name'] === 'SYS_GEN'));
assertCondition(
    $sysGen && $sysGen['action'] === AreaFixParser::ACTION_SUBSCRIBE && $sysGen['description'] === 'SysOp General Chat',
    'Correctly marks SYS_GEN as ACTION_SUBSCRIBE with description from %LINKED'
);

// --------------------------------------------------------------------------
// Test 2: Single Area Subscription Confirmation (+TAG)
// --------------------------------------------------------------------------
echo "\n2. Testing single area subscription confirmation:\n";

$singleSubscribe = <<<BODY
Your AREAFIX request has been processed

Command: +TQW_TEST [R=100]
 Result: Echo will now be exported
BODY;

$parsedSingle = $parser->parse($singleSubscribe);
assertCondition(
    count($parsedSingle) === 1 && $parsedSingle[0]['name'] === 'TQW_TEST' && $parsedSingle[0]['action'] === AreaFixParser::ACTION_SUBSCRIBE,
    'Accepts single-area subscription confirmation (+TQW_TEST)'
);

assertCondition(
    $af->isAreaListResponse('AREAFIX response', $singleSubscribe) === true,
    'isAreaListResponse recognizes single +TAG confirmation as actionable'
);

// --------------------------------------------------------------------------
// Test 3: Clearing Houz Colon Delimited Table with Subscribed & Available Flags
// --------------------------------------------------------------------------
echo "\n3. Testing Clearing Houz delimited table with status flags:\n";

$clearingHouzTable = <<<BODY
                                ▄▄▄ ▄   ▄▄▄ ▄▄▄ ▄▄▄ ▄ ▄▄▄ ▄▄▄
                                █ ▀ █   █▄█ ▄▄█ █ ▀ ▄ █ █ █▄█
                                █▄█ █▄█ █▄▄ █▄█ █   █ █ █ ▄▄█
──────────────────────────────────────────────────────────────────────────────
 ┌─┐┌─┐┌─┐┌─┐┌─┐■┌ ┐ Here are the list of available echoareas:
 ┌─││  │─┘┌─││─ ┬ X
 └─┘┴  └─┘└─┘└  ┴└ ┘

:---:------------:--------------------------------------------------:------:
:   : AREA       : DESCRIPTION                                      : MSGS :
:---:------------:--------------------------------------------------:------:
:*  : FSX_ADS    : FSX: Ads + ANSI Art                              :  547 :
:   : FSX_BBS    : FSX: BBS Support/Dev                             :   53 :
:*  : FSX_BOT    : FSX: Automated roBOT Posts                       :  255 :
:---:------------:--------------------------------------------------:------:

... Why did the robot cross the road?
BODY;

$parsedCh = $parser->parse($clearingHouzTable);
assertCondition(
    count($parsedCh) === 3,
    'Parses all 3 rows from Clearing Houz table and ignores surrounding ANSI art',
    'Found count: ' . count($parsedCh)
);

$fsxAds = current(array_filter($parsedCh, fn($a) => $a['name'] === 'FSX_ADS'));
assertCondition(
    $fsxAds && $fsxAds['action'] === AreaFixParser::ACTION_SUBSCRIBE && $fsxAds['is_subscribed'] === true,
    'FSX_ADS (marked with *) is recognized as ACTION_SUBSCRIBE'
);
assertCondition(
    $fsxAds && $fsxAds['description'] === 'FSX: Ads + ANSI Art',
    'FSX_ADS description preserves full text across colons ("FSX: Ads + ANSI Art")'
);

$fsxBbs = current(array_filter($parsedCh, fn($a) => $a['name'] === 'FSX_BBS'));
assertCondition(
    $fsxBbs && $fsxBbs['action'] === AreaFixParser::ACTION_AVAILABLE && $fsxBbs['is_subscribed'] === false,
    'FSX_BBS (unmarked) is recognized as ACTION_AVAILABLE'
);
assertCondition(
    $fsxBbs && $fsxBbs['description'] === 'FSX: BBS Support/Dev',
    'FSX_BBS description correctly extracted ("FSX: BBS Support/Dev")'
);

// Test placeholder description detection
assertCondition(
    AreaFixManager::isPlaceholderDescription('Auto-created: NASA Astronomy Picture of the') === true,
    'isPlaceholderDescription flags "Auto-created: NASA..." as placeholder'
);
assertCondition(
    AreaFixManager::isPlaceholderDescription('Auto-created from TIC file') === true,
    'isPlaceholderDescription flags "Auto-created from TIC file" as placeholder'
);
assertCondition(
    AreaFixManager::isPlaceholderDescription("Auto-created:   ▄▄▄   ▄▄▄") === true,
    'isPlaceholderDescription flags ANSI block art as placeholder'
);
assertCondition(
    AreaFixManager::isPlaceholderDescription('FSX: Image Files (Various)') === false,
    'isPlaceholderDescription accepts real description "FSX: Image Files (Various)"'
);

// --------------------------------------------------------------------------
// Test 4: HPT Columnar Table with Dotted Leaders & Rescan Status
// --------------------------------------------------------------------------
echo "\n4. Testing HPT columnar dotted-leader status table:\n";

$hptTable = <<<BODY
 Area                                                Status
 --------------------------------------------------  -------------------------
 LVLY_ANNOUNCE ....................................  rescanned 22 mails
 LVLY_CHAT ........................................  unsubscribed

Following is the original message text
--------------------------------------
%RESCAN LVLY_ANNOUNCE
BODY;

$parsedHpt = $parser->parse($hptTable);
assertCondition(
    count($parsedHpt) === 2,
    'Parses 2 areas from HPT table',
    'Found count: ' . count($parsedHpt)
);

$lvlyAnnounce = current(array_filter($parsedHpt, fn($a) => $a['name'] === 'LVLY_ANNOUNCE'));
assertCondition(
    $lvlyAnnounce && $lvlyAnnounce['action'] === AreaFixParser::ACTION_SUBSCRIBE,
    'LVLY_ANNOUNCE (rescanned) is recognized as ACTION_SUBSCRIBE'
);

$lvlyChat = current(array_filter($parsedHpt, fn($a) => $a['name'] === 'LVLY_CHAT'));
assertCondition(
    $lvlyChat && $lvlyChat['action'] === AreaFixParser::ACTION_UNSUBSCRIBE,
    'LVLY_CHAT (unsubscribed) is recognized as ACTION_UNSUBSCRIBE'
);

// --------------------------------------------------------------------------
// Test 5: Real Areas Named LINUX, WINDOWS, BASE (no word-blacklist false negatives)
// --------------------------------------------------------------------------
echo "\n5. Testing real echoareas with common names (LINUX, WINDOWS, BASE):\n";

$realCommonNames = <<<BODY
Command: %LIST
 Result: List of all areas:
  LINUX                                    Linux Kernel and Distributions
  WINDOWS                                  Windows NT Discussion
  BASE                                     Base Software Development
BODY;

$parsedCommon = $parser->parse($realCommonNames);
$commonTags = array_column($parsedCommon, 'name');
assertCondition(
    in_array('LINUX', $commonTags, true) && in_array('WINDOWS', $commonTags, true) && in_array('BASE', $commonTags, true),
    'Preserves real areas named LINUX, WINDOWS, BASE without keyword collision'
);

// --------------------------------------------------------------------------
// Test 6: Help Manuals Rejection (0 areas, 0 false positives)
// --------------------------------------------------------------------------
echo "\n6. Testing help manuals rejection across mailers:\n";

$mysticHelp = <<<BODY
Command: %HELP
 Result: Help included at end of message

 What is AreaFix?
 AreaFix is an automated response system which provides echomail nodes the
 ability to self-service their configuration.
BODY;

assertCondition(
    empty($parser->parse($mysticHelp)),
    'Mystic BBS %HELP text produces 0 areas'
);
assertCondition(
    $af->isAreaListResponse('AREAFIX response', $mysticHelp) === false,
    'isAreaListResponse rejects Mystic BBS %HELP text'
);

$hptHelp = <<<BODY
Here's some help about how you can use AreaFix to change your echomail areas.
All AreaFix commands are case insensitive, you may use %help as well as %HELP.
%HELP                        <- AreaFix will send you this help.
%LIST                        <- List accessible areas
BODY;

assertCondition(
    empty($parser->parse($hptHelp)),
    'HPT %HELP text produces 0 areas'
);

// --------------------------------------------------------------------------
// Test 7: Rescan-only receipts without areas (rejected)
// --------------------------------------------------------------------------
echo "\n7. Testing rescan-only receipts without area lists:\n";

$rescanOnly = <<<BODY
Command: %RESCAN [365]
 Result: Rescanned 0 messages in 0 bases
BODY;

assertCondition(
    empty($parser->parse($rescanOnly)),
    '%RESCAN receipt produces 0 areas'
);
assertCondition(
    $af->isAreaListResponse('AREAFIX response', $rescanOnly) === false,
    'isAreaListResponse rejects %RESCAN receipt'
);

// --------------------------------------------------------------------------
// Test 8: %QUERY block mixing linked and unlinked areas (row-level status)
// --------------------------------------------------------------------------
echo "\n8. Testing %QUERY block with mixed linked/unlinked row annotations:\n";

$mixedQuery = <<<BODY
Your AREAFIX request has been processed

Command: %QUERY
 Result: List of all linked and unlinked areas:
  SYS_GEN                                  SysOp General Chat (linked)
  SYS_ADMN                                 SysOpNet Administration
  SYS_ARCHIVE                              Archived Discussions (unlinked)
  SYS_TST                                  Test Message Area (not linked)
BODY;

$mixedAreas = $parser->parse($mixedQuery);
assertCondition(
    count($mixedAreas) === 4,
    'Extracts all 4 areas from a mixed %QUERY block',
    'Found count: ' . count($mixedAreas)
);

$sysGen = current(array_filter($mixedAreas, fn($a) => $a['name'] === 'SYS_GEN'));
assertCondition(
    $sysGen && $sysGen['action'] === AreaFixParser::ACTION_SUBSCRIBE && $sysGen['is_subscribed'] === true
        && $sysGen['description'] === 'SysOp General Chat',
    'A row explicitly annotated "(linked)" is ACTION_SUBSCRIBE, with the annotation stripped from the description',
    'Got: ' . var_export($sysGen, true)
);

$sysAdmn = current(array_filter($mixedAreas, fn($a) => $a['name'] === 'SYS_ADMN'));
assertCondition(
    $sysAdmn && $sysAdmn['action'] === AreaFixParser::ACTION_SUBSCRIBE && $sysAdmn['is_subscribed'] === true,
    'A row with no annotation falls back to the %QUERY command-level default (ACTION_SUBSCRIBE)'
);

$sysArchive = current(array_filter($mixedAreas, fn($a) => $a['name'] === 'SYS_ARCHIVE'));
assertCondition(
    $sysArchive && $sysArchive['action'] === AreaFixParser::ACTION_AVAILABLE && $sysArchive['is_subscribed'] === false
        && $sysArchive['description'] === 'Archived Discussions',
    'A row explicitly annotated "(unlinked)" is ACTION_AVAILABLE despite the %QUERY command-level default, and the annotation is stripped from the description',
    'Got: ' . var_export($sysArchive, true)
);

$sysTst = current(array_filter($mixedAreas, fn($a) => $a['name'] === 'SYS_TST'));
assertCondition(
    $sysTst && $sysTst['action'] === AreaFixParser::ACTION_AVAILABLE && $sysTst['is_subscribed'] === false,
    'A row explicitly annotated "(not linked)" is also treated as unlinked'
);

// --------------------------------------------------------------------------
// Test 9: Live Database Replay against Netmail Records
// --------------------------------------------------------------------------
echo "\n9. Testing against live database records in Netmail table:\n";

try {
    $db = \BinktermPHP\Database::getInstance()->getPdo();
    $stmt = $db->query("SELECT id, from_address, from_name, subject, message_text FROM netmail WHERE to_name ILIKE '%fix%' OR subject ILIKE '%fix%' OR from_address ILIKE '%fix%' OR from_name ILIKE '%robot%' OR from_name ILIKE '%Clearing%' ORDER BY id");
    $dbMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $helpCount = 0;
    $listCount = 0;
    $rescanCount = 0;

    foreach ($dbMessages as $m) {
        $mid = (int)$m['id'];
        $body = (string)$m['message_text'];
        $subj = (string)$m['subject'];

        $areas = $parser->parse($body, $subj);
        $isActionable = $af->isAreaListResponse($subj, $body);

        // Verification of known IDs:
        if ($mid === 125) {
            // SysOpNet stacked +TAG and %LINKED: 13 areas
            assertCondition(
                count($areas) === 13 && $isActionable === true,
                "Netmail ID 125 (SysOpNet stacked): extracted exactly 13 areas (found " . count($areas) . ")"
            );
        } elseif ($mid === 119) {
            // SysOpNet %HELP manual: must be 0 areas
            assertCondition(
                count($areas) === 0 && $isActionable === false,
                "Netmail ID 119 (Mystic %HELP): rejected, extracted 0 areas (found " . count($areas) . ")"
            );
        } elseif ($mid === 54) {
            // HPT %HELP manual: must be 0 areas
            assertCondition(
                count($areas) === 0 && $isActionable === false,
                "Netmail ID 54 (HPT %HELP): rejected, extracted 0 areas (found " . count($areas) . ")"
            );
        } elseif ($mid === 110) {
            // tqwNet stacked +TAG: 9 exported areas
            assertCondition(
                count($areas) === 9 && $isActionable === true,
                "Netmail ID 110 (tqwNet stacked +TAG): extracted exactly 9 areas (found " . count($areas) . ")"
            );
        } elseif ($mid === 9) {
            // Clearing Houz %LIST: table with fsxNet areas
            assertCondition(
                count($areas) > 10 && $isActionable === true,
                "Netmail ID 9 (Clearing Houz %LIST): extracted " . count($areas) . " areas"
            );
        }
    }
} catch (\Throwable $e) {
    echo "  (Database replay skipped: " . $e->getMessage() . ")\n";
}

// --------------------------------------------------------------------------
// Summary
// --------------------------------------------------------------------------
echo "\n-------------------------------------------------------\n";
echo "Results: {$passed} passed, {$failed} failed.\n";
echo "=======================================================\n";

exit($failed > 0 ? 1 : 0);
