#!/usr/bin/env php
<?php
/**
 * Reference pre_login_command helper for RLogin doors using the
 * "Synchronet with BinktermPHP Service" BBS type.
 *
 * Talks to the companion Synchronet-side services.ini service
 * (binktermphp-synchronet, https://github.com/awehttam/binktermphp-synchronet)
 * via BinktermPHP\Synchronet. See that class for the wire protocol.
 *
 * Usage (as the door's pre_login_command):
 *   php scripts/synchronet_add_user.php <user_name> <real_name> <user_number>
 *
 * Reads the target service host/port/shared secret from
 * config/rlogin_synchronet_service.json (copy config/rlogin_synchronet_service.json.example
 * to get started):
 *   { "host": "127.0.0.1", "port": 24512, "secret": "changeme" }
 *
 * On success, prints {"remote_username":"..."} as JSON to stdout and exits 0.
 * On failure, prints an error message to stderr and exits 1.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Synchronet;

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$userName = $argv[1] ?? '';
$realName = $argv[2] ?? '';
$userNumber = $argv[3] ?? '';

if ($userName === '') {
    fail('user_name argument is required');
}

$configPath = defined('BINKTERMPHP_BASEDIR')
    ? BINKTERMPHP_BASEDIR . '/config/rlogin_synchronet_service.json'
    : __DIR__ . '/../config/rlogin_synchronet_service.json';

try {
    $client = Synchronet::fromConfigFile($configPath);
    $response = $client->provision($userName, $realName !== '' ? $realName : null);
} catch (\Throwable $e) {
    fail($e->getMessage());
}

if (empty($response['success'])) {
    fail($response['error'] ?? 'Service rejected the request');
}

$remoteUsername = !empty($response['username']) ? $response['username'] : $userName;

echo json_encode(['remote_username' => $remoteUsername]) . PHP_EOL;
exit(0);
