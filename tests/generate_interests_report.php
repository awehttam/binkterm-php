<?php
/**
 * Generate an interests report from echo areas.
 *
 * Reads all echo areas from the database, strips common network prefixes/suffixes
 * from their tags, groups them into named interest categories, and prints a report.
 *
 * Usage:
 *   php tests/generate_interests_report.php [--mode=keyword|ai|compare]
 *
 *   --mode=keyword   Keyword heuristics only (default, no API calls)
 *   --mode=ai        Anthropic API only (requires ANTHROPIC_API_KEY in .env)
 *   --mode=compare   Run both and show a side-by-side comparison table
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Config;
use BinktermPHP\Database;

Database::getInstance();
$db = Database::getInstance()->getPdo();

// ---------------------------------------------------------------------------
// Parse --mode flag
// ---------------------------------------------------------------------------

$mode = 'keyword';
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--mode=(keyword|ai|compare)$/', $arg, $m)) {
        $mode = $m[1];
    }
}

// ---------------------------------------------------------------------------
// 1. Fetch all echo areas
// ---------------------------------------------------------------------------

$stmt = $db->query("SELECT id, tag, description FROM echoareas ORDER BY tag");
$echoareas = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($echoareas)) {
    echo "No echo areas found in database.\n";
    exit(0);
}

echo "Found " . count($echoareas) . " echo areas.  Mode: {$mode}\n\n";

// ---------------------------------------------------------------------------
// 2. Strip known network prefixes and suffixes from tags
// ---------------------------------------------------------------------------

$prefixes = [
    'LVLY_',     // LovlyNet
    'MIN_',      // Micronet / Miniature Net
    'DOVE_',     // Dovenet
    'FSX_',      // fsxNet
    'AGL_',      // Agoranet
    'AGN_',      // Agoranet alternate
    'HACK_',     // Hackernet
    'HBN_',      // Hobbynet
    'CFN_',      // CoffeeNet
    'TQN_',      // TQNet
    'WIN_',      // Winlink?
    'SFN_',      // SciNet / Sci-Fi Net
    'VAX_',      // VAXnet
    'SCI_',
    'FRL_',      // FidoNet Research Lab
    'FTN_',      // Generic FTN
    'NET_',
    'FIDONET_',
    'FIDO_',
];

$suffixes = [
    '_ECHO',
    '_AREA',
    '_NET',
    '_BASE',
    '_CHAT',
    '_FORUM',
];

function cleanTag(string $tag, array $prefixes, array $suffixes): string
{
    $clean = strtoupper($tag);

    foreach ($prefixes as $prefix) {
        if (str_starts_with($clean, strtoupper($prefix))) {
            $clean = substr($clean, strlen($prefix));
            break;
        }
    }

    foreach ($suffixes as $suffix) {
        if (str_ends_with($clean, strtoupper($suffix))) {
            $clean = substr($clean, 0, -strlen($suffix));
            break;
        }
    }

    return $clean;
}

// ---------------------------------------------------------------------------
// 3. Interest category definitions
// ---------------------------------------------------------------------------

$interestCategories = [
    [
        'name' => 'Retro Computing & Vintage Hardware',
        'keywords' => [
            'RETRO', 'VINTAGE', 'CLASSIC', 'C64', 'COMMODORE', 'AMIGA', 'ATARI',
            'APPLE2', 'APPLE_2', 'TRS', 'CP/M', 'CPM', 'ZX', 'SPECTRUM',
            'MSX', 'TANDY', 'KAYPRO', 'OSBORNE', 'ALTAIR', 'S100',
            'OLDCOMP', 'OLD_COMP', 'HISTORIC', 'MUSEUM',
        ],
    ],
    [
        'name' => 'BBS & Fidonet',
        'keywords' => [
            'BBS', 'FIDONET', 'FIDO', 'SYSOP', 'DOOR', 'ANSI', 'ASCII',
            'NODELIST', 'ECHOMAIL', 'NETMAIL', 'BINKP', 'FTN', 'MAILER',
            'FOSSIL', 'TICFILE', 'FILEECHO', 'POINTLIST',
        ],
    ],
    [
        'name' => 'Programming & Software Development',
        'keywords' => [
            'PROG', 'CODE', 'CODING', 'DEVEL', 'DEV', 'PYTHON', 'JAVA',
            'CPLUS', 'CPLUSPLUS', 'CSHARP', 'DOTNET', 'PERL', 'RUBY',
            'JAVASCRIPT', 'TYPESCRIPT', 'GOLANG', 'RUST', 'SWIFT',
            'PASCAL', 'BASIC', 'ASSEMBL', 'ASM', 'FORTRAN', 'COBOL',
            'PHP', 'HTML', 'CSS', 'SQL', 'DATABASE', 'OPENSOURCE',
            'GITHUB', 'GIT', 'LINUX_DEV', 'KERNEL', 'COMPILER',
            'ALGORITHM', 'DATASTRUC', 'SOFTWARE',
        ],
    ],
    [
        'name' => 'Linux & Open Source',
        'keywords' => [
            'LINUX', 'UNIX', 'GNU', 'UBUNTU', 'DEBIAN', 'FEDORA', 'CENTOS',
            'ARCH', 'GENTOO', 'SLACKWARE', 'FREEBSD', 'OPENBSD', 'BSD',
            'OPENSRC', 'OPENSOURCE', 'FOSS', 'KERNEL', 'BASH', 'SHELL',
            'SYSADMIN', 'SYS_ADMIN',
        ],
    ],
    [
        'name' => 'Windows & Microsoft',
        'keywords' => [
            'WINDOWS', 'WINNT', 'WIN95', 'WIN98', 'WINXP', 'WIN10', 'WIN11',
            'MICROSOFT', 'MSDOS', 'DOS', 'POWERSHELL', 'DOTNET', 'AZURE',
        ],
    ],
    [
        'name' => 'Gaming & Video Games',
        'keywords' => [
            'GAME', 'GAMING', 'GAMER', 'VIDEO', 'CONSOLE', 'ARCADE',
            'NINTENDO', 'SEGA', 'ATARI_GAME', 'PLAYSTATION', 'XBOX',
            'STEAM', 'PC_GAME', 'PCGAME', 'RPG', 'FPS', 'MMORPG',
            'EMULAT', 'ROMS',
        ],
    ],
    [
        'name' => 'Science Fiction & Fantasy',
        'keywords' => [
            'SCIFI', 'SCI_FI', 'SCIFIX', 'FANTASY', 'STARTREK', 'STAR_TREK',
            'STARWARS', 'STAR_WARS', 'DRWHO', 'DR_WHO', 'DOCTORWHO',
            'TOLKIEN', 'DUNE', 'BABYLON', 'BATTLESTAR', 'ANIME',
            'MANGA', 'COMICS', 'COMIC', 'MARVEL', 'DC_COMICS',
        ],
    ],
    [
        'name' => 'Music',
        'keywords' => [
            'MUSIC', 'ROCK', 'METAL', 'JAZZ', 'BLUES', 'COUNTRY',
            'CLASSICAL', 'HIP_HOP', 'HIPHOP', 'RAP', 'PUNK', 'FOLK',
            'ELECTRONIC', 'TECHNO', 'MIDI', 'AUDIO', 'CHIPTUNE',
            'GUITAR', 'PIANO', 'DRUMS', 'BASS_MUSIC',
        ],
    ],
    [
        'name' => 'Ham Radio & Electronics',
        'keywords' => [
            'HAM', 'HAMRADIO', 'RADIO', 'AMATEUR', 'QRP', 'QSL',
            'ELECTRON', 'CIRCUIT', 'ARDUINO', 'RASPBERRY', 'RASPI',
            'MICROCONTROL', 'HARDWARE', 'SOLDERING', 'PCB',
            'RF', 'SDR', 'CW', 'MORSE',
        ],
    ],
    [
        'name' => 'Networking & Security',
        'keywords' => [
            'NETWORK', 'SECURITY', 'HACK', 'HACKER', 'INFOSEC',
            'PENTEST', 'CTF', 'CRYPTO', 'ENCRYPT', 'FIREWALL',
            'TCP', 'IP', 'PROTOCOL', 'WIFI', 'WIRELESS',
            'PRIVACY', 'VPN', 'TOR',
        ],
    ],
    [
        'name' => 'Politics & Current Events',
        'keywords' => [
            'POLITIC', 'POLITICS', 'NEWS', 'CURRENT', 'WORLD',
            'GOVERN', 'GOVERNMENT', 'LAW', 'LEGAL', 'LIBERTARIAN',
            'CONSERV', 'LIBERAL', 'DEMOCRAT', 'REPUBLICAN',
            'ELECTION', 'DEBATE', 'OPINION', 'EDITORIAL',
        ],
    ],
    [
        'name' => 'Religion & Philosophy',
        'keywords' => [
            'RELIGION', 'RELIGIOUS', 'CHRISTIAN', 'CATHOLIC', 'PROTESTANT',
            'BIBLE', 'ISLAM', 'MUSLIM', 'JEWISH', 'JUDAISM', 'BUDDHISM',
            'PHILOSOPHY', 'ETHICS', 'SPIRITUAL', 'OCCULT', 'PAGAN',
            'ATHEIST', 'ATHEISM', 'AGNOSTIC',
        ],
    ],
    [
        'name' => 'Food & Cooking',
        'keywords' => [
            'FOOD', 'COOK', 'COOKING', 'RECIPE', 'CUISINE', 'BAKING',
            'CHEF', 'KITCHEN', 'VEGETARIAN', 'VEGAN', 'BEER', 'WINE',
            'HOMEBREWING', 'HOMEBREW',
        ],
    ],
    [
        'name' => 'Sports & Fitness',
        'keywords' => [
            'SPORT', 'SPORTS', 'FOOTBALL', 'SOCCER', 'BASEBALL', 'BASKETBALL',
            'HOCKEY', 'TENNIS', 'GOLF', 'CYCLING', 'RUNNING', 'FITNESS',
            'GYM', 'WORKOUT', 'MARTIAL', 'RACING', 'MOTORSPORT',
        ],
    ],
    [
        'name' => 'Humour & Entertainment',
        'keywords' => [
            'HUMOR', 'HUMOUR', 'FUNNY', 'JOKE', 'COMEDY', 'LAUGH',
            'ENTERTAIN', 'TRIVIA', 'RIDDLE', 'PRANK',
        ],
    ],
    [
        'name' => 'Books & Literature',
        'keywords' => [
            'BOOK', 'BOOKS', 'NOVEL', 'FICTION', 'NONFIC', 'NONFICTION',
            'AUTHOR', 'WRITING', 'POETRY', 'POEM', 'READING',
            'LIBRARY', 'EBOOK',
        ],
    ],
    [
        'name' => 'Art & Creative',
        'keywords' => [
            'ART', 'ARTIST', 'CREATIVE', 'DESIGN', 'GRAPHIC', 'PHOTO',
            'PHOTOGRAPHY', 'PAINT', 'DRAWING', 'ILLUSTRATION', 'PIXEL',
            'TEXTART', 'ASCII_ART',
        ],
    ],
    [
        'name' => 'Health & Medicine',
        'keywords' => [
            'HEALTH', 'MEDICAL', 'MEDICINE', 'DOCTOR', 'NURSE',
            'MENTAL', 'WELLNESS', 'DIET', 'NUTRITION',
            'DISABILITY', 'COVID', 'VIRUS',
        ],
    ],
    [
        'name' => 'Weather & Environment',
        'keywords' => [
            'WEATHER', 'CLIMATE', 'FORECAST', 'STORM', 'HURRICANE',
            'TORNADO', 'METEOR', 'ENVIRON', 'ECOLOGY', 'GREEN',
            'SOLAR', 'WIND_ENERGY',
        ],
    ],
    [
        'name' => 'Astrology & Horoscopes',
        'keywords' => [
            'HOROSCOPE', 'ASTROLOGY', 'ZODIAC', 'TAROT', 'PSYCHIC',
            'DIVINATION',
        ],
    ],
    [
        'name' => 'History & Cold War',
        'keywords' => [
            'HISTORY', 'HISTORIC', 'COLDWAR', 'COLD_WAR', 'MILITARY',
            'WAR', 'WWII', 'WW2', 'WW1', 'NUCLEAR', 'CIVIL_WAR',
            'ANCIENT', 'MEDIEVAL', 'LONGLINES', 'BUNKER',
        ],
    ],
    [
        'name' => 'Synchronet & Other BBS Software',
        'keywords' => [
            'SYNCHRONET', 'SYNCDATA', 'SBBS', 'ENIGMA', 'MYSTIC',
            'MAXIMUS', 'TELEGARD', 'RENEGADE', 'WILDCAT', 'PCBOARD',
            'WWIV', 'TRIBBS',
        ],
    ],
    [
        'name' => 'General Chat & Social',
        'keywords' => [
            'CHAT', 'GENERAL', 'TALK', 'SOCIAL', 'DISCUSS',
            'LOUNGE', 'OFFTOPIC', 'OFF_TOPIC', 'RANDOM', 'MISC',
            'INTRO', 'INTRODUCE', 'HELLO', 'HI',
        ],
    ],
    [
        'name' => 'Test & Development Areas',
        'keywords' => [
            'TEST', 'TESTING', 'SANDBOX', 'DEV', 'DEBUG', 'JUNK',
            'TRASH', 'DUMMY', 'SAMPLE',
        ],
    ],
];

$categoryNames = array_column($interestCategories, 'name');

// ---------------------------------------------------------------------------
// 4. Classification functions
// ---------------------------------------------------------------------------

/**
 * Classify all echo areas using keyword heuristics.
 * Returns array<tag, interest_name|null>.
 */
