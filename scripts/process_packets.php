#!/usr/bin/env php
<?php

chdir(__DIR__);

// Packet processing script - run this via cron or as a service

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\BinkdProcessor;
use BinktermPHP\Database;
use BinktermPHP\TicFileProcessor;

// Initialize database
Database::getInstance();

/**
 * Process TIC files from inbound directory
 */
function processInboundTicFiles($ticProcessor) {
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
                    echo "  ✓ Stored in area: {$result['area']} (file_id={$result['file_id']})\n";

                    if (isset($result['duplicate']) && $result['duplicate']) {
                        echo "  ⚠ Duplicate file (skipped)\n";
                    }

                    // Clean up TIC file (data file was cleaned up by processor)
                    unlink($ticPath);
                    $processedCount++;
                } else {
                    echo "  ✗ Error: {$result['error']}\n";
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
                echo "  ⚠ TIC file without data file: $ticFilename (waiting for $dataFilename)\n";
            }
        } else {
            echo "  ✗ Invalid TIC file (no File field): $ticFilename\n";
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
    if($cleaned)
        echo "Cleaned up {$cleaned} old packet records\n";

    // TODO: Process outbound queue (send pending messages)

    echo "Packet processing completed\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}