#!/usr/bin/php
<?php

chdir(__DIR__ . "/../");

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Admin\AdminDaemonClient;

function showUsage()
{
    echo "Usage: php admin_client.php [options] <command> [args]\n";
    echo "Commands:\n";
    echo "  process-packets             Run packet processing\n";
    echo "  binkp-poll <upstream|all>    Poll a specific uplink or all uplinks\n";
    echo "Options:\n";
    echo "  --socket=TARGET              Socket target override\n";
    echo "  --secret=SECRET              Shared secret override\n";
    echo "  --help                       Show this help message\n";
    echo "\n";
}

function parseArgs($argv)
{
    $args = [];
    $positional = [];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];

        if (strpos($arg, '--') === 0) {
            if (strpos($arg, '=') !== false) {
                list($key, $value) = explode('=', substr($arg, 2), 2);
                $args[$key] = $value;
            } else {
                $args[substr($arg, 2)] = true;
            }
        } else {
            $positional[] = $arg;
        }
    }

    return [$args, $positional];
}

function printResult(array $result): void
{
    $exitCode = $result['exit_code'] ?? null;
    $stdout = $result['stdout'] ?? '';
    $stderr = $result['stderr'] ?? '';

    if ($stdout !== '') {
        echo $stdout;
        if (substr($stdout, -1) !== "\n") {
            echo "\n";
        }
    }

    if ($stderr !== '') {
        fwrite(STDERR, $stderr);
        if (substr($stderr, -1) !== "\n") {
            fwrite(STDERR, "\n");
        }
    }

    if ($exitCode !== null) {
        echo "Exit code: {$exitCode}\n";
    }
}

list($args, $positional) = parseArgs($argv);

if (isset($args['help']) || empty($positional)) {
    showUsage();
    exit(isset($args['help']) ? 0 : 1);
}

$command = array_shift($positional);

try {
    $client = new AdminDaemonClient($args['socket'] ?? null, $args['secret'] ?? null);

    switch ($command) {
        case 'process-packets':
            $result = $client->processPackets();
            printResult($result);
            break;
        case 'binkp-poll':
            $upstream = $positional[0] ?? null;
            if (!$upstream) {
                fwrite(STDERR, "Error: upstream address required\n");
                exit(1);
            }
            $result = $client->binkPoll($upstream);
            printResult($result);
            break;
        default:
            fwrite(STDERR, "Error: unknown command\n");
            showUsage();
            exit(1);
    }
} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
