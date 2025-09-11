<?php

/**
 * Debug the message list to see the exact data being passed to the template
 */

require_once __DIR__ . '/vendor/autoload.php';

$srcFiles = glob(__DIR__ . '/src/*.php');
foreach ($srcFiles as $file) {
    require_once $file;
}

try {
    $db = \BinktermPHP\Database::getInstance()->getPdo();
    $handler = new \BinktermPHP\MessageHandler();
    
    echo "Debugging echomail message list data...\n\n";
    
    // Get message list like the API does
    $result = $handler->getEchomail('FUTURE4FIDO', 1, 25, 1);
    
    if (!$result || !isset($result['messages'])) {
        echo "Failed to get messages\n";
        exit;
    }
    
    // Find message 288
    $message288 = null;
    foreach ($result['messages'] as $msg) {
        if ($msg['id'] == 288) {
            $message288 = $msg;
            break;
        }
    }
    
    if (!$message288) {
        echo "Message 288 not found in message list\n";
        exit;
    }
    
    echo "Message 288 in message list:\n";
    echo "ID: " . $message288['id'] . "\n";
    echo "from_name: '" . $message288['from_name'] . "'\n";
    echo "from_address: '" . $message288['from_address'] . "'\n";
    
    if (isset($message288['replyto_name'])) {
        echo "replyto_name: '" . $message288['replyto_name'] . "'\n";
    } else {
        echo "replyto_name: NOT SET\n";
    }
    
    if (isset($message288['replyto_address'])) {
        echo "replyto_address: '" . $message288['replyto_address'] . "'\n";
    } else {
        echo "replyto_address: NOT SET\n";
    }
    
    echo "\nTemplate logic would use:\n";
    $compose_to = isset($message288['replyto_address']) && $message288['replyto_address'] 
        ? $message288['replyto_address'] 
        : $message288['from_address'];
    $compose_name = isset($message288['replyto_name']) && $message288['replyto_name'] 
        ? $message288['replyto_name'] 
        : $message288['from_name'];
        
    echo "Compose to address: '" . $compose_to . "'\n";
    echo "Compose to name: '" . $compose_name . "'\n";
    
    echo "\nExpected compose link:\n";
    echo "/compose/netmail?to=" . urlencode($compose_to) . "&to_name=" . urlencode($compose_name) . "&subject=" . urlencode('Re: ' . ($message288['subject'] ?? '')) . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

?>