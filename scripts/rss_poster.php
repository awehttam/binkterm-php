#!/usr/bin/env php
<?php
/**
 * Auto Feed: Feed to Echoarea Poster
 *
 * Monitors configured feeds and posts new items to specified echoareas.
 * Run periodically via cron or manually to check for new feed items.
 *
 * Usage:
 *   php rss_poster.php              # Check all active feeds
 *   php rss_poster.php --feed-id=1  # Check specific feed
 *   php rss_poster.php --force      # Force check even if recently checked
 *   php rss_poster.php --verbose    # Show detailed output
 *
 * Configuration is stored in the database (auto_feed_sources table).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;
use BinktermPHP\MessageHandler;

// Parse command line arguments
$options = getopt('', ['feed-id:', 'force', 'verbose', 'help']);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

$feedId = $options['feed-id'] ?? null;
$force = isset($options['force']);
$verbose = isset($options['verbose']);

// Get database connection
$db = Database::getInstance()->getPdo();

// Initialize message handler
$messageHandler = new MessageHandler();

// Get feeds to check
if ($feedId) {
    $stmt = $db->prepare("
        SELECT f.*, e.tag as echoarea_tag, e.domain as echoarea_domain
        FROM auto_feed_sources f
        JOIN echoareas e ON e.id = f.echoarea_id
        WHERE f.id = ? AND f.active = TRUE
    ");
    $stmt->execute([$feedId]);
    $feeds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($feeds)) {
        echo "Error: Feed ID $feedId not found or inactive\n";
        exit(1);
    }
} else {
    $stmt = $db->query("
        SELECT f.*, e.tag as echoarea_tag, e.domain as echoarea_domain
        FROM auto_feed_sources f
        JOIN echoareas e ON e.id = f.echoarea_id
        WHERE f.active = TRUE
        ORDER BY f.id
    ");
    $feeds = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (empty($feeds)) {
    echo "No active feeds configured\n";
    exit(0);
}

echo "Auto Feed - Checking " . count($feeds) . " feed(s)\n";
echo str_repeat('=', 60) . "\n";

$totalPosted = 0;
$totalErrors = 0;

foreach ($feeds as $feed) {
    try {
        echo sprintf("[Feed #%d] Checking %s (%s)...\n",
            $feed['id'],
            $feed['feed_name'] ?: 'Unnamed Feed',
            $feed['echoarea_tag']
        );

        $posted = processFeed($db, $messageHandler, $feed, $force, $verbose);
        $totalPosted += $posted;

        echo sprintf("[Feed #%d] Complete: %d new article(s)\n",
            $feed['id'], $posted);
    } catch (Exception $e) {
        $totalErrors++;
        echo sprintf("[Feed #%d] ERROR: %s\n", $feed['id'], $e->getMessage());

        // Log error to database
        $stmt = $db->prepare("UPDATE auto_feed_sources SET last_error = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$e->getMessage(), $feed['id']]);
    }
}

echo str_repeat('-', 60) . "\n";
echo "Complete: $totalPosted article(s) posted";
if ($totalErrors > 0) {
    echo ", $totalErrors error(s)";
}
echo "\n";

exit($totalErrors > 0 ? 1 : 0);

/**
 * Process a single RSS feed
 *
 * @param PDO $db Database connection
 * @param MessageHandler $messageHandler Message handler
 * @param array $feed Feed configuration
 * @param bool $force Force check even if recently checked
 * @param bool $verbose Show verbose output
 * @return int Number of articles posted
 */
