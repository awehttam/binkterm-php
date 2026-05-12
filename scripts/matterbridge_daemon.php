#!/usr/bin/env php
<?php

/*
 * Matterbridge Inbound Daemon
 *
 * Polls the Matterbridge API for inbound messages (e.g. from Discord) and
 * injects them into the appropriate local chat rooms.
 *
 * Usage: php matterbridge_daemon.php [--daemon] [--pid-file=path] [--log-level=LEVEL] [--poll-interval=N]
 */

chdir(__DIR__ . "/../");

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Binkp\Logger;
use BinktermPHP\Chat\ChatMessageService;
use BinktermPHP\Chat\MatterbridgeConfig;
use BinktermPHP\Chat\MatterbridgeService;
use BinktermPHP\Database;

// ========================================
// CLI Helpers
// ========================================

function showUsage(): void
{
    echo "Usage: php matterbridge_daemon.php [options]\n";
    echo "Options:\n";
    echo "  --daemon             Run as daemon (detach from terminal)\n";
    echo "  --pid-file=FILE      Write PID file (default: data/run/matterbridge_daemon.pid)\n";
    echo "  --log-level=LEVEL    Log level (default: INFO)\n";
    echo "  --poll-interval=N    Seconds between polls (default: 2)\n";
    echo "  --help               Show this help message\n\n";
}

function parseArgs(array $argv): array
{
    $args = [];
    foreach ($argv as $arg) {
        if (strpos($arg, '--') === 0) {
            if (strpos($arg, '=') !== false) {
                [$key, $value] = explode('=', substr($arg, 2), 2);
                $args[$key] = $value;
            } else {
                $args[substr($arg, 2)] = true;
            }
        }
    }
    return $args;
}

function daemonize(): void
{
    $pid = pcntl_fork();
    if ($pid === -1) {
        die("Could not fork process\n");
    } elseif ($pid > 0) {
        echo "Matterbridge daemon started with PID: $pid\n";
        exit(0);
    }

    if (posix_setsid() === -1) {
        die("Could not detach from terminal\n");
    }

    chdir('/');
    umask(0);
    fclose(STDIN);
    fclose(STDOUT);
    fclose(STDERR);
}

// ========================================
// Main
// ========================================

$args = parseArgs($argv);

if (isset($args['help'])) {
    showUsage();
    exit(0);
}

$pidFile      = $args['pid-file'] ?? 'data/run/matterbridge_daemon.pid';
$logLevelName = strtoupper((string)($args['log-level'] ?? 'INFO'));
$pollInterval = max(1, (int)($args['poll-interval'] ?? 2));

$logLevelMap = [
    'DEBUG'    => Logger::LEVEL_DEBUG,
    'INFO'     => Logger::LEVEL_INFO,
    'WARNING'  => Logger::LEVEL_WARNING,
    'ERROR'    => Logger::LEVEL_ERROR,
    'CRITICAL' => Logger::LEVEL_CRITICAL,
];
$logLevel = $logLevelMap[$logLevelName] ?? Logger::LEVEL_INFO;

if (isset($args['daemon'])) {
    daemonize();
}

$pidDir = dirname($pidFile);
if (!is_dir($pidDir)) {
    mkdir($pidDir, 0755, true);
}
file_put_contents($pidFile, getmypid() . "\n");

$logger = new Logger(
    \BinktermPHP\Config::getLogPath('server.log'),
    $logLevel,
    false
);

$logger->info('Matterbridge daemon starting', [
    'poll_interval' => $pollInterval,
    'pid_file'      => $pidFile,
]);

// ========================================
// Signal handling
// ========================================

$running = true;

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$running, $logger) {
        $logger->info('Matterbridge daemon received SIGTERM, shutting down');
        $running = false;
    });
    pcntl_signal(SIGINT, function () use (&$running, $logger) {
        $logger->info('Matterbridge daemon received SIGINT, shutting down');
        $running = false;
    });
}

// ========================================
// Room cache  (gateway name -> room id, refreshed every 60 s)
// ========================================

/** @var array<string,int> $roomCache  gateway => room_id */
$roomCache       = [];
$roomCacheExpiry = 0;

function refreshRoomCache(\PDO $db): array
{
    $stmt = $db->query("
        SELECT id, matterbridge_gateway
        FROM   chat_rooms
        WHERE  matterbridge_enabled = true
          AND  matterbridge_gateway IS NOT NULL
          AND  matterbridge_gateway <> ''
    ");
    $cache = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $cache[(string)$row['matterbridge_gateway']] = (int)$row['id'];
    }
    return $cache;
}

// ========================================
// Main loop
// ========================================

$config      = MatterbridgeConfig::getInstance();
$service     = new MatterbridgeService($config, $logger);

if (!$config->isEnabled()) {
    $logger->warning('Matterbridge is not enabled in config/matterbridge.json — exiting');
    @unlink($pidFile);
    exit(0);
}

$bridgeUserId = $config->getBridgeUserId();
if ($bridgeUserId <= 0) {
    $logger->warning('bridge_user_id is not set in config/matterbridge.json — inbound messages cannot be injected');
    $logger->warning('Create a dedicated bridge user, note its ID, and set bridge_user_id in config/matterbridge.json');
    @unlink($pidFile);
    exit(1);
}

$db             = Database::getInstance()->getPdo();
$chatService    = new ChatMessageService($db, $service, $logger);

$logger->info('Matterbridge daemon ready', [
    'bridge_user_id' => $bridgeUserId,
    'base_url'       => $config->getBaseUrl(),
]);

while ($running) {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    // Refresh room cache every 60 seconds
    if (time() >= $roomCacheExpiry) {
        $roomCache       = refreshRoomCache($db);
        $roomCacheExpiry = time() + 60;

        $logger->debug('Room cache refreshed', ['gateways' => array_keys($roomCache)]);
    }

    try {
        $messages = $service->pollMessages();
    } catch (\Throwable $e) {
        $logger->error('Matterbridge poll failed', ['error' => $e->getMessage()]);
        sleep($pollInterval);
        continue;
    }

    foreach ($messages as $msg) {
        $gateway  = trim((string)($msg['gateway']  ?? ''));
        $text     = trim((string)($msg['text']     ?? ''));
        $username = trim((string)($msg['username'] ?? ''));
        $protocol = strtolower(trim((string)($msg['protocol'] ?? 'remote')));

        if ($gateway === '' || $text === '' || $username === '') {
            continue;
        }

        $roomId = $roomCache[$gateway] ?? null;
        if ($roomId === null) {
            $logger->debug('No local room for gateway, skipping', [
                'gateway' => $gateway,
            ]);
            continue;
        }

        $body = '[' . strtoupper($protocol) . '] <' . $username . '> ' . $text;

        try {
            $chatService->sendMessage($bridgeUserId, $roomId, null, $body, false);
            $logger->debug('Injected inbound bridge message', [
                'gateway'  => $gateway,
                'room_id'  => $roomId,
                'protocol' => $protocol,
                'username' => $username,
            ]);
        } catch (\Throwable $e) {
            $logger->error('Failed to inject inbound bridge message', [
                'gateway' => $gateway,
                'room_id' => $roomId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    sleep($pollInterval);
}

$logger->info('Matterbridge daemon stopped');
@unlink($pidFile);