function classifyByKeyword(array $echoareas, array $interestCategories, array $prefixes, array $suffixes): array
{
    $results = [];
    foreach ($echoareas as $area) {
        $cleanedTag = cleanTag($area['tag'], $prefixes, $suffixes);
        $searchText = strtoupper($cleanedTag . ' ' . ($area['description'] ?? ''));

        $results[$area['tag']] = null;
        foreach ($interestCategories as $cat) {
            foreach ($cat['keywords'] as $kw) {
                if (str_contains($searchText, strtoupper($kw))) {
                    $results[$area['tag']] = $cat['name'];
                    break 2;
                }
            }
        }
    }
    return $results;
}

/**
 * Call the Anthropic Messages API with a single user message.
 * Returns the text content of the first response block, or null on failure.
 */
function callAnthropicApi(string $apiKey, string $userMessage): ?string
{
    $payload = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 2048,
        'messages'   => [['role' => 'user', 'content' => $userMessage]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        echo "[AI] API request failed (HTTP {$httpCode}): {$response}\n";
        return null;
    }

    $data = json_decode($response, true);
    return $data['content'][0]['text'] ?? null;
}

/**
 * Classify ALL echo areas via the Anthropic API in one batch.
 * Returns array<tag, interest_name|null>.
 */
function classifyByAi(string $apiKey, array $echoareas, array $categoryNames): array
{
    $categoryList = implode("\n", array_map(fn($n) => "  - {$n}", $categoryNames));
    $areaList = implode("\n", array_map(
        fn($a) => '  ' . $a['tag'] . (!empty($a['description']) ? ': ' . $a['description'] : ''),
        $echoareas
    ));

    $prompt = <<<PROMPT
You are classifying FTN/Fidonet BBS echo areas (message boards) into interest categories.

Prefer the existing categories listed below. Only use a new category name if none fits.

Existing categories:
{$categoryList}

Echo areas to classify (tag: description):
{$areaList}

Respond with ONLY a JSON object mapping each tag exactly as given to the best category name.
Example: {"AREA_TAG": "Category Name", "OTHER_TAG": "Another Category"}
PROMPT;

    echo "[AI] Classifying " . count($echoareas) . " area(s) via Anthropic API...\n";

    $text = callAnthropicApi($apiKey, $prompt);
    if ($text === null) {
        return array_fill_keys(array_column($echoareas, 'tag'), null);
    }

    if (preg_match('/\{[\s\S]*\}/u', $text, $m)) {
        $decoded = json_decode($m[0], true);
        if (is_array($decoded)) {
            // Ensure every area has an entry, defaulting to null
            $results = [];
            foreach ($echoareas as $area) {
                $results[$area['tag']] = $decoded[$area['tag']] ?? null;
            }
            return $results;
        }
    }

    echo "[AI] Could not parse JSON from API response.\n";
    return array_fill_keys(array_column($echoareas, 'tag'), null);
}

