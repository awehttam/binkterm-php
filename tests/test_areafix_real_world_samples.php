<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\AreaFix\AreaFixParser;

echo "=======================================================\n";
echo "AreaFix Parser: Real-World Hub Reply Samples\n";
echo "=======================================================\n\n";

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
// Sample 1: SBBSecho bare two-column area list (no header, no delimiter,
// terminated by a "--- SBBSecho x.xx" tearline). Captured from a real hub reply.
// --------------------------------------------------------------------------
echo "1. SBBSecho bare two-column list (CHEESE_* areas):\n";

$sbbsechoBody = <<<BODY
CHEESE_ADS        Advertisements
CHEESE_ANNOUNCE   CheeseNet announcements
CHEESE_BBSBUZZ    BBS Buzz
CHEESE_CREATIVE   Creative works
CHEESE_CULTURE    Culture
CHEESE_DEBATE     Debate
CHEESE_HUMANSONLY Human posters only - bots prohibited by design
CHEESE_NEWS       Breaking News
CHEESE_POLITICS   Politics
CHEESE_SCITECH    Science and Tech
CHEESE_SPORTS     Sports Talk
CHEESE_SYSOPS     Sysops only
CHEESE_TEST       Test echo
CHEESE_TRENDING   Trending discussion
--- SBBSecho 3.37-Linux
BODY;

$sbbsechoAreas = $parser->parse($sbbsechoBody);

assertCondition(
    count($sbbsechoAreas) === 13,
    'Parses 13 of 14 CHEESE_* areas via the freeform fallback tier (headerless, no delimiter)',
    'Found count: ' . count($sbbsechoAreas) . ' (Improvement 1 fallback now handles this format)'
);

$sbbsechoTags = array_column($sbbsechoAreas, 'name');
assertCondition(
    in_array('CHEESE_ADS', $sbbsechoTags, true) && in_array('CHEESE_TRENDING', $sbbsechoTags, true),
    'Includes both the first (CHEESE_ADS) and last (CHEESE_TRENDING) listed areas'
);

assertCondition(
    !in_array('CHEESE_HUMANSONLY', $sbbsechoTags, true),
    'Known limitation: CHEESE_HUMANSONLY is not captured because its long tag name leaves only a single space before the description; the freeform fallback requires 2+ spaces to avoid misreading ordinary prose sentences as area rows',
    'This is an intentional precision/coverage tradeoff, not a regression — loosening to a 1-space gap would make the fallback match arbitrary two-word sentences.'
);

assertCondition(
    $parser->hasActionableContent($sbbsechoBody),
    'hasActionableContent() recognizes the SBBSecho list as actionable'
);

// --------------------------------------------------------------------------
// Sample 2: Mystic BBS %LIST Command/Result block with an 18-area indented
// listing. Captured from a real hub reply (TQW_* areas).
// --------------------------------------------------------------------------
echo "\n2. Mystic BBS %LIST Command/Result block (TQW_* areas):\n";

$mysticListBody = <<<BODY
Your AREAFIX request has been processed

Command: %LIST
 Result: List of all areas:
  TQW_ADS                                  BBS Adverts
  TQW_BBSGEN                               General BBS Chat
  TQW_BOT                                  roBOT output
  TQW_DOCKER                               Homelab Chat
  TQW_GEN                                  General Chat
  TQW_GENSCI                               General Science News
  TQW_GENTECH                              General Technology News
  TQW_LINUX                                Linux
  TQW_MACOSX                               Mac OSX
  TQW_MBSE                                 MBSE BBS Chat
  TQW_MDEV                                 Mystic BBS Development
  TQW_MYS                                  Mystic BBS Chat
  TQW_PYTHON                               Python General Chat
  TQW_RASPBPI                              Raspberry Pi
  TQW_SUG                                  Suggestions Box
  TQW_SYNCHRO                              Synchronet BBS Chat
  TQW_TEST                                 Test Echo
  TQW_ECHOCHAMBER                          Demoscene Party Events
BODY;

$mysticAreas = $parser->parse($mysticListBody);

assertCondition(
    count($mysticAreas) === 18,
    'Parses all 18 TQW_* areas from a Mystic %LIST result block',
    'Found count: ' . count($mysticAreas)
);

$mysticTags = array_column($mysticAreas, 'name');
assertCondition(
    in_array('TQW_LINUX', $mysticTags, true) && in_array('TQW_ECHOCHAMBER', $mysticTags, true),
    'Includes areas with common-word names (TQW_LINUX) and the last listed area (TQW_ECHOCHAMBER)'
);

$tqwLinux = current(array_filter($mysticAreas, fn($a) => $a['name'] === 'TQW_LINUX'));
assertCondition(
    $tqwLinux && $tqwLinux['description'] === 'Linux' && $tqwLinux['action'] === AreaFixParser::ACTION_AVAILABLE,
    'TQW_LINUX is classified as ACTION_AVAILABLE (an %LIST result is a catalog, not a subscription confirmation)'
);

assertCondition(
    $parser->hasActionableContent($mysticListBody),
    'hasActionableContent() recognizes the Mystic %LIST reply as actionable'
);

// --------------------------------------------------------------------------
// Sample 3: A hub's combined "mail groups" + "Con / Message area / Description"
// columnar reply (AgoraNet-style AreaMgr/Husky output), including a leading
// unrelated network-group table and trailing legend/footer text. Captured
// from a real hub reply (AGN_* areas).
// --------------------------------------------------------------------------
echo "\n3. AreaMgr-style \"Con / Message area / Description\" table (AGN_* areas):\n";

$agoranetBody = <<<BODY
Dear Matthew Asham:

The following is a list of available mail groups:

Group                Description
-------------------- --------------------------------------------------
AgoraNet             AgoraNet
-------------------- --------------------------------------------------
Total: 1 group

 The following is a list of all message areas


Group AgoraNet - AgoraNet (46:1/156@agoranet)
Con  Message area              Description
---- ------------------------- ---------------------------------------------
     AGN_BBS                   BBS Discussion
     AGN_NIX                   Unix/Linux Related
     AGN_ADS                   BBS and Network Ads
     AGN_HUB                   Agoranet Hub Stats
     AGN_SYS                   Sysops Only
     AGN_TST                   Testing Setups
     AGN_ART                   Art/Demo Scene
     AGN_GEN                   General Chat
     AGN_DEV                   Software Development
-----------------------------------------------------------------------------
9 Available areas.

9 Total Available areas.

