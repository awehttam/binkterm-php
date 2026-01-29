#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Config;
use BinktermPHP\Binkp\Config\BinkpConfig;

function showUsage()
{
    echo "Usage: php scripts/send_activityreport.php [options]\n\n";
    echo "Options:\n";
    echo "  --since=PERIOD       Relative period for digest (default: 7d)\n";
    echo "  --from=YYYY-MM-DD    Start date (overrides --since)\n";
    echo "  --to=YYYY-MM-DD      End date (overrides --since)\n";
    echo "  --from=ADDRESS       From FTN address (default: " . Config::FIDONET_ORIGIN . ")\n";
    echo "  --from-name=NAME     From name (default: " . Config::SYSOP_NAME . ")\n";
    echo "  --to=ADDRESS         To FTN address (default: " . Config::FIDONET_ORIGIN . ")\n";
    echo "  --to-name=NAME       To name (default: sysop)\n";
    echo "  --subject=TEXT       Subject line (default: Activity Digest)\n";
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

function runCommand(array $command)
{
    $escaped = array_map('escapeshellarg', $command);
    $cmd = implode(' ', $escaped);
    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    return [$exitCode, implode("\n", $output)];
}

$args = parseArgs($argv);

if (isset($args['help'])) {
    showUsage();
    exit(0);
}

try {
    $since = $args['since'] ?? '7d';
    $from = $args['from'] ?? null;
    $to = $args['to'] ?? null;

    $binkpConfig = BinkpConfig::getInstance();
    $defaultAddress = $binkpConfig->getSystemAddress() ?: Config::FIDONET_ORIGIN;
    $fromAddress = $args['from'] ?? $defaultAddress;
    $fromName = $args['from-name'] ?? Config::SYSOP_NAME;
    $toAddress = $args['to'] ?? $defaultAddress;
    $toName = $args['to-name'] ?? 'sysop';
    $subject = $args['subject'] ?? 'Activity Digest';

    $tmpFile = tempnam(sys_get_temp_dir(), 'bbs_digest_');
    if (!$tmpFile) {
        throw new RuntimeException('Failed to create temporary file');
    }
    $tmpFile .= '.ans';

    $digestCommand = [
        PHP_BINARY,
        __DIR__ . '/activity_digest.php',
        '--format=ansi',
        '--output=' . $tmpFile
    ];
    if ($from) {
        $digestCommand[] = '--from=' . $from;
    } elseif ($since) {
        $digestCommand[] = '--since=' . $since;
    }
    if ($to) {
        $digestCommand[] = '--to=' . $to;
    }

    [$digestExit, $digestOutput] = runCommand($digestCommand);
    if ($digestExit !== 0) {
        throw new RuntimeException("Digest generation failed: {$digestOutput}");
    }

    $postCommand = [
        PHP_BINARY,
        __DIR__ . '/post_message.php',
        '--type=netmail',
        '--from=' . $fromAddress,
        '--from-name=' . $fromName,
        '--to=' . $toAddress,
        '--to-name=' . $toName,
        '--subject=' . $subject,
        '--file=' . $tmpFile
    ];

    [$postExit, $postOutput] = runCommand($postCommand);
    @unlink($tmpFile);

    if ($postExit !== 0) {
        throw new RuntimeException("Failed to post digest: {$postOutput}");
    }

    echo "Activity digest posted to {$toName}.\n";
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
