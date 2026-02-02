#!/usr/bin/env php
<?php
/**
 * Test script for TIC file processing
 *
 * Usage: php tests/test_tic_processor.php <tic_file> <data_file>
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\TicFileProcessor;

if ($argc < 3) {
    echo "Usage: php test_tic_processor.php <tic_file> <data_file>\n";
    echo "\nExample:\n";
    echo "  php test_tic_processor.php /path/to/file.tic /path/to/file.zip\n";
    exit(1);
}

$ticFile = $argv[1];
$dataFile = $argv[2];

// Verify files exist
if (!file_exists($ticFile)) {
    echo "Error: TIC file not found: $ticFile\n";
    exit(1);
}

if (!file_exists($dataFile)) {
    echo "Error: Data file not found: $dataFile\n";
    exit(1);
}

echo "TIC File Processor Test\n";
echo "=======================\n\n";

echo "TIC file:  $ticFile\n";
echo "Data file: $dataFile\n\n";

// Create temp copy so original stays intact for repeated testing
$tempFile = sys_get_temp_dir() . '/' . basename($dataFile) . '.' . uniqid();
if (!copy($dataFile, $tempFile)) {
    echo "Error: Could not create temp copy\n";
    exit(1);
}

// Process TIC file (will consume the temp file)
$processor = new TicFileProcessor();
$result = $processor->processTicFile($ticFile, $tempFile);

if ($result['success']) {
    echo "✓ SUCCESS\n\n";
    echo "File ID:   {$result['file_id']}\n";
    echo "Area:      {$result['area']}\n";
    echo "Filename:  {$result['filename']}\n";

    if (isset($result['duplicate']) && $result['duplicate']) {
        echo "Status:    Duplicate (file already exists)\n";
    } else {
        echo "Status:    New file stored\n";
    }

    exit(0);
} else {
    echo "✗ FAILED\n\n";
    echo "Error: {$result['error']}\n";
    exit(1);
}