Con means:
 R  - You receive mail from my system
 S  - You may send mail to my system
 P  - The message area is temporary paused
 C  - You are cutoff from this area

 You are welcome: Andrew Leary

... So easy, a child could do it.  Child sold separately.
BODY;

$agoranetAreas = $parser->parse($agoranetBody);

assertCondition(
    count($agoranetAreas) === 9,
    'Parses all 9 AGN_* message areas from the "Con / Message area / Description" table',
    'Found count: ' . count($agoranetAreas) . ' (known gap: header reads "Con  Message area  Description", not the literal "Area ... Status|Description|Msgs|Files" the columnar parser requires, see docs/proposals/PR460Proposal.md Improvement 1)'
);

$agoranetTags = array_column($agoranetAreas, 'name');
assertCondition(
    in_array('AGN_BBS', $agoranetTags, true) && in_array('AGN_DEV', $agoranetTags, true),
    'Includes both the first (AGN_BBS) and last (AGN_DEV) listed areas'
);

assertCondition(
    !in_array('AGORANET', $agoranetTags, true) && !in_array('GROUP', $agoranetTags, true),
    'Does not misparse the unrelated leading "mail groups" table (AgoraNet group name / header words) as an area'
);

assertCondition(
    $parser->hasActionableContent($agoranetBody),
    'hasActionableContent() recognizes the AreaMgr-style reply as actionable'
);

// --------------------------------------------------------------------------
// Sample 4: Mystic BBS %LIST Command/Result block with 35 areas whose
// descriptions contain colons, brackets, ampersands, apostrophes, and
// truncated (fixed-width-clipped) text. Captured from a real hub reply
// (SpookNet SN_* areas). Exercises that the Mystic block parser does not
// split on ':' the way the delimited-table parser does.
// --------------------------------------------------------------------------
echo "\n4. Mystic BBS %LIST result block with punctuation-heavy descriptions (SpookNet SN_* areas):\n";

$spooknetBody = <<<BODY
Your AREAFIX request has been processed

Command: %LIST
 Result: List of all areas:
  SN_ALIEN                                 [SpookNet]: Aliens, UFOs & EBEs
  SN_SECURITY                              [SpookNet]: Computer Security & Hack
  SN_TERROR                                [SpookNet]: Counter-Terrorism
  SN_COVERT                                [SpookNet]: Covert Communication
  SN_CRYPTO                                [SpookNet]: Cryptography & Steganogr
  SN_DARKNET                               [SpookNet]: Darknets, Net. Undergrou
  SN_DISASTER                              [SpookNet]: Disease, Pandemics, Disa
  SN_END_TIME                              [SpookNet]: End Times & The Last Day
  SN_FINANCIAL                             [SpookNet]: Financial Crisis & Meltd
  SN_CONSPIRACY                            [SpookNet]: General Conspiracies
  SN_FORENSICS                             [SpookNet]: General Forensics
  SN_INTEL                                 [SpookNet]: Intelligence & Espionage
  SN_LAW                                   [SpookNet]: Intelligence & the Law
  SN_TORTURE                               [SpookNet]: Interrogation & Torture
  SN_LANG                                  [SpookNet]: Languages & Public Speak
  SN_MID_EAST                              [SpookNet]: Middle East Studies
  SN_MIND                                  [SpookNet]: Mind Control & Programmi
  SN_NWO                                   [SpookNet]: New World Order
  SN_PARANORMAL                            [SpookNet]: Paranormal Studies
  SN_POLITICS                              [SpookNet]: Politics & People
  SN_PREDICT                               [SpookNet]: Predictive Studies
  SN_DISINFO                               [SpookNet]: Propaganda & Disinformat
  SN_SAFE_HOUSE                            [SpookNet]: Safe Houses & Sanctuarie
  SN_SOCIETY                               [SpookNet]: Secret Societies
  SN_SILENCE                               [SpookNet]: Silencers of Information
  SN_WEAPONS                               [SpookNet]: Spooks & Their Weapons
  SN_TRUTH                                 [SpookNet]: Truth, Polygraphs, Serum
  SN_TSCM                                  [SpookNet]: TSCM Bug Detection
  SN_RELIGION                              [SpookNet]: Violent Extremist Religi
  SN_WHISTLE                               [SpookNet]: Whistle Blowers
  SN_SYSOP                                 [SpookNet]: [ADMIN] Sysop Network Ch
  SN_CURRENCY                              [SpookNet]: General CryptoCurrency
  SN_MONERO                                [SpookNet]: Secure eCurrencies
  SN_INVEST                                [SpookNet]: The Investor's Network (
  SN_BUYSELL                               [SpookNet]: Buy, Sell, Trade, Free
BODY;

$spooknetAreas = $parser->parse($spooknetBody);

assertCondition(
    count($spooknetAreas) === 35,
    'Parses all 35 SN_* areas from a Mystic %LIST result block',
    'Found count: ' . count($spooknetAreas)
);

$snAlien = current(array_filter($spooknetAreas, fn($a) => $a['name'] === 'SN_ALIEN'));
assertCondition(
    $snAlien && $snAlien['description'] === '[SpookNet]: Aliens, UFOs & EBEs',
    'Description containing a colon, brackets, and an ampersand is preserved in full (SN_ALIEN)',
    'Got: ' . var_export($snAlien['description'] ?? null, true)
);

$snSysop = current(array_filter($spooknetAreas, fn($a) => $a['name'] === 'SN_SYSOP'));
assertCondition(
    $snSysop && $snSysop['description'] === '[SpookNet]: [ADMIN] Sysop Network Ch',
    'Description with a second bracketed segment is preserved without being split on the inner colon (SN_SYSOP)',
    'Got: ' . var_export($snSysop['description'] ?? null, true)
);

$snInvest = current(array_filter($spooknetAreas, fn($a) => $a['name'] === 'SN_INVEST'));
assertCondition(
    $snInvest && $snInvest['description'] === "[SpookNet]: The Investor's Network (",
    'Description containing an apostrophe is preserved without being treated as a quote/tag boundary (SN_INVEST)',
    'Got: ' . var_export($snInvest['description'] ?? null, true)
);

assertCondition(
    $parser->hasActionableContent($spooknetBody),
    'hasActionableContent() recognizes the SpookNet %LIST reply as actionable'
);