function processFeed($db, $messageHandler, $feed, $force, $verbose) {
    // Check if feed should be checked (don't check too frequently)
    if (!$force && $feed['last_check']) {
        $lastCheck = strtotime($feed['last_check']);
        $minInterval = 300; // 5 minutes minimum between checks

        if (time() - $lastCheck < $minInterval) {
            if ($verbose) {
                echo sprintf("[Feed #%d] Skipping (checked %d seconds ago)\n",
                    $feed['id'], time() - $lastCheck);
            }
            return 0;
        }
    }

    // Fetch source feed
    if ($verbose) {
        echo sprintf("[Feed #%d] Fetching %s feed from %s\n",
            $feed['id'], getFeedSourceType($feed), $feed['feed_url']);
    }
    $articles = fetchArticlesForFeed($feed);

    if ($verbose) {
        echo sprintf("[Feed #%d] Found %d item(s) in feed\n", $feed['id'], count($articles));
    }

    if (empty($articles)) {
        if ($verbose) {
            echo sprintf("[Feed #%d] No items found in feed\n", $feed['id']);
        }
        updateLastCheck($db, $feed['id']);
        return 0;
    }

    // Limit articles per check
    $maxArticles = $feed['max_articles_per_check'] ?? 10;
    $articles = array_slice($articles, 0, $maxArticles);

    // Filter out already-posted articles
    $newArticles = filterNewArticles($db, $feed['id'], $articles);

    if (empty($newArticles)) {
        if ($verbose) {
            echo sprintf("[Feed #%d] No new items\n", $feed['id']);
        }
        updateLastCheck($db, $feed['id']);
        return 0;
    }

    // Post new articles to echoarea.
    // Articles arrive newest-first from the feed; reverse so parents are written
    // to the DB before their replies within the same batch, enabling within-run threading.
    $newestGuid = $newArticles[0]['guid'] ?? null;
    $posted = 0;
    foreach (array_reverse($newArticles) as $article) {
        try {
            postArticleToEchoarea($db, $messageHandler, $feed, $article, $verbose);
            $posted++;
        } catch (Exception $e) {
            echo sprintf("[Feed #%d] Failed to post item '%s': %s\n",
                $feed['id'], substr($article['title'], 0, 50), $e->getMessage());
        }
    }

    // Track the newest article GUID (first in original feed order) for deduplication.
    if ($newestGuid !== null) {
        $stmt = $db->prepare("UPDATE auto_feed_sources SET last_article_guid = ? WHERE id = ?");
        $stmt->execute([$newestGuid, $feed['id']]);
    }

    // Update last check time
    updateLastCheck($db, $feed['id']);

    return $posted;
}

/**
 * Fetch and parse articles for a configured feed source.
 *
 * @param array $feed Feed configuration
 * @return array Array of normalized article records
 * @throws Exception on fetch or parse failure
 */
function fetchArticlesForFeed($feed) {
    $sourceType = getFeedSourceType($feed);

    if ($sourceType === 'bluesky') {
        $requestLimit = min(100, max(10, (int)($feed['max_articles_per_check'] ?? 10) * 5));
        return fetchBlueskyFeed($feed['feed_url'], $requestLimit);
    }

    $xml = fetchRssFeed($feed['feed_url']);
    return parseRssFeed($xml);
}

/**
 * Resolve the feed source type. Older rows default to RSS, while Bluesky profile
 * URLs are auto-detected to keep existing configuration simple.
 *
 * @param array $feed Feed configuration
 * @return string Source type
 */
function getFeedSourceType($feed) {
    $sourceType = strtolower((string)($feed['source_type'] ?? ''));
    if ($sourceType !== '') {
        return $sourceType;
    }

    return isBlueskyProfileUrl((string)$feed['feed_url']) ? 'bluesky' : 'rss';
}

/**
 * Fetch public Bluesky author posts and normalize posts with media attachments.
 *
 * @param string $url Bluesky profile URL, handle, or DID
 * @param int $limit Maximum number of posts to request
 * @return array Normalized article records ordered newest first
 * @throws Exception on fetch or parse failure
 */
function fetchBlueskyFeed($url, $limit = 10) {
    $actor = extractBlueskyActor($url);
    if ($actor === '') {
        throw new Exception("Invalid Bluesky profile URL: $url");
    }

    $limit = max(1, min(100, $limit));
    $apiUrl = 'https://public.api.bsky.app/xrpc/app.bsky.feed.getAuthorFeed'
        . '?actor=' . rawurlencode($actor)
        . '&filter=posts_no_replies'
        . '&limit=' . $limit;

    $json = fetchJson($apiUrl, 'BinktermPHP Auto Feed Bluesky/1.0');
    $items = $json['feed'] ?? null;
    if (!is_array($items)) {
        throw new Exception('Bluesky response did not include a feed array');
    }

    $articles = [];
    foreach ($items as $item) {
        if (!empty($item['reason'])) {
            continue;
        }

        $post = $item['post'] ?? null;
        if (!is_array($post)) {
            continue;
        }

        $article = normalizeBlueskyPost($post);
        if ($article !== null) {
            $articles[] = $article;
        }
    }

    return $articles;
}

