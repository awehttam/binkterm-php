#!/usr/bin/env php
<?php

chdir(__DIR__ . "/../");

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Binkp\Logger;
use BinktermPHP\Config;
use BinktermPHP\Ftp\FtpServer;

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
    echo "Usage: php scripts/ftp_daemon.php [options]\n";
    echo "Options:\n";
    echo "  --host=HOST        FTP bind host (default: FTPD_BIND_HOST or 0.0.0.0)\n";
    echo "  --port=PORT        FTP bind port (default: FTPD_PORT or 2121)\n";
    echo "  --public-host=HOST Public host/IP advertised in PASV replies (default: FTPD_PUBLIC_HOST)\n";
    echo "  --pasv-start=PORT  First passive data port (default: FTPD_PASSIVE_PORT_START or 2122)\n";
    echo "  --pasv-end=PORT    Last passive data port (default: FTPD_PASSIVE_PORT_END or 2149)\n";
    echo "  --daemon           Run as daemon (requires pcntl_fork)\n";
    echo "  --pid-file=FILE    Write PID file (default: data/run/ftpd.pid)\n";
    echo "  --log-file=FILE    Log file path (default: data/logs/ftpd.log)\n";
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
        echo "FTP daemon started with PID: $pid\n";
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

$enabled = strtolower((string)Config::env('FTPD_ENABLED', 'false')) === 'true';
if (!$enabled) {
    fwrite(STDERR, "FTPD is disabled. Set FTPD_ENABLED=true to run scripts/ftp_daemon.php.\n");
    exit(1);
}

$host = (string)($args['host'] ?? Config::env('FTPD_BIND_HOST', '0.0.0.0'));
$port = (int)($args['port'] ?? Config::env('FTPD_PORT', '2121'));
$publicHost = (string)($args['public-host'] ?? Config::env('FTPD_PUBLIC_HOST', ''));
$passiveStart = (int)($args['pasv-start'] ?? Config::env('FTPD_PASSIVE_PORT_START', '2122'));
$passiveEnd = (int)($args['pasv-end'] ?? Config::env('FTPD_PASSIVE_PORT_END', '2149'));
$pidFile = (string)($args['pid-file'] ?? (__DIR__ . '/../data/run/ftpd.pid'));
$logFile = (string)($args['log-file'] ?? Config::getLogPath('ftpd.log'));
$logLevel = (string)($args['log-level'] ?? 'INFO');
$logToConsole = !isset($args['no-console']);

$logger = new Logger($logFile, $logLevel, $logToConsole);

if (isset($args['daemon'])) {
    if (!function_exists('pcntl_fork')) {
        fwrite(STDERR, "--daemon requires pcntl_fork, which is not available on this platform.\n");
        exit(1);
    }
    $logger->setLogToConsole(false);
    daemonize();
} else {
    setConsoleTitle('BinktermPHP FTP Daemon');
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
    $server = new FtpServer($host, $port, $publicHost, $passiveStart, $passiveEnd, $logger);
    $server->start();

    while (true) {
        $read = $server->getReadSockets();
        $write = $server->getWriteSockets();
        $except = null;

        if ($read === [] && $write === []) {
            usleep(200000);
            $server->tick();
            continue;
        }

        $changed = @stream_select($read, $write, $except, 1);
        if ($changed === false) {
            usleep(200000);
        } elseif ($changed > 0) {
            foreach ($read as $socket) {
                $server->handleReadableSocket($socket);
            }
            foreach ($write as $socket) {
                $server->handleWritableSocket($socket);
            }
        }

        $server->tick();
    }
} catch (Throwable $e) {
    $logger->error('FTP daemon failed', ['error' => $e->getMessage()]);
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
