#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Advertising;

function adCampaignUsage(): void
{
    echo "Usage: php scripts/run_ad_campaigns.php [options]\n\n";
    echo "Options:\n";
    echo "  --campaign-id=ID   Run only one campaign\n";
    echo "  --dry-run          Show what would be posted without posting\n";
    echo "  --quiet            Suppress normal output\n";
    echo "  --help             Show this help\n";
}

function adCampaignParseArgs(array $argv): array
{
    $args = [];
    foreach ($argv as $arg) {
        if (strpos($arg, '--') !== 0) {
            continue;
        }

        $body = substr($arg, 2);
        if (strpos($body, '=') !== false) {
            [$key, $value] = explode('=', $body, 2);
            $args[$key] = $value;
        } else {
            $args[$body] = true;
        }
    }

    return $args;
}

$args = adCampaignParseArgs($argv);
if (!empty($args['help'])) {
    adCampaignUsage();
    exit(0);
}

$campaignId = isset($args['campaign-id']) ? (int)$args['campaign-id'] : null;
$dryRun = !empty($args['dry-run']);
$quiet = !empty($args['quiet']);

try {
    $advertising = new Advertising();
    $results = $advertising->processDueCampaigns($campaignId ?: null, $dryRun);

    if (!$quiet) {
        if ($results === []) {
            echo "No due campaigns.\n";
        } else {
            foreach ($results as $result) {
                $status = strtoupper((string)($result['status'] ?? 'unknown'));
                $campaign = $result['campaign_name'] ?? ('Campaign #' . ($result['campaign_id'] ?? '?'));
                $target = $result['target'] ?? '-';
                $ad = $result['advertisement_title'] ?? '-';
                $subject = $result['subject'] ?? '-';
                echo "[{$status}] {$campaign} -> {$target} :: {$ad} :: {$subject}\n";
                if (!empty($result['error'])) {
                    echo "  Error: {$result['error']}\n";
                }
                if (!empty($result['reason'])) {
                    echo "  Reason: {$result['reason']}\n";
                }
            }
        }
    }

    $hadFailure = false;
    foreach ($results as $result) {
        if (($result['status'] ?? '') === 'failed') {
            $hadFailure = true;
            break;
        }
    }

    exit($hadFailure ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
