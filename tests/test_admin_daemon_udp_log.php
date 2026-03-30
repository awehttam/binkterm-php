#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Config;

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

    $logFile = (string)($options['file'] ?? 'server.log');

    $levelValue = resolveLevelValue((string)($options['level'] ?? 'INFO'));
    if ($levelValue === null) {
        fwrite(STDERR, "Unknown level: " . ($options['level'] ?? '') . "\n");
        exit(1);
    }

    $message = (string)($options['message'] ?? ($positional[0] ?? 'test admin daemon udp log'));
    if (!mb_check_encoding($message, 'UTF-8')) {
        fwrite(STDERR, "Message must be valid UTF-8.\n");
        exit(1);
    }

    if (strlen($message) > 1200) {
        $message = substr($message, 0, 1200);
    }

    $packet = buildPacket($logFile, $levelValue, $message);
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
    echo "File={$logFile} Level={$levelValue} PID=" . getmypid() . " Message=\"{$message}\"\n";
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
    echo "  php {$name} [--socket=tcp://127.0.0.1:9065] [--file=server.log] [--level=INFO] [--message=\"hello\"]\n";
    echo "  php {$name} [message]\n\n";
    echo "Options:\n";
    echo "  --file    Basename of the target log file (e.g. server.log, packets.log)\n";
    echo "  --level   Log level: DEBUG, INFO, WARNING, ERROR\n";
    echo "  --message Log message text\n";
    echo "  --socket  Admin daemon socket (default: tcp://127.0.0.1:9065)\n";
}

function socketTargetToUdpTarget(string $socketTarget): ?string
{
    if (preg_match('#^tcp://([^:]+):(\\d+)$#', $socketTarget, $matches) !== 1) {
        return null;
    }

    return 'udp://' . $matches[1] . ':' . $matches[2];
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

function buildPacket(string $logFile, int $level, string $message): string
{
    $timestampMs = (int) floor(microtime(true) * 1000);
    $pid = (int) getmypid();
    $filenameBytes = substr($logFile, 0, 255);
    $filenameLen = strlen($filenameBytes);
    $messageLen = strlen($message);

    // Packet layout: uint64 timestamp | uint8 level | uint32 pid | uint8 filenameLen | filename | uint16 msgLen | message
    return packUint64BE($timestampMs)
        . pack('C', $level)
        . pack('N', $pid)
        . pack('C', $filenameLen)
        . $filenameBytes
        . pack('n', $messageLen)
        . $message;
}

function packUint64BE(int $value): string
{
    $hi = ($value >> 32) & 0xFFFFFFFF;
    $lo = $value & 0xFFFFFFFF;
    return pack('NN', $hi, $lo);
}
