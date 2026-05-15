#!/usr/bin/env php
<?php
/**
 * Output a report of interests, their member areas, and subscriber counts.
 *
 * Usage:
 *   php scripts/interests_report.php
 *   php scripts/interests_report.php --all
 *   php scripts/interests_report.php --help
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Config;
use BinktermPHP\InterestManager;

function printUsage(): void
{
    echo "Usage: php scripts/interests_report.php [options]\n\n";
    echo "Options:\n";
    echo "  --all     Include inactive interests\n";
    echo "  --help    Show this help message\n";
}

function parseArgs(array $argv): array
{
    $args = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $args[substr($arg, 2)] = true;
    }

    return $args;
}

function renderAreaName(array $member): string
{
    $name = (string)$member['tag'];
    $domain = trim((string)($member['domain'] ?? ''));
    if ($domain !== '') {
        $name .= '@' . $domain;
    }

    return $name;
}

function printInterestReport(array $report, bool $includeInactive): void
{
    echo "Interests Report\n";
    echo 'Feature enabled: ' . (Config::env('ENABLE_INTERESTS', 'true') === 'true' ? 'yes' : 'no') . "\n";
    echo 'Scope: ' . ($includeInactive ? 'all interests' : 'active interests only') . "\n";
    echo 'Total interests: ' . count($report) . "\n\n";

    if ($report === []) {
        echo "No interests found.\n";
        return;
    }

    foreach ($report as $interest) {
        $subscriberCount = (int)($interest['subscriber_count'] ?? 0);
        $echoareaCount = (int)($interest['echoarea_count'] ?? 0);
        $fileareaCount = (int)($interest['filearea_count'] ?? 0);
        $status = !empty($interest['is_active']) ? 'active' : 'inactive';
        $slug = trim((string)($interest['slug'] ?? ''));
        $description = trim((string)($interest['description'] ?? ''));

        echo $interest['name'] . " [{$status}]\n";
        if ($slug !== '') {
            echo "Slug: {$slug}\n";
        }
        if ($description !== '') {
            echo "Description: {$description}\n";
        }
        echo "Subscribers: {$subscriberCount} | Echo areas: {$echoareaCount} | File areas: {$fileareaCount}\n";
        echo str_repeat('-', 78) . "\n";

        $members = $interest['members'] ?? [];
        if ($members === []) {
            echo "(no member areas)\n\n";
            continue;
        }

        echo sprintf("%-10s %-32s %s\n", 'Type', 'Area', 'Description');
        echo str_repeat('-', 78) . "\n";

        foreach ($members as $member) {
            $type = $member['member_type'] === 'filearea' ? 'filearea' : 'echoarea';
            $areaName = renderAreaName($member);
            $memberDescription = trim((string)($member['description'] ?? ''));

            echo sprintf(
                "%-10s %-32s %s\n",
                $type,
                $areaName,
                $memberDescription !== '' ? $memberDescription : '-'
            );
        }

        echo "\n";
    }
}

$args = parseArgs($argv);

if (isset($args['help'])) {
    printUsage();
    exit(0);
}

try {
    $includeInactive = isset($args['all']);
    $manager = new InterestManager();
    $report = $manager->getInterestReport(!$includeInactive);
    printInterestReport($report, $includeInactive);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
