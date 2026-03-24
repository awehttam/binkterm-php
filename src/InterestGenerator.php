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

use BinktermPHP\AI\AiRequest;
use BinktermPHP\AI\AiService;

/**
 * Classifies echo areas into interest categories using keyword heuristics
 * and optionally an external AI provider.
 *
 * Derived from tests/generate_interests_report.php — keep prefix/suffix lists
 * and keyword table in sync with that script.
 */
class InterestGenerator
{
    private \PDO $db;
    private AiService $aiService;

    /** Network prefixes to strip from echo area tags before keyword matching. */
    private const PREFIXES = [
        'LVLY_', 'MIN_', 'DOVE-', 'DOVE_', 'FSX_', 'AGL_', 'AGN_',
        'HACK_', 'HNET_', 'HBN_', 'CFN_', 'TQN_', 'WIN_', 'SFN_',
        'VAX_', 'SCI_', 'FRL_', 'FTN_', 'NET_', 'FIDONET_', 'FIDO_',
        'RTN_', 'ESS_', 'PIN_', 'PIN-',
        // NIX_ intentionally omitted: keeping NIX as a token provides a Linux classification signal
    ];

    /** Common tag suffixes to strip. */
    private const SUFFIXES = [
        '_ECHO', '_AREA', '_NET', '_BASE', '_CHAT', '_FORUM', '_AMY',
    ];