// ---------------------------------------------------------------------------
// 5. Run classification based on mode
// ---------------------------------------------------------------------------

$keywordResults = [];  // tag => interest|null
$aiResults      = [];  // tag => interest|null

switch ($mode) {
    case 'keyword':
        $keywordResults = classifyByKeyword($echoareas, $interestCategories, $prefixes, $suffixes);
        break;

    case 'ai':
        $apiKey = Config::env('ANTHROPIC_API_KEY', '');
        if (empty($apiKey)) {
            echo "Error: ANTHROPIC_API_KEY not set in .env\n";
            exit(1);
        }
        $aiResults = classifyByAi($apiKey, $echoareas, $categoryNames);
        echo "\n";
        break;

    case 'compare':
        $keywordResults = classifyByKeyword($echoareas, $interestCategories, $prefixes, $suffixes);
        $apiKey = Config::env('ANTHROPIC_API_KEY', '');
        if (empty($apiKey)) {
            echo "Error: ANTHROPIC_API_KEY not set in .env\n";
            exit(1);
        }
        $aiResults = classifyByAi($apiKey, $echoareas, $categoryNames);
        echo "\n";
        break;
}

// ---------------------------------------------------------------------------
// 6. Output
// ---------------------------------------------------------------------------

/**
 * Build a grouped map from a tag=>interest results array.
 * Returns array<interest_name, area[]>  (preserving category order).
 */
