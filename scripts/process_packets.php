#!/usr/bin/env php
<?php

chdir(__DIR__);

// Packet processing script - run this via cron or as a service

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\BinkdProcessor;
use BinktermPHP\Binkp\Logger;
use BinktermPHP\Config;
use BinktermPHP\Database;
use BinktermPHP\TicFileProcessor;

// Initialize database
Database::getInstance();

$logger = new Logger(
    Config::getLogPath('packets.log'),
    Config::env('PROCESS_PACKETS_LOG_LEVEL', 'INFO'),
    true
);

// Ensure only one instance runs at a time
$lockFile = __DIR__ . '/../data/run/process_packets.lock';
if (!is_dir(dirname($lockFile))) {
    mkdir(dirname($lockFile), 0755, true);
}
$lockFh = fopen($lockFile, 'c');
if (!$lockFh || !flock($lockFh, LOCK_EX | LOCK_NB)) {
    $logger->warning('Another instance of process_packets.php is already running. Exiting.');
    exit(0);
}

/**
 * Process TIC files from inbound directory.
 */
function processInboundTicFiles(TicFileProcessor $ticProcessor, Logger $logger): int
{
    $inboundPath = __DIR__ . '/../data/inbound';
    $processedCount = 0;

    // Find all .tic files (both lowercase and uppercase)
    $ticFiles = array_merge(
        glob($inboundPath . '/*.tic') ?: [],
        glob($inboundPath . '/*.TIC') ?: []
    );
    if (!$ticFiles || count($ticFiles) === 0) {
        return 0;
    }

    foreach ($ticFiles as $ticPath) {
        $ticFilename = basename($ticPath);

        // Determine data filename (TIC usually references filename without .tic)
        // Read first few lines to get the File field
        $ticContent = file_get_contents($ticPath);
        if (preg_match('/^File\s+(.+)$/im', $ticContent, $matches)) {
            $dataFilename = trim($matches[1]);
            $dataPath = $inboundPath . '/' . $dataFilename;

            if (file_exists($dataPath)) {
                // Both TIC and data file exist - process them
                $logger->info("Processing TIC: $ticFilename -> $dataFilename");

                $result = $ticProcessor->processTicFile($ticPath, $dataPath);

                if ($result['success']) {
                    $logger->info("  OK Stored in area: {$result['area']} (file_id={$result['file_id']})");

                    if (isset($result['duplicate']) && $result['duplicate']) {
                        $logger->warning("  WARN Duplicate file (skipped)");
                    }

                    // Clean up TIC file (data file was cleaned up by processor)
                    unlink($ticPath);
                    $processedCount++;
                } else {
                    $errMsg  = $result['error']      ?? 'unknown error';
                    $errCode = $result['error_code'] ?? '';
                    $logger->error("[TIC] FAILED {$ticFilename}/{$dataFilename}: [{$errCode}] {$errMsg}");

                    // Move failed TIC to .failed directory for manual review
                    $failedDir = $inboundPath . '/.failed';
                    if (!is_dir($failedDir)) {
                        mkdir($failedDir, 0755, true);
                    }
                    rename($ticPath, $failedDir . '/' . $ticFilename);
                    if (file_exists($dataPath)) {
                        rename($dataPath, $failedDir . '/' . $dataFilename);
                    }
                }
            } else {
                // TIC exists but data file missing - will be retried next run
                $logger->warning("TIC file without data file: $ticFilename (waiting for $dataFilename)");
            }
        } else {
            $logger->error("Invalid TIC file (no File field): $ticFilename");
            unlink($ticPath);
        }
    }

    return $processedCount;
}

// Create processors
$processor    = new BinkdProcessor();
$ticProcessor = new TicFileProcessor();

try {
    $logger->info('Starting packet processing...');

    // Process inbound packets
    $processed = $processor->processInboundPackets();
    $logger->info("Processed {$processed} inbound packets");

    // Process TIC files (if feature is enabled)
    if (\BinktermPHP\FileAreaManager::isFeatureEnabled()) {
        $ticProcessed = processInboundTicFiles($ticProcessor, $logger);
        if ($ticProcessed > 0) {
            $logger->info("Processed {$ticProcessed} TIC files");
        }
    }

    // Clean up old packet records (older than 6 months)
    $cleaned = $processor->cleanupOldPackets();
    if ($cleaned) {
        $logger->info("Cleaned up {$cleaned} old packet records");
    }

    // Keep unprocessed files only when explicitly enabled in .env.
    $keepUnprocessedFiles = filter_var(
        (string) Config::env('BINKP_KEEP_UNPROCESSED_FILES', 'false'),
        FILTER_VALIDATE_BOOLEAN
    );

    // Handle any unrecognized/unprocessed files in inbound.
    // TIC files are left in place because they may still be waiting for their data file.
    $inboundPath    = __DIR__ . '/../data/inbound';
    $unprocessedDir = $inboundPath . '/unprocessed';
    $leftover       = array_filter(glob($inboundPath . '/*') ?: [], 'is_file');

    if ($keepUnprocessedFiles && !is_dir($unprocessedDir)) {
        mkdir($unprocessedDir, 0755, true);
    }

    foreach ($leftover as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'tic') {
            continue; // Leave TIC files — they may still be waiting for their data file
        }
        if ($ext === 'tmp') {
            continue; // Leave .tmp files — they are being actively received by the binkp session
        }

        if ($keepUnprocessedFiles) {
            $dest = $unprocessedDir . '/' . basename($file);
            if (rename($file, $dest)) {
                $logger->info('Moved unprocessed file to unprocessed/: ' . basename($file));
            }
            continue;
        }

        if (unlink($file)) {
            $logger->info('Deleted unprocessed file: ' . basename($file));
        }
    }

    // TODO: Process outbound queue (send pending messages)

    $logger->info('Packet processing completed');
} catch (Exception $e) {
    $logger->error('Error: ' . $e->getMessage());
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    exit(1);
}

flock($lockFh, LOCK_UN);
fclose($lockFh);
