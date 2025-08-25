<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\MessageHandler;
use BinktermPHP\Database;

// Initialize database
Database::getInstance();

$messageHandler = new MessageHandler();

// Post a test echomail message
try {
    echo "Posting test echomail message...\n";
    
    $result = $messageHandler->postEchomail(
        1,                    // fromUserId (assuming user ID 1 exists)
        'LOCALTEST',         // echoareaTag
        'All',               // toName
        'Debug Test',        // subject
        'This is a test message to debug echomail formatting.'  // messageText
    );
    
    if ($result) {
        echo "✓ Echomail posted successfully\n";
    } else {
        echo "✗ Failed to post echomail\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "Check the error logs for debug information.\n";