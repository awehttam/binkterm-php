#!/usr/bin/env php
<?php
/**
 * Auto Feed: RSS to Echoarea Poster
 *
 * Monitors RSS feeds and posts new articles to specified echoareas.
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
echo str_repeat('-', 60) . "\n";

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

    // Fetch RSS feed
    $xml = fetchRssFeed($feed['feed_url']);

    // Parse feed
    $articles = parseRssFeed($xml);

    if (empty($articles)) {
        if ($verbose) {
            echo sprintf("[Feed #%d] No articles found in feed\n", $feed['id']);
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
            echo sprintf("[Feed #%d] No new articles\n", $feed['id']);
        }
        updateLastCheck($db, $feed['id']);
        return 0;
    }

    // Post new articles to echoarea
    $posted = 0;
    foreach ($newArticles as $article) {
        try {
            postArticleToEchoarea($db, $messageHandler, $feed, $article, $verbose);
            $posted++;
        } catch (Exception $e) {
            echo sprintf("[Feed #%d] Failed to post article '%s': %s\n",
                $feed['id'], substr($article['title'], 0, 50), $e->getMessage());
        }
    }

    // Update last check time
    updateLastCheck($db, $feed['id']);

    return $posted;
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
            'user_agent' => 'BinktermPHP RSS Poster/1.0'
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

    // Detect feed type (RSS 2.0, Atom, etc.)
    if (isset($xml->channel->item)) {
        // RSS 2.0
        foreach ($xml->channel->item as $item) {
            $articles[] = [
                'guid' => (string)($item->guid ?? $item->link),
                'title' => (string)$item->title,
                'link' => (string)$item->link,
                'description' => (string)($item->description ?? ''),
                'pubDate' => (string)($item->pubDate ?? '')
            ];
        }
    } elseif (isset($xml->entry)) {
        // Atom
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
    $subject = truncate($article['title'], 72); // FTN subject line limit

    if ($verbose) {
        echo sprintf("  Posting: %s\n", $subject);
        echo sprintf("    Echo: %s @ %s\n", $feed['echoarea_tag'], $feed['echoarea_domain'] ?: '(blank)');
    }

    $messageId = $messageHandler->postEchomail(
        $userId,
        $feed['echoarea_tag'],
        $feed['echoarea_domain'] ?: '',
        'All',
        $subject,
        $body,
        null,
        'Auto Feed RSS'
    );

    // Update last posted article GUID and increment counter
    $stmt = $db->prepare("
        UPDATE auto_feed_sources
        SET last_article_guid = ?,
            articles_posted = articles_posted + 1,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$article['guid'], $feed['id']]);
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
        $body .= str_repeat('-', min(strlen($article['title']), 79)) . "\n\n";
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

    // Add link
    if (!empty($article['link'])) {
        $body .= "Read more: " . $article['link'] . "\n";
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
Auto Feed: RSS/Atom to Echoarea Poster

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
