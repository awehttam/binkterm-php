#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

/**
 * Convert Caddy JSON access logs to Apache common log format.
 *
 * Usage:
 *   php scripts/caddylog.php /path/to/caddy.log
 *   php scripts/caddylog.php --file=/path/to/caddy.log
 *   php scripts/caddylog.php --file=/path/to/caddy.log --tail
 */

main($argv);

function main(array $argv): void
{
    [$options, $positional] = parseArgs($argv);

    if (!empty($options['help'])) {
        printUsage($argv[0] ?? 'scripts/caddylog.php');
        exit(0);
    }

    $logFile = (string)($options['file'] ?? ($positional[0] ?? ''));
    if ($logFile === '') {
        fwrite(STDERR, "Error: missing Caddy log file path.\n\n");
        printUsage($argv[0] ?? 'scripts/caddylog.php');
        exit(1);
    }

    if (!is_file($logFile)) {
        fwrite(STDERR, "Error: log file not found: {$logFile}\n");
        exit(1);
    }

    if (!is_readable($logFile)) {
        fwrite(STDERR, "Error: log file is not readable: {$logFile}\n");
        exit(1);
    }

    if (!empty($options['tail'])) {
        tailLogFile($logFile);
        return;
    }

    processFile($logFile);
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

        if ($arg === '--tail') {
            $options['tail'] = true;
            continue;
        }

        if (str_starts_with($arg, '--file=')) {
            $options['file'] = substr($arg, 7);
            continue;
        }

        if (str_starts_with($arg, '--')) {
            fwrite(STDERR, "Warning: ignoring unknown option {$arg}\n");
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
    echo "  php {$name} /path/to/caddy.log\n";
    echo "  php {$name} --file=/path/to/caddy.log [--tail]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --file=PATH   Path to the Caddy JSON access log file\n";
    echo "  --tail        Follow the file from EOF and emit converted lines in real time\n";
    echo "  --help        Show this help message\n";
    echo "\n";
}

function processFile(string $logFile): void
{
    $handle = fopen($logFile, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Failed to open log file: {$logFile}");
    }

    try {
        while (($line = fgets($handle)) !== false) {
            emitConvertedLine($line, $logFile);
        }
    } finally {
        fclose($handle);
    }
}

function tailLogFile(string $logFile): void
{
    $size = @filesize($logFile);
    if ($size === false) {
        throw new RuntimeException("Failed to stat log file: {$logFile}");
    }

    $position = (int)$size;
    $partial = '';

    while (true) {
        clearstatcache(true, $logFile);
        $size = @filesize($logFile);
        if ($size === false) {
            usleep(250000);
            continue;
        }

        // Handle truncation or rotation to a smaller file.
        if ($size < $position) {
            $position = 0;
            $partial = '';
        }

        if ($size > $position) {
            $handle = fopen($logFile, 'rb');
            if ($handle === false) {
                usleep(250000);
                continue;
            }

            fseek($handle, $position);
            $chunk = stream_get_contents($handle);
            fclose($handle);

            if ($chunk !== false && $chunk !== '') {
                $position += strlen($chunk);
                $partial .= $chunk;

                while (($newlinePos = strpos($partial, "\n")) !== false) {
                    $line = substr($partial, 0, $newlinePos + 1);
                    $partial = substr($partial, $newlinePos + 1);
                    emitConvertedLine($line, $logFile);
                }
            }
        }

        usleep(250000);
    }
}

function emitConvertedLine(string $line, string $source): void
{
    $converted = convertCaddyLogLineToCommon($line);
    if ($converted === null) {
        $trimmed = trim($line);
        if ($trimmed !== '') {
            fwrite(STDERR, "Warning: skipped unparsable line from {$source}\n");
        }
        return;
    }

    echo $converted, PHP_EOL;
    flush();
}

function convertCaddyLogLineToCommon(string $line): ?string
{
    $line = trim($line);
    if ($line === '') {
        return null;
    }

    try {
        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return null;
    }

    if (!is_array($row)) {
        return null;
    }

    $request = is_array($row['request'] ?? null) ? $row['request'] : [];

    $remoteHost = firstNonEmptyString([
        $request['client_ip'] ?? null,
        $request['remote_ip'] ?? null,
        $row['remote_ip'] ?? null,
    ]) ?? '-';

    $user = firstNonEmptyString([
        $row['user_id'] ?? null,
        $request['remote_user'] ?? null,
        $request['user_id'] ?? null,
    ]) ?? '-';

    $time = formatApacheTime($row['ts'] ?? $row['time'] ?? null);

    $method = firstNonEmptyString([
        $request['method'] ?? null,
        $row['method'] ?? null,
    ]) ?? 'GET';

    $uri = firstNonEmptyString([
        $request['uri'] ?? null,
        $request['path'] ?? null,
        $row['uri'] ?? null,
    ]) ?? '/';

    $proto = firstNonEmptyString([
        $request['proto'] ?? null,
        $row['proto'] ?? null,
    ]) ?? 'HTTP/1.1';

    $requestLine = sprintf('%s %s %s', $method, $uri, $proto);

    $status = extractStatusCode($row, $request);
    $bytes = extractResponseSize($row);

    return sprintf(
        '%s - %s [%s] "%s" %s %s',
        $remoteHost,
        $user,
        $time,
        escapeRequestLine($requestLine),
        $status,
        $bytes
    );
}

function extractStatusCode(array $row, array $request): string
{
    $candidates = [
        $row['status'] ?? null,
        $row['resp']['status'] ?? null,
        $request['status'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_int($candidate) || (is_string($candidate) && ctype_digit($candidate))) {
            return (string)$candidate;
        }
    }

    return '000';
}

function extractResponseSize(array $row): string
{
    $candidates = [
        $row['size'] ?? null,
        $row['bytes_written'] ?? null,
        $row['resp']['size'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_int($candidate)) {
            return (string)$candidate;
        }
        if (is_string($candidate) && ctype_digit($candidate)) {
            return $candidate;
        }
    }

    return '-';
}

function formatApacheTime(mixed $value): string
{
    try {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $seconds = (float)$value;
            $date = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $seconds));
            if ($date === false) {
                $date = new DateTimeImmutable('@' . (string)((int)$seconds));
            }
            return $date->setTimezone(new DateTimeZone(date_default_timezone_get()))
                ->format('d/M/Y:H:i:s O');
        }

        if (is_string($value) && $value !== '') {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                ->format('d/M/Y:H:i:s O');
        }
    } catch (Throwable $e) {
        // Fall through to current time.
    }

    return (new DateTimeImmutable('now'))
        ->format('d/M/Y:H:i:s O');
}

function firstNonEmptyString(array $values): ?string
{
    foreach ($values as $value) {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }
    }

    return null;
}

function escapeRequestLine(string $requestLine): string
{
    return str_replace('"', '\"', $requestLine);
}
