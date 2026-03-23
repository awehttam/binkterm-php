<?php

/*
 * Copyright Matthew Asham and BinktermPHP Contributors
 *
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the
 * following conditions are met:
 *
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 */

namespace BinktermPHP;

/**
 * Classifies echo areas into interest categories using keyword heuristics
 * and optionally the Anthropic API.
 *
 * Derived from tests/generate_interests_report.php — keep prefix/suffix lists
 * and keyword table in sync with that script.
 */
class InterestGenerator
{
    private \PDO $db;

    /** Network prefixes to strip from echo area tags before keyword matching. */
    private const PREFIXES = [
        'LVLY_', 'MIN_', 'DOVE-', 'DOVE_', 'FSX_', 'AGL_', 'AGN_',
        'HACK_', 'HNET_', 'HBN_', 'CFN_', 'TQN_', 'WIN_', 'SFN_',
        'VAX_', 'SCI_', 'FRL_', 'FTN_', 'NET_', 'FIDONET_', 'FIDO_',
        'NIX_', 'RTN_',
    ];

    /** Common tag suffixes to strip. */
    private const SUFFIXES = [
        '_ECHO', '_AREA', '_NET', '_BASE', '_CHAT', '_FORUM',
    ];

    /**
     * Category definitions: name, keywords, icon (FontAwesome), color.
     *
     * @var array<int,array{name:string,keywords:string[],icon:string,color:string}>
     */
    private const CATEGORIES = [
        ['name' => 'Retro Computing & Vintage Hardware', 'icon' => 'fa-desktop', 'color' => '#8e44ad',
         'keywords' => ['RETRO','VINTAGE','CLASSIC','C64','COMMODORE','AMIGA','ATARI','APPLE2','APPLE_2','APPLE',
                        'TRS80','TRS-80','CP/M','CPM','ZX','SPECTRUM','MSX','TANDY','KAYPRO','OSBORNE','ALTAIR',
                        'S100','OLDCOMP','OLD_COMP','MUSEUM','MAINFRAME','COL_ADAM','TI99','TI-99','RTN_TI']],
        ['name' => 'BBS & Fidonet', 'icon' => 'fa-terminal', 'color' => '#2c3e50',
         'keywords' => ['BBS','FIDONET','FIDO','SYSOP','DOOR','ANSI','ASCII','NODELIST','ECHOMAIL','NETMAIL',
                        'BINKP','FTN','MAILER','FOSSIL','TICFILE','FILEECHO','POINTLIST',
                        'ECHO_ADS','FILEFIND','IBBSDOOR','DOORGAMES','ALLFIX','PDNECHO','GOLDBASE','GOLDED',
                        'FIDOTEST','FIDONEWS','FTSC','FUTURE4FIDO']],
        ['name' => 'Programming & Software Development', 'icon' => 'fa-code', 'color' => '#3498db',
         'keywords' => ['PROG','CODE','CODING','DEVEL','DEV','PYTHON','JAVA','CPLUS','CPLUSPLUS','CSHARP',
                        'DOTNET','PERL','RUBY','JAVASCRIPT','TYPESCRIPT','GOLANG','RUST','SWIFT','PASCAL',
                        'BASIC','ASSEMBL','ASM','FORTRAN','COBOL','PHP','HTML','CSS','SQL','DATABASE',
                        'OPENSOURCE','GITHUB','GIT','LINUX_DEV','KERNEL','COMPILER','ALGORITHM',
                        'DATASTRUC','SOFTWARE']],
        ['name' => 'Linux & Open Source', 'icon' => 'fa-linux', 'color' => '#e67e22',
         'keywords' => ['LINUX','UNIX','GNU','UBUNTU','DEBIAN','FEDORA','CENTOS','ARCH','GENTOO','SLACKWARE',
                        'FREEBSD','OPENBSD','BSD','OPENSRC','OPENSOURCE','FOSS','KERNEL','BASH','SHELL',
                        'SYSADMIN','SYS_ADMIN']],
        ['name' => 'Windows & Microsoft', 'icon' => 'fa-windows', 'color' => '#0078d4',
         'keywords' => ['WINDOWS','WINNT','WIN95','WIN98','WINXP','WIN10','WIN11','MICROSOFT','MSDOS','DOS',
                        'POWERSHELL','DOTNET','AZURE']],
        ['name' => 'Gaming & Video Games', 'icon' => 'fa-gamepad', 'color' => '#e74c3c',
         'keywords' => ['GAME','GAMING','GAMER','VIDEO','CONSOLE','ARCADE','NINTENDO','SEGA','ATARI_GAME',
                        'PLAYSTATION','XBOX','STEAM','PC_GAME','PCGAME','RPG','FPS','MMORPG','EMULAT','ROMS']],
        ['name' => 'Science Fiction & Fantasy', 'icon' => 'fa-rocket', 'color' => '#9b59b6',
         'keywords' => ['SCIFI','SCI_FI','SCIFIX','FANTASY','STARTREK','STAR_TREK','STARWARS','STAR_WARS',
                        'DRWHO','DR_WHO','DOCTORWHO','TOLKIEN','DUNE','BABYLON','BATTLESTAR','ANIME',
                        'MANGA','COMICS','COMIC','MARVEL','DC_COMICS']],
        ['name' => 'Music', 'icon' => 'fa-music', 'color' => '#e91e63',
         'keywords' => ['MUSIC','ROCK','METAL','JAZZ','BLUES','COUNTRY','CLASSICAL','HIP_HOP','HIPHOP',
                        'RAP','PUNK','FOLK','ELECTRONIC','TECHNO','MIDI','AUDIO','CHIPTUNE','GUITAR',
                        'PIANO','DRUMS','BASS_MUSIC']],
        ['name' => 'Ham Radio & Electronics', 'icon' => 'fa-broadcast-tower', 'color' => '#27ae60',
         'keywords' => ['HAM','HAMRADIO','RADIO','AMATEUR','QRP','QSL','ELECTRON','CIRCUIT','ARDUINO',
                        'RASPBERRY','RASPI','MICROCONTROL','HARDWARE','SOLDERING','PCB','SDR','MORSE']],
        ['name' => 'Networking & Security', 'icon' => 'fa-shield-alt', 'color' => '#c0392b',
         'keywords' => ['NETWORK','SECURITY','HACK','HACKER','INFOSEC','PENTEST','CTF','CRYPTO','ENCRYPT',
                        'FIREWALL','TCP/IP','PROTOCOL','WIFI','WIRELESS','PRIVACY','VPN','TOR','INTERNET',
                        'MOBILE']],
        ['name' => 'Politics & Current Events', 'icon' => 'fa-landmark', 'color' => '#607d8b',
         'keywords' => ['POLITIC','POLITICS','NEWS','CURRENT','WORLD','GOVERN','GOVERNMENT','LAW','LEGAL',
                        'LIBERTARIAN','CONSERV','LIBERAL','DEMOCRAT','REPUBLICAN','ELECTION','DEBATE',
                        'OPINION','EDITORIAL','GUN','CONSPRCY','CONSPIR']],
        ['name' => 'Religion & Philosophy', 'icon' => 'fa-place-of-worship', 'color' => '#795548',
         'keywords' => ['RELIGION','RELIGIOUS','CHRISTIAN','CATHOLIC','PROTESTANT','BIBLE','ISLAM','MUSLIM',
                        'JEWISH','JUDAISM','BUDDHISM','PHILOSOPHY','ETHICS','SPIRITUAL','OCCULT','PAGAN',
                        'ATHEIST','ATHEISM','AGNOSTIC']],
        ['name' => 'Food & Cooking', 'icon' => 'fa-utensils', 'color' => '#f39c12',
         'keywords' => ['FOOD','COOK','COOKING','RECIPE','CUISINE','BAKING','CHEF','KITCHEN','VEGETARIAN',
                        'VEGAN','BEER','WINE','HOMEBREWING','HOMEBREW']],
        ['name' => 'Sports, Fitness & Outdoors', 'icon' => 'fa-running', 'color' => '#16a085',
         'keywords' => ['SPORT','SPORTS','FOOTBALL','SOCCER','BASEBALL','BASKETBALL','HOCKEY','TENNIS',
                        'GOLF','CYCLING','RUNNING','FITNESS','GYM','WORKOUT','MARTIAL','RACING',
                        'MOTORSPORT','CAMP','CAMPING','HIKE','HIKING','OUTDOOR','HUNTING','FISHING',
                        'DIVING','SCUBA','CLIMBING','PICKLEBALL']],
        ['name' => 'Humour & Entertainment', 'icon' => 'fa-laugh', 'color' => '#f1c40f',
         'keywords' => ['HUMOR','HUMOUR','FUNNY','JOKE','COMEDY','LAUGH','ENTERTAIN','TRIVIA','RIDDLE',
                        'PRANK']],
        ['name' => 'Books & Literature', 'icon' => 'fa-book', 'color' => '#8d6e63',
         'keywords' => ['BOOK','BOOKS','NOVEL','FICTION','NONFIC','NONFICTION','AUTHOR','WRITING','POETRY',
                        'POEM','READING','LIBRARY','EBOOK']],
        ['name' => 'Art & Creative', 'icon' => 'fa-palette', 'color' => '#d35400',
         'keywords' => ['ART','ARTIST','CREATIVE','DESIGN','GRAPHIC','PHOTO','PHOTOGRAPHY','PAINT',
                        'DRAWING','ILLUSTRATION','PIXEL','TEXTART','ASCII_ART']],
        ['name' => 'Health & Medicine', 'icon' => 'fa-heartbeat', 'color' => '#e74c3c',
         'keywords' => ['HEALTH','MEDICAL','MEDICINE','DOCTOR','NURSE','MENTAL','WELLNESS','DIET',
                        'NUTRITION','DISABILITY','COVID','VIRUS']],
        ['name' => 'Weather & Environment', 'icon' => 'fa-cloud-sun', 'color' => '#2980b9',
         'keywords' => ['WEATHER','CLIMATE','FORECAST','STORM','HURRICANE','TORNADO','METEOR','ENVIRON',
                        'ECOLOGY','GREEN','SOLAR','WIND_ENERGY']],
        ['name' => 'Space & Astronomy', 'icon' => 'fa-meteor', 'color' => '#1a237e',
         'keywords' => ['SPACE','ASTRONOMY','ASTRONO','ASTRO','NASA','ESA','ROCKET','SATELLITE','ORBIT',
                        'PLANET','PLANETS','SOLAR','MARS','MOON','LUNAR','TELESCOPE','COSMO','COSMOS',
                        'UNIVERSE','GALAXY','NEBULA','STARGAZING','HUBBLE','SPACEX','ISS','ASTRONAUT']],
        ['name' => 'Astrology & Horoscopes', 'icon' => 'fa-star', 'color' => '#8e44ad',
         'keywords' => ['HOROSCOPE','ASTROLOGY','ZODIAC','TAROT','PSYCHIC','DIVINATION']],
        ['name' => 'History & Cold War', 'icon' => 'fa-monument', 'color' => '#7f8c8d',
         'keywords' => ['HISTORY','HISTORIC','COLDWAR','COLD_WAR','MILITARY','WAR','WWII','WW2','WW1',
                        'NUCLEAR','CIVIL_WAR','ANCIENT','MEDIEVAL','LONGLINES','BUNKER']],
        ['name' => 'Synchronet & Other BBS Software', 'icon' => 'fa-server', 'color' => '#2c3e50',
         'keywords' => ['SYNCHRONET','SYNCDATA','SYNCANNO','SYNCOPS','SYNCJS','SBBS','ENIGMA','MYSTIC',
                        'MAXIMUS','TELEGARD','RENEGADE','WILDCAT','PCBOARD','WWIV','TRIBBS']],
        ['name' => 'Hobbies & Crafts', 'icon' => 'fa-tools', 'color' => '#27ae60',
         'keywords' => ['HOBBY','HOBBIES','CRAFT','CRAFTS','ANTIQUE','BASKETRY','BEADING','CERAMICS',
                        'CROCHET','CROSS_STITCH','DOLLMAKING','ENAMELING','FLOWERS','GLASS','JEWELRY',
                        'LEGO','MODEL','MODELS','HORSE','WOOD','KNITTING','SEWING','QUILTING','EMBROIDERY',
                        'ORIGAMI','MINIATURE','COLLECTIBLE','COLLECT']],
        ['name' => 'Genealogy & Family History', 'icon' => 'fa-sitemap', 'color' => '#a0522d',
         'keywords' => ['GENEALOGY','GENEALOG','GENEAOLOGY','ANCESTRY','FAMILY_HIST','FAMILY_TREE',
                        'HERITAGE','LINEAGE']],
        ['name' => 'Paranormal & Conspiracy', 'icon' => 'fa-ghost', 'color' => '#6c3483',
         'keywords' => ['UFO','PARANORMAL','CONSPIRACY','CONSPRCY','ALIEN','CRYPTID','BIGFOOT','GHOST',
                        'SUPERNATURAL','PSYCH']],
        ['name' => 'Classifieds & Buy/Sell', 'icon' => 'fa-tag', 'color' => '#17a2b8',
         'keywords' => ['CLASSIFIED','CLASSIFIEDS','BUY','SELL','FORSALE','FOR_SALE','TRADE','SWAP','WANTED',
                        'AUCTION','MARKETPLACE','FLEA','DEALER','VENDOR','ADVERT','LISTING']],
        ['name' => 'General Chat & Social', 'icon' => 'fa-comments', 'color' => '#6c757d',
         'keywords' => ['CHAT','GENERAL','TALK','SOCIAL','DISCUSS','LOUNGE','OFFTOPIC','OFF_TOPIC',
                        'RANDOM','MISC','INTRO','INTRODUCE','HELLO','HI','STATS','OTHER']],
        ['name' => 'Test & Development Areas', 'icon' => 'fa-flask', 'color' => '#95a5a6',
         'keywords' => ['TEST','TESTING','SANDBOX','DEBUG','JUNK','TRASH','DUMMY','SAMPLE']],
    ];

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    /**
     * Run the classification pipeline and return suggested interests.
     *
     * Each element:
     * [
     *   'name'      => string,
     *   'icon'      => string,   (FontAwesome class)
     *   'color'     => string,   (hex color)
     *   'echoareas' => [['id'=>int,'tag'=>string,'description'=>string], ...],
     *   'source'    => 'keyword'|'ai'|'unmatched',
     * ]
     *
     * @return array{suggestions:array<int,array<string,mixed>>, stats:array<string,int>}
     */
    public function generate(bool $useAi = true, bool $useKeywords = true): array
    {
        // Fetch active echo areas only
        $stmt = $this->db->query("SELECT id, tag, description, domain FROM echoareas WHERE is_active = TRUE ORDER BY tag");
        $echoareas = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Get names of existing interests to avoid duplicates
        $existingStmt = $this->db->query("SELECT LOWER(name) FROM interests");
        $existingNames = array_flip($existingStmt->fetchAll(\PDO::FETCH_COLUMN));

        $noDescription = count(array_filter($echoareas, fn($e) => trim((string)($e['description'] ?? '')) === ''));

        // Pass 1: keyword heuristics (optional)
        $tagToCategory = $useKeywords ? $this->classifyByKeyword($echoareas) : [];

        // Pass 2: AI for unmatched (or all) areas
        $unmatched = array_filter($echoareas, fn($e) => ($tagToCategory[$e['tag']] ?? null) === null);
        if ($useAi && !empty($unmatched)) {
            $apiKey = Config::env('ANTHROPIC_API_KEY', '');
            if ($apiKey !== '') {
                $aiResults = $this->classifyByAi($apiKey, array_values($unmatched));
                foreach ($aiResults as $tag => $category) {
                    if ($category !== null) {
                        $tagToCategory[$tag] = ['category' => $category, 'source' => 'ai'];
                    }
                }
            }
        }

        // Build area lookup
        $areaById = [];
        foreach ($echoareas as $area) {
            $areaById[$area['tag']] = $area;
        }

        // Group areas by category
        $groups = [];   // category_name => ['icon', 'color', 'source', 'areas']
        foreach ($tagToCategory as $tag => $match) {
            if ($match === null) {
                continue;
            }
            $catName = is_array($match) ? $match['category'] : $match;
            $source  = is_array($match) ? $match['source'] : 'keyword';
            $area    = $areaById[$tag] ?? null;
            if (!$area) {
                continue;
            }
            if (!isset($groups[$catName])) {
                $catMeta = $this->getCategoryMeta($catName);
                $groups[$catName] = [
                    'icon'   => $catMeta['icon'],
                    'color'  => $catMeta['color'],
                    'source' => $source,
                    'areas'  => [],
                ];
            }
            if ($source === 'ai' && $groups[$catName]['source'] === 'keyword') {
                // keep keyword source label if any area in group was keyword-matched
            } elseif ($source === 'keyword') {
                $groups[$catName]['source'] = 'keyword';
            }
            $groups[$catName]['areas'][] = [
                'id'          => (int)$area['id'],
                'tag'         => $area['tag'],
                'description' => $area['description'] ?? '',
                'domain'      => $area['domain'] ?? '',
            ];
        }

        // Build suggestions, skipping categories that already exist as interests
        $suggestions = [];
        foreach ($groups as $catName => $group) {
            if (isset($existingNames[strtolower($catName)])) {
                continue;
            }
            $suggestions[] = [
                'name'      => $catName,
                'icon'      => $group['icon'],
                'color'     => $group['color'],
                'echoareas' => $group['areas'],
                'source'    => $group['source'],
            ];
        }

        // Sort by area count descending
        usort($suggestions, fn($a, $b) => count($b['echoareas']) - count($a['echoareas']));

        $categorised = count(array_filter($tagToCategory, fn($v) => $v !== null));

        return [
            'suggestions' => $suggestions,
            'stats' => [
                'total'          => count($echoareas),
                'categorised'    => $categorised,
                'uncategorised'  => count($echoareas) - $categorised,
                'no_description' => $noDescription,
                'ai_available'   => Config::env('ANTHROPIC_API_KEY', '') !== '',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Pass 1: keyword heuristics.
     * Returns array<tag, array{category:string,source:'keyword'}|null>
     *
     * @param array<int,array<string,mixed>> $echoareas
     * @return array<string,array{category:string,source:string}|null>
     */
    private function classifyByKeyword(array $echoareas): array
    {
        $results = [];
        foreach ($echoareas as $area) {
            $cleanedTag = $this->cleanTag($area['tag']);
            $searchText = strtoupper($cleanedTag . ' ' . ($area['description'] ?? ''));

            $results[$area['tag']] = null;
            foreach (self::CATEGORIES as $cat) {
                foreach ($cat['keywords'] as $kw) {
                    if (str_contains($searchText, strtoupper($kw))) {
                        $results[$area['tag']] = ['category' => $cat['name'], 'source' => 'keyword'];
                        break 2;
                    }
                }
            }
        }
        return $results;
    }

    /**
     * Pass 2: AI classification for unmatched areas.
     * Returns array<tag, string|null>
     *
     * @param array<int,array<string,mixed>> $echoareas Unmatched areas only
     * @return array<string,string|null>
     */
    private function classifyByAi(string $apiKey, array $echoareas): array
    {
        $categoryNames = array_column(self::CATEGORIES, 'name');
        $categoryList  = implode("\n", array_map(fn($n) => "  - {$n}", $categoryNames));
        $batchSize     = 50;
        $batches       = array_chunk($echoareas, $batchSize);
        $results       = [];

        foreach ($batches as $batch) {
            $batchResults = $this->classifyBatch($apiKey, $batch, $categoryList);
            $results      = array_merge($results, $batchResults);
        }

        return $results;
    }

    /**
     * Classify one batch via the Anthropic API.
     * Returns array<tag, string|null>
     *
     * @param array<int,array<string,mixed>> $batch
     * @return array<string,string|null>
     */
    private function classifyBatch(string $apiKey, array $batch, string $categoryList): array
    {
        $areaList = implode("\n", array_map(
            fn($a) => '  ' . $a['tag'] . (!empty($a['description']) ? ': ' . $a['description'] : ''),
            $batch
        ));

        $prompt = <<<PROMPT
You are classifying FTN/Fidonet BBS echo areas (message boards) into interest categories.

Use ONLY the existing categories listed below. Do NOT invent new category names.
If an area does not clearly and confidently belong to one of the listed categories, return null for that tag — do not guess.

Existing categories:
{$categoryList}

Echo areas to classify (tag: description):
{$areaList}

Respond with ONLY a JSON object mapping each tag exactly as given to its category name, or null if uncertain.
Example: {"AREA_TAG": "Category Name", "UNCLEAR_TAG": null}
PROMPT;

        $payload = json_encode([
            'model'      => 'claude-haiku-4-5-20251001',
            'max_tokens' => 8192,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_POST           => true,
            \CURLOPT_POSTFIELDS     => $payload,
            \CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            \CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return array_fill_keys(array_column($batch, 'tag'), null);
        }

        $data = json_decode((string)$response, true);
        $text = $data['content'][0]['text'] ?? null;

        if ($text !== null && preg_match('/\{[\s\S]*\}/u', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                $results = [];
                foreach ($batch as $area) {
                    $val = $decoded[$area['tag']] ?? null;
                    // Treat explicit "None"/"none"/empty string as unmatched
                    if (is_string($val) && (strtolower(trim($val)) === 'none' || trim($val) === '')) {
                        $val = null;
                    }
                    $results[$area['tag']] = $val;
                }
                return $results;
            }
        }

        return array_fill_keys(array_column($batch, 'tag'), null);
    }

    /**
     * Strip known network prefixes and suffixes from an echo area tag.
     */
    private function cleanTag(string $tag): string
    {
        $clean = strtoupper($tag);

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($clean, strtoupper($prefix))) {
                $clean = substr($clean, strlen($prefix));
                break;
            }
        }

        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($clean, strtoupper($suffix))) {
                $clean = substr($clean, 0, -strlen($suffix));
                break;
            }
        }

        return $clean;
    }

    /**
     * Look up icon and color for a known category name; return defaults for unknown.
     *
     * @return array{icon:string,color:string}
     */
    private function getCategoryMeta(string $name): array
    {
        foreach (self::CATEGORIES as $cat) {
            if (strcasecmp($cat['name'], $name) === 0) {
                return ['icon' => $cat['icon'], 'color' => $cat['color']];
            }
        }
        return ['icon' => 'fa-layer-group', 'color' => '#6c757d'];
    }
}