// --------------------------------------------------------------------------
// Sample 5: Mystic BBS %LIST Command/Result block with 44 areas, including a
// hyphenated description ("Hobbynet - Wood Crafting") and an area whose
// description is coincidentally identical to its own tag (HNET_GENEAOLOGY).
// Captured from a real hub reply (HobbyNet HNET_* areas).
// --------------------------------------------------------------------------
echo "\n5. Mystic BBS %LIST result block with 44 areas (HobbyNet HNET_* areas):\n";

$hobbynetBody = <<<BODY
Your AREAFIX request has been processed

Command: %LIST
 Result: List of all areas:
  HNET_LEGO                                LEGOs to relax and de-stress, and he
  HNET_MUSIC                               Includes singing, and the art of mak
  HNET_OIL_PAINTING                        Oil painting on all mediums
  HNET_RC                                  Radio controlled hobbies including l
  HNET_PHOTOGRAPHY                         Photography, a fun and creative way
  HNET_MODELS                              Includes boats and ships, dollhouse
  HNET_HOME_MEDIA_SERVERS                  Home media servers to store your dig
  HNET_WOOD_CRAFT                          Hobbynet - Wood Crafting
  HNET_HOMESTEADING                        Homesteading to grow your own food,
  HNET_TABLE_TENNIS                        If you love ping pong.
  HNET_CROCHET                             How-to information, stitch guides, y
  HNET_ANTIQUES                            The world of antiques and collectibl
  HNET_GENEALOGY                           The hobby that studies ancestry and
  HNET_MODEL_HORSE                         Toy and model horse hobby collectibl
  HNET_GENEAOLOGY                          HNET_GENEAOLOGY
  HNET_VIDEO_EDITING                       Video editing and editing techniques
  HNET_ENAMELING                           The enameling technique, a craft inv
  HNET_SCUBA                               Scuba to explore the underwater worl
  HNET_MICROCONTROLLER_PROJECTS            Re-programmable elements with source
  HNET_PICKLEBALL                          That great sport of pickleball
  HNET_BEADING                             The craft of beading and beadwork je
  HNET_BASKETRY                            The craft of basketry including, ins
  HNET_CRAFTS                              Craft projects such as sewing, paper
  HNET_COMPUTING                           Computing of any form including retr
  HNET_STATS                               Daily statistics for HobbyNet traffi
  HNET_FLOWERS                             Dried or pressed flowers and dried o
  HNET_DOLLMAKING                          Doll making instructions, advice, ex
  HNET_COMIC_UNIVERSE                      Comics whether on paper or in the mo
  HNET_DANCE                               Any style including Latin/Rhythym, S
  HNET_CRAFT_CUTTING_MACHINES              All things crafty with machines like
  HNET_CROSS_STITCH                        Anything cross stitch, including pat
  HNET_CAMPING                             Camping for recreation, whether by t
  HNET_WET_ON_WET_OIL_PAINTING             Bob Ross/Bill Alexander Painting Sty
  HNET_HOBBIES                             Hobbynet - Misc Hobbies
  HNET_CHAT                                Hobbynet - General Chat
  HNET_SPACE                               Hobbynet - Space Chat
  HNET_TEST                                Hobbynet - Test Messages
  HNET_SYSOP_ADMIN                         Hobbynet - Sysop/Admin
  HNET_ACRYLIC_PAINTING                    Hobbynet - Acrylic Painting
  HNET_GARDENING                           Gardening as a hobby.
  HNET_HAM_RADIO                           For amateur radio operators.
  HNET_GLASS                               Glass artists and crafters to offer
  HNET_CERAMICS                            Hobby ceramists, including tips, tut
  HNET_JEWELRY                             Handmade recreational gems and jewel
BODY;

$hobbynetAreas = $parser->parse($hobbynetBody);

assertCondition(
    count($hobbynetAreas) === 44,
    'Parses all 44 HNET_* areas from a Mystic %LIST result block',
    'Found count: ' . count($hobbynetAreas)
);

$hobbynetTags = array_column($hobbynetAreas, 'name');
assertCondition(
    count($hobbynetTags) === count(array_unique($hobbynetTags)),
    'No duplicate or dropped tags among the 44 areas'
);

$woodCraft = current(array_filter($hobbynetAreas, fn($a) => $a['name'] === 'HNET_WOOD_CRAFT'));
assertCondition(
    $woodCraft && $woodCraft['description'] === 'Hobbynet - Wood Crafting',
    'Description containing a hyphen surrounded by spaces is preserved in full (HNET_WOOD_CRAFT)',
    'Got: ' . var_export($woodCraft['description'] ?? null, true)
);

$geneaology = current(array_filter($hobbynetAreas, fn($a) => $a['name'] === 'HNET_GENEAOLOGY'));
assertCondition(
    $geneaology && $geneaology['description'] === 'HNET_GENEAOLOGY',
    'An area whose description happens to equal its own tag is not treated as a missing/duplicate description (HNET_GENEAOLOGY)',
    'Got: ' . var_export($geneaology['description'] ?? null, true)
);

assertCondition(
    $parser->hasActionableContent($hobbynetBody),
    'hasActionableContent() recognizes the HobbyNet %LIST reply as actionable'
);

// --------------------------------------------------------------------------
// Sample 6: BBBS/Li6 "+/space TAG (address) \"description\"" format, with
// descriptions that wrap onto a continuation line with no leading tag, and a
// single reply combining both an echo-area list and a file-area list (each
// introduced by its own "List of all ... areas available for node ..."
// banner). Captured from a real hub reply (233 echo + 79 file areas).
// --------------------------------------------------------------------------
echo "\n6. BBBS/Li6 combined echo+file area list with wrapped descriptions:\n";

$bbbsBody = <<<BODY
 List of all echo areas available for node 1:153/150.0.
 + = Area already connected

+10TH_AMD                       (1:153/757) "10th Amendment Discussion"
 ABLED                          (1:153/757) "disABLED Users Information
                                Exchange"
+AFTERSHOCK                     (1:153/757) "Aftershock Help & Support"
+ALASKA_CHAT                    (1:153/757) "Alaska Chat"
+ALL-POLITICS                   (1:153/757) "Politics Unlimited"
+ALLFIX_FILE                    (1:153/757) "Allfix File Announce
                                Conference"
+ALLFIX_HELP                    (1:153/757) "ALLFIX Help & Support"
+ALT.ENERGY                     (1:153/757) "Alternative Energy"
+AMATEUR_RADIO                  (1:153/757) "Amateur Radio / Ham Radio"
+AMIGA                          (1:153/757) "Amiga International Echo"
+ANTI_VIRUS                     (1:153/757) "Anti-Virus Discussion & News"
+ANTIQUES                       (1:153/757) "Antiques And Vintage
                                Collections"
