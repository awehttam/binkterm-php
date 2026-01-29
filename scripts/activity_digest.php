#!/usr/bin/php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Config;
use BinktermPHP\Database;
use BinktermPHP\Binkp\Config\BinkpConfig;

function showUsage()
{
    echo "Usage: php scripts/activity_digest.php [options]\n\n";
    echo "Options:\n";
    echo "  --since=PERIOD       Relative period (default: 30d). Examples: 12h, 7d, 2w, 1mo\n";
    echo "  --from=YYYY-MM-DD    Start date (overrides --since)\n";
    echo "  --to=YYYY-MM-DD      End date (overrides --since, defaults to today)\n";
    echo "  --format=TYPE        Output format: ascii or ansi (default: ascii)\n";
    echo "  --output=FILE        Output file path (optional)\n";
    echo "  --help               Show this help message\n\n";
}

function parseArgs($argv)
{
    $args = [];
    foreach ($argv as $arg) {
        if (strpos($arg, '--') === 0) {
            if (strpos($arg, '=') !== false) {
                list($key, $value) = explode('=', substr($arg, 2), 2);
                $args[$key] = $value;
            } else {
                $args[substr($arg, 2)] = true;
            }
        }
    }
    return $args;
}

function parseSincePeriod(string $period): DateTime
{
    $period = trim($period);
    if ($period === '') {
        throw new RuntimeException('Invalid period');
    }

    if (!preg_match('/^(\d+)(s|m|h|d|w|mo|y)$/i', $period, $matches)) {
        throw new RuntimeException('Invalid period format');
    }

    $value = (int)$matches[1];
    $unit = strtolower($matches[2]);
    $now = new DateTime('now');

    switch ($unit) {
        case 's':
            return $now->sub(new DateInterval('PT' . $value . 'S'));
        case 'm':
            return $now->sub(new DateInterval('PT' . $value . 'M'));
        case 'h':
            return $now->sub(new DateInterval('PT' . $value . 'H'));
        case 'd':
            return $now->sub(new DateInterval('P' . $value . 'D'));
        case 'w':
            return $now->sub(new DateInterval('P' . ($value * 7) . 'D'));
        case 'mo':
            return $now->sub(new DateInterval('P' . $value . 'M'));
        case 'y':
            return $now->sub(new DateInterval('P' . $value . 'Y'));
        default:
            throw new RuntimeException('Unsupported period unit');
    }
}

function formatAnsi($text, $code)
{
    return "\033[" . $code . "m" . $text . "\033[0m";
}

function buildDigest($format, $data, DateTime $start, DateTime $end)
{
    $isAnsi = $format === 'ansi';
    $lines = [];
    $title = BinkpConfig::getInstance()->getSystemName() . ' Activity Digest';
    $range = $start->format('Y-m-d') . ' to ' . $end->format('Y-m-d');

    if ($isAnsi) {
        $lines[] = formatAnsi($title, '1;36');
        $lines[] = formatAnsi("Period: {$range}", '0;37');
    } else {
        $lines[] = $title;
        $lines[] = "Period: {$range}";
    }

    $lines[] = '';

    $sectionTitle = $isAnsi ? formatAnsi('Highlights', '1;33') : 'Highlights';
    $lines[] = $sectionTitle;
    $lines[] = "- Netmail received: {$data['netmail_received']}";
    $lines[] = "- Echomail received: {$data['echomail_received']}";
    $lines[] = "- Chat messages: {$data['chat_messages']}";
    $lines[] = "- Shoutbox posts: {$data['shoutbox_posts']}";
    $lines[] = "- New polls: {$data['new_polls']}";
    $lines[] = "- New users: {$data['new_users']}";
    $lines[] = '';

    $sectionTitle = $isAnsi ? formatAnsi('Active Polls', '1;33') : 'Active Polls';
    $lines[] = $sectionTitle;
    if (empty($data['poll_questions'])) {
        $lines[] = '- None';
    } else {
        foreach ($data['poll_questions'] as $question) {
            $lines[] = "- {$question}";
        }
    }
    $lines[] = '';

    $sectionTitle = $isAnsi ? formatAnsi('Top Echo Areas', '1;33') : 'Top Echo Areas';
    $lines[] = $sectionTitle;
    if (empty($data['top_echoareas'])) {
        $lines[] = '- None';
    } else {
        foreach ($data['top_echoareas'] as $area) {
            $lines[] = "- {$area['tag']}: {$area['count']} posts";
        }
    }
    $lines[] = '';

    $sectionTitle = $isAnsi ? formatAnsi('Top Shoutbox Contributors', '1;33') : 'Top Shoutbox Contributors';
    $lines[] = $sectionTitle;
    if (empty($data['top_shoutbox_users'])) {
        $lines[] = '- None';
    } else {
        foreach ($data['top_shoutbox_users'] as $user) {
            $lines[] = "- {$user['username']}: {$user['count']} shouts";
        }
    }
    $lines[] = '';

    $sectionTitle = $isAnsi ? formatAnsi('Game Leaderboard', '1;33') : 'Game Leaderboard';
    $lines[] = $sectionTitle;
    if (empty($data['game_leaderboard'])) {
        $lines[] = '- None';
    } else {
        foreach ($data['game_leaderboard'] as $entry) {
            $lines[] = "- {$entry['username']} - {$entry['game_id']} ({$entry['board']}): {$entry['score']}";
        }
    }

    return implode("\n", $lines) . "\n";
}

