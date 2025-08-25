<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Binktest\Binkp\Protocol\BinkpServer;
use Binktest\Binkp\Config\BinkpConfig;
use Binktest\Binkp\Logger;

function showUsage()
{
    echo "Usage: php binkp_server.php [options]\n";
    echo "Options:\n";
    echo "  --port=PORT       Listen on specific port (default: from config)\n";
    echo "  --bind=ADDRESS    Bind to specific address (default: from config)\n";
    echo "  --log-level=LEVEL Log level: DEBUG, INFO, WARNING, ERROR, CRITICAL\n";
    echo "  --log-file=FILE   Log file path (default: data/logs/binkp_server.log)\n";
    echo "  --no-console      Disable console logging\n";
    echo "  --daemon          Run as daemon (detach from terminal)\n";
    echo "  --help            Show this help message\n";
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
        echo "Server started with PID: $pid\n";
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
    $config = BinkpConfig::getInstance();
    
    if (isset($args['port'])) {
        $config->setBinkpConfig((int) $args['port']);
    }
    
    if (isset($args['bind'])) {
        $config->setBinkpConfig(null, null, null, $args['bind']);
    }
    
    $logLevel = isset($args['log-level']) ? $args['log-level'] : 'INFO';
    $logFile = isset($args['log-file']) ? $args['log-file'] : 'data/logs/binkp_server.log';
    $logToConsole = !isset($args['no-console']);
    
    $logger = new Logger($logFile, $logLevel, $logToConsole);
    
    if (isset($args['daemon']) && function_exists('pcntl_fork')) {
        $logger->setLogToConsole(false);
        daemonize();
    }
    
    $server = new BinkpServer($config, $logger);
    
    $logger->info("Starting Binkp server...");
    $logger->info("System address: " . $config->getSystemAddress());
    $logger->info("Listening on: " . $config->getBindAddress() . ":" . $config->getBinkpPort());
    $logger->info("Max connections: " . $config->getMaxConnections());
    $logger->info("Timeout: " . $config->getBinkpTimeout() . "s");
    
    $uplinks = $config->getUplinks();
    $logger->info("Configured uplinks: " . count($uplinks));
    
    foreach ($uplinks as $uplink) {
        $status = ($uplink['enabled'] ?? true) ? 'enabled' : 'disabled';
        $logger->info("  - {$uplink['address']} ({$uplink['hostname']}) [{$status}]");
    }
    
    pcntl_signal(SIGTERM, function() use ($server, $logger) {
        $logger->info("Received SIGTERM, shutting down...");
        $server->stop();
    });
    
    pcntl_signal(SIGINT, function() use ($server, $logger) {
        $logger->info("Received SIGINT, shutting down...");
        $server->stop();
    });
    
    $server->start();
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}