#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/src/SshSession.php';
require_once __DIR__ . '/src/SshStreamWrapper.php';
require_once __DIR__ . '/src/SshServer.php';
require_once __DIR__ . '/../telnet/src/BbsSession.php';
require_once __DIR__ . '/../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../telnet/src/MailUtils.php';
require_once __DIR__ . '/../telnet/src/NetmailHandler.php';
require_once __DIR__ . '/../telnet/src/EchomailHandler.php';
require_once __DIR__ . '/../telnet/src/ShoutboxHandler.php';
require_once __DIR__ . '/../telnet/src/PollsHandler.php';
require_once __DIR__ . '/../telnet/src/DoorHandler.php';
require_once __DIR__ . '/../telnet/src/ZmodemTransfer.php';
require_once __DIR__ . '/../telnet/src/FileHandler.php';

use BinktermPHP\Config;
use BinktermPHP\SshServer\SshServer;

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
    echo "Usage: php ssh/ssh_daemon.php [options]\n";
    echo "  --host=ADDR       Bind address (default: 0.0.0.0)\n";
    echo "  --port=PORT       SSH port (default: SSH_PORT or 2222)\n";
    echo "  --api-base=URL    API base URL (default: SITE_URL or http://127.0.0.1)\n";
    echo "  --debug           Enable debug mode with verbose logging\n";
    echo "  --daemon          Run as background daemon\n";
    echo "  --pid-file=FILE   Write process ID to file (default: data/run/sshd.pid)\n";
    echo "  --insecure        Disable SSL certificate verification\n";
    exit(0);
}

// Extract configuration from arguments, falling back to .env then hardcoded defaults
$host     = $args['host'] ?? Config::env('SSH_BIND_HOST', '0.0.0.0');
$port     = (int)($args['port'] ?? Config::env('SSH_PORT', '2022'));
$apiBase  = buildApiBase($args);
$debug    = !empty($args['debug']);
$daemon   = !empty($args['daemon']);
$insecure = !empty($args['insecure']);

// Create SSH server instance
$server = new SshServer($host, $port, $apiBase, $debug, $insecure);

// Set PID file path for daemon mode
$pidFile = $args['pid-file'] ?? dirname(__DIR__) . '/data/run/sshd.pid';
$server->setPidFile($pidFile);

// Start the server (this will daemonize if needed and write PID file)
$server->start($daemon);
