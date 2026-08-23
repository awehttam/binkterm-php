#!/usr/bin/env php
<?php
/**
 * Reference pre_login_command helper for RLogin doors using the
 * "Synchronet with BinktermPHP Service" BBS type.
 *
 * Talks to the companion Synchronet-side services.ini service
 * (binktermphp-synchronet, https://github.com/awehttam/binktermphp-synchronet)
 * over a one-shot JSON-over-TCP protocol to provision/sync the remote
 * account before an rlogin session is launched. See that project's
 * binktermphp-api.js for the authoritative protocol; summarized here:
 *
 *   Request (one line of JSON):
 *     {"api_key":"<shared secret>","username":"...","real_name":"...","location":"..."}
 *
 *   Response (one line of JSON), success:
 *     {"success":true,"username":"...","user_number":42,"created":true}
 *
 *   Response, failure:
 *     {"success":false,"error":"reason"}
 *
 * Usage (as the door's pre_login_command):
 *   php scripts/rlogin_synchronet_service_client.php <user_name> <real_name> <user_number>
 *
 * Reads the target service host/port/shared secret from
 * config/rlogin_synchronet_service.json (created manually by the sysop):
 *   { "host": "127.0.0.1", "port": 24512, "secret": "changeme" }
 *
 * On success, prints {"remote_username":"..."} as JSON to stdout and exits 0.
 * On failure, prints an error message to stderr and exits 1.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Config;

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

if (!file_exists($configPath)) {
    fail("Service config not found: $configPath");
}

$serviceConfig = json_decode((string)file_get_contents($configPath), true);
if (!is_array($serviceConfig) || empty($serviceConfig['host']) || empty($serviceConfig['port'])) {
    fail("Invalid service config: $configPath");
}

$host = (string)$serviceConfig['host'];
$port = (int)$serviceConfig['port'];
$secret = (string)($serviceConfig['secret'] ?? '');
$timeout = (float)($serviceConfig['timeout'] ?? 5);

$socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
if ($socket === false) {
    fail("Could not connect to $host:$port - $errstr ($errno)");
}

stream_set_timeout($socket, (int)ceil($timeout));

$request = json_encode([
    'api_key' => $secret,
    'username' => $userName,
    'real_name' => $realName !== '' ? $realName : null,
]);

if ($request === false || fwrite($socket, $request . "\n") === false) {
    fclose($socket);
    fail('Failed to write request to service');
}

$response = fgets($socket);
fclose($socket);

if ($response === false) {
    fail('No response from service (timeout or connection closed)');
}

$decoded = json_decode(trim($response), true);
if (!is_array($decoded)) {
    fail("Invalid response from service: $response");
}

if (empty($decoded['success'])) {
    fail((string)($decoded['error'] ?? "Service rejected the request: $response"));
}

$remoteUsername = isset($decoded['username']) && $decoded['username'] !== ''
    ? (string)$decoded['username']
    : $userName;

echo json_encode(['remote_username' => $remoteUsername]) . PHP_EOL;
exit(0);
