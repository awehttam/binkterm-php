#!/usr/bin/env php
<?php

/*
 * Tor Exit Node List Updater
 *
 * Downloads the Tor Project's bulk exit list and refreshes the tor_exit_nodes
 * cache table used by the registration screening feature. Nodes not seen in the
 * current download are pruned so departed exit relays age out.
 *
 * Normally invoked automatically by scripts/binkp_scheduler.php on a 6-hour
 * interval; can also be run by hand.
 *
 * Usage: php scripts/update_tor_exit_list.php [--verbose]
 *
 * @see docs/proposals/NewUserScreeningIPAddress.md
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;
use BinktermPHP\Net\TorExitListUpdater;

$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);

$db = Database::getInstance()->getPdo();
$logger = getServerLogger();

$updater = new TorExitListUpdater($db, static function (string $msg) use ($verbose) {
    if ($verbose) {
        echo $msg . "\n";
    }
});

$result = $updater->run();

if ($result['status'] === 'ok') {
    $logger->info(sprintf(
        'update_tor_exit_list: refreshed tor_exit_nodes (%d current, %d pruned)',
        $result['total'],
        $result['pruned']
    ));
    exit(0);
}

$logger->error('update_tor_exit_list: ' . ($result['error'] ?? 'unknown error'));
fwrite(STDERR, 'Tor exit list update failed: ' . ($result['error'] ?? 'unknown error') . "\n");
exit(1);