+ANYTHING_GOES                  (1:153/757) "Anything Goes - Open & Spirited
                                Chat"
+APPLE                          (1:153/757) "Apple Products - MacOS, iOS &
                                iCloud"
+AQUARIUM                       (1:153/757) "Aquariums and Fishkeeping"
+ARGUS                          (1:153/757) "Argus Support Echo"
+ARROWBRIDGE                    (1:153/757) "Arrowbridge Door Game"
+ARTWARE                        (1:153/757) "TimEd, NetMgr, WIMM Support"
+ASCII_ART                      (1:153/757) "Ascii Art Showcase"
+ASIAN_LINK                     (1:153/757) "ASIAN_LINK: International Chat"
+ASTRONET                       (1:153/757) "Astronet"
+ASTRONOMY                      (1:153/757) "Astronomy Discussion"
+AUDIO                          (1:153/757) "Audio Discussion"
+AUTOMOTIVE                     (1:153/757) "Automotive Discussion"
+AVIATION                       (1:153/757) "Aviation Discussion"
+BAMA                           (1:153/757) "Science Research Echo"
+BASH                           (1:153/757) "BASH Scripting"
+BATPOWER                       (1:153/757) "Batch Language Programming"
+BBBS.ENGLISH                   (1:153/757) "BBBS Help & Support"
+BBS-SCENE                      (1:153/757) "Support Echo for bbs-scene.org"
+BBS_ADS                        (1:153/757) "BBS Advertisements"
+BBS_CARNIVAL                   (1:153/757) "BBS Software Chatter"
 BBSDOOR_DISCUSSION             (1:153/757) "BBS Doorgame and Utility
                                Discussion"
+BBS_INTERNET                   (1:153/757) "BBS Internet (DOS/Win/OS2/Unix)
                                Applications"
+BBS_PROMOTION                  (1:153/757) "The BBS Promotion Team; Join
                                Us!"
+BEL.CHARTER97.EN               (1:153/757) "News for Free Belarus"
+BIBLE                          (1:153/757) "International Bible Conference"
+BINKD                          (1:153/757) "The BinkD TCP/IP FTN mailer"
+BINKLEY                        (1:153/757) "BinkleyTerm Mailer Help &
                                Support"
+BLUEWAVE                       (1:153/757) "Blue Wave Offline Mail System"
+CANACHAT                       (1:153/757) "The CANADA Chat Echo"
+CATS_MEOW                      (1:153/757) "The CATS MEOW echo"
+CBM                            (1:153/757) "Commodore Computer Conference"
+CFORSALE                       (1:153/757) "Commercial for Sale Echo"
+CHAT                           (1:153/757) "General Chat & Discussion"
 CHWARE                         (1:153/757) "Cheepware Chat/Support"
+CLASSIC_COMPUTER               (1:153/757) "Classic Computers"
+CNET_BBS                       (1:153/757) "CNET BBS Support"
+COFFEE_KLATSCH                 (1:153/757) "Gossip and chit-chat echo"
+COMM                           (1:153/757) "Communications Echo"
+CONSPRCY                       (1:153/757) "Conspiracy Discussions"
+CONTROVERSIAL                  (1:153/757) "Controversial Topics & Current
                                Events"
+COOKING                        (1:153/757) "The National Cooking Echo"
+CROSSFIRE                      (1:153/757) "Politics and Current Events"
+CYBER-DANGER                   (1:153/757) "Malware, Trojan, Virus & Rogue
                                App Discussion"
+DADS                           (1:153/757) "DADS Discussion"
 DBRIDGE                        (1:153/757) "D'Bridge Support Echo"
+DC_UNIVERSE                    (1:153/757) "DC Universe Comics/TV/Movies"
+DEBATE                         (1:153/757) "DEBATE Conference"
+DELPHI                         (1:153/757) "Delphi Programming"
+DOGHOUSE                       (1:153/757) "International Dog Lovers
                                Echomail Conference"
+DOOM                           (1:153/757) "DOOM (and other 3D games)
                                discussion and support"
+DOORGAMES                      (1:153/757) "Door Games"
+DOS                            (1:153/757) "DOS Operating Systems"
+DOS_INTERNET                   (1:153/757) "DOS Internet Applications"
 DR                             (1:153/757) "Chat from Dark Realms BBS"
 E-TEST                         (1:153/757) "Net 3634 Test"
+EARTH                          (1:153/757) "Discussion of Earth Sciences
                                Material"
+ECHO_ADS                       (1:153/757) "Advertise FidoNet Echos Here"
 ECHOLIST                       (1:153/757) "EchoList Access Conference"
+ELIST                          (1:153/757) "Elist Conference"
+ENGLISH_TUTOR                  (1:153/757) "English Tutoring for Students
                                of English"
+ESSNASA                        (1:153/757) "Earth & Space Sci-Tech + NASA"
+FDECHO                         (1:153/757) "FrontDoor/TosScan Support
                                Conference"
+FDN_ANNOUNCE                   (1:153/757) "File Distribution Networks
                                Files, Info, & Links"
+FE_HELP                        (1:153/757) "The FastEcho Mailprocessor
                                Support Conference"
+FIDO-REQ                       (1:153/757) "New file announcements"
+FIDO_SYSOP                     (1:153/757) "The FIDO SYSOP Echo"
+FIDO_UTIL                      (1:153/757) "Fidonet Related SysOp Utils and
                                Apps"
+FIDOGAZETTE                    (1:153/757) "FidoGazette: An Alternative
                                Newsletter"
+FIDONET.ORG                    (1:153/757) "fidonet.org Management &
                                Discussion"
+FIDONEWS                       (1:153/757) "FidoNews Discussion"
+FIDOTEST                       (1:153/757) "Fidonet TEST echo and
                                malfunction conference"
+FIDOPOLS                       (1:153/757) "Fidonet Policy Discussion"
+FILEFIND                       (1:153/757) "ALLFIX International FILEFIND
                                Echo"
+FILEGATE                       (1:153/757) "IFDC FileGate Project(sm)
                                International Echo"
