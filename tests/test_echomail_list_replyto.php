<?php

/**
 * Test echomail message list includes REPLYTO data
 */

require_once __DIR__ . '/vendor/autoload.php';

$srcFiles = glob(__DIR__ . '/src/*.php');
foreach ($srcFiles as $file) {
    require_once $file;
}

try {
    $db = \BinktermPHP\Database::getInstance()->getPdo();
    $handler = new \BinktermPHP\MessageHandler();
    
    echo "Testing echomail message list REPLYTO parsing...\n\n";
    
    // Test getEchomail method to see if it includes REPLYTO data
    $result = $handler->getEchomail('FUTURE4FIDO', 1, 10, 1); // Get first 10 messages from FUTURE4FIDO
    
    if (!$result || !isset($result['messages'])) {
        echo "Failed to get echomail messages\n";
        exit;
    }
    
    echo "Found " . count($result['messages']) . " messages in FUTURE4FIDO\n\n";
    
    // Look for message 288 specifically
    $message288 = null;
    foreach ($result['messages'] as $msg) {
        if ($msg['id'] == 288) {
            $message288 = $msg;
            break;
        }
    }
    
    if ($message288) {
        echo "Found message 288 in list:\n";
        echo "ID: " . $message288['id'] . "\n";
        echo "From: " . $message288['from_name'] . " (" . $message288['from_address'] . ")\n";
        
        if (isset($message288['replyto_address'])) {
            echo "✓ REPLYTO Address: " . $message288['replyto_address'] . "\n";
            echo "✓ REPLYTO Name: " . ($message288['replyto_name'] ?: '(none)') . "\n\n";
            
            echo "✓ SUCCESS: Message list includes REPLYTO data!\n";
            echo "✓ Netmail compose links will now use REPLYTO priority\n";
        } else {
            echo "✗ No REPLYTO data found in message list\n";
            echo "Available fields: " . implode(', ', array_keys($message288)) . "\n";
        }
    } else {
        echo "Message 288 not found in current page of messages\n";
        echo "Available message IDs: ";
        $ids = array_column($result['messages'], 'id');
        echo implode(', ', $ids) . "\n";
        
        // Test with first message that has REPLYTO data
        foreach ($result['messages'] as $msg) {
            if (isset($msg['replyto_address'])) {
                echo "\nFound message " . $msg['id'] . " with REPLYTO data:\n";
                echo "REPLYTO Address: " . $msg['replyto_address'] . "\n";
                echo "REPLYTO Name: " . ($msg['replyto_name'] ?: '(none)') . "\n";
                break;
            }
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

?>