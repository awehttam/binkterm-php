<?php

/**
 * Test parseReplyToKludge with the exact content from message 288
 */

require_once __DIR__ . '/vendor/autoload.php';

$srcFiles = glob(__DIR__ . '/src/*.php');
foreach ($srcFiles as $file) {
    require_once $file;
}

// Copy the exact functions from index.php
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
    
    $stmt = $db->prepare("SELECT kludge_lines FROM echomail WHERE id = ?");
    $stmt->execute([288]);
    $message = $stmt->fetch();
    
    if (!$message) {
        echo "Message 288 not found.\n";
        exit;
    }
    
    echo "Testing parseReplyToKludge with message 288 kludge lines...\n\n";
    
    // Test the function
    $result = parseReplyToKludge($message['kludge_lines']);
    
    if ($result) {
        echo "✓ SUCCESS! Found REPLYTO:\n";
        echo "  Address: " . $result['address'] . "\n";
        echo "  Name: " . ($result['name'] ?: '(none)') . "\n";
    } else {
        echo "✗ No REPLYTO found\n";
        
        // Debug: show each line with its hex representation
        echo "\nDebugging each line:\n";
        $lines = preg_split('/\r\n|\r|\n/', $message['kludge_lines']);
        foreach ($lines as $i => $line) {
            $hex = bin2hex($line);
            echo "Line $i: '$line' -> $hex\n";
            
            if (stripos($line, 'replyto') !== false) {
                echo "  ^^ This line contains REPLYTO!\n";
                
                // Test the regex
                if (preg_match('/^\x01REPLYTO\s+(.+)$/i', trim($line), $matches)) {
                    echo "  ^^ Regex matched! Data: " . $matches[1] . "\n";
                } else {
                    echo "  ^^ Regex did NOT match\n";
                    
                    // Try without \x01 requirement
                    if (preg_match('/^REPLYTO\s+(.+)$/i', trim($line), $matches2)) {
                        echo "  ^^ Would match without \\x01 prefix\n";
                    }
                }
            }
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

?>