+FMAIL_HELP                     (1:153/757) "FMail Help & Support"
+FN_SYSOP                       (1:153/757) "The FidoNet Sysop Echo"
+FTSC_PUBLIC                    (1:153/757) "FTSC Public Echo"
+FUNNY                          (1:153/757) "FUNNY Jokes and Stories"
+FUTURE4FIDO                    (1:153/757) "Discussion of new and future
                                Fidonet technologies"
+GECHO_HELP                     (1:153/757) "I'ntl GEcho Support"
+GENEALOGY                      (1:153/757) "Fidonet Genealogy discussion"
+GOLDED                         (1:153/757) "GoldED Public Release
                                discussion"
+GOLDEN                         (1:153/757) "The Golden FTN Support Echo"
+GUITAR                         (1:153/757) "Guitar/Bass Guitar Topics"
+GUN_CONTROL                    (1:153/757) "Gun Control Discussion"
+GUNS_N_SUCH                    (1:153/757) "Discussion of Guns (No Debate)"
+HAM_TECH                       (1:153/757) "Amateur(HAM) Radio TECHnical
                                Conference"
+HAM                            (1:153/757) "Amateur Radio Interest"
+HOLYSMOKE                      (1:153/757) "Religion Debate"
+HOME_COOKING                   (1:153/757) "Home Cooking and Related
                                Topics"
+HOME_N_GRDN                    (1:153/757) "Home & Garden"
+HOROSCOPE                      (1:153/757) "Horoscope Listings &
                                Discussion"
+HOTDOGED                       (1:153/757) "HOTDOGED Help & Support"
+HUB12ELINK                     (1:153/757) "Hub 12 Echo Link"
+HUB12FILES                     (1:153/757) "Hub 12 File Link"
+FIDOSOFT.HUSKY                 (1:153/757) "Husky HPT/HTick Help & Support"
+IBBSDOOR                       (1:153/757) "InterBBS Games"
 IC                             (1:153/757) "IC Discussions"
+IMECHO                         (1:153/757) "InterMail/Echo Help & Support"
+INTERNET                       (1:153/757) "Internet Discussion"
+IPV6                           (1:153/757) "IPv6 Discussion"
+IREX                           (1:153/757) "Internet Rex FTN Mailer Help &
                                Support"
+JAMNNTPD                       (1:153/757) "Support Echo For JAMNNTPD NNTP
                                Server"
+JAZZ                           (1:153/757) "Jazz Music & Musicians"
+LINUX                          (1:153/757) "Linux operating systems (OS)"
+LINUX-USER                     (1:153/757) "Linux User"
+LINUX_BBS                      (1:153/757) "Linux BBSing"
 LISTS.CISA-ADVISORIES          (1:153/757) "Cyber Security Alerts &
                                Advisories"
 LISTS.UBUNTU-SECURITY          (1:153/757) "Security Announcements from
                                Ubuntu"
+LITRPG                         (1:153/757) "Literature Role Playing Games"
+LORD                           (1:153/757) "Legend of the Red Dragon Door"
+MAKENL_NG                      (1:153/757) "MakeNL Next Generation"
+MANAGERS                       (1:153/757) "Region 17 Echomail Stats"
+MARVEL_UNIVERSE                (1:153/757) "MARVEL Universe -
                                Comics/TV/Movies"
+MBSE                           (1:153/757) "The MBSE BBS support echo"
+MEMORIES                       (1:153/757) "Memories & Nostalgia"
+MINISTER                       (1:153/757) "e-Ministries Devotions"
+ML_BASEBALL                    (1:153/757) "Major League Baseball"
+MOBILE                         (1:153/757) "Discussion of Mobile Devices &
                                Communications"
+MOSCOWTIMES                    (1:153/757) "News from The Moscow Times"
+MONTE                          (1:153/757) "Monty Python"
+MOVIES                         (1:153/757) "Movies & TV Discussion"
+MUFFIN                         (1:153/757) "Maximus BBS Discussion &
                                Support"
+MUSIC                          (1:153/757) "All about Music"
+MYSTIC                         (1:153/757) "Mystic BBS Help & Support"
+NET153                         (1:153/757) "Net 153 Chat"
+NET_DEV                        (1:153/757) "Fidonet Network Development"
+NFL                            (1:153/757) "National Football League
                                scores, discussion, etc."
+NHL                            (1:153/757) "The National Hockey League
                                Discussion"
+NIGHTWISH                      (1:153/757) "NIGHTWISH"
+NODELIST-POLICE                (1:153/757) "Discussion and reports about
                                nodelist matters"
+NZ_FIDONET                     (1:153/757) "New Zealand Chat"
 NZ_TEST                        (1:153/757) "Testing (New Zealand)"
+OFFLINE                        (1:153/757) "Offline Mail Readers/Doors"
+OS2                            (1:153/757) "International OS/2 Conference"
+OS2BBS                         (1:153/757) "OS/2 Based BBS Software"
+OS2DOS                         (1:153/757) "OS/2 DOS & Windows"
+OS2DOSBBS                      (1:153/757) "DOS Based BBS on OS/2"
+OS2PROG                        (1:153/757) "OS/2 Programming"
+OS2REXX                        (1:153/757) "OS/2 Rexx Programming"
+OTHERNETS                      (1:153/757) "OtherNets: Information on
                                Networks other than FidoNet"
+OZ_HUMOUR                      (1:153/757) "Australian Humour"
+PALESTINECHRONICAL             (1:153/757) "The Palestine Chronical"
+PASCAL                         (1:153/757) "Pascal Programming Help &
                                Discussion"
+PASCAL_LESSONS                 (1:153/757) "Pascal Programming Lessons"
+PCBOARD                        (1:153/757) "PCBoard Help & Support"
+PDNECHO                        (1:153/757) "Programmers Distribution FDN"
+PERL.CPAN                      (1:153/757) "Perl Module Updates"
+PERL                           (1:153/757) "Perl Programming Languange"
+POINTS                         (1:153/757) "Point Systems & Support"
+POLITICS                       (1:153/757) "Political Discussions"
+POL_INC                        (1:153/757) "Politically Incorrect"
+PRISM                          (1:153/757) "Prism BBS Chat"
+PROBOARD                       (1:153/757) "Proboard BBS Support"
+PKEY_DROP                      (1:153/757) "Public-Key Distribution Echo"
+PUBLIC_KEYS                    (1:153/757) "Public-Key Distribution"
+PYTHON                         (1:153/757) "Python Programming"
+QUIK_BAS                       (1:153/757) "Quick Basic Programming"
+RA_32BIT                       (1:153/757) "32Bit RemoteAccess Discussion
                                Echo"
