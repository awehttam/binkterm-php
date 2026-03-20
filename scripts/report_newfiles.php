#!/usr/bin/env php
<?php
/**
 * Report newly uploaded or hatched files from the past week by default.
 *
 * Usage:
 *   php scripts/report_newfiles.php
 *   php scripts/report_newfiles.php --since=14d
 *   php scripts/report_newfiles.php --days=30
 *   php scripts/report_newfiles.php --from=2026-03-01 --to=2026-03-20
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;

const MAX_FILES_PER_AREA = 20;

function printUsage(): void
{
    echo "Usage: php scripts/report_newfiles.php [options]\n\n";
    echo "Options:\n";
    echo "  --since=PERIOD       Relative period (default: 7d). Examples: 12h, 7d, 2w, 1mo\n";
    echo "  --days=N            Shortcut for --since=Nd\n";
    echo "  --from=YYYY-MM-DD   Start date (overrides --since/--days)\n";
    echo "  --to=YYYY-MM-DD     End date (used with --from, defaults to now)\n";
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

function parseSincePeriod(string $period): DateTimeImmutable
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
    $now = new DateTimeImmutable('now');

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

function parseDateArgument(string $value, bool $endOfDay = false): DateTimeImmutable
{
    $suffix = $endOfDay ? '23:59:59' : '00:00:00';
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', trim($value) . ' ' . $suffix);
    if (!$date) {
        throw new RuntimeException("Invalid date: {$value}");
    }

    return $date;
}

function buildWindow(array $args): array
{
    $now = new DateTimeImmutable('now');

    if (!empty($args['from'])) {
        $from = parseDateArgument((string)$args['from']);
        $to = !empty($args['to']) ? parseDateArgument((string)$args['to'], true) : $now;
    } else {
        $since = '7d';
        if (!empty($args['days'])) {
            $days = (int)$args['days'];
            if ($days <= 0) {
                throw new RuntimeException('--days must be greater than 0');
            }
            $since = $days . 'd';
        } elseif (!empty($args['since'])) {
            $since = (string)$args['since'];
        }

        $from = parseSincePeriod($since);
        $to = $now;
    }

    if ($from >= $to) {
        throw new RuntimeException('Start time must be earlier than end time');
    }

    return [$from, $to];
}

function fetchNewFiles(DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $db = Database::getInstance()->getPdo();
    $stmt = $db->prepare("
        SELECT
            f.id,
            f.filename,
            f.filesize,
            f.short_description,
            f.uploaded_from_address,
            f.source_type,
            f.created_at,
            fa.tag AS area_tag,
            fa.domain,
            u.username AS owner_username
        FROM files f
        JOIN file_areas fa ON fa.id = f.file_area_id
        LEFT JOIN users u ON u.id = f.owner_id
        WHERE f.status = 'approved'
          AND f.created_at >= ?
          AND f.created_at < ?
          AND fa.is_private = FALSE
          AND f.source_type IN ('fidonet', 'user_upload')
        ORDER BY f.created_at DESC, f.id DESC
    ");
    $stmt->execute([
        $from->format('Y-m-d H:i:sP'),
        $to->format('Y-m-d H:i:sP'),
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function formatSize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float)$bytes;
    $unitIndex = 0;

    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }

    if ($unitIndex === 0) {
        return (string)$bytes . ' ' . $units[$unitIndex];
    }

    return number_format($size, 2) . ' ' . $units[$unitIndex];
}

function classifySource(string $sourceType): string
{
    return $sourceType === 'fidonet' ? 'hatched' : 'uploaded';
}

function formatSourceName(array $row): string
{
    $source = trim((string)($row['uploaded_from_address'] ?? ''));
    if ($source !== '') {
        return $source;
    }

    $owner = trim((string)($row['owner_username'] ?? ''));
    if ($owner !== '') {
        return $owner;
    }

    return '-';
}

function formatTimestamp(string $value): string
{
    try {
        $date = new DateTimeImmutable($value);
        return $date->format('Y-m-d H:i');
    } catch (Throwable $e) {
        return $value;
    }
}

function groupRowsByArea(array $rows): array
{
    $grouped = [];

    foreach ($rows as $row) {
        $areaKey = (string)$row['area_tag'] . '@' . (string)$row['domain'];
        if (!isset($grouped[$areaKey])) {
            $grouped[$areaKey] = [
                'tag' => (string)$row['area_tag'],
                'domain' => (string)$row['domain'],
                'rows' => [],
                'total_bytes' => 0,
            ];
        }

        $grouped[$areaKey]['rows'][] = $row;
        $grouped[$areaKey]['total_bytes'] += (int)$row['filesize'];
    }

    return $grouped;
}

function printReport(array $rows, DateTimeImmutable $from, DateTimeImmutable $to): void
{
    echo "New Files Report\n";
    echo "From: " . $from->format('Y-m-d H:i:s T') . "\n";
    echo "To:   " . $to->format('Y-m-d H:i:s T') . "\n";
    echo "Total: " . count($rows) . "\n";
    echo "\n";

    if ($rows === []) {
        echo "No new hatched or uploaded files found.\n";
        return;
    }

    $grouped = groupRowsByArea($rows);

    foreach ($grouped as $area) {
        $header = ">Area : {$area['tag']}";
        if ($area['domain'] !== '') {
            $header .= " @ {$area['domain']}";
        }
        echo $header . "\n";
        echo str_repeat('-', 78) . "\n";
        echo sprintf("%-24s %10s  %s\n", 'Filename', 'Size', 'Description');
        echo str_repeat('-', 78) . "\n";

        $visibleRows = array_slice($area['rows'], 0, MAX_FILES_PER_AREA);

        foreach ($visibleRows as $row) {
            $description = trim((string)($row['short_description'] ?? ''));

            echo sprintf(
                "%-24s %10d  %s\n",
                (string)$row['filename'],
                (int)$row['filesize'],
                $description !== '' ? $description : '-'
            );
        }

        $remainingCount = count($area['rows']) - count($visibleRows);
        if ($remainingCount > 0) {
            echo "(... {$remainingCount} more new files found)\n";
        }

        echo str_repeat('-', 78) . "\n";
        echo $area['total_bytes'] . " bytes in " . count($area['rows']) . " file(s)\n";
        echo "\n";
    }
}

$args = parseArgs($argv);

if (isset($args['help'])) {
    printUsage();
    exit(0);
}

try {
    [$from, $to] = buildWindow($args);
    $rows = fetchNewFiles($from, $to);
    printReport($rows, $from, $to);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
