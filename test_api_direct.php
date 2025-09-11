<?php

/**
 * Test the API endpoint directly to see if it includes REPLYTO data
 */

require_once __DIR__ . '/vendor/autoload.php';

$srcFiles = glob(__DIR__ . '/src/*.php');
foreach ($srcFiles as $file) {
    require_once $file;
}

try {
    // Simulate what the API endpoint does
    $handler = new \BinktermPHP\MessageHandler();
    $userId = 1; // Assuming user ID 1
    
    echo "Testing API endpoint logic directly...\n\n";
    
    // Call getEchomail like the API does
    $result = $handler->getEchomail('FUTURE4FIDO', 1, 25, $userId, 'all', false);
    
    if (!$result || !isset($result['messages'])) {
        echo "Failed to get messages\n";
        exit;
    }
    
    echo "Found " . count($result['messages']) . " messages\n";
    
    // Look for message 288
    $message288 = null;
    foreach ($result['messages'] as $msg) {
        if ($msg['id'] == 288) {
            $message288 = $msg;
            break;
        }
    }
    
    if (!$message288) {
        echo "Message 288 not found\n";
        // Show first few message IDs
        $ids = array_slice(array_column($result['messages'], 'id'), 0, 10);
        echo "First 10 message IDs: " . implode(', ', $ids) . "\n";
        exit;
    }
    
    echo "Message 288 data returned by getEchomail():\n";
    echo "ID: " . $message288['id'] . "\n";
    echo "from_name: '" . $message288['from_name'] . "'\n";
    echo "from_address: '" . $message288['from_address'] . "'\n";
    
    // Check if REPLYTO fields are present
    $hasReplytoAddress = isset($message288['replyto_address']);
    $hasReplytoName = isset($message288['replyto_name']);
    
    echo "has replyto_address: " . ($hasReplytoAddress ? 'YES' : 'NO') . "\n";
    echo "has replyto_name: " . ($hasReplytoName ? 'YES' : 'NO') . "\n";
    
    if ($hasReplytoAddress) {
        echo "replyto_address: '" . $message288['replyto_address'] . "'\n";
    }
    
    if ($hasReplytoName) {
        echo "replyto_name: '" . $message288['replyto_name'] . "'\n";
    }
    
    echo "\nJSON representation (what API would return):\n";
    echo json_encode($message288, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n";
    echo $e->getTraceAsString() . "\n";
}

?>