+RA_MULTI                       (1:153/757) "RemoteAccess Multi-Node Sysops
                                Echo"
+RA_SUPPORT                     (1:153/757) "RemoteAccess Support Echo"
+RA_UTIL                        (1:153/757) "RemoteAccess Utilities Echo"
 RAR                            (1:153/757) "RAR Archiver Support"
+RBERRYPI                       (1:153/757) "Raspberry Pi"
+RECIPES                        (1:153/757) "Recipes!"
+RENEGADE_BBS                   (1:153/757) "Renegade BBS Help & Support"
+RETAIL_HORROR                  (1:153/757) "Retail Horror Stories"
+RGN17-ADMIN                    (1:153/757) "Region 17 Admin"
+RGN17                          (1:153/757) "Region 17 SysOps"
+RUSSIAN_TUTOR                  (1:153/757) "Learn Russian by example"
 SCANRADIO                      (1:153/757) "Scanning Radio Support Echo"
+SHAREWARE_SUPPORT              (1:153/757) "ShareWare Software Support
                                Conference"
+SPITFIRE                       (1:153/757) "Spitfire BBS Help & Support"
+STATS                          (1:153/757) "Echomail Stats & Info"
+SECURITY                       (1:153/757) "Vulerability of the Day"
+SURVIVOR                       (1:153/757) "Survivor: Coping with
                                Adversity"
+SYNC_PROGRAMMING               (1:153/757) "Synchronet Programming (C/C++
                                and CVS)"
+SYNC_SYSOPS                    (1:153/757) "Synchronet Multinode BBS
                                Software Support (Sysops Only)"
+SYNCDATA                       (1:153/757) "Synchronet Distributed
                                Databases"
+SYNCHRONET                     (1:153/757) "Synchronet Discussion"
+SYSOP                          (1:153/757) "The SYSOP Echo"
+TAGLINES                       (1:153/757) "Tagline Collectors Echo"
+FIDONET.TELEGRAM               (1:153/757) "Telegram Help & Support"
+TERMINAT                       (1:153/757) "Terminate, The Final Terminal,
                                Global support"
 TEST                           (1:153/757) "An echomail test area"
+THEMESH                        (1:153/757) "Off-grid meshing ie.
                                Meshtastic/Meshcore"
+TG_SUPPORT                     (1:153/757) "Telegard BBS Support"
+TREK                           (1:153/757) "Star Trek General Discussions"
+TUB                            (1:153/757) "Squish Echomail Processor"
+TUXPOWER                       (1:153/757) "Linux And Related Batch
                                Scripting"
+LINUX-UBUNTU                   (1:153/757) "Ubuntu Linux Discussion"
+UFO                            (1:153/757) "Unidentified Flying Objects"
 UK_FILE_DIST                   (1:153/757) "UK New File Distribution"
+UKRNEWS                        (1:153/757) "Ukrainian News (English)"
+UTF-8                          (1:153/757) "UTF-8 Discussion"
+VIASOFT_SUPPORT                (1:153/757) "ViaSoft software Support and
                                Discussion"
+VADV                           (1:153/757) "Virtual Advanced BBS Support
                                Echo"
+VATICAN                        (1:153/757) "Vatican Information Service"
+WEATHER                        (1:153/757) "National Weather Network"
+WEATHER.TROPIC                 (1:153/757) "International Tropical/High
                                Seas Weather Alerts"
+WHAT'S_HOT!                    (1:153/757) "What's Hot / Latest News"
+WIFI                           (1:153/757) "Wi-Fi Discussion"
+WILDCAT!_SUPPORT               (1:153/757) "WILDCAT! BBS Software Support"
+WIN95                          (1:153/757) "Windows95 Discussion"
+WINDOWS                        (1:153/757) "Microsoft Windows International
                                Echo"
 WINPOINT                       (1:153/757) "WinPoint Help & Support"
+WRITING                        (1:153/757) "The Writing Echo"
+WWIV                           (1:153/757) "WWIV BBS/Mailer discussion"
 WX_TALK                        (1:153/757) "Weather Discussion"
+X-FILES                        (1:153/757) "X-Files"
+XPOINT                         (1:153/757) "OpenXP Help & Support"
+XPOINT_INFO                    (1:153/757) "OpenXP Info"
+Z1_BACKBONE                    (1:153/757) "Zone 1 Echomail Backbone"
+Z1C                            (1:153/757) "Zone 1 Coordinator Contact"
+Z1DAILY                        (1:153/757) "Zone 1 Daily Nodelst process
                                receipts"
+Z2DAILY                        (1:153/757) "Zone 2 Daily Nodelst process
                                receipts"
+Z1_ECHOMAIL                    (1:153/757) "Z1 Echomail Distribution"
+Z1_ELECTION                    (1:153/757) "Z1 Election Discussion"
+Z1_ROUTING                     (1:153/757) "Z1 Routing Updates &
                                Discussion"
+Z1_SYSOP                       (1:153/757) "Z1 SysOp Echo"
+ZCC-PUBLIC                     (1:153/757) "ZCC Public Announcements"
+ZEC                            (1:153/757) "Z1 ZEC Discussion"

 List of all file areas available for node 1:153/150.0.
 + = Area already connected

 AFTNBINKD                      (1:153/757, 0kB) "Binkd - New IP Mailer"
 AFTNMISC                       (1:153/757, 0kB) "Multiplatform Fidonet
                                Software"
+BACKBONE                       (1:153/757, 0kB) "Backbone Echomail Tag
                                Lists"
 BBBSUTIL                       (1:153/757, 0kB) "BBBS 3rd Party Mods &
                                Utilities"
 BBBS-2                         (1:153/757, 0kB) "BBBS and BTerm for OS/2"
 BBBS-D                         (1:153/757, 0kB) "BBBS and BTerm for DOS"
 BBBS-FBI                       (1:153/757, 0kB) "BBBS and BTerm for
                                FreeBSD"
 BBBS-IRS                       (1:153/757, 0kB) "BBBS and BTerm for IRIX"
 BBBS-L                         (1:153/757, 0kB) "BBBS and BTerm for Linux"
 BBBS-NT                        (1:153/757, 0kB) "BBBS and BTerm for
                                Windows95/98/NT"
 BBBS-SOS                       (1:153/757, 0kB) "BBBS and BTerm for
                                Solaris"
 BBBS-SUM                       (1:153/757, 0kB) "BBBS and BTerm for
                                SunOS/M"
 BBBS-SUS                       (1:153/757, 0kB) "BBBS and BTerm for
                                SunOS/S"
 BBBS-ULD                       (1:153/757, 0kB) "BBBS and BTerm for Ultrix"
 BBBS-UW                        (1:153/757, 0kB) "BBBS and BTerm for
                                UnixWare"