/**
 * Normalize a Bluesky post view into the common article structure.
 *
 * @param array $post Bluesky post view
 * @return array|null Article data, or null when the post has no media URL
 */
function normalizeBlueskyPost($post) {
    $mediaUrls = extractBlueskyMediaUrls($post['embed'] ?? null);
    if (empty($mediaUrls)) {
        return null;
    }

    $record = is_array($post['record'] ?? null) ? $post['record'] : [];
    $author = is_array($post['author'] ?? null) ? $post['author'] : [];
    $text = trim((string)($record['text'] ?? ''));
    $handle = (string)($author['handle'] ?? '');
    $displayName = trim((string)($author['displayName'] ?? ''));
    $postedBy = $displayName !== '' ? $displayName : ($handle !== '' ? '@' . $handle : 'Bluesky');
    $createdAt = (string)($record['createdAt'] ?? $post['indexedAt'] ?? '');
    $uri = (string)($post['uri'] ?? '');
    $cid = (string)($post['cid'] ?? '');
    $link = buildBlueskyPostUrl($handle, $uri);

    $title = $text !== ''
        ? firstLine($text)
        : 'Bluesky media post by ' . $postedBy;

    return [
        'guid' => $uri !== '' ? $uri : $cid,
        'title' => $title,
        'link' => $link,
        'description' => $text,
        'pubDate' => $createdAt,
        'sourceType' => 'bluesky',
        'author' => $postedBy,
        'mediaUrls' => $mediaUrls
    ];
}

/**
 * Extract image/video thumbnail URLs from supported Bluesky embed shapes.
 *
 * @param mixed $embed Bluesky embed view
 * @return array Unique media URLs
 */
function extractBlueskyMediaUrls($embed) {
    if (!is_array($embed)) {
        return [];
    }

    $urls = [];
    $type = (string)($embed['$type'] ?? '');

    if ($type === 'app.bsky.embed.images#view' && is_array($embed['images'] ?? null)) {
        foreach ($embed['images'] as $image) {
            if (!is_array($image)) {
                continue;
            }
            $url = (string)($image['fullsize'] ?? $image['thumb'] ?? '');
            if ($url !== '') {
                $urls[] = $url;
            }
        }
    } elseif ($type === 'app.bsky.embed.video#view') {
        $url = (string)($embed['thumbnail'] ?? '');
        if ($url !== '') {
            $urls[] = $url;
        }
    } elseif ($type === 'app.bsky.embed.external#view' && is_array($embed['external'] ?? null)) {
        $url = (string)($embed['external']['thumb'] ?? '');
        if ($url !== '') {
            $urls[] = $url;
        }
    } elseif ($type === 'app.bsky.embed.recordWithMedia#view') {
        $urls = array_merge($urls, extractBlueskyMediaUrls($embed['media'] ?? null));
    }

    return array_values(array_unique(array_filter($urls, function($url) {
        return preg_match('/^https:\/\//i', $url) === 1;
    })));
}

/**
 * Extract a Bluesky actor handle or DID from a profile URL or raw input.
 *
 * @param string $url Profile URL, handle, or DID
 * @return string Actor identifier
 */
function extractBlueskyActor($url) {
    $value = trim($url);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^did:[a-z0-9:._-]+$/i', $value)) {
        return $value;
    }

    if (preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/i', $value)) {
        return $value;
    }

    $parts = parse_url($value);
    $host = strtolower((string)($parts['host'] ?? ''));
    $path = (string)($parts['path'] ?? '');
    if (($host === 'bsky.app' || $host === 'www.bsky.app')
        && preg_match('#^/profile/([^/]+)#', $path, $matches)) {
        return rawurldecode($matches[1]);
    }

    return '';
}

/**
 * Build a human-facing Bluesky post URL.
 *
 * @param string $handle Author handle
 * @param string $uri AT URI
 * @return string Public post URL
 */
function buildBlueskyPostUrl($handle, $uri) {
    if ($handle === '' || !preg_match('#/app\.bsky\.feed\.post/([^/]+)$#', $uri, $matches)) {
        return '';
    }

    return 'https://bsky.app/profile/' . rawurlencode($handle) . '/post/' . rawurlencode($matches[1]);
}

/**
 * Return the first non-empty line of text.
 *
 * @param string $text Input text
 * @return string First line
 */
function firstLine($text) {
    $lines = preg_split('/\R/', $text);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            return $line;
        }
    }
    return '';
}

