#!/usr/bin/env php
<?php

chdir(__DIR__ . '/..');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\DoorData\DoorDataParseException;
use BinktermPHP\DoorData\DoorLeaderboardSync;

function showUsage(): void
{
    echo "Door Data Sync\n\n";
    echo "Reads a DOS door's game data files with its registered parser and copies\n";
    echo "player scores into the /games Top Scores leaderboard.\n\n";
    echo "Usage: php scripts/door_data_sync.php <door_id> [options]\n\n";
    echo "Options:\n";
    echo "  --help   Show this help message\n\n";
    echo "The bridge runs this automatically when a door exits.\n";
}

$options = getopt('', ['help']);
$doorId = $argv[1] ?? '';
if (isset($options['help']) || $doorId === '' || $doorId[0] === '-') {
    showUsage();
    exit(isset($options['help']) ? 0 : 1);
}

$logger = new \BinktermPHP\Binkp\Logger(
    \BinktermPHP\Config::getLogPath('dosdoor.log'),
    \BinktermPHP\Binkp\Logger::LEVEL_INFO,
    false
);

try {
    $result = (new DoorLeaderboardSync())->syncDoor($doorId);
    $summary = "inserted={$result['inserted']} unchanged={$result['unchanged']} unmatched={$result['unmatched']}";
    $logger->info("DOSDOOR: [DataSync] $doorId $summary");
    echo "$doorId: $summary\n";
} catch (DoorDataParseException $e) {
    $logger->warning("DOSDOOR: [DataSync] $doorId: " . $e->getMessage());
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