    /**
     * Category definitions: name, keywords, icon (FontAwesome), color.
     *
     * @var array<int,array{name:string,keywords:string[],icon:string,color:string}>
     */
    private const CATEGORIES = [
        ['name' => 'Computer Support', 'icon' => 'fa-laptop', 'color' => '#546e7a',
         'keywords' => ['COMP','PCHELP','TECHHELP','TECH_HELP','COMPHELP','COMP_HELP',
                        'HELPDESK','HARDWARE','UPGRADE','TROUBLESHOOT',
                        'COMPUTING','TECH','TECHTALK','AMIPI','VIRUS']],
        ['name' => 'Retro Computing & Vintage Hardware', 'icon' => 'fa-desktop', 'color' => '#8e44ad',
         'keywords' => ['RETRO','VINTAGE','CLASSIC','C64','COMMODORE','AMIGA','ATARI','APPLE2','APPLE_2','APPLE',
                        'TRS80','TRS-80','CP/M','CPM','ZX','SPECTRUM','MSX','TANDY','KAYPRO','OSBORNE','ALTAIR',
                        'S100','OLDCOMP','OLD_COMP','MUSEUM','MAINFRAME','COL_ADAM','TI99','TI-99','RTN_TI',
                        'CNET','COMMS','HARD','DBBSOFT','TDRS']],
        ['name' => 'Sysop & Network Operations', 'icon' => 'fa-server', 'color' => '#37474f',
         'keywords' => ['SYSOP','ADMIN','OPS','ANNOUNCE','NODELIST','POINTLIST','ELIST','FILEFIND',
                        'ALLFIX','PDNECHO','FILEECHO','TICFILE','MAILER','BINKP','FOSSIL',
                        'SYNCOPS','SYNCANNO','SYNCJS','SYNCDATA','BOT','AUTOANNO','FILEANN',
                        'GENAN','NETSYS','NETOPS','HUBSYS','Z1C',
                        'Z39','NEWS','STATS','FILECHAT']],
        ['name' => 'Synchronet & Other BBS Software', 'icon' => 'fa-server', 'color' => '#2c3e50',
         'keywords' => ['SYNCHRONET','SBBS','ENIGMA','MYSTIC','MYS','MAXIMUS','TELEGARD','RENEGADE',
                        'WILDCAT','PCBOARD','PB','WWIV','TRIBBS','BINKTERMPHP',
                        'GOLDED','GOLDBASE','HUSKY','FIDOSOFT',
                        'BINKD','JAMNNTPD','ZEUS','BBSSOFT','MAIL']],
        ['name' => 'BBS & Fidonet', 'icon' => 'fa-terminal', 'color' => '#2c3e50',
         'keywords' => ['BBS','FIDONET','FIDO','DOOR','ANSI','ASCII','ECHOMAIL','NETMAIL',
                        'FTN','IBBSDOOR','DOORGAMES','FIDONEWS','FTSC','FUTURE4FIDO','BBSADS',
                        'IPV6','ADS','OTHERNETS','BBSNEWS']],
        ['name' => 'Programming & Software Development', 'icon' => 'fa-code', 'color' => '#3498db',
         'keywords' => ['PROG','PRGS','CODE','CODING','DEVEL','DEV','PYTHON','JAVA','CPLUS','CPLUSPLUS','CSHARP',
                        'DOTNET','PERL','RUBY','JAVASCRIPT','TYPESCRIPT','GOLANG','RUST','SWIFT','PASCAL',
                        'BASIC','ASSEMBL','ASM','FORTRAN','COBOL','PHP','HTML','CSS','SQL','DATABASE',
                        'OPENSOURCE','GITHUB','GIT','LINUX_DEV','KERNEL','COMPILER','ALGORITHM',
                        'DATASTRUC','SOFTWARE']],
        ['name' => 'Linux & Open Source', 'icon' => 'fa-linux', 'color' => '#e67e22',
         'keywords' => ['LINUX','UNIX','NIX','GNU','UBUNTU','DEBIAN','FEDORA','CENTOS','ARCH','GENTOO','SLACKWARE',
                        'FREEBSD','OPENBSD','BSD','OPENSRC','OPENSOURCE','FOSS','KERNEL','BASH','SHELL',
                        'SYSADMIN','SYS_ADMIN']],
        ['name' => 'Windows & Microsoft', 'icon' => 'fa-windows', 'color' => '#0078d4',
         'keywords' => ['WINDOWS','WINNT','WIN95','WIN98','WINXP','WIN10','WIN11','MICROSOFT','MSDOS','DOS',
                        'POWERSHELL','DOTNET','AZURE']],
        ['name' => 'Gaming & Video Games', 'icon' => 'fa-gamepad', 'color' => '#e74c3c',
         'keywords' => ['GAME','GAMING','GAMER','CONSOLE','ARCADE','NINTENDO','SEGA','ATARI_GAME',
                        'PLAYSTATION','XBOX','STEAM','PC_GAME','PCGAME','RPG','FPS','MMORPG','EMULAT','ROMS']],
        ['name' => 'Humour & Entertainment', 'icon' => 'fa-laugh', 'color' => '#f1c40f',
         'keywords' => ['HUMOR','HUMOUR','FUNNY','JOKE','JOKES','COMEDY','LAUGH','ENTERTAIN','TRIVIA','RIDDLE',
                        'PRANK','VIDEO','MOVIES','MOVIE','COMIC']],
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
                        'MOBILE','CRY']],
        ['name' => 'Politics & Current Events', 'icon' => 'fa-landmark', 'color' => '#607d8b',
         'keywords' => ['POLITIC','POLITICS','NEWS','CURRENT','WORLD','GOVERN','GOVERNMENT','LAW','LEGAL',
                        'LIBERTARIAN','CONSERV','LIBERAL','DEMOCRAT','REPUBLICAN','ELECTION','DEBATE',
                        'OPINION','EDITORIAL','GUN','CONSPRCY','CONSPIR','CYBERCHAT']],
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
        ['name' => 'Art & Creative', 'icon' => 'fa-palette', 'color' => '#d35400',
         'keywords' => ['ART','ARTIST','CREATIVE','DESIGN','GRAPHIC','PHOTO','PHOTOGRAPHY','PAINT',
                        'DRAWING','ILLUSTRATION','PIXEL','TEXTART','ASCII_ART','EDITING','VIDEO_EDIT']],
        ['name' => 'Books & Literature', 'icon' => 'fa-book', 'color' => '#8d6e63',
         'keywords' => ['BOOK','BOOKS','NOVEL','FICTION','NONFIC','NONFICTION','AUTHOR','WRITING','POETRY',
                        'POEM','READING','LIBRARY','EBOOK']],
        ['name' => 'Health & Medicine', 'icon' => 'fa-heartbeat', 'color' => '#e74c3c',
         'keywords' => ['HEALTH','MEDICAL','MEDICINE','DOCTOR','NURSE','MENTAL','WELLNESS','DIET',
                        'NUTRITION','DISABILITY','COVID']],
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
                        'RANDOM','MISC','INTRO','INTRODUCE','HELLO','HI','OTHER']],
        ['name' => 'Test & Development Areas', 'icon' => 'fa-flask', 'color' => '#95a5a6',
         'keywords' => ['TEST','TESTING','SANDBOX','DEBUG','JUNK','TRASH','DUMMY','SAMPLE','FIDOTEST','TESTNET']],
    ];

    public function __construct(?AiService $aiService = null)
    {
        $this->db = Database::getInstance()->getPdo();
        $this->aiService = $aiService ?? AiService::create();
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
        if ($useAi && !empty($unmatched) && $this->isAiAvailable()) {
            $aiResults = $this->classifyByAi(array_values($unmatched));
            foreach ($aiResults as $tag => $category) {
                if ($category !== null) {
                    $tagToCategory[$tag] = ['category' => $category, 'source' => 'ai'];
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
                'ai_available'   => $this->isAiAvailable(),
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

            // Split tag into whole tokens for exact matching (avoids e.g. FIDO matching FIDOSOFT)
            $tagTokens = preg_split('/[_.\-!\/\s]+/', strtoupper($cleanedTag), -1, PREG_SPLIT_NO_EMPTY);
            $descText  = strtoupper($area['description'] ?? '');

            $bestCategory = null;
            $bestScore    = -1;

            foreach (self::CATEGORIES as $cat) {
                foreach ($cat['keywords'] as $kw) {
                    $kwUpper = strtoupper($kw);

                    if (in_array($kwUpper, $tagTokens)) {
                        // Tag token exact match — high priority; longer keyword wins ties
                        $score = 100 + strlen($kw);
                    } elseif (str_contains($descText, $kwUpper)) {
                        // Description substring match — lower priority; longer keyword wins ties
                        $score = strlen($kw);
                    } else {
                        continue;
                    }

                    if ($score > $bestScore) {
                        $bestScore    = $score;
                        $bestCategory = $cat['name'];
                    }
                }
            }

            $results[$area['tag']] = $bestCategory
                ? ['category' => $bestCategory, 'source' => 'keyword']
                : null;
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
    private function classifyByAi(array $echoareas): array
    {
        $categoryNames = array_column(self::CATEGORIES, 'name');
        $categoryList  = implode("\n", array_map(function($n) {
            $hint = self::CATEGORY_HINTS[$n] ?? '';
            return $hint ? "  - {$n}: {$hint}" : "  - {$n}";
        }, $categoryNames));
        $batchSize     = 50;
        $batches       = array_chunk($echoareas, $batchSize);
        $results       = [];

        foreach ($batches as $batch) {
            $batchResults = $this->classifyBatch($batch, $categoryList);
            $results      = array_merge($results, $batchResults);
        }

        return $results;
    }

    /**
     * Classify one batch via the configured AI provider.
     * Returns array<tag, string|null>
     *
     * @param array<int,array<string,mixed>> $batch
     * @return array<string,string|null>
     */
    /**
     * Short descriptions sent to the AI alongside each category name to prevent
     * misclassification of ambiguous areas (e.g. "internet discussion" ≠ security).
     *
     * @var array<string,string>
     */
    private const CATEGORY_HINTS = [
        'Computer Support'                      => 'general computer help, hardware support, PC troubleshooting, tech support — NOT retro/vintage computing',
        'Retro Computing & Vintage Hardware'    => 'old computers, vintage hardware, C64, Amiga, Atari, CP/M, classic systems',
        'Sysop & Network Operations'            => 'sysop administration, network ops, announcements, nodelist/pointlist management, file distribution, mailers, binkp, automated network tooling',
        'BBS & Fidonet'                         => 'BBS culture, FidoNet discussion, door games, BBS advertisements, echomail/netmail as topics, FidoNet standards and history',
        'Programming & Software Development'    => 'coding, programming languages, software development, algorithms',
        'Linux & Open Source'                   => 'Linux, Unix, BSD, open source software, system administration',
        'Windows & Microsoft'                   => 'Windows OS, Microsoft products, DOS',
        'Gaming & Video Games'                  => 'video games, consoles, arcade, PC gaming, RPGs, emulation',
        'Science Fiction & Fantasy'             => 'sci-fi, fantasy, Star Trek, Star Wars, Doctor Who, comics, anime',
        'Music'                                 => 'music genres, instruments, bands, audio production',
        'Ham Radio & Electronics'               => 'amateur radio, electronics, circuits, Arduino, hardware tinkering',
        'Networking & Security'                 => 'cybersecurity, hacking, infosec, encryption, firewalls, VPNs, penetration testing — NOT general internet or mobile discussion',
        'Politics & Current Events'             => 'politics, news, government, elections, current events, debate',
        'Religion & Philosophy'                 => 'religion, philosophy, spirituality, ethics',
        'Food & Cooking'                        => 'cooking, recipes, food, baking, beverages, homebrewing',
        'Sports, Fitness & Outdoors'            => 'sports, fitness, outdoor activities, camping, hiking, hunting, fishing',
        'Humour & Entertainment'                => 'jokes, comedy, trivia, entertainment, video/film content — NOT video games',
        'Books & Literature'                    => 'books, novels, reading, poetry, writing',
        'Art & Creative'                        => 'art, design, photography, drawing, pixel art, ASCII art, video editing, creative media',
        'Health & Medicine'                     => 'health, medicine, wellness, mental health, nutrition',
        'Weather & Environment'                 => 'weather, climate, environment, ecology',
        'Astrology & Horoscopes'                => 'astrology, horoscopes, zodiac, tarot, divination',
        'Space & Astronomy'                     => 'space exploration, astronomy, planets, NASA, telescopes, stargazing',
        'History & Cold War'                    => 'history, military history, cold war, ancient/medieval history',
        'Synchronet & Other BBS Software'       => 'Synchronet BBS software, Mystic, Enigma, BinktermPHP, GoldED, Husky, other specific BBS/FTN software packages',
        'Hobbies & Crafts'                      => 'hobbies, crafts, models, collectibles, knitting, woodworking, sewing',
        'Genealogy & Family History'            => 'genealogy, family history, ancestry research',
        'Paranormal & Conspiracy'               => 'UFOs, paranormal, conspiracy theories, cryptids, ghosts',
        'Classifieds & Buy/Sell'                => 'buying and selling, classifieds, trade, swap, wanted ads, marketplace',
        'General Chat & Social'                 => 'general chat, off-topic, introductions, social discussion, announcements',
        'Test & Development Areas'              => 'test areas, sandboxes, debug/dummy areas, FIDOTEST',
    ];

    private function classifyBatch(array $batch, string $categoryList): array
    {
        $areaList = implode("\n", array_map(
            fn($a) => '  ' . $a['tag'] . (!empty($a['description']) ? ': ' . $a['description'] : ''),
            $batch
        ));

        $prompt = <<<PROMPT
You are classifying FTN/Fidonet BBS echo areas (message boards) into interest categories.

Use ONLY the existing categories listed below. Do NOT invent new category names.
If an area does not clearly and confidently belong to one of the listed categories, return null for that tag — do not guess.

Existing categories (name: what belongs here):
{$categoryList}

Echo areas to classify (tag: description):
{$areaList}

Respond with ONLY a JSON object mapping each tag exactly as given to its category name, or null if uncertain.
Example: {"AREA_TAG": "Category Name", "UNCLEAR_TAG": null}
PROMPT;

        try {
            $response = $this->aiService->generateJson(new AiRequest(
                'interest_generation',
                'You classify FTN/Fidonet BBS echo areas into one of the provided categories. Return only JSON.',
                $prompt,
                null,
                null,
                0.2,
                8192,
                60,
                null,
                ['batch_size' => count($batch)]
            ));
            $decoded = $response->getParsedJson();
            if (!is_array($decoded)) {
                return array_fill_keys(array_column($batch, 'tag'), null);
            }

            $results = [];
            foreach ($batch as $area) {
                $val = $decoded[$area['tag']] ?? null;
                if (is_string($val) && (strtolower(trim($val)) === 'none' || trim($val) === '')) {
                    $val = null;
                }
                $results[$area['tag']] = is_string($val) ? trim($val) : null;
            }
            return $results;
        } catch (\Throwable $e) {
            return array_fill_keys(array_column($batch, 'tag'), null);
        }
    }

    private function isAiAvailable(): bool
    {
        return !empty($this->aiService->getConfiguredProviders());
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
