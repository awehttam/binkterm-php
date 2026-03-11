#!/usr/bin/env php
<?php

chdir(__DIR__);

// Packet processing script - run this via cron or as a service

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\BinkdProcessor;
use BinktermPHP\Config;
use BinktermPHP\Database;
use BinktermPHP\TicFileProcessor;

// Initialize database
Database::getInstance();

// Ensure only one instance runs at a time
$lockFile = __DIR__ . '/../data/run/process_packets.lock';
if (!is_dir(dirname($lockFile))) {
    mkdir(dirname($lockFile), 0755, true);
}
$lockFh = fopen($lockFile, 'c');
if (!$lockFh || !flock($lockFh, LOCK_EX | LOCK_NB)) {
    echo "Another instance of process_packets.php is already running. Exiting.\n";
    exit(0);
}

/**
 * Process TIC files from inbound directory
 */
function processInboundTicFiles($ticProcessor)
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
                echo "Processing TIC: $ticFilename -> $dataFilename\n";

                $result = $ticProcessor->processTicFile($ticPath, $dataPath);

                if ($result['success']) {
                    echo "  OK Stored in area: {$result['area']} (file_id={$result['file_id']})\n";

                    if (isset($result['duplicate']) && $result['duplicate']) {
                        echo "  WARN Duplicate file (skipped)\n";
                    }

                    // Clean up TIC file (data file was cleaned up by processor)
                    unlink($ticPath);
                    $processedCount++;
                } else {
                    $errMsg = $result['error'] ?? 'unknown error';
                    $errCode = $result['error_code'] ?? '';
                    echo "  ERROR [{$errCode}]: {$errMsg}\n";
                    // Log to packets.log so failures are visible even when running via admin daemon
                    $logLine = "[" . date('Y-m-d H:i:s') . "] [TIC] FAILED {$ticFilename}/{$dataFilename}: [{$errCode}] {$errMsg}\n";
                    @file_put_contents(__DIR__ . '/../data/logs/packets.log', $logLine, FILE_APPEND | LOCK_EX);
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
                // TIC exists but data file missing - log warning
                echo "  WARN TIC file without data file: $ticFilename (waiting for $dataFilename)\n";
            }
        } else {
            echo "  ERROR Invalid TIC file (no File field): $ticFilename\n";
            unlink($ticPath);
        }
    }

    return $processedCount;
}

// Create processors
$processor = new BinkdProcessor();
$ticProcessor = new TicFileProcessor();

try {
    echo "Starting packet processing...\n";

    // Process inbound packets
    $processed = $processor->processInboundPackets();
    echo "Processed {$processed} inbound packets\n";

    // Process TIC files (if feature is enabled)
    if (\BinktermPHP\FileAreaManager::isFeatureEnabled()) {
        $ticProcessed = processInboundTicFiles($ticProcessor);
        if ($ticProcessed > 0) {
            echo "Processed {$ticProcessed} TIC files\n";
        }
    }

    // Clean up old packet records (older than 6 months)
    $cleaned = $processor->cleanupOldPackets();
    if ($cleaned) {
        echo "Cleaned up {$cleaned} old packet records\n";
    }

    // Keep unprocessed files only when explicitly enabled in .env.
    $keepUnprocessedFiles = filter_var(
        (string) Config::env('BINKP_KEEP_UNPROCESSED_FILES', 'false'),
        FILTER_VALIDATE_BOOLEAN
    );

    // Handle any unrecognized/unprocessed files in inbound.
    // TIC files are left in place because they may still be waiting for their data file.
    $inboundPath = __DIR__ . '/../data/inbound';
    $unprocessedDir = $inboundPath . '/unprocessed';
    $leftover = array_filter(glob($inboundPath . '/*') ?: [], 'is_file');

    if ($keepUnprocessedFiles && !is_dir($unprocessedDir)) {
        mkdir($unprocessedDir, 0755, true);
    }

    foreach ($leftover as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'tic') {
            continue; // Leave TIC files because they may still be waiting for their data file
        }

        if ($keepUnprocessedFiles) {
            $dest = $unprocessedDir . '/' . basename($file);
            if (rename($file, $dest)) {
                echo "  -> Moved unprocessed file to unprocessed/: " . basename($file) . "\n";
            }
            continue;
        }

        if (unlink($file)) {
            echo "  -> Deleted unprocessed file: " . basename($file) . "\n";
        }
    }

    // TODO: Process outbound queue (send pending messages)

    echo "Packet processing completed\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    exit(1);
}

flock($lockFh, LOCK_UN);
fclose($lockFh);