/**
 * Fetch and decode JSON from an HTTP endpoint.
 *
 * @param string $url Endpoint URL
 * @param string $userAgent HTTP user agent
 * @return array Decoded JSON
 * @throws Exception on fetch or decode failure
 */
function fetchJson($url, $userAgent) {
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => $userAgent,
            'follow_location' => true,
            'max_redirects' => 5,
            'header' => "Accept: application/json\r\n"
        ]
    ]);

    $json = @file_get_contents($url, false, $context);

    if ($json === false) {
        throw new Exception("Failed to fetch JSON: $url");
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        throw new Exception('Failed to parse JSON response');
    }

    return $decoded;
}

/**
 * Determine whether a URL points at a Bluesky profile.
 *
 * @param string $url URL to test
 * @return bool True for Bluesky profile URLs
 */
function isBlueskyProfileUrl($url) {
    return extractBlueskyActor($url) !== '';
}

/**
 * Fetch RSS feed content
 *
 * @param string $url Feed URL
 * @return SimpleXMLElement Parsed XML
 * @throws Exception on fetch failure
 */
function fetchRssFeed($url) {
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => 'BinktermPHP RSS Poster/1.0',
            'follow_location' => true,
            'max_redirects' => 5
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);

    $xml = @file_get_contents($url, false, $context);

    if ($xml === false) {
        throw new Exception("Failed to fetch feed: $url");
    }

    libxml_use_internal_errors(true);
    $parsed = @simplexml_load_string($xml);

    if ($parsed === false) {
        $errors = libxml_get_errors();
        $errorMsg = !empty($errors) ? $errors[0]->message : 'Invalid XML';
        throw new Exception("Failed to parse feed XML: $errorMsg");
    }

    return $parsed;
}

/**
 * Parse RSS/Atom feed into article array
 *
 * @param SimpleXMLElement $xml Parsed XML
 * @return array Array of articles with guid, title, link, description, pubDate
 */
function parseRssFeed($xml) {
    $articles = [];

    // Detect feed type (RSS 2.0, RSS 1.0/RDF, Atom, etc.)
    if (isset($xml->channel->item)) {
        // RSS 2.0
        getServerLogger()->info("Auto Feed: Parsing RSS 2.0 feed with " . count($xml->channel->item) . " items");
        foreach ($xml->channel->item as $item) {
            $articles[] = [
                'guid' => (string)($item->guid ?? $item->link),
                'title' => (string)$item->title,
                'link' => (string)$item->link,
                'description' => (string)($item->description ?? ''),
                'pubDate' => (string)($item->pubDate ?? '')
            ];
        }
    } elseif (isset($xml->item)) {
        // RSS 1.0 (RDF) - items are direct children of root
        getServerLogger()->info("Auto Feed: Parsing RSS 1.0 (RDF) feed with " . count($xml->item) . " items");
        foreach ($xml->item as $item) {
            $articles[] = [
                'guid' => (string)($item->guid ?? $item->link),
                'title' => (string)$item->title,
                'link' => (string)$item->link,
                'description' => (string)($item->description ?? ''),
                'pubDate' => (string)($item->pubDate ?? $item->date ?? '')
            ];
        }
    } elseif (isset($xml->entry)) {
        // Atom
        getServerLogger()->info("Auto Feed: Parsing Atom feed with " . count($xml->entry) . " entries");
        foreach ($xml->entry as $entry) {
            $link = '';
            if (isset($entry->link)) {
                $link = (string)$entry->link['href'];
            }

            $articles[] = [
                'guid' => (string)($entry->id ?? $link),
                'title' => (string)$entry->title,
                'link' => $link,
                'description' => (string)($entry->summary ?? $entry->content ?? ''),
                'pubDate' => (string)($entry->published ?? $entry->updated ?? '')
            ];
        }
    } else {
        getServerLogger()->warning("Auto Feed: Unknown feed format - root element: " . $xml->getName());
        getServerLogger()->warning("Auto Feed: XML structure: " . print_r(array_keys((array)$xml), true));
    }

    return $articles;
}

/**
 * Filter articles to only those not already posted
 *
 * @param PDO $db Database connection
 * @param int $feedId Feed ID
 * @param array $articles Articles to filter (ordered newest first)
 * @return array New articles only
 */