+BBSLISTS                       (1:153/757, 0kB) "BBS Lists"
 CH-WARE                        (1:153/757, 0kB) "Cheepware Doors & Utils"
 COBOL-TOOLS                    (1:153/757, 0kB) "Cobol compiler updates
                                such as GnuCOBOL"
 COORDUTL                       (1:153/757, 0kB) "Coordinator Tools &
                                Utilities"
 DAILYLIST                      (1:153/757, 43kB) "Daily Fidonet Nodelist
                                (Z1 in ZIP format)"
 DBRIDGE                        (1:153/757, 0kB) "D'Bridge Software
                                Releases"
 DIFFLZH                        (1:153/757, 0kB) "Weekly Fidonet Nodediff
                                (Z1 in LZH format)"
 DIFFZIP                        (1:153/757, 0kB) "Weekly Fidonet Nodediff
                                (Z1 in ZIP format)"
 DOORS                          (1:153/757, 0kB) "BBS Doors"
 ECHOLIST                       (1:153/757, 0kB) "Echo Conference
                                Lists/Moderators/Rules"
+FGAZETTE                       (1:153/757, 0kB) "FIDOGAZETTE: Alternative
                                Fidonet Newsletter"
+FG_WORF                        (1:153/757, 0kB) "FileGate Info files,
                                FAQ's, FILEGATE.ZXX"
+FIDONEWS                       (1:153/757, 0kB) "Weekly FidoNews
                                Newsletter"
+FTSC                           (1:153/757, 0kB) "Current FTSC Documents"
 FTSC-OLD                       (1:153/757, 0kB) "Obsolete/Reference FTSC
                                Documents"
 FWDRIVRS                       (1:153/757, 0kB) "OS/2 Drivers, including
                                WINOS2"
 I-ARGUS                        (1:153/757, 0kB) "Weekly ARGUS.TXT auxiliary
                                TCP/IP nodelist"
 I-BINKD                        (1:153/757, 41kB) "Weekly BINKD.TXT file
                                includeble by BINKD"
+INFOPACK                       (1:153/757, 1802kB) "Othernet/League
                                Infopacks"
 MBSE_BBS                       (1:153/757, 0kB) "MBSE BBS for Unix"
+NASA                           (1:153/757, 2296kB) "Earth & Space Science
                                Material"
 NODEDIFF                       (1:153/757, 0kB) "Weekly Fidonet Nodediff
                                (Z1 in ARC format)"
+NODELIST                       (1:153/757, 0kB) "Weekly Fidonet Nodelist
                                (Z1 in ZIP format)"
 OPENXP                         (1:153/757, 0kB) "OpenXP FTN Point Software"
 OREDSON-SW                     (1:153/757, 0kB) "Software by Erik Oredson"
 PDNBASIC                       (1:153/757, 0kB) "Basic Related"
 RAR                            (1:153/757, 0kB) "RAR archiver
                                releases/public betas"
+UTILLNX                        (1:153/757, 0kB) "Linux Utilities"
 WEATHER                        (1:153/757, 1867kB) "Daily graphic forecasts
                                for N.America"
 VIA_SOFT                       (1:153/757, 0kB) "ViaSoft mailer/utils"
 WINPOINT                       (1:153/757, 0kB) "WinPoint Software
                                Releases/Updates"
 WINSUTIL                       (1:153/757, 0kB) "Wildcat 5+ Ultilities"
 WINSGAME                       (1:153/757, 0kB) "Wildcat 5+ Games written
                                in wcCode"
 WINSCODE                       (1:153/757, 0kB) "Wildcat 5+ wcCode
                                snippets"
 WINUTILS                       (1:153/757, 0kB) "Windows Utilities"
 Z1BONE                         (1:153/757, 0kB) "Weekly Echomail Lists"
 GFD.APP.ARC                    (1:153/757, 0kB) "Packer and Unpacker"
 GFD.APP.BACK                   (1:153/757, 0kB) "Backup Utilities"
 GFD.APP.DB                     (1:153/757, 0kB) "Database Programs and
                                Tools"
 GFD.APP.EDIT                   (1:153/757, 0kB) "Editors (text/binary)"
 GFD.APP.FILE                   (1:153/757, 0kB) "File and Disk Managers,
                                Tools"
 GFD.APP.GFX                    (1:153/757, 0kB) "Graphcal Applications"
 GFD.APP.GAME                   (1:153/757, 0kB) "Games for OS/2"
 GFD.APP.MISC                   (1:153/757, 0kB) "Misc Applications"
 GFD.APP.MMPM                   (1:153/757, 0kB) "Multi-Media Applications"
 GFD.DEV.MISC                   (1:153/757, 0kB) "Misc Development Files"
 GFD.DEV.REXX                   (1:153/757, 0kB) "REXX Samples, Libs, DLLs,
                                etc"
 GFD.DEV.TOOL                   (1:153/757, 0kB) "Compilers, interpreters,
                                tools, etc"
 GFD.DEV.XMPL                   (1:153/757, 0kB) "Samples with sources"
 GFD.FTN.MAIL                   (1:153/757, 0kB) "FTN Mailers and Tools"
 GFD.GNU.APPS                   (1:153/757, 0kB) "GNU Apps (TeX, Emacs,
                                Ghostscript, etc)"
 GFD.GNU.DEV                    (1:153/757, 0kB) "GNU Compiler (GCC, EMX,
                                Smalltalk, etc)"
 GFD.GNU.SRC                    (1:153/757, 0kB) "GNU sources (also with
                                EXE)"
 GFD.GNU.TOOL                   (1:153/757, 0kB) "GNU tools (without
                                sources)"
 GFD.NET.CONN                   (1:153/757, 0kB) "SLIP, PPP, UUCP, proxies
                                etc"
 GFD.NET.MISC                   (1:153/757, 0kB) "Misc Networking Files"
 GFD.NET.TCP                    (1:153/757, 0kB) "Network Drivers"
 GFD.NET.WWW                    (1:153/757, 0kB) "HTTP Servers, Browsers and
                                Accessories"
 GFD.SYS.DISK                   (1:153/757, 0kB) "Hard Disk & CDROM Drivers"
 GFD.SYS.DRV                    (1:153/757, 0kB) "Other Device Drivers"
 GFD.SYS.MISC                   (1:153/757, 0kB) "Other System Tools"
 GFD.SYS.TOOL                   (1:153/757, 0kB) "Tools for the System
                                Administrator"
 GFD.WPS.FONT                   (1:153/757, 0kB) "Fonts for the WPS"
 GFD.WPS.TOOL                   (1:153/757, 0kB) "Tools for the WPS"