function buildGroups(array $results, array $echoareas, array $categoryNames): array
{
    $grouped = array_fill_keys($categoryNames, []);

    // Map tags to area rows for quick lookup
    $areaByTag = [];
    foreach ($echoareas as $area) {
        $areaByTag[$area['tag']] = $area;
    }

    foreach ($results as $tag => $interest) {
        $area = $areaByTag[$tag] ?? ['tag' => $tag, 'description' => ''];
        if ($interest !== null) {
            if (!isset($grouped[$interest])) {
                $grouped[$interest] = [];  // AI-suggested new category
            }
            $grouped[$interest][] = $area;
        }
    }
    return $grouped;
}

function printGrouped(array $grouped, array $results): void
{
    $uncategorised = [];
    foreach ($results as $tag => $interest) {
        if ($interest === null) {
            $uncategorised[] = $tag;
        }
    }

    $totalAreas = count($results);
    $categorised = $totalAreas - count($uncategorised);
    $groupCount = count(array_filter($grouped, fn($g) => !empty($g)));

    echo "=============================================================\n";
    echo sprintf("  %d of %d echo areas categorised into %d interest groups\n",
        $categorised, $totalAreas, $groupCount);
    echo "=============================================================\n\n";

    foreach ($grouped as $interestName => $areas) {
        if (empty($areas)) {
            continue;
        }
        $n = count($areas);
        echo "--- {$interestName} ({$n} area" . ($n === 1 ? '' : 's') . ") ---\n";
        foreach ($areas as $area) {
            $desc = trim($area['description'] ?? '');
            echo $desc !== ''
                ? sprintf("  %-30s  %s\n", $area['tag'], $desc)
                : sprintf("  %s\n", $area['tag']);
        }
        echo "\n";
    }

    if (!empty($uncategorised)) {
        $n = count($uncategorised);
        echo "--- Uncategorised ({$n} area" . ($n === 1 ? '' : 's') . ") ---\n";
        foreach ($uncategorised as $tag) {
            echo "  {$tag}\n";
        }
        echo "\n";
    }

    echo "=============================================================\n";
    echo "  END OF REPORT\n";
    echo "=============================================================\n";
}

