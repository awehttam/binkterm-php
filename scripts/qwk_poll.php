#!/usr/bin/env php
<?php

chdir(__DIR__ . '/../');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

function qwkPollShowUsage(): void
{
    echo "Usage: php qwk_poll.php [options] [mailbox-id]\n";
    echo "Options:\n";
    echo "  --all             Poll all enabled QWK mailboxes\n";
    echo "  --dry-run         Download/import/build packets but skip REP upload\n";
    echo "  --log-level=LVL   Log level: DEBUG, INFO, WARNING, ERROR, CRITICAL\n";
    echo "  --log-file=FILE   Accepted for compatibility\n";
    echo "  --no-console      Accepted for compatibility\n";
    echo "  --quiet           Minimal output\n";
    echo "  --help            Show this help message\n";
}

/**
 * @return array{0:array<string,mixed>,1:array<int,string>}
 */
function qwkPollParseArgs(array $argv): array
{
    $args = [];
    $positional = [];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (strpos($arg, '--') === 0) {
            if (strpos($arg, '=') !== false) {
                [$key, $value] = explode('=', substr($arg, 2), 2);
                $args[$key] = $value;
            } else {
                $args[substr($arg, 2)] = true;
            }
        } else {
            $positional[] = $arg;
        }
    }
    return [$args, $positional];
}

function qwkPollResolveLogLevel(string $levelName): int
{
    static $levels = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
        'CRITICAL' => 4,
    ];

    $normalized = strtoupper(trim($levelName));
    return $levels[$normalized] ?? $levels['INFO'];
}

function qwkPollLog(callable $writer, int $threshold, string $level, string $message): void
{
    if (qwkPollResolveLogLevel($level) < $threshold) {
        return;
    }

    $writer(sprintf('[%s] %s', strtoupper($level), $message));
}

[$args, $positional] = qwkPollParseArgs($argv);
if (isset($args['help'])) {
    qwkPollShowUsage();
    exit(0);
}

$quiet = isset($args['quiet']);
$logThreshold = qwkPollResolveLogLevel((string)($args['log-level'] ?? 'INFO'));
$dryRun = isset($args['dry-run']);
$poller = new \BinktermPHP\Qwk\QwkPoller();
$poller->setDryRun($dryRun);
$poller->setPreserveDebugArtifacts($logThreshold <= qwkPollResolveLogLevel('DEBUG'));
$poller->setLogger(static function (string $level, string $message) use ($quiet, $logThreshold): void {
    if ($quiet) {
        return;
    }
    qwkPollLog(static function (string $line): void {
        echo $line . "\n";
    }, $logThreshold, $level, $message);
});

try {
    if (isset($args['all'])) {
        $results = $poller->pollAllEnabled();
        foreach ($results as $name => $result) {
            if ($quiet) {
                echo $name . ': ' . (!empty($result['success']) ? 'OK' : 'FAIL') . "\n";
                continue;
            }

            echo '[' . $name . '] ' . (!empty($result['success']) ? 'SUCCESS' : 'FAILED') . "\n";
            if (!empty($result['imported']) || !empty($result['skipped'])) {
                echo '  Imported: ' . (int)($result['imported'] ?? 0) . "\n";
                echo '  Skipped: ' . (int)($result['skipped'] ?? 0) . "\n";
            }
            if (!empty($result['dry_run'])) {
                echo "  Dry run: yes\n";
            }
            if (array_key_exists('uploaded', $result)) {
                echo '  Uploaded REP: ' . (!empty($result['dry_run']) ? 'skipped (dry run)' : (!empty($result['uploaded']) ? 'yes' : 'no')) . "\n";
            }
            if (!empty($result['error'])) {
                echo '  Error: ' . $result['error'] . "\n";
            }
        }

        $allOk = array_reduce($results, static function (bool $carry, array $result): bool {
            return $carry && !empty($result['success']);
        }, true);
        exit($allOk ? 0 : 1);
    }

    if ($positional === []) {
        qwkPollShowUsage();
        exit(1);
    }

    $mailboxId = (int)$positional[0];
    $result = $poller->pollMailbox($mailboxId);
    if ($quiet) {
        echo !empty($result['success']) ? "OK\n" : "FAIL\n";
        exit(!empty($result['success']) ? 0 : 1);
    }

    echo (!empty($result['success']) ? 'SUCCESS' : 'FAILED') . "\n";
    if (!empty($result['imported']) || !empty($result['skipped'])) {
        echo 'Imported: ' . (int)($result['imported'] ?? 0) . "\n";
        echo 'Skipped: ' . (int)($result['skipped'] ?? 0) . "\n";
    }
    if (!empty($result['dry_run'])) {
        echo "Dry run: yes\n";
    }
    if (array_key_exists('uploaded', $result)) {
        echo 'Uploaded REP: ' . (!empty($result['dry_run']) ? 'skipped (dry run)' : (!empty($result['uploaded']) ? 'yes' : 'no')) . "\n";
    }
    if (!empty($result['error'])) {
        echo 'Error: ' . $result['error'] . "\n";
    }
    exit(!empty($result['success']) ? 0 : 1);
} catch (\Throwable $e) {
    if ($quiet) {
        echo "FAIL\n";
    } else {
        echo 'Error: ' . $e->getMessage() . "\n";
    }
    exit(1);
}
