<?php

// Test script for zip bundle processing
require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\BinkdProcessor;
use BinktermPHP\Database;

echo "Testing zip bundle processing...\n";

// Initialize database
Database::getInstance();

// Create test zip with dummy packet
$testZip = __DIR__ . '/../data/inbound/test_bundle.zip';
$zip = new ZipArchive();

if ($zip->open($testZip, ZipArchive::CREATE) === TRUE) {
    // Create a minimal test packet content (this is just for testing the extraction)
    $dummyPacketContent = str_repeat("\x00", 58) . pack('v', 0); // Header + terminator
    $zip->addFromString('test.pkt', $dummyPacketContent);
    $zip->close();
    echo "Created test zip bundle: $testZip\n";
} else {
    echo "Failed to create test zip\n";
    exit(1);
}

// Test the processor
try {
    $processor = new BinkdProcessor();
    $processed = $processor->processInboundPackets();
    echo "Processed $processed packets from bundles\n";
    
    // Check if zip was removed (indicates successful processing)
    if (!file_exists($testZip)) {
        echo "✓ Test zip bundle was processed and removed\n";
    } else {
        echo "✗ Test zip bundle still exists\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "Test complete.\n";