--- BBBS/Li6 v4.10 Toy-7
BODY;

$bbbsAreas = $parser->parse($bbbsBody);

assertCondition(
    count($bbbsAreas) === 305,
    'Parses 305 unique areas from a BBBS/Li6 "+/space TAG (address) \"description\"" reply (Improvement 1 quoted-address grammar)',
    'Found count: ' . count($bbbsAreas) . '. Expected 305: of the 312 listed rows (233 echo + 79 file), 2 are skipped because their tags contain characters isValidTag() does not allow (WHAT\'S_HOT! and WILDCAT!_SUPPORT — apostrophe/exclamation), and 5 tags are reused across the echo-area and file-area sections with different descriptions (ECHOLIST, DBRIDGE, FIDONEWS, RAR, WINPOINT) and collapse to one entry each via deduplicateAreas(). 312 - 2 - 5 = 305.'
);

$bbbsTags = array_column($bbbsAreas, 'name');
assertCondition(
    in_array('10TH_AMD', $bbbsTags, true) && in_array('ZEC', $bbbsTags, true),
    'Includes the first echo-area tag (10TH_AMD) and the last echo-area tag (ZEC)'
);

assertCondition(
    in_array('AFTNBINKD', $bbbsTags, true) && in_array('GFD.WPS.TOOL', $bbbsTags, true),
    'Includes the first file-area tag (AFTNBINKD) and the last file-area tag (GFD.WPS.TOOL), confirming both banner sections in the same reply are parsed'
);

$echolist = current(array_filter($bbbsAreas, fn($a) => $a['name'] === 'ECHOLIST'));
assertCondition(
    $echolist && $echolist['description'] === 'EchoList Access Conference',
    'A tag reused across the echo-area and file-area sections (ECHOLIST) collapses to one entry rather than being duplicated',
    'Got: ' . var_export($echolist['description'] ?? null, true)
);

assertCondition(
    $parser->hasActionableContent($bbbsBody),
    'hasActionableContent() recognizes the BBBS/Li6 reply as actionable'
);

// --------------------------------------------------------------------------
// Sample 7: HPT %LIST flag-prefixed dotted-leader list with quoted
// descriptions, a wrapped multi-line description, a trailing flag-legend
// footer, and an echoed "Following is the original message text" section
// with the original %LIST request and a tearline/Origin line. Captured from
// a real hub reply (LovlyNet LVLY_* areas, all linked/subscribed via '*').
// --------------------------------------------------------------------------
echo "\n7. HPT %LIST flag-prefixed dotted-leader quoted list (LovlyNet LVLY_* areas):\n";

$hptQuotedBody = <<<BODY
Available areas for 227:1/400

*S   LVLY_ADULT ............... "Mature/18+ topics of discussion, humour, etc."
*S   LVLY_AI ...................... "Discussions about Artificial Intelligence"
*S   LVLY_ANNOUNCE .......................... "LovlyNet News and Announcements"
*S   LVLY_BINKTERMPHP .............................. "BinktermPHP Support Echo"
*S   LVLY_CHAT ............................................ "General Chit Chat"
*S   LVLY_COLDWARCOMMS ............................................... "Coldwar
                            Communications with an emphasis on AT&T Longlines"
*S   LVLY_CYBERCHAT ........................... "Cyber and digital discussions"
*S   LVLY_DEMOSCENE .......... "Demoscene discussions, media, news and events."
*S   LVLY_FILECHAT ........................ "File Area discussions on LovlyNet"
*S   LVLY_SYSOP ............................................. "Sysop only chat"
*S   LVLY_TEST ................................................. "Testing echo"

'*' = area is active
'R' = area is readonly for you
'W' = area is writeonly for you
'M' = area is mandatory for you
'S' = area is rescanable

 11 area(s) available, 11 area(s) linked

Following is the original message text
--------------------------------------
%LIST

--------------------------------------

--- hpt/lnx 1.9 2024-03-02 areafix
 * Origin: Areafix robot (227:1/1)
BODY;

$hptQuotedAreas = $parser->parse($hptQuotedBody);

assertCondition(
    count($hptQuotedAreas) === 11,
    'Parses all 11 LVLY_* areas from an HPT %LIST flag-prefixed dotted-leader quoted list',
    'Found count: ' . count($hptQuotedAreas)
);

$hptQuotedTags = array_column($hptQuotedAreas, 'name');
assertCondition(
    in_array('LVLY_ADULT', $hptQuotedTags, true) && in_array('LVLY_TEST', $hptQuotedTags, true),
    'Includes both the first (LVLY_ADULT) and last (LVLY_TEST) listed areas'
);

$coldwar = current(array_filter($hptQuotedAreas, fn($a) => $a['name'] === 'LVLY_COLDWARCOMMS'));
assertCondition(
    $coldwar && $coldwar['description'] === 'Coldwar Communications with an emphasis on AT&T Longlines',
    'A description wrapped onto a continuation line is joined correctly (LVLY_COLDWARCOMMS)',
    'Got: ' . var_export($coldwar['description'] ?? null, true)
);

$adult = current(array_filter($hptQuotedAreas, fn($a) => $a['name'] === 'LVLY_ADULT'));
assertCondition(
    $adult && $adult['action'] === AreaFixParser::ACTION_SUBSCRIBE && $adult['is_subscribed'] === true,
    'A row with the \'*\' flag is classified as ACTION_SUBSCRIBE / is_subscribed=true (LVLY_ADULT)'
);

assertCondition(
    !in_array('LIST', $hptQuotedTags, true),
    'Does not misparse the echoed original "%LIST" request text as an area'
);

assertCondition(
    $parser->hasActionableContent($hptQuotedBody),
    'hasActionableContent() recognizes the HPT %LIST quoted reply as actionable'
);

echo "\n-------------------------------------------------------\n";
echo "Results: {$passed} passed, {$failed} failed.\n";
echo "=======================================================\n";

exit($failed > 0 ? 1 : 0);
