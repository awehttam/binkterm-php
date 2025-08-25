<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\MessageHandler;
use BinktermPHP\Database;

// Initialize database  
Database::getInstance();

echo "=== MINIMAL ECHOMAIL TEST ===\n";

// Turn off all debug logging temporarily
error_reporting(E_ERROR);

$messageHandler = new MessageHandler();

// Post the simplest possible echomail message
$result = $messageHandler->postEchomail(
    1,                    // fromUserId
    'LOCALTEST',         // echoareaTag  
    'All',               // toName
    'Simple Test',       // subject
    'This is a very simple test message.'  // messageText
);

if ($result) {
    echo "✓ Simple echomail posted successfully\n";
    echo "Check your uplink logs to see if this is still treated as netmail.\n";
    echo "If it is, the issue may be with your uplink configuration or the echoarea setup.\n";
} else {
    echo "✗ Failed to post echomail\n";
}

echo "=== TEST COMPLETE ===\n";