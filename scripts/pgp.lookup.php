#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\PgpLookupService;

function showUsage(): void
{
    echo "Usage: php scripts/pgp.lookup.php <search> [--address=FTN] [--get]\n";
    echo "\n";
    echo "Perform a destination-aware PGP lookup using the same local/remote logic\n";
    echo "as netmail compose.\n";
    echo "\n";
    echo "Options:\n";
    echo "  --address=FTN  Destination FTN address. Blank means local delivery.\n";
    echo "  --get          Fetch one armored public key instead of listing matches.\n";
    echo "  --help         Show this help.\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php scripts/pgp.lookup.php sysop\n";
    echo "  php scripts/pgp.lookup.php 1:153/149 --address=1:153/149 --get\n";
    echo "  php scripts/pgp.lookup.php alice@example.com --address=2:280/555\n";
}

/**
 * @return array{search:string,address:string,op:string}
 */
function parseArgs(array $argv): array
{
    $search = '';
    $address = '';
    $op = 'index';

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            showUsage();
            exit(0);
        }

        if ($arg === '--get') {
            $op = 'get';
            continue;
        }

        if (str_starts_with($arg, '--address=')) {
            $address = trim((string)substr($arg, 10));
            continue;
        }

        if (str_starts_with($arg, '--')) {
            fwrite(STDERR, "Unknown option: {$arg}\n");
            showUsage();
            exit(1);
        }

        if ($search === '') {
            $search = trim((string)$arg);
            continue;
        }

        fwrite(STDERR, "Unexpected argument: {$arg}\n");
        showUsage();
        exit(1);
    }

    if ($search === '') {
        showUsage();
        exit(1);
    }

    return [
        'search' => $search,
        'address' => $address,
        'op' => $op,
    ];
}

/**
 * @param array<string, mixed> $target
 */
function printLookupHeader(array $target, string $search, string $op): void
{
    echo 'Lookup mode: ' . $op . "\n";
    echo 'Search: ' . $search . "\n";
    echo 'Query type: ' . ($target['type'] ?? 'unknown') . "\n";

    $address = trim((string)($target['address'] ?? ''));
    if ($address === '') {
        echo "Destination address: [local delivery]\n";
    } else {
        echo 'Destination address: ' . $address . "\n";
    }

    if (($target['type'] ?? '') === 'remote') {
        $baseUrl = (string)($target['base_url'] ?? '');
        $source = (string)($target['source'] ?? 'remote');
        if ($baseUrl !== '') {
            echo 'Remote lookup source: ' . $source . "\n";
            echo 'Remote API endpoint: ' . $baseUrl . '?op=' . $op . '&search=' . rawurlencode($search) . "\n";
        } else {
            echo "Remote lookup source: unresolved\n";
            echo "Remote API endpoint: [unresolved]\n";
        }
    }

    echo "\n";
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function printIndexResults(array $rows): void
{
    echo 'Matches: ' . count($rows) . "\n";
    if ($rows === []) {
        return;
    }

    foreach ($rows as $index => $row) {
        $fingerprint = strtoupper((string)($row['fingerprint'] ?? ''));
        $uid = trim((string)($row['user_id_string'] ?? ''));
        $username = trim((string)($row['username'] ?? ''));
        $algorithm = trim((string)($row['key_algorithm'] ?? ''));
        $created = trim((string)($row['key_created_at'] ?? ''));
        $source = trim((string)($row['lookup_source'] ?? ''));

        echo ($index + 1) . ". {$fingerprint}\n";
        echo '   Identity: ' . ($uid !== '' ? $uid : ($username !== '' ? $username : '[unknown]')) . "\n";
        echo '   Algorithm: ' . ($algorithm !== '' ? $algorithm : '[unknown]') . "\n";
        echo '   Created: ' . ($created !== '' ? $created : '[unknown]') . "\n";
        echo '   Source: ' . ($source !== '' ? $source : '[unknown]') . "\n";
    }
}

/**
 * @param array<string, mixed>|null $row
 */
function printGetResult(?array $row): void
{
    if ($row === null) {
        echo "Match: not found\n";
        return;
    }

    $fingerprint = strtoupper((string)($row['fingerprint'] ?? ''));
    $uid = trim((string)($row['user_id_string'] ?? ''));
    $email = trim((string)($row['email'] ?? ''));
    $algorithm = trim((string)($row['key_algorithm'] ?? ''));
    $created = trim((string)($row['key_created_at'] ?? ''));
    $source = trim((string)($row['lookup_source'] ?? ''));
    $armored = trim((string)($row['armored_public_key'] ?? ''));

    echo "Match: found\n";
    echo 'Fingerprint: ' . ($fingerprint !== '' ? $fingerprint : '[unknown]') . "\n";
    echo 'Identity: ' . ($uid !== '' ? $uid : '[unknown]') . "\n";
    echo 'Email: ' . ($email !== '' ? $email : '[unknown]') . "\n";
    echo 'Algorithm: ' . ($algorithm !== '' ? $algorithm : '[unknown]') . "\n";
    echo 'Created: ' . ($created !== '' ? $created : '[unknown]') . "\n";
    echo 'Source: ' . ($source !== '' ? $source : '[unknown]') . "\n";
    echo "\n";
    echo $armored . "\n";
}

$args = parseArgs($argv);
$service = new PgpLookupService();
$target = $service->describeLookupTarget($args['address']);

printLookupHeader($target, $args['search'], $args['op']);

if ($args['op'] === 'get') {
    printGetResult($service->findPublicKeyForDestination($args['search'], $args['address']));
    exit(0);
}

printIndexResults($service->searchPublicKeysForDestination($args['search'], $args['address']));
