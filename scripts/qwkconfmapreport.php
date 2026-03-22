#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;

main($argv);

function main(array $argv): void
{
    $args = parseArgs($argv);

    if (!empty($args['help'])) {
        printUsage();
        exit(0);
    }

    $db = Database::getInstance()->getPdo();

    $params = [];
    $where  = [];

    if ($args['user_id'] !== null) {
        $where[] = 'q.user_id = :user_id';
        $params[':user_id'] = $args['user_id'];
    }

    if ($args['username'] !== null) {
        $where[] = 'LOWER(u.username) = LOWER(:username)';
        $params[':username'] = $args['username'];
    }

    $sql = "
        SELECT q.id,
               q.user_id,
               q.downloaded_at,
               q.message_count,
               q.packet_size,
               q.conference_map,
               u.username,
               u.real_name
        FROM qwk_download_log q
        LEFT JOIN users u ON u.id = q.user_id
    ";

    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY q.user_id ASC, q.downloaded_at ASC, q.id ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        fwrite(STDOUT, "No QWK download log rows matched the requested filters.\n");
        exit(0);
    }

    $report = buildReport($rows, !empty($args['changes_only']));
    renderReport($report, !empty($args['changes_only']));
}

function parseArgs(array $argv): array
{
    $args = [
        'help' => false,
        'user_id' => null,
        'username' => null,
        'changes_only' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $args['help'] = true;
            continue;
        }

        if ($arg === '--changes-only') {
            $args['changes_only'] = true;
            continue;
        }

        if (str_starts_with($arg, '--user-id=')) {
            $value = substr($arg, strlen('--user-id='));
            if ($value === '' || !ctype_digit($value)) {
                fwrite(STDERR, "Invalid --user-id value.\n");
                exit(2);
            }
            $args['user_id'] = (int)$value;
            continue;
        }

        if (str_starts_with($arg, '--username=')) {
            $value = trim(substr($arg, strlen('--username=')));
            if ($value === '') {
                fwrite(STDERR, "Invalid --username value.\n");
                exit(2);
            }
            $args['username'] = $value;
            continue;
        }

        fwrite(STDERR, "Unknown option: {$arg}\n");
        printUsage();
        exit(2);
    }

    return $args;
}

function printUsage(): void
{
    echo <<<TXT
QWK conference map history report

Usage:
  php scripts/qwkconfmapreport.php [--user-id=N] [--username=NAME] [--changes-only]

Options:
  --user-id=N       Limit report to one user id
  --username=NAME   Limit report to one username (case-insensitive)
  --changes-only    Show only conference entries whose number changed
  --help, -h        Show this help

Examples:
  php scripts/qwkconfmapreport.php
  php scripts/qwkconfmapreport.php --username=sysop
  php scripts/qwkconfmapreport.php --user-id=42 --changes-only

TXT;
}

function buildReport(array $rows, bool $changesOnly): array
{
    $report = [];

    foreach ($rows as $row) {
        $userId = (int)$row['user_id'];
        if (!isset($report[$userId])) {
            $report[$userId] = [
                'user_id' => $userId,
                'username' => $row['username'] ?? '(unknown)',
                'real_name' => $row['real_name'] ?? '',
                'downloads' => [],
                'changes' => 0,
                'areas_changed' => [],
                'previous_numbers' => [],
            ];
        }

        $map = json_decode((string)$row['conference_map'], true);
        if (!is_array($map)) {
            $map = [];
        }

        ksort($map, SORT_NUMERIC);
        $entries = [];

        foreach ($map as $conferenceNumberRaw => $conf) {
            if (!is_array($conf)) {
                continue;
            }

            $conferenceNumber = (int)$conferenceNumberRaw;
            $areaKey = buildAreaKey($conf, $conferenceNumber);
            $display = buildAreaDisplayName($conf, $conferenceNumber);
            $previous = $report[$userId]['previous_numbers'][$areaKey] ?? null;
            $changed = $previous !== null && $previous !== $conferenceNumber;

            if ($changed) {
                $report[$userId]['changes']++;
                $report[$userId]['areas_changed'][$areaKey] = true;
            }

            if (!$changesOnly || $changed) {
                $entries[] = [
                    'conference_number' => $conferenceNumber,
                    'previous_number' => $previous,
                    'changed' => $changed,
                    'area_key' => $areaKey,
                    'display' => $display,
                    'echoarea_id' => isset($conf['echoarea_id']) ? (int)$conf['echoarea_id'] : null,
                    'tag' => (string)($conf['tag'] ?? ''),
                    'domain' => (string)($conf['domain'] ?? ''),
                    'is_netmail' => !empty($conf['is_netmail']),
                ];
            }

            $report[$userId]['previous_numbers'][$areaKey] = $conferenceNumber;
        }

        if (!$changesOnly || !empty($entries)) {
            $report[$userId]['downloads'][] = [
                'id' => (int)$row['id'],
                'downloaded_at' => $row['downloaded_at'],
                'message_count' => (int)$row['message_count'],
                'packet_size' => (int)$row['packet_size'],
                'entries' => $entries,
            ];
        }
    }

    foreach ($report as $userId => $userReport) {
        unset($report[$userId]['previous_numbers']);
        $report[$userId]['areas_changed_count'] = count($report[$userId]['areas_changed']);
    }

    return $report;
}

