#!/usr/bin/php
<?php

chdir(__DIR__);

// Packet processing script - run this via cron or as a service

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\BinkdProcessor;
use BinktermPHP\Database;

// Initialize database
Database::getInstance();

// Create processor
$processor = new BinkdProcessor();

try {
    echo "Starting packet processing...\n";
    
    // Process inbound packets
    $processed = $processor->processInboundPackets();
    echo "Processed {$processed} inbound packets\n";
    
    // TODO: Process outbound queue (send pending messages)
    
    echo "Packet processing completed\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}