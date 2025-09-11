<?php

/**
 * Test the echomail API response to verify REPLYTO fields are included
 */

// Simulate the API call
require_once __DIR__ . '/vendor/autoload.php';

$srcFiles = glob(__DIR__ . '/src/*.php');
foreach ($srcFiles as $file) {
    require_once $file;
}

// Include the functions from index.php
function isValidFidonetAddress($address) {
    return preg_match('/^\d+:\d+\/\d+(?:\.\d+)?(?:@\w+)?$/', trim($address));
}

function parseReplyToKludge($messageText) {
    if (empty($messageText)) {
        return null;
    }
    
    // Normalize line endings and split into lines
    $lines = preg_split('/\r\n|\r|\n/', $messageText);
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        
        // Look for REPLYTO kludge line (must have \x01 prefix)
        if (preg_match('/^\x01REPLYTO\s+(.+)$/i', $trimmed, $matches)) {
            $replyToData = trim($matches[1]);
            
            // Parse "address name" or just "address"
            if (preg_match('/^(\S+)(?:\s+(.+))?$/', $replyToData, $addressMatches)) {
                $address = trim($addressMatches[1]);
                $name = isset($addressMatches[2]) ? trim($addressMatches[2]) : null;
                
                // Only return if it's a valid FidoNet address
                if (isValidFidonetAddress($address)) {
                    return [
                        'address' => $address,
                        'name' => $name
                    ];
                }
            }
        }
    }
    
    return null;
}

try {
    $db = \BinktermPHP\Database::getInstance()->getPdo();
    $handler = new \BinktermPHP\MessageHandler();
    
    echo "Testing API response for message 288...\n\n";
    
    // Simulate what the API endpoint does
    $message = $handler->getMessage(288, 'echomail', 1); // assuming user ID 1
    
    if (!$message) {
        echo "Message 288 not found via MessageHandler\n";
        exit;
    }
    
    echo "Message retrieved successfully:\n";
    echo "From: " . $message['from_name'] . " (" . $message['from_address'] . ")\n";
    echo "Has kludge_lines: " . (isset($message['kludge_lines']) ? 'Yes' : 'No') . "\n\n";
    
    // Apply the API logic
    // Parse REPLYTO kludge from message text and add to response
    $replyToData = parseReplyToKludge($message['message_text']);
    if ($replyToData) {
        $message['replyto_address'] = $replyToData['address'];
        $message['replyto_name'] = $replyToData['name'];
        echo "✓ Found REPLYTO in message_text:\n";
        echo "  Address: " . $replyToData['address'] . "\n";
        echo "  Name: " . $replyToData['name'] . "\n\n";
    } else {
        echo "✗ No REPLYTO found in message_text\n\n";
    }
    
    // Also check kludge_lines for REPLYTO
    if (isset($message['kludge_lines'])) {
        $replyToDataKludge = parseReplyToKludge($message['kludge_lines']);
        if ($replyToDataKludge) {
            $message['replyto_address'] = $replyToDataKludge['address'];
            $message['replyto_name'] = $replyToDataKludge['name'];
            echo "✓ Found REPLYTO in kludge_lines:\n";
            echo "  Address: " . $replyToDataKludge['address'] . "\n";
            echo "  Name: " . $replyToDataKludge['name'] . "\n\n";
        } else {
            echo "✗ No REPLYTO found in kludge_lines\n\n";
        }
    }
    
    // Show final message state
    echo "Final API response would include:\n";
    if (isset($message['replyto_address'])) {
        echo "✓ replyto_address: " . $message['replyto_address'] . "\n";
        echo "✓ replyto_name: " . ($message['replyto_name'] ?: '(none)') . "\n";
    } else {
        echo "✗ No REPLYTO fields added to response\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

?>