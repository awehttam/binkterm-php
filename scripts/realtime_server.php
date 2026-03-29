#!/usr/bin/env php
<?php

chdir(__DIR__ . "/../");

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Binkp\Logger;
use BinktermPHP\Config;
use BinktermPHP\Realtime\WebSocketServer;

function parseArgs(array $argv): array
{
    $args = [];
    foreach ($argv as $arg) {
        if (strpos($arg, '--') !== 0) {
            continue;
        }
        if (strpos($arg, '=') !== false) {
            [$key, $value] = explode('=', substr($arg, 2), 2);
            $args[$key] = $value;
        } else {
            $args[substr($arg, 2)] = true;
        }
    }
    return $args;
}

function showUsage(): void
{
    echo "Usage: php scripts/realtime_server.php [options]\n";
    echo "Options:\n";
    echo "  --host=HOST        WebSocket bind host (default: BINKSTREAM_WS_BIND_HOST or 127.0.0.1)\n";
    echo "  --port=PORT        WebSocket bind port (default: BINKSTREAM_WS_PORT or 6010)\n";
    echo "  --daemon           Run as daemon (requires pcntl_fork)\n";
    echo "  --pid-file=FILE    Write PID file (default: data/run/realtime_server.pid)\n";
    echo "  --log-file=FILE    Log file path (default: data/logs/realtime_server.log)\n";
    echo "  --log-level=LEVEL  Log level (default: INFO)\n";
    echo "  --no-console       Disable console logging\n";
    echo "  --help             Show this help message\n";
}

function daemonize(): void
{
    $pid = pcntl_fork();
    if ($pid === -1) {
        die("Could not fork process\n");
    } elseif ($pid) {
        echo "Realtime websocket server started with PID: $pid\n";
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
    fopen('/dev/null', 'r');
    fopen('/dev/null', 'a');
    fopen('/dev/null', 'a');
}

function setConsoleTitle(string $title): void
{
    echo "\033]0;{$title}\007";
}

$args = parseArgs($argv);
if (isset($args['help'])) {
    showUsage();
    exit(0);
}

$host = (string)($args['host'] ?? Config::env('BINKSTREAM_WS_BIND_HOST', Config::env('REALTIME_WS_BIND_HOST', '127.0.0.1')));
$port = (int)($args['port'] ?? Config::env('BINKSTREAM_WS_PORT', Config::env('REALTIME_WS_PORT', '6010')));
$pidFile = (string)($args['pid-file'] ?? (Config::env('BINKSTREAM_WS_PID_FILE', Config::env('REALTIME_WS_PID_FILE')) ?: (__DIR__ . '/../data/run/realtime_server.pid')));
$logFile = (string)($args['log-file'] ?? Config::getLogPath('realtime_server.log'));
$logLevel = (string)($args['log-level'] ?? 'INFO');
$logToConsole = !isset($args['no-console']);

$logger = new Logger($logFile, $logLevel, $logToConsole);

if (isset($args['daemon']) && function_exists('pcntl_fork')) {
    $logger->setLogToConsole(false);
    daemonize();
} else {
    setConsoleTitle('BinktermPHP Realtime Server');
}

$pidDir = dirname($pidFile);
if (!is_dir($pidDir)) {
    @mkdir($pidDir, 0755, true);
}
@file_put_contents($pidFile, (string)getmypid());
@chmod($pidFile, 0644);

register_shutdown_function(static function () use ($pidFile): void {
    if (file_exists($pidFile) && (int)trim((string)file_get_contents($pidFile)) === getmypid()) {
        @unlink($pidFile);
    }
});

try {
    (new WebSocketServer($host, $port, $logger))->run();
} catch (Throwable $e) {
    $logger->error('Realtime daemon failed', ['error' => $e->getMessage()]);
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