function printComparison(array $keywordResults, array $aiResults, array $echoareas): void
{
    $areaByTag = [];
    foreach ($echoareas as $area) {
        $areaByTag[$area['tag']] = $area;
    }

    $agree    = 0;
    $disagree = 0;
    $diffRows = [];

    foreach ($keywordResults as $tag => $kwInterest) {
        $aiInterest = $aiResults[$tag] ?? null;
        $kwLabel    = $kwInterest  ?? '(none)';
        $aiLabel    = $aiInterest  ?? '(none)';

        if ($kwLabel === $aiLabel) {
            $agree++;
        } else {
            $disagree++;
            $desc = trim($areaByTag[$tag]['description'] ?? '');
            $diffRows[] = ['tag' => $tag, 'desc' => $desc, 'keyword' => $kwLabel, 'ai' => $aiLabel];
        }
    }

    $total = count($keywordResults);
    echo "=============================================================\n";
    echo "  COMPARISON REPORT  (keyword vs AI)\n";
    echo "=============================================================\n";
    echo sprintf("  Total areas : %d\n", $total);
    echo sprintf("  Agreement   : %d (%.0f%%)\n", $agree, $total > 0 ? ($agree / $total * 100) : 0);
    echo sprintf("  Disagreement: %d (%.0f%%)\n", $disagree, $total > 0 ? ($disagree / $total * 100) : 0);
    echo "=============================================================\n\n";

    if (empty($diffRows)) {
        echo "Both methods agree on every area.\n\n";
    } else {
        echo "--- Areas where keyword and AI disagree ---\n\n";
        foreach ($diffRows as $row) {
            echo sprintf("  %-30s  %s\n", $row['tag'], $row['desc']);
            echo sprintf("    Keyword : %s\n", $row['keyword']);
            echo sprintf("    AI      : %s\n", $row['ai']);
            echo "\n";
        }
    }

    // Also print the full AI-based grouping for reference
    echo "=============================================================\n";
    echo "  FULL AI CLASSIFICATION\n";
    echo "=============================================================\n\n";

    $aiGrouped = buildGroups($aiResults, $echoareas, array_keys(buildGroups($aiResults, $echoareas, [])));
    // Rebuild with proper category ordering
    $allNames = array_unique(array_merge(
        array_filter(array_values($aiResults), fn($v) => $v !== null)
    ));
    $aiGroupedOrdered = [];
    foreach ($allNames as $name) {
        $aiGroupedOrdered[$name] = [];
    }
    foreach ($aiResults as $tag => $interest) {
        if ($interest !== null) {
            $aiGroupedOrdered[$interest][] = $areaByTag[$tag] ?? ['tag' => $tag, 'description' => ''];
        }
    }
    ksort($aiGroupedOrdered);

    foreach ($aiGroupedOrdered as $interestName => $areas) {
        $n = count($areas);
        echo "--- {$interestName} ({$n} area" . ($n === 1 ? '' : 's') . ") ---\n";
        foreach ($areas as $area) {
            $desc = trim($area['description'] ?? '');
            echo $desc !== ''
                ? sprintf("  %-30s  %s\n", $area['tag'], $desc)
                : sprintf("  %s\n", $area['tag']);
        }
        echo "\n";
    }

    // Uncategorised by AI
    $aiUncategorised = array_keys(array_filter($aiResults, fn($v) => $v === null));
    if (!empty($aiUncategorised)) {
        $n = count($aiUncategorised);
        echo "--- Uncategorised by AI ({$n}) ---\n";
        foreach ($aiUncategorised as $tag) {
            echo "  {$tag}\n";
        }
        echo "\n";
    }

    echo "=============================================================\n";
    echo "  END OF REPORT\n";
    echo "=============================================================\n";
}

// ---------------------------------------------------------------------------
// 7. Render
// ---------------------------------------------------------------------------

echo "=============================================================\n";
echo "  INTERESTS REPORT\n";
echo "=============================================================\n";

switch ($mode) {
    case 'keyword':
        $grouped = buildGroups($keywordResults, $echoareas, $categoryNames);
        printGrouped($grouped, $keywordResults);
        break;

    case 'ai':
        $grouped = buildGroups($aiResults, $echoareas, $categoryNames);
        printGrouped($grouped, $aiResults);
        break;

    case 'compare':
        printComparison($keywordResults, $aiResults, $echoareas);
        break;
}
