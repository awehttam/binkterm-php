<?php
/**
 * Fix echomail author addresses by extracting from MSGID kludge lines
 * This script updates existing echomail messages that have incorrect from_address
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;

function main() {
    echo "Fixing Echomail Author Addresses\n";
    echo "================================\n\n";
    
    try {
        $db = Database::getInstance()->getPdo();
        
        // Get all echomail messages with Origin lines
        $stmt = $db->prepare("
            SELECT id, from_address, origin_line, kludge_lines, from_name, subject
            FROM echomail 
            WHERE origin_line IS NOT NULL AND origin_line != ''
            ORDER BY id
        ");
        $stmt->execute();
        $messages = $stmt->fetchAll();
        
        echo "Found " . count($messages) . " echomail messages with Origin lines.\n\n";
        
        if (empty($messages)) {
            echo "No messages to process.\n";
            return;
        }
        
        $updateStmt = $db->prepare("UPDATE echomail SET from_address = ? WHERE id = ?");
        $updatedCount = 0;
        $noChangeCount = 0;
        
        foreach ($messages as $message) {
            $originalAuthorAddress = extractOriginalAuthorFromOrigin($message['origin_line']);
            
            if ($originalAuthorAddress && $originalAuthorAddress !== $message['from_address']) {
                echo sprintf(
                    "ID %d: '%s' by %s\n  Old: %s -> New: %s\n\n",
                    $message['id'],
                    substr($message['subject'], 0, 50),
                    $message['from_name'],
                    $message['from_address'],
                    $originalAuthorAddress
                );
                
                $updateStmt->execute([$originalAuthorAddress, $message['id']]);
                $updatedCount++;
            } else {
                $noChangeCount++;
            }
        }
        
        echo "Processing complete!\n";
        echo "Updated: $updatedCount messages\n";
        echo "No change needed: $noChangeCount messages\n";
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function extractOriginalAuthorFromOrigin($originLine) {
    if (empty($originLine)) {
        return null;
    }
    
    // Origin format: " * Origin: System Name (1:123/456)"
    if (preg_match('/\((\d+:\d+\/\d+(?:\.\d+)?)\)/', $originLine, $matches)) {
        return $matches[1];
    }
    
    return null;
}

// Run the script
main();