function fetchDigestData(DateTime $start, DateTime $end): array
{
    $db = Database::getInstance()->getPdo();
    $startStr = $start->format('Y-m-d H:i:s');
    $endStr = $end->format('Y-m-d H:i:s');

    $netmailStmt = $db->prepare("SELECT COUNT(*) as count FROM netmail WHERE date_received >= ? AND date_received < ?");
    $netmailStmt->execute([$startStr, $endStr]);
    $netmailReceived = (int)($netmailStmt->fetch()['count'] ?? 0);

    $echomailStmt = $db->prepare("SELECT COUNT(*) as count FROM echomail WHERE date_received >= ? AND date_received < ?");
    $echomailStmt->execute([$startStr, $endStr]);
    $echomailReceived = (int)($echomailStmt->fetch()['count'] ?? 0);

    $chatStmt = $db->prepare("SELECT COUNT(*) as count FROM chat_messages WHERE created_at >= ? AND created_at < ?");
    $chatStmt->execute([$startStr, $endStr]);
    $chatMessages = (int)($chatStmt->fetch()['count'] ?? 0);

    $shoutStmt = $db->prepare("SELECT COUNT(*) as count FROM shoutbox_messages WHERE created_at >= ? AND created_at < ?");
    $shoutStmt->execute([$startStr, $endStr]);
    $shoutboxPosts = (int)($shoutStmt->fetch()['count'] ?? 0);

    $pollStmt = $db->prepare("SELECT COUNT(*) as count FROM polls WHERE created_at >= ? AND created_at < ?");
    $pollStmt->execute([$startStr, $endStr]);
    $newPolls = (int)($pollStmt->fetch()['count'] ?? 0);

    $userStmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE created_at >= ? AND created_at < ?");
    $userStmt->execute([$startStr, $endStr]);
    $newUsers = (int)($userStmt->fetch()['count'] ?? 0);

    $pollQuestionsStmt = $db->prepare("
        SELECT question
        FROM polls
        WHERE is_active = TRUE
        ORDER BY created_at ASC
        LIMIT 10
    ");
    $pollQuestionsStmt->execute();
    $pollQuestions = array_map(function($row) {
        return $row['question'];
    }, $pollQuestionsStmt->fetchAll());

    $echoStmt = $db->prepare("
        SELECT e.tag, COUNT(*) as count
        FROM echomail em
        INNER JOIN echoareas e ON em.echoarea_id = e.id
        WHERE em.date_received >= ? AND em.date_received < ?
        GROUP BY e.tag
        ORDER BY count DESC
        LIMIT 5
    ");
    $echoStmt->execute([$startStr, $endStr]);
    $topEchoareas = $echoStmt->fetchAll();

    $shoutTopStmt = $db->prepare("
        SELECT u.username, COUNT(*) as count
        FROM shoutbox_messages s
        INNER JOIN users u ON s.user_id = u.id
        WHERE s.created_at >= ? AND s.created_at < ?
        GROUP BY u.username
        ORDER BY count DESC, u.username ASC
        LIMIT 10
    ");
    $shoutTopStmt->execute([$startStr, $endStr]);
    $topShoutboxUsers = $shoutTopStmt->fetchAll();

    $leaderboardStmt = $db->prepare("
        SELECT DISTINCT ON (l.user_id)
            u.username,
            l.game_id,
            l.board,
            l.score,
            l.created_at
        FROM webdoor_leaderboards l
        INNER JOIN users u ON l.user_id = u.id
        WHERE l.created_at >= ? AND l.created_at < ?
        ORDER BY l.user_id, l.score DESC, l.created_at DESC
    ");
    $leaderboardStmt->execute([$startStr, $endStr]);
    $leaderboardRaw = $leaderboardStmt->fetchAll();

    usort($leaderboardRaw, function($a, $b) {
        if ((int)$b['score'] === (int)$a['score']) {
            return strtotime($b['created_at']) <=> strtotime($a['created_at']);
        }
        return (int)$b['score'] <=> (int)$a['score'];
    });
    $gameLeaderboard = array_slice($leaderboardRaw, 0, 10);

    return [
        'netmail_received' => $netmailReceived,
        'echomail_received' => $echomailReceived,
        'chat_messages' => $chatMessages,
        'shoutbox_posts' => $shoutboxPosts,
        'new_polls' => $newPolls,
        'new_users' => $newUsers,
        'poll_questions' => $pollQuestions,
        'top_echoareas' => $topEchoareas,
        'top_shoutbox_users' => $topShoutboxUsers,
        'game_leaderboard' => $gameLeaderboard
    ];
}

$args = parseArgs($argv);

if (isset($args['help'])) {
    showUsage();
    exit(0);
}

try {
    $format = strtolower($args['format'] ?? 'ascii');
    if (!in_array($format, ['ascii', 'ansi'], true)) {
        throw new RuntimeException('Invalid format (use ascii or ansi)');
    }

    if (!empty($args['from'])) {
        $start = new DateTime($args['from'] . ' 00:00:00');
        $end = !empty($args['to']) ? new DateTime($args['to'] . ' 23:59:59') : new DateTime('now');
    } else {
        $since = $args['since'] ?? '30d';
        $start = parseSincePeriod($since);
        $end = new DateTime('now');
    }

    if ($start >= $end) {
        throw new RuntimeException('Start date must be before end date');
    }

    $data = fetchDigestData($start, $end);
    $digest = buildDigest($format, $data, $start, $end);

    $outputFile = $args['output'] ?? null;
    if ($outputFile) {
        if (@file_put_contents($outputFile, $digest) === false) {
            throw new RuntimeException('Failed to write output file');
        }
    } else {
        echo $digest;
    }

    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
