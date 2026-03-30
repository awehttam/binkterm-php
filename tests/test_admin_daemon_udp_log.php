#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Config;

const TEST_UDP_TAGS = [
    'server' => 0,
    'packets' => 1,
    'multiplexing_server' => 2,
    'binkp_poll' => 3,
    'binkp_server' => 4,
    'binkp_scheduler' => 5,
    'admin_daemon' => 6,
    'mrc_daemon' => 7,
    'crashmail' => 8,
];

main($argv);

function main(array $argv): void
{
    [$options, $positional] = parseArgs($argv);

    if (!empty($options['help'])) {
        printUsage($argv[0] ?? 'tests/test_admin_daemon_udp_log.php');
        exit(0);
    }

    $socketTarget = (string)($options['socket'] ?? (Config::env('ADMIN_DAEMON_SOCKET') ?: 'tcp://127.0.0.1:9065'));
    $udpTarget = socketTargetToUdpTarget($socketTarget);
    if ($udpTarget === null) {
        fwrite(STDERR, "Unsupported socket target for UDP logging: {$socketTarget}\n");
        exit(1);
    }

    $tagInput = strtolower((string)($options['tag'] ?? 'server'));
    $tagValue = resolveTagValue($tagInput);
    if ($tagValue === null) {
        fwrite(STDERR, "Unknown tag: {$tagInput}\n");
        exit(1);
    }

    $levelValue = resolveLevelValue((string)($options['level'] ?? 'INFO'));
    if ($levelValue === null) {
        fwrite(STDERR, "Unknown level: " . ($options['level'] ?? '') . "\n");
        exit(1);
    }

    $message = (string)($options['message'] ?? ($positional[0] ?? 'test admin daemon udp log'));
    $messageBytes = $message;
    if (!mb_check_encoding($messageBytes, 'UTF-8')) {
        fwrite(STDERR, "Message must be valid UTF-8.\n");
        exit(1);
    }

    if (strlen($messageBytes) > 1200) {
        $messageBytes = substr($messageBytes, 0, 1200);
    }

    $packet = buildPacket($tagValue, $levelValue, $messageBytes);
    $socket = @stream_socket_client($udpTarget, $errno, $errstr, 2, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        fwrite(STDERR, "Failed to connect to {$udpTarget}: {$errstr} ({$errno})\n");
        exit(1);
    }

    $written = @fwrite($socket, $packet);
    fclose($socket);

    if ($written === false || $written !== strlen($packet)) {
        fwrite(STDERR, "Failed to send full UDP packet.\n");
        exit(1);
    }

    echo "Sent UDP log packet to {$udpTarget}\n";
    echo "Tag={$tagValue} Level={$levelValue} PID=" . getmypid() . " Message=\"{$messageBytes}\"\n";
}

function parseArgs(array $argv): array
{
    $options = [];
    $positional = [];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }

        if (str_starts_with($arg, '--') && strpos($arg, '=') !== false) {
            [$key, $value] = explode('=', substr($arg, 2), 2);
            $options[$key] = $value;
            continue;
        }

        $positional[] = $arg;
    }

    return [$options, $positional];
}

function printUsage(string $script): void
{
    $name = basename($script);
    echo "Usage:\n";
    echo "  php {$name} [--socket=tcp://127.0.0.1:9065] [--tag=server] [--level=INFO] [--message=\"hello\"]\n";
    echo "  php {$name} [message]\n\n";
    echo "Tags:\n";
    foreach (TEST_UDP_TAGS as $nameKey => $value) {
        echo "  {$nameKey} = {$value}\n";
    }
    echo "\nLevels: DEBUG, INFO, WARNING, ERROR\n";
}

function socketTargetToUdpTarget(string $socketTarget): ?string
{
    if (preg_match('#^tcp://([^:]+):(\\d+)$#', $socketTarget, $matches) !== 1) {
        return null;
    }

    return 'udp://' . $matches[1] . ':' . $matches[2];
}

function resolveTagValue(string $tagInput): ?int
{
    if ($tagInput !== '' && ctype_digit($tagInput)) {
        $value = (int)$tagInput;
        return ($value >= 0 && $value <= 255) ? $value : null;
    }

    return TEST_UDP_TAGS[$tagInput] ?? null;
}

function resolveLevelValue(string $level): ?int
{
    $map = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
    ];

    return $map[strtoupper($level)] ?? null;
}

function buildPacket(int $tag, int $level, string $message): string
{
    $timestampMs = (int) floor(microtime(true) * 1000);
    $pid = (int) getmypid();
    $messageLen = strlen($message);

    return packUint64BE($timestampMs)
        . pack('C', $tag)
        . pack('C', $level)
        . pack('N', $pid)
        . pack('n', $messageLen)
        . $message;
}

function packUint64BE(int $value): string
{
    $hi = ($value >> 32) & 0xFFFFFFFF;
    $lo = $value & 0xFFFFFFFF;
    return pack('NN', $hi, $lo);
}
