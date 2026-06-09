<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Database;
use BinktermPHP\EchoareaSubscriptionManager;
use BinktermPHP\MessageHandler;

function parseArgs(array $argv): array
{
    $options = [
        'userId' => null,
        'page' => 1,
        'sort' => 'date_desc',
        'filter' => 'all',
        'limit' => null,
        'query' => 'all',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--user-id=(\d+)$/', $arg, $m)) {
            $options['userId'] = (int)$m[1];
        } elseif (preg_match('/^--page=(\d+)$/', $arg, $m)) {
            $options['page'] = max(1, (int)$m[1]);
        } elseif (preg_match('/^--sort=(date_desc|date_asc|subject|author)$/', $arg, $m)) {
            $options['sort'] = $m[1];
        } elseif (preg_match('/^--filter=(all|unread|read|tome|saved)$/', $arg, $m)) {
            $options['filter'] = $m[1];
        } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
            $options['limit'] = max(1, (int)$m[1]);
        } elseif (preg_match('/^--query=(all|page|total|unread)$/', $arg, $m)) {
            $options['query'] = $m[1];
        } elseif ($arg === '--help' || $arg === '-h') {
            echo "Usage: php tests/explain_echomail_list_queries.php [options]\n";
            echo "  --user-id=N                 Override sysop user lookup\n";
            echo "  --page=N                    Page number (default: 1)\n";
            echo "  --sort=date_desc|date_asc|subject|author\n";
            echo "  --filter=all|unread|read|tome|saved\n";
            echo "  --limit=N                   Override messages_per_page\n";
            echo "  --query=all|page|total|unread\n";
            exit(0);
        }
    }

    return $options;
}

function printSection(string $title): void
{
    echo "\n" . str_repeat('=', 78) . "\n";
    echo $title . "\n";
    echo str_repeat('=', 78) . "\n";
}

function fetchOne(PDO $db, string $sql, array $params = []): ?array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function explain(PDO $db, string $sql, array $params): string
{
    $stmt = $db->prepare('EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT) ' . $sql);
    $stmt->execute($params);
    $lines = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    return implode(PHP_EOL, $lines);
}

function resolveSysopUserId(PDO $db): int
{
    $sysopName = '';
    try {
        $sysopName = trim((string)BinkpConfig::getInstance()->getSystemSysop());
    } catch (Throwable $e) {
        $sysopName = '';
    }

    if ($sysopName !== '') {
        $row = fetchOne(
            $db,
            "SELECT id
             FROM users
             WHERE is_admin = TRUE
               AND (LOWER(username) = LOWER(?) OR LOWER(real_name) = LOWER(?))
             ORDER BY id
             LIMIT 1",
            [$sysopName, $sysopName]
        );
        if ($row) {
            return (int)$row['id'];
        }
    }

    $row = fetchOne(
        $db,
        "SELECT id
         FROM users
         WHERE is_admin = TRUE
         ORDER BY id
         LIMIT 1"
    );
    if ($row) {
        return (int)$row['id'];
    }

    throw new RuntimeException('No sysop/admin user found.');
}

function buildFilterState(MessageHandler $handler, PDO $db, int $userId, string $filter): array
{
    $filterClause = '';
    $filterParams = [];

    if ($filter === 'unread') {
        $filterClause = ' AND mrs.read_at IS NULL';
    } elseif ($filter === 'read') {
        $filterClause = ' AND mrs.read_at IS NOT NULL';
    } elseif ($filter === 'tome') {
        $user = fetchOne($db, 'SELECT username, real_name FROM users WHERE id = ?', [$userId]);
        if ($user) {
            $filterClause = ' AND (LOWER(em.to_name) = LOWER(?) OR LOWER(em.to_name) = LOWER(?))';
            $filterParams[] = $user['username'];
            $filterParams[] = $user['real_name'];
        }
    } elseif ($filter === 'saved') {
        $filterClause = ' AND sav.id IS NOT NULL';
    }

    $filterClause .= " AND (em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC'))";
    $ignoreFilter = $handler->buildEchomailIgnoreFilter($userId, 'em');
    $filterClause .= $ignoreFilter['sql'];
    $moderationFilter = $handler->buildModerationVisibilityFilter($userId, 'em');
    $filterClause .= $moderationFilter['sql'];
    foreach ($moderationFilter['params'] as $param) {
        $filterParams[] = $param;
    }

    return [
        'filterClause' => $filterClause,
        'filterParams' => $filterParams,
        'ignoreFilter' => $ignoreFilter,
    ];
}

$options = parseArgs($argv ?? []);

$db = Database::getInstance()->getPdo();
$handler = new MessageHandler();
$subscriptionManager = new EchoareaSubscriptionManager();

