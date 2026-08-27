#!/usr/bin/env php
<?php

/*
 * Disposable Email Domain List Updater
 *
 * Downloads a disposable / throwaway email provider domain list and refreshes
 * the disposable_email_domains cache table used by the registration screening
 * feature. Domains not present in the current download are pruned.
 *
 * Normally invoked automatically by scripts/binkp_scheduler.php every 24 hours;
 * can also be run by hand.
 *
 * Usage: php scripts/update_disposable_email_list.php [--verbose]
 *
 * @see docs/proposals/NewUserScreeningIPAddress.md
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;
use BinktermPHP\Net\DisposableEmailListUpdater;

$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);

$db = Database::getInstance()->getPdo();
$logger = getServerLogger();

$updater = new DisposableEmailListUpdater($db, static function (string $msg) use ($verbose) {
    if ($verbose) {
        echo $msg . "\n";
    }
});

$result = $updater->run();

if ($result['status'] === 'ok') {
    $logger->info(sprintf(
        'update_disposable_email_list: refreshed disposable_email_domains (%d current, %d pruned)',
        $result['total'],
        $result['pruned']
    ));
    exit(0);
}

$logger->error('update_disposable_email_list: ' . ($result['error'] ?? 'unknown error'));
fwrite(STDERR, 'Disposable email list update failed: ' . ($result['error'] ?? 'unknown error') . "\n");
exit(1);
