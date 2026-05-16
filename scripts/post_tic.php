#!/usr/bin/env php
<?php
/**
 * Post a file to outbound with a generated TIC file.
 *
 * Usage: php postticfile.php <filename> <areatag> --domain=DOMAIN [--outbound=PATH]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Database;

function printUsage(): void
{
    echo "Usage: php postticfile.php <filename> <areatag> --domain=DOMAIN [--outbound=PATH]\n";
    echo "Example: php postticfile.php NODELIST.Z30 NODELIST --domain=localnet\n";
}

function parseArgs(array $argv): array
{
    $args = [
        'filename' => $argv[1] ?? null,
        'areatag' => $argv[2] ?? null,
        'domain' => null,
        'outbound' => null
    ];

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--domain=')) {
            $args['domain'] = substr($arg, strlen('--domain='));
        } elseif (str_starts_with($arg, '--outbound=')) {
            $args['outbound'] = substr($arg, strlen('--outbound='));
        }
    }

    return $args;
}

function resolveFilePath(PDO $db, string $filename, array $fileArea): string
{
    if (file_exists($filename)) {
        return realpath($filename) ?: $filename;
    }

    $dirName = $fileArea['tag'] . '-' . $fileArea['id'];
    $candidate = __DIR__ . '/../data/files/' . $dirName . '/' . $filename;
    if (file_exists($candidate)) {
        return realpath($candidate) ?: $candidate;
    }

    $stmt = $db->prepare("SELECT storage_path FROM files WHERE file_area_id = ? AND filename = ? LIMIT 1");
    $stmt->execute([$fileArea['id'], $filename]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['storage_path']) && file_exists($row['storage_path'])) {
        return realpath($row['storage_path']) ?: $row['storage_path'];
    }

    throw new RuntimeException("File not found: {$filename}");
}

function loadFileRecord(PDO $db, int $areaId, string $filename): array
{
    $stmt = $db->prepare("SELECT * FROM files WHERE file_area_id = ? AND filename = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$areaId, $filename]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

function calculateCrc32(string $filePath): string
{
    $content = file_get_contents($filePath);
    return strtoupper(dechex(crc32($content)));
}

function copyToOutbound(string $sourcePath, string $outboundDir, string $filename): string
{
    if (!is_dir($outboundDir)) {
        mkdir($outboundDir, 0755, true);
    }

    $targetPath = rtrim($outboundDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    if (file_exists($targetPath)) {
        if (hash_file('sha256', $targetPath) === hash_file('sha256', $sourcePath)) {
            return $targetPath;
        }

        $counter = 1;
        $pathInfo = pathinfo($filename);
        while (file_exists($targetPath)) {
            $versioned = $pathInfo['filename'] . '_' . $counter;
            if (!empty($pathInfo['extension'])) {
                $versioned .= '.' . $pathInfo['extension'];
            }
            $targetPath = rtrim($outboundDir, '/\\') . DIRECTORY_SEPARATOR . $versioned;
            $counter++;
        }
    }

    if (!copy($sourcePath, $targetPath)) {
        throw new RuntimeException("Failed to copy file to outbound: {$targetPath}");
    }

    return $targetPath;
}

function writeTicFile(array $fileArea, array $fileRecord, string $filename, string $sourcePath, string $outboundDir): string
{
    $config = BinkpConfig::getInstance();
    $fromAddress = $config->getSystemAddress();

    $lines = [];
    $lines[] = 'Area ' . strtoupper($fileArea['tag']);
    $lines[] = 'File ' . $filename;

    if (!empty($fileRecord['short_description'])) {
        $lines[] = 'Desc ' . $fileRecord['short_description'];
    }

    if (!empty($fileRecord['long_description'])) {
        $longDescLines = explode("\n", $fileRecord['long_description']);
        foreach ($longDescLines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = 'LDesc ' . $line;
            }
        }
    }

    $lines[] = 'From ' . $fromAddress;
    $lines[] = 'Path ' . $fromAddress . ' ' . time();
    $lines[] = 'Seenby ' . $fromAddress;
    $lines[] = 'Created BinktermPHP ' . \BinktermPHP\Version::getVersion();
    $lines[] = 'Date ' . date('D, d M Y H:i:s O');
    $lines[] = 'Size ' . filesize($sourcePath);
    $lines[] = 'Crc ' . calculateCrc32($sourcePath);

    $ticFilename = bin2hex(random_bytes(4)) . '.tic';
    $ticPath = rtrim($outboundDir, '/\\') . DIRECTORY_SEPARATOR . $ticFilename;
    while (file_exists($ticPath)) {
        $ticFilename = bin2hex(random_bytes(4)) . '.tic';
        $ticPath = rtrim($outboundDir, '/\\') . DIRECTORY_SEPARATOR . $ticFilename;
    }

    $content = implode("\r\n", $lines) . "\r\n";
    if (file_put_contents($ticPath, $content) === false) {
        throw new RuntimeException("Failed to write TIC file: {$ticPath}");
    }

    return $ticPath;
}

$args = parseArgs($argv);
if (!$args['filename'] || !$args['areatag'] || !$args['domain']) {
    printUsage();
    exit(1);
}

$db = Database::getInstance()->getPdo();

$stmt = $db->prepare("SELECT * FROM file_areas WHERE tag = ? AND domain = ? LIMIT 1");
$stmt->execute([strtoupper($args['areatag']), $args['domain']]);
$fileArea = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$fileArea) {
    echo "File area not found for tag {$args['areatag']} and domain {$args['domain']}\n";
    exit(1);
}

try {
    $sourcePath = resolveFilePath($db, $args['filename'], $fileArea);
    $fileRecord = loadFileRecord($db, (int)$fileArea['id'], basename($sourcePath));

    $outboundDir = $args['outbound'];
    if (!$outboundDir) {
        $config = BinkpConfig::getInstance();
        $outboundDir = $config->getOutboundPath();
    }

    $copiedPath = copyToOutbound($sourcePath, $outboundDir, basename($sourcePath));
    $ticPath = writeTicFile($fileArea, $fileRecord, basename($copiedPath), $sourcePath, $outboundDir);

    echo "Copied to: {$copiedPath}\n";
    echo "TIC created: {$ticPath}\n";
    exit(0);
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