$userId = $options['userId'] ?? resolveSysopUserId($db);
$userRow = fetchOne($db, 'SELECT id, username, real_name, is_admin FROM users WHERE id = ?', [$userId]);
if (!$userRow) {
    fwrite(STDERR, "User {$userId} not found.\n");
    exit(1);
}

$subscriptions = $subscriptionManager->getUserSubscribedEchoareas($userId);
$echoareaIds = array_map(static fn(array $row): int => (int)$row['id'], $subscriptions);

if (empty($echoareaIds)) {
    fwrite(STDERR, "User {$userId} has no subscribed echoareas.\n");
    exit(1);
}

$limit = $options['limit'];
if ($limit === null) {
    $settings = $handler->getUserSettings($userId);
    $limit = (int)($settings['messages_per_page'] ?? 25);
}

$page = $options['page'];
$offset = ($page - 1) * $limit;
$sort = $options['sort'];
$filter = $options['filter'];
$echoPlaceholders = implode(',', array_fill(0, count($echoareaIds), '?'));

$dateField = 'date_received';
$orderBy = match ($sort) {
    'date_asc' => "em.{$dateField} ASC",
    'subject' => 'em.subject ASC',
    'author' => 'em.from_name ASC',
    default => "CASE WHEN em.{$dateField} > NOW() THEN 0 ELSE 1 END, em.{$dateField} DESC",
};

$filterState = buildFilterState($handler, $db, $userId, $filter);
$filterClause = $filterState['filterClause'];
$filterParams = $filterState['filterParams'];
$ignoreFilter = $filterState['ignoreFilter'];

$pageSql = "
    SELECT em.id, em.from_name, em.from_address, em.to_name,
           em.subject, em.date_received, em.date_written, em.echoarea_id,
           em.message_id, em.reply_to_id,
           ea.tag AS echoarea, ea.color AS echoarea_color, ea.domain AS echoarea_domain,
           COALESCE(NULLIF(em.art_format, ''), NULLIF(ea.art_format_hint, '')) AS art_format,
           CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END AS is_read,
           CASE WHEN EXISTS (
               SELECT 1
               FROM shared_messages
               WHERE message_id = em.id
                 AND message_type = 'echomail'
                 AND is_active = TRUE
                 AND (expires_at IS NULL OR expires_at > NOW())
           ) THEN 1 ELSE 0 END AS is_shared,
           CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END AS is_saved
    FROM echomail em
    JOIN echoareas ea ON em.echoarea_id = ea.id
    LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
    LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
    WHERE ea.id IN ({$echoPlaceholders}) AND ea.is_active = TRUE{$filterClause}
    ORDER BY {$orderBy}
    LIMIT ? OFFSET ?
";
$pageParams = [$userId, $userId];
$pageParams = array_merge($pageParams, $echoareaIds, $filterParams, $ignoreFilter['params'], [$limit, $offset]);

$totalSql = "
    SELECT COUNT(*) AS total
    FROM echomail em
    JOIN echoareas ea ON em.echoarea_id = ea.id
    LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
    LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
    WHERE ea.id IN ({$echoPlaceholders}) AND ea.is_active = TRUE{$filterClause}
";
$totalParams = [$userId, $userId];
$totalParams = array_merge($totalParams, $echoareaIds, $filterParams, $ignoreFilter['params']);

$unreadSql = "
    SELECT COUNT(*) AS count
    FROM echomail em
    JOIN echoareas ea ON em.echoarea_id = ea.id
    LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
    WHERE ea.id IN ({$echoPlaceholders}) AND ea.is_active = TRUE
      AND mrs.read_at IS NULL
      AND (em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC')){$ignoreFilter['sql']}
";
$unreadParams = [$userId];
$unreadParams = array_merge($unreadParams, $echoareaIds, $ignoreFilter['params']);

echo "Echomail List Query Explain Helper\n";
echo "User: {$userRow['id']} ({$userRow['username']} / {$userRow['real_name']})\n";
echo "Admin: " . (!empty($userRow['is_admin']) ? 'yes' : 'no') . "\n";
echo "Subscribed echoareas: " . count($echoareaIds) . "\n";
echo "Filter: {$filter}\n";
echo "Sort: {$sort}\n";
echo "Page: {$page}\n";
echo "Limit: {$limit}\n";
echo "Query selection: {$options['query']}\n";

if ($options['query'] === 'all' || $options['query'] === 'page') {
    printSection('PAGE QUERY');
    echo explain($db, $pageSql, $pageParams) . PHP_EOL;
}

if ($options['query'] === 'all' || $options['query'] === 'total') {
    printSection('TOTAL COUNT QUERY');
    echo explain($db, $totalSql, $totalParams) . PHP_EOL;
}

if ($options['query'] === 'all' || $options['query'] === 'unread') {
    printSection('UNREAD COUNT QUERY');
    echo explain($db, $unreadSql, $unreadParams) . PHP_EOL;
}