function filterNewArticles($db, $feedId, $articles) {
    if (empty($articles)) {
        return [];
    }

    // Get last posted article GUID
    $stmt = $db->prepare("SELECT last_article_guid FROM auto_feed_sources WHERE id = ?");
    $stmt->execute([$feedId]);
    $lastGuid = $stmt->fetchColumn();

    // If no last GUID, this is first run - return all articles
    if (!$lastGuid) {
        return $articles;
    }

    // Find position of last posted article
    $newArticles = [];
    $foundLast = false;

    foreach ($articles as $article) {
        if ($article['guid'] === $lastGuid) {
            $foundLast = true;
            break; // Stop here, everything after this is old
        }
        $newArticles[] = $article;
    }

    // If we didn't find the last GUID, the feed may have been cleared
    // Return all articles to be safe (but limited by max_articles_per_check)
    if (!$foundLast && count($articles) > 0) {
        return $articles;
    }

    return $newArticles;
}

/**
 * Strip RE:/Fwd: and similar reply prefixes from a subject, handling multiple levels.
 *
 * @param string $subject Raw subject
 * @return string Subject with all reply prefixes removed
 */
function stripReplyPrefixes(string $subject): string
{
    $pattern = '/^(re|fwd?|aw|sv)\s*:\s*/iu';
    do {
        $prev = $subject;
        $subject = (string)preg_replace($pattern, '', $subject);
    } while ($subject !== $prev);
    return trim($subject);
}

/**
 * Find the echomail ID of the most recent message that appears to be the parent
 * of a reply, by matching the stripped subject against recent messages in the area.
 * Returns null when the given subject has no reply prefix or no parent is found.
 *
 * @param PDO $db Database connection
 * @param int $echoareaId Echoarea to search within
 * @param string $subject Raw subject of the article being posted
 * @param int $limit Maximum number of recent messages to scan
 * @return int|null Parent echomail ID, or null
 */
function findParentMessageId(PDO $db, int $echoareaId, string $subject, int $limit = 1000): ?int
{
    $base = stripReplyPrefixes($subject);
    if (strtolower($base) === strtolower(trim($subject))) {
        return null; // no reply prefix — not a reply
    }
    $baseLower = strtolower($base);

    // Fast path: exact case-insensitive match on the bare subject
    $stmt = $db->prepare(
        "SELECT id FROM echomail
         WHERE echoarea_id = ? AND LOWER(subject) = ?
         ORDER BY date_received DESC LIMIT 1"
    );
    $stmt->execute([$echoareaId, $baseLower]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return (int)$row['id'];
    }

    // Slow path: scan recent messages and strip prefixes in PHP to handle RE: chains
    $stmt = $db->prepare(
        "SELECT id, subject FROM echomail
         WHERE echoarea_id = ?
         ORDER BY date_received DESC LIMIT ?"
    );
    $stmt->execute([$echoareaId, $limit]);
    foreach ($stmt as $row) {
        if (strtolower(stripReplyPrefixes((string)$row['subject'])) === $baseLower) {
            return (int)$row['id'];
        }
    }
    return null;
}

/**
 * Post an article to echoarea
 *
 * @param PDO $db Database connection
 * @param MessageHandler $messageHandler Message handler
 * @param array $feed Feed configuration
 * @param array $article Article data
 * @param bool $verbose Show verbose output
 */
function postArticleToEchoarea($db, $messageHandler, $feed, $article, $verbose) {
    // Format message body
    $body = formatArticleMessage($article);

    // Determine user ID to post as (default to system user 1)
    $userId = $feed['post_as_user_id'] ?? 1;

    // Post to echoarea
    $subject = buildArticleSubject($feed, $article);

    // Attempt subject-based threading when enabled for this feed
    $replyToId = null;
    if (!empty($feed['thread_replies'])) {
        $lookupLimit = max(100, (int)($feed['thread_lookup_limit'] ?? 1000));
        $replyToId = findParentMessageId($db, (int)$feed['echoarea_id'], $article['title'], $lookupLimit);
    }

    if ($verbose) {
        echo sprintf("  Posting: %s\n", $subject);
        echo sprintf("    Echo: %s @ %s\n", $feed['echoarea_tag'], $feed['echoarea_domain'] ?: '(blank)');
        if ($replyToId !== null) {
            echo sprintf("    Threading as reply to message ID: %d\n", $replyToId);
        }
    }

    $messageId = $messageHandler->postEchomail(
        $userId,
        $feed['echoarea_tag'],
        $feed['echoarea_domain'] ?: '',
        'All',
        $subject,
        $body,
        $replyToId, // null unless threading detected a parent
        'BinktermPHP Auto Feed', // tagline
        false,      // skipCredits
        null,       // markupType
        '',         // prependKludges
        'BinktermPHP Auto Feed' // tearlineComponent
    );

    // Increment posted article counter
    $stmt = $db->prepare("
        UPDATE auto_feed_sources
        SET articles_posted = articles_posted + 1,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$feed['id']]);
}

