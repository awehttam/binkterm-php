#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/src/TelnetServer.php';
require_once __DIR__ . '/src/TelnetUtils.php';
require_once __DIR__. '/src/MailUtils.php';
require_once __DIR__ . '/src/NetmailHandler.php';
require_once __DIR__ . '/src/EchomailHandler.php';
require_once __DIR__ . '/src/ShoutboxHandler.php';
require_once __DIR__ . '/src/PollsHandler.php';

use BinktermPHP\Config;
use BinktermPHP\TelnetServer\TelnetServer;

/**
 * Parse command line arguments
 *
 * @param array $argv Command line arguments
 * @return array Associative array of parsed arguments
 */
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

/**
 * Build API base URL from arguments or configuration
 *
 * @param array $args Parsed command line arguments
 * @return string API base URL
 */
function buildApiBase(array $args): string
{
    if (!empty($args['api-base'])) {
        return rtrim($args['api-base'], '/');
    }

    // Try to get site URL from config
    try {
        return Config::getSiteUrl();
    } catch (\Exception $e) {
        return 'http://127.0.0.1';
    }
}

// Parse command line arguments
$args = parseArgs($argv);

// Show help if requested
if (!empty($args['help'])) {
    echo "Usage: php telnet/telnet_daemon.php [options]\n";
    echo "  --host=ADDR       Bind address (default: 0.0.0.0)\n";
    echo "  --port=PORT       Bind port (default: 2323)\n";
    echo "  --api-base=URL    API base URL (default: SITE_URL or http://127.0.0.1)\n";
    echo "  --debug           Enable debug mode with verbose logging\n";
    echo "  --daemon          Run as background daemon\n";
    echo "  --pid-file=FILE   Write process ID to file (default: data/run/telnetd.pid)\n";
    echo "  --insecure        Disable SSL certificate verification\n";
    exit(0);
}

// Extract configuration from arguments
$host = $args['host'] ?? '0.0.0.0';
$port = (int)($args['port'] ?? 2323);
$apiBase = buildApiBase($args);
$debug = !empty($args['debug']);
$daemonMode = !empty($args['daemon']);
$insecure = !empty($args['insecure']);
$pidFile = $args['pid-file'] ?? __DIR__ . '/../data/run/telnetd.pid';

// Write PID file
$pidDir = dirname($pidFile);
if (!is_dir($pidDir)) {
    mkdir($pidDir, 0755, true);
}
file_put_contents($pidFile, getmypid());

// Register shutdown function to clean up PID file
register_shutdown_function(function() use ($pidFile) {
    if (file_exists($pidFile)) {
        unlink($pidFile);
    }
});

// Create telnet server instance
$server = new TelnetServer($host, $port, $apiBase, $debug, $insecure);

// Start the server
$server->start($daemonMode);
