#!/usr/bin/php
<?php

chdir(__DIR__ . "/../");

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Admin\AdminDaemonServer;
use BinktermPHP\Binkp\Logger;
use BinktermPHP\Config;

function showUsage()
{
    echo "Usage: php admin_daemon.php [options]\n";
    echo "Options:\n";
    echo "  --socket=TARGET     Socket target (unix:///path.sock or tcp://127.0.0.1:PORT)\n";
    echo "  --secret=SECRET     Shared secret for auth (overrides ADMIN_DAEMON_SECRET)\n";
    echo "  --log-file=FILE     Log file path (default: " . Config::getLogPath('admin_daemon.log') . ")\n";
    echo "  --log-level=LEVEL   Log level: DEBUG, INFO, WARNING, ERROR, CRITICAL\n";
    echo "  --no-console        Disable console logging\n";
    echo "  --pid-file=FILE     Write PID file\n";
    echo "  --socket-perms=MODE Set unix socket permissions (octal)\n";
    echo "  --daemon            Run as daemon (requires pcntl_fork)\n";
    echo "  --help              Show this help message\n";
    echo "\n";
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

function daemonize()
{
    $pid = pcntl_fork();
    
    if ($pid == -1) {
        die("Could not fork process\n");
    } elseif ($pid) {
        echo "Admin daemon started with PID: $pid\n";
        exit(0);
    }
    
    if (posix_setsid() == -1) {
        die("Could not detach from terminal\n");
    }
    
    chdir('/');
    umask(0);
    
    fclose(STDIN);
    fclose(STDOUT);
    fclose(STDERR);
}

$args = parseArgs($argv);

if (isset($args['help'])) {
    showUsage();
    exit(0);
}

try {
    $logLevel = $args['log-level'] ?? 'INFO';
    $logFile = $args['log-file'] ?? Config::getLogPath('admin_daemon.log');
    $logToConsole = !isset($args['no-console']);

    $logger = new Logger($logFile, $logLevel, $logToConsole);

    if (isset($args['daemon']) && function_exists('pcntl_fork')) {
        $logger->setLogToConsole(false);
        daemonize();
    }

    $server = new AdminDaemonServer(
        $args['socket'] ?? null,
        $args['secret'] ?? null,
        $logger,
        $args['pid-file'] ?? null,
        $args['socket-perms'] ?? null
    );

    $server->run();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
