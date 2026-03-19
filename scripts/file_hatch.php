#!/usr/bin/env php
<?php
/**
 * Re-hatch an existing file by regenerating outbound TIC files and copying the
 * file back into data/outbound for re-sending.
 *
 * Usage:
 *   php scripts/file_hatch.php --file-id=123
 *   php scripts/file_hatch.php FILENAME.ZIP AREATAG [--domain=DOMAIN]
 *
 * Lookup modes:
 *   --file-id=ID                 Re-hatch a file by files.id
 *   FILENAME AREATAG             Re-hatch the most recent matching file in area
 *
 * Options:
 *   --domain=DOMAIN              File area domain when using filename lookup
 *   --allow-nonapproved          Allow rehatching files not in approved status
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;
use BinktermPHP\FileAreaManager;
use BinktermPHP\TicFileGenerator;

function printUsage(): void
{
    echo "Usage:\n";
    echo "  php scripts/file_hatch.php --file-id=123\n";
    echo "  php scripts/file_hatch.php FILENAME.ZIP AREATAG [--domain=DOMAIN]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --file-id=ID         Re-hatch a file by files.id\n";
    echo "  --domain=DOMAIN      File area domain for filename lookup\n";
    echo "  --allow-nonapproved  Allow rehatching files not in approved status\n";
}

function parseArgs(array $argv): array
{
    $args = [
        'file_id' => null,
        'filename' => null,
        'area_tag' => null,
        'domain' => '',
        'allow_nonapproved' => false,
    ];

    $positional = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--file-id=')) {
            $args['file_id'] = (int) substr($arg, strlen('--file-id='));
        } elseif (str_starts_with($arg, '--domain=')) {
            $args['domain'] = substr($arg, strlen('--domain='));
        } elseif ($arg === '--allow-nonapproved') {
            $args['allow_nonapproved'] = true;
        } elseif (!str_starts_with($arg, '--')) {
            $positional[] = $arg;
        }
    }

    if ($args['file_id'] === null) {
        $args['filename'] = $positional[0] ?? null;
        $args['area_tag'] = isset($positional[1]) ? strtoupper($positional[1]) : null;
    }

    return $args;
}

function findFileByName(PDO $db, int $fileAreaId, string $filename): ?array
{
    $stmt = $db->prepare("
        SELECT *
        FROM files
        WHERE file_area_id = ?
          AND LOWER(filename) = LOWER(?)
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$fileAreaId, $filename]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

$args = parseArgs($argv);

if (($args['file_id'] ?? null) === null && (!$args['filename'] || !$args['area_tag'])) {
    printUsage();
    exit(1);
}

$db = Database::getInstance()->getPdo();
$manager = new FileAreaManager();

try {
    if ($args['file_id'] !== null) {
        $file = $manager->getFileById((int) $args['file_id']);
        if (!$file) {
            throw new RuntimeException("File ID {$args['file_id']} not found");
        }

        $fileArea = $manager->getFileAreaById((int) $file['file_area_id']);
        if (!$fileArea) {
            throw new RuntimeException("File area {$file['file_area_id']} not found");
        }
    } else {
        $fileArea = $manager->getFileAreaByTag($args['area_tag'], $args['domain']);
        if (!$fileArea) {
            $domainText = $args['domain'] !== '' ? " in domain '{$args['domain']}'" : '';
            throw new RuntimeException("File area '{$args['area_tag']}' not found{$domainText}");
        }

        $file = findFileByName($db, (int) $fileArea['id'], (string) $args['filename']);
        if (!$file) {
            throw new RuntimeException("File '{$args['filename']}' not found in area {$fileArea['tag']}");
        }
    }

    if (!$args['allow_nonapproved'] && ($file['status'] ?? '') !== 'approved') {
        throw new RuntimeException("File status is '{$file['status']}' not 'approved' (use --allow-nonapproved to override)");
    }

    if (!empty($fileArea['is_local'])) {
        throw new RuntimeException("File area {$fileArea['tag']} is local-only; no TICs will be generated");
    }

    if (!empty($fileArea['is_private'])) {
        throw new RuntimeException("File area {$fileArea['tag']} is private; no TICs will be generated");
    }

    $sourcePath = $manager->resolveFilePath($file);
    if (!is_file($sourcePath)) {
        throw new RuntimeException("Source file not found on disk: {$sourcePath}");
    }

    $generator = new TicFileGenerator();
    $createdTics = $generator->createTicFilesForUplinks($file, $fileArea);

    if (count($createdTics) === 0) {
        throw new RuntimeException("No TIC files were created. Check that domain '{$fileArea['domain']}' has configured uplinks.");
    }

    echo "Re-hatched file:\n";
    echo "  ID:       {$file['id']}\n";
    echo "  Filename: {$file['filename']}\n";
    echo "  Area:     {$fileArea['tag']}@{$fileArea['domain']}\n";
    echo "  Source:   {$sourcePath}\n";
    echo "\n";
    echo "Queued in data/outbound:\n";
    echo "  File: {$file['filename']}\n";
    foreach ($createdTics as $ticPath) {
        echo "  TIC:  " . basename($ticPath) . "\n";
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