function buildAreaKey(array $conf, int $conferenceNumber): string
{
    if (!empty($conf['is_netmail'])) {
        return 'netmail';
    }

    if (isset($conf['echoarea_id']) && $conf['echoarea_id'] !== null && $conf['echoarea_id'] !== '') {
        return 'echoarea_id:' . (int)$conf['echoarea_id'];
    }

    $tag = strtoupper(trim((string)($conf['tag'] ?? '')));
    $domain = strtoupper(trim((string)($conf['domain'] ?? '')));
    if ($tag !== '') {
        return $domain !== '' ? $tag . '@' . $domain : $tag;
    }

    $name = strtoupper(trim((string)($conf['name'] ?? '')));
    if ($name !== '') {
        return 'name:' . $name;
    }

    return 'conf:' . $conferenceNumber;
}

function buildAreaDisplayName(array $conf, int $conferenceNumber): string
{
    if (!empty($conf['is_netmail'])) {
        return 'Personal Mail';
    }

    $tag = strtoupper(trim((string)($conf['tag'] ?? '')));
    $domain = strtoupper(trim((string)($conf['domain'] ?? '')));
    if ($tag !== '') {
        return $domain !== '' ? $tag . '@' . $domain : $tag;
    }

    $name = trim((string)($conf['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    return 'Conference ' . $conferenceNumber;
}

function renderReport(array $report, bool $changesOnly): void
{
    $totalUsers = count($report);
    $totalDownloads = 0;
    $totalChanges = 0;

    foreach ($report as $userReport) {
        $totalDownloads += count($userReport['downloads']);
        $totalChanges += $userReport['changes'];
    }

    echo 'QWK Conference Map Report' . PHP_EOL;
    echo 'Users: ' . $totalUsers . PHP_EOL;
    echo 'Downloads: ' . $totalDownloads . PHP_EOL;
    echo 'Conference-number changes detected: ' . $totalChanges . PHP_EOL;
    echo 'Mode: ' . ($changesOnly ? 'changes-only' : 'full history') . PHP_EOL;

    foreach ($report as $userReport) {
        echo PHP_EOL;
        echo 'User #' . $userReport['user_id'] . ' ' . $userReport['username'];
        if ($userReport['real_name'] !== '') {
            echo ' (' . $userReport['real_name'] . ')';
        }
        echo PHP_EOL;
        echo 'Downloads: ' . count($userReport['downloads'])
            . ' | Changes: ' . $userReport['changes']
            . ' | Areas changed: ' . $userReport['areas_changed_count']
            . PHP_EOL;

        if (empty($userReport['downloads'])) {
            echo "  No matching download rows after filters.\n";
            continue;
        }

        foreach ($userReport['downloads'] as $download) {
            echo PHP_EOL;
            echo '  Download #' . $download['id']
                . ' at ' . $download['downloaded_at']
                . ' | messages=' . $download['message_count']
                . ' | packet_size=' . $download['packet_size']
                . PHP_EOL;

            if (empty($download['entries'])) {
                echo "    No conference entries matched.\n";
                continue;
            }

            foreach ($download['entries'] as $entry) {
                $line = '    '
                    . str_pad((string)$entry['conference_number'], 5, ' ', STR_PAD_LEFT)
                    . '  '
                    . $entry['display'];

                if ($entry['changed']) {
                    $line .= '  CHANGED from ' . $entry['previous_number'];
                } elseif ($entry['previous_number'] !== null) {
                    $line .= '  same as previous (' . $entry['previous_number'] . ')';
                } else {
                    $line .= '  first seen';
                }

                if ($entry['echoarea_id'] !== null) {
                    $line .= '  [echoarea_id=' . $entry['echoarea_id'] . ']';
                }

                echo $line . PHP_EOL;
            }
        }
    }
}