/**
 * Build the FTN subject line for an auto-feed article.
 *
 * @param array $feed Feed configuration
 * @param array $article Article data
 * @return string
 */
function buildArticleSubject(array $feed, array $article): string
{
    $title = trim((string)($article['title'] ?? ''));

    if (!empty($feed['include_feed_name_in_subject'])) {
        $feedName = trim((string)($feed['feed_name'] ?? ''));
        if ($feedName !== '') {
            $subject = $title !== '' ? '[' . $feedName . '] ' . $title : '[' . $feedName . ']';
            return truncate($subject, 72);
        }
    }

    return truncate($title, 72);
}

/**
 * Format article into message body
 *
 * @param array $article Article data
 * @return string Formatted message
 */
function formatArticleMessage($article) {
    $body = '';

    // Add title (if not already in subject)
    if (!empty($article['title'])) {
        $body .= wordwrap($article['title'], 79) . "\n";
        $body .= str_repeat('=', min(strlen($article['title']), 79)) . "\n\n";
    }

    if (($article['sourceType'] ?? '') === 'bluesky' && !empty($article['author'])) {
        $body .= "Posted by: " . $article['author'] . "\n\n";
    }

    // Add description/summary
    if (!empty($article['description'])) {
        // Strip HTML tags
        $description = strip_tags($article['description']);

        // Decode HTML entities
        $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Wrap text to 79 characters
        $description = wordwrap(trim($description), 79);

        $body .= $description . "\n\n";
    }

    if (!empty($article['mediaUrls']) && is_array($article['mediaUrls'])) {
        $body .= "Media:\n";
        foreach ($article['mediaUrls'] as $mediaUrl) {
            $body .= $mediaUrl . "\n";
        }
        $body .= "\n";
    }

    // Add link
    if (!empty($article['link'])) {
        $label = (($article['sourceType'] ?? '') === 'bluesky') ? 'View post' : 'Read more';
        $body .= $label . ": " . $article['link'] . "\n";
    }

    // Add publication date
    if (!empty($article['pubDate'])) {
        $timestamp = strtotime($article['pubDate']);
        if ($timestamp) {
            $body .= "\nPublished: " . date('Y-m-d H:i:s T', $timestamp) . "\n";
        }
    }

    return $body;
}

/**
 * Update last check timestamp for feed
 *
 * @param PDO $db Database connection
 * @param int $feedId Feed ID
 */
function updateLastCheck($db, $feedId) {
    $stmt = $db->prepare("
        UPDATE auto_feed_sources
        SET last_check = NOW(), last_error = NULL, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$feedId]);
}

/**
 * Truncate string to specified length
 *
 * @param string $str String to truncate
 * @param int $length Maximum length
 * @return string Truncated string
 */
function truncate($str, $length) {
    if (strlen($str) <= $length) {
        return $str;
    }
    return substr($str, 0, $length - 3) . '...';
}

/**
 * Show help message
 */
function showHelp() {
    echo <<<HELP
Auto Feed: Feed to Echoarea Poster

Usage:
  php rss_poster.php [options]

Options:
  --feed-id=<id>   Check only the specified feed ID
  --force          Force check even if recently checked
  --verbose        Show detailed output
  --help           Show this help message

Examples:
  php rss_poster.php                # Check all active feeds
  php rss_poster.php --verbose      # Check with detailed output
  php rss_poster.php --feed-id=1    # Check only feed #1
  php rss_poster.php --force        # Force immediate check

Configuration:
  Feeds are configured via the Admin -> Auto Feed web interface.
  Feeds are stored in the auto_feed_sources database table.

Cron Example:
  */30 * * * * cd /path/to/binktest && php scripts/rss_poster.php >> data/logs/auto_feed.log 2>&1

HELP;
}
