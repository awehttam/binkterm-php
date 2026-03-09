#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/src/TelnetServer.php';
require_once __DIR__ . '/src/BbsSession.php';
require_once __DIR__ . '/src/TelnetUtils.php';
require_once __DIR__ . '/src/TerminalMarkupRenderer.php';
require_once __DIR__. '/src/MailUtils.php';
require_once __DIR__ . '/src/NetmailHandler.php';
require_once __DIR__ . '/src/EchomailHandler.php';
require_once __DIR__ . '/src/ShoutboxHandler.php';
require_once __DIR__ . '/src/PollsHandler.php';
require_once __DIR__ . '/src/DoorHandler.php';
require_once __DIR__ . '/src/ZmodemTransfer.php';
require_once __DIR__ . '/src/FileHandler.php';
require_once __DIR__ . '/src/TerminalSettingsHandler.php';

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
    echo "  --port=PORT       Plain-text port (default: 2323)\n";
    echo "  --no-tls          Disable TLS (TLS is enabled by default on port 8023)\n";
    echo "  --tls-port=PORT   TLS port (default: TELNET_TLS_PORT or 8023)\n";
    echo "  --tls-cert=FILE   TLS certificate PEM file (default: auto-generated)\n";
    echo "  --tls-key=FILE    TLS private key PEM file (default: auto-generated)\n";
    echo "  --api-base=URL    API base URL (default: SITE_URL or http://127.0.0.1)\n";
    echo "  --debug           Enable debug mode with verbose logging\n";
    echo "  --daemon          Run as background daemon\n";
    echo "  --pid-file=FILE   Write process ID to file (default: data/run/telnetd.pid)\n";
    echo "  --insecure        Disable SSL certificate verification\n";
    exit(0);
}

// Extract configuration from arguments, falling back to .env then hardcoded defaults
$host = $args['host'] ?? Config::env('TELNET_BIND_HOST', '0.0.0.0');
$port = (int)($args['port'] ?? Config::env('TELNET_PORT', '2323'));
$apiBase = buildApiBase($args);
$debug = !empty($args['debug']);
$daemonMode = !empty($args['daemon']);
$insecure = !empty($args['insecure']);

// Create telnet server instance
$server = new TelnetServer($host, $port, $apiBase, $debug, $insecure);

// TLS is enabled by default; --no-tls or TELNET_TLS=false disables it
$tlsDisabled = !empty($args['no-tls']) || Config::env('TELNET_TLS', 'true') === 'false';
if ($tlsDisabled) {
    $server->disableTls();
} else {
    $tlsPort = (int)($args['tls-port'] ?? Config::env('TELNET_TLS_PORT', '8023'));
    $tlsCert = $args['tls-cert'] ?? Config::env('TELNET_TLS_CERT', '') ?: null;
    $tlsKey  = $args['tls-key']  ?? Config::env('TELNET_TLS_KEY', '')  ?: null;
    $server->setTls($tlsPort, $tlsCert, $tlsKey);
}

// Set PID file path for daemon mode
$pidFile = $args['pid-file'] ?? dirname(__DIR__) . '/data/run/telnetd.pid';
$server->setPidFile($pidFile);

// Start the server (this will daemonize if needed and write PID file)
$server->start($daemonMode);
