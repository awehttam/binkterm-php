<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\BinkdProcessor;
use BinktermPHP\Database;

echo "Testing packet cleanup functionality...\n";

try {
    $processor = new BinkdProcessor();
    $deletedCount = $processor->cleanupOldPackets();
    
    echo "Cleanup completed successfully!\n";
    echo "Records deleted: $deletedCount\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Test completed.\n";