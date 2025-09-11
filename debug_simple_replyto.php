<?php

/**
 * Simple debug script to check if message 288 has REPLYTO data
 */

// Include the Database class and other dependencies
require_once __DIR__ . '/vendor/autoload.php';

// Include the necessary source files
$srcFiles = glob(__DIR__ . '/src/*.php');
foreach ($srcFiles as $file) {
    require_once $file;
}

// Include the parseReplyToKludge function from index.php
function parseReplyToKludge($messageText) {
    if (empty($messageText)) {
        return null;
    }
    
    // Normalize line endings and split into lines
    $lines = preg_split('/\r\n|\r|\n/', $messageText);
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        
        // Look for REPLYTO kludge line
        if (preg_match('/^REPLYTO:\s*(.+)/', $trimmed, $matches)) {
            $replyToValue = trim($matches[1]);
            
            // Parse format: "name@address" or just "address"
            if (preg_match('/^(.+?)@(\d+:\d+\/\d+(?:\.\d+)?)$/', $replyToValue, $addressMatches)) {
                return [
                    'name' => trim($addressMatches[1]),
                    'address' => trim($addressMatches[2])
                ];
            }
            // If no @ symbol, treat entire value as address
            elseif (preg_match('/^\d+:\d+\/\d+(?:\.\d+)?$/', $replyToValue)) {
                return [
                    'name' => '', // No name provided
                    'address' => $replyToValue
                ];
            }
        }
    }
    
    return null;
}

try {
    $db = \BinktermPHP\Database::getInstance()->getPdo();
    echo "Connected to database successfully.\n\n";
    
    // Get message 288
    $stmt = $db->prepare("SELECT id, message_text, kludge_lines, from_name, from_address FROM echomail WHERE id = ?");
    $stmt->execute([288]);
    $message = $stmt->fetch();
    
    if (!$message) {
        echo "Message 288 not found.\n";
        exit;
    }
    
    echo "Message 288 Details:\n";
    echo "From: " . $message['from_name'] . "\n";
    echo "Address: " . $message['from_address'] . "\n";
    echo "Message text length: " . strlen($message['message_text']) . "\n";
    echo "Kludge lines length: " . strlen($message['kludge_lines'] ?? '') . "\n\n";
    
    // Check message text for REPLYTO
    echo "=== Checking message_text ===\n";
    $replyToData = parseReplyToKludge($message['message_text']);
    if ($replyToData) {
        echo "✓ Found REPLYTO:\n";
        echo "  Name: " . $replyToData['name'] . "\n";
        echo "  Address: " . $replyToData['address'] . "\n";
    } else {
        echo "✗ No REPLYTO found in message_text\n";
        
        // Show lines containing "reply" (case insensitive) for debugging
        $lines = preg_split('/\r\n|\r|\n/', $message['message_text']);
        $foundReplyLines = [];
        foreach ($lines as $i => $line) {
            if (stripos($line, 'reply') !== false) {
                $foundReplyLines[] = "Line " . ($i + 1) . ": " . trim($line);
            }
        }
        
        if (!empty($foundReplyLines)) {
            echo "Found lines containing 'reply':\n";
            foreach ($foundReplyLines as $line) {
                echo "  " . $line . "\n";
            }
        }
    }
    
    // Check kludge lines if they exist
    if (!empty($message['kludge_lines'])) {
        echo "\n=== Checking kludge_lines ===\n";
        $replyToDataKludge = parseReplyToKludge($message['kludge_lines']);
        if ($replyToDataKludge) {
            echo "✓ Found REPLYTO in kludge_lines:\n";
            echo "  Name: " . $replyToDataKludge['name'] . "\n";
            echo "  Address: " . $replyToDataKludge['address'] . "\n";
        } else {
            echo "✗ No REPLYTO found in kludge_lines\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

?>