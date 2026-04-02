#!/usr/bin/env php
<?php
/**
 * Report echomail traffic over a configurable period.
 *
 * Usage:
 *   php scripts/echomail_stats.php
 *   php scripts/echomail_stats.php --days=30
 *   php scripts/echomail_stats.php --from=2026-03-01 --to=2026-03-31
 *   php scripts/echomail_stats.php --domain=fidonet
 *   php scripts/echomail_stats.php --area=GENERAL
 *   php scripts/echomail_stats.php --top=25
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;

const DEFAULT_DAYS = 30;
const DEFAULT_TOP = 50;

function printUsage(): void
{
    echo "Usage: php scripts/echomail_stats.php [options]\n\n";
    echo "Options:\n";
    echo "  --days=N            Look back N days (default: 30)\n";
    echo "  --from=YYYY-MM-DD   Start date (overrides --days)\n";
    echo "  --to=YYYY-MM-DD     End date (used with --from, defaults to now)\n";
    echo "  --domain=NAME       Limit to a single echomail domain\n";
    echo "  --area=TAG          Limit to a single echo area tag\n";
    echo "  --top=N             Show top N areas in the ranked table (default: 50)\n";
    echo "  --help              Show this help message\n";
}

function parseArgs(array $argv): array
{
    $args = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $arg = substr($arg, 2);
        if (strpos($arg, '=') !== false) {
            [$key, $value] = explode('=', $arg, 2);
            $args[$key] = $value;
        } else {
            $args[$arg] = true;
        }
    }

    return $args;
}

function parseDateArgument(string $value, bool $endOfDay = false): DateTimeImmutable
{
    $suffix = $endOfDay ? '23:59:59' : '00:00:00';
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', trim($value) . ' ' . $suffix, new DateTimeZone('UTC'));
    if (!$date) {
        throw new RuntimeException("Invalid date: {$value}");
    }

    return $date;
}

function buildWindow(array $args): array
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    if (!empty($args['from'])) {
        $from = parseDateArgument((string)$args['from']);
        $to = !empty($args['to']) ? parseDateArgument((string)$args['to'], true) : $now;
    } else {
        $days = isset($args['days']) ? (int)$args['days'] : DEFAULT_DAYS;
        if ($days <= 0) {
            throw new RuntimeException('--days must be greater than 0');
        }
        $from = $now->sub(new DateInterval('P' . $days . 'D'));
        $to = $now;
    }

    if ($from >= $to) {
        throw new RuntimeException('Start time must be earlier than end time');
    }

    return [$from, $to];
}

function buildFilters(array $args): array
{
    $where = [];
    $params = [];

    if (!empty($args['domain'])) {
        $where[] = "LOWER(COALESCE(ea.domain, '')) = LOWER(?)";
        $params[] = trim((string)$args['domain']);
    }

    if (!empty($args['area'])) {
        $where[] = 'LOWER(ea.tag) = LOWER(?)';
        $params[] = trim((string)$args['area']);
    }

    return [$where, $params];
}

function fetchOverview(DateTimeImmutable $from, DateTimeImmutable $to, array $where, array $params): array
{
    $db = Database::getInstance()->getPdo();
    $sql = "
        SELECT
            COUNT(*) AS total_messages,
            COUNT(*) FILTER (WHERE em.reply_to_id IS NULL) AS total_threads,
            COUNT(DISTINCT em.echoarea_id) AS active_areas,
            COUNT(DISTINCT COALESCE(ea.domain, '')) AS active_domains
        FROM echomail em
        INNER JOIN echoareas ea ON ea.id = em.echoarea_id
        WHERE em.date_received >= ?
          AND em.date_received < ?
    ";

    if ($where !== []) {
        $sql .= ' AND ' . implode(' AND ', $where);
    }

    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([
        $from->format('Y-m-d H:i:sP'),
        $to->format('Y-m-d H:i:sP'),
    ], $params));

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function fetchAreaStats(DateTimeImmutable $from, DateTimeImmutable $to, array $where, array $params, int $top): array
{
    $db = Database::getInstance()->getPdo();
    $sql = "
        SELECT
            ea.tag,
            COALESCE(ea.domain, '') AS domain,
            COUNT(*) AS messages,
            COUNT(*) FILTER (WHERE em.reply_to_id IS NULL) AS threads,
            COUNT(*) FILTER (WHERE em.reply_to_id IS NOT NULL) AS replies,
            COUNT(DISTINCT DATE(em.date_received AT TIME ZONE 'UTC')) AS active_days,
            MAX(em.date_received) AS last_received
        FROM echomail em
        INNER JOIN echoareas ea ON ea.id = em.echoarea_id
        WHERE em.date_received >= ?
          AND em.date_received < ?
    ";

    if ($where !== []) {
        $sql .= ' AND ' . implode(' AND ', $where);
    }

    $sql .= "
        GROUP BY ea.id, ea.tag, ea.domain
        ORDER BY messages DESC, threads DESC, ea.tag ASC
        LIMIT ?
    ";

    $stmt = $db->prepare($sql);
    $execParams = array_merge([
        $from->format('Y-m-d H:i:sP'),
        $to->format('Y-m-d H:i:sP'),
    ], $params, [$top]);
    $stmt->execute($execParams);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchDailyStats(DateTimeImmutable $from, DateTimeImmutable $to, array $where, array $params): array
{
    $db = Database::getInstance()->getPdo();
    $sql = "
        SELECT
            DATE(em.date_received AT TIME ZONE 'UTC') AS day,
            COUNT(*) AS messages,
            COUNT(*) FILTER (WHERE em.reply_to_id IS NULL) AS threads
        FROM echomail em
        INNER JOIN echoareas ea ON ea.id = em.echoarea_id
        WHERE em.date_received >= ?
          AND em.date_received < ?
    ";

    if ($where !== []) {
        $sql .= ' AND ' . implode(' AND ', $where);
    }

    $sql .= "
        GROUP BY day
        ORDER BY day ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([
        $from->format('Y-m-d H:i:sP'),
        $to->format('Y-m-d H:i:sP'),
    ], $params));

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function formatTimestamp(?string $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    try {
        return (new DateTimeImmutable($value))->format('Y-m-d');
    } catch (Throwable $e) {
        return $value;
    }
}

function formatAreaName(array $row): string
{
    $label = (string)$row['tag'];
    $domain = trim((string)($row['domain'] ?? ''));
    if ($domain !== '') {
        $label .= '@' . $domain;
    }

    return $label;
}

function printOverview(array $overview, DateTimeImmutable $from, DateTimeImmutable $to): void
{
    $days = max(1, (int)$from->diff($to)->days);
    $messages = (int)($overview['total_messages'] ?? 0);
    $threads = (int)($overview['total_threads'] ?? 0);

    echo "Echomail Traffic Report\n";
    echo "From: " . $from->format('Y-m-d H:i:s T') . "\n";
    echo "To:   " . $to->format('Y-m-d H:i:s T') . "\n";
    echo "Days: {$days}\n";
    echo "\n";
    echo "Total messages : {$messages}\n";
    echo "New threads    : {$threads}\n";
    echo "Replies        : " . max(0, $messages - $threads) . "\n";
    echo "Active areas   : " . (int)($overview['active_areas'] ?? 0) . "\n";
    echo "Active domains : " . (int)($overview['active_domains'] ?? 0) . "\n";
    echo "Msgs / day     : " . number_format($messages / $days, 1) . "\n";
    echo "Threads / day  : " . number_format($threads / $days, 1) . "\n";
    echo "\n";
}

function printAreaTable(array $rows, int $days): void
{
    echo "By Echo Area\n";
    echo str_repeat('-', 94) . "\n";
    echo sprintf("%-28s %8s %8s %8s %8s %10s %10s\n", 'Area', 'Msgs', 'Threads', 'Replies', 'ActDays', 'Msgs/Day', 'Last Msg');
    echo str_repeat('-', 94) . "\n";

    if ($rows === []) {
        echo "No echomail activity found in the selected window.\n\n";
        return;
    }

    foreach ($rows as $row) {
        $messages = (int)$row['messages'];
        echo sprintf(
            "%-28s %8d %8d %8d %8d %10.1f %10s\n",
            mb_strimwidth(formatAreaName($row), 0, 28, '…'),
            $messages,
            (int)$row['threads'],
            (int)$row['replies'],
            (int)$row['active_days'],
            $messages / max(1, $days),
            formatTimestamp($row['last_received'] ?? null)
        );
    }

    echo "\n";
}

function printDailyTable(array $rows): void
{
    echo "Daily Totals\n";
    echo str_repeat('-', 40) . "\n";
    echo sprintf("%-12s %10s %10s\n", 'Day', 'Messages', 'Threads');
    echo str_repeat('-', 40) . "\n";

    if ($rows === []) {
        echo "No daily traffic to report.\n\n";
        return;
    }

    foreach ($rows as $row) {
        echo sprintf(
            "%-12s %10d %10d\n",
            (string)$row['day'],
            (int)$row['messages'],
            (int)$row['threads']
        );
    }

    echo "\n";
}

$args = parseArgs($argv);

if (isset($args['help'])) {
    printUsage();
    exit(0);
}

try {
    [$from, $to] = buildWindow($args);
    [$where, $params] = buildFilters($args);
    $top = isset($args['top']) ? (int)$args['top'] : DEFAULT_TOP;
    if ($top <= 0) {
        throw new RuntimeException('--top must be greater than 0');
    }

    $overview = fetchOverview($from, $to, $where, $params);
    $areaRows = fetchAreaStats($from, $to, $where, $params, $top);
    $dailyRows = fetchDailyStats($from, $to, $where, $params);
    $days = max(1, (int)$from->diff($to)->days);

    printOverview($overview, $from, $to);
    printAreaTable($areaRows, $days);
    printDailyTable($dailyRows);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
