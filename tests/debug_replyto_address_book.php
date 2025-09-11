<?php
/**
 * Debug why address book save isn't using REPLYTO data
 * This simulates the exact API call that the frontend makes
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/MessageHandler.php';

use BinktermPHP\Database;
use BinktermPHP\MessageHandler;

// Include the parseReplyToKludge function
include_once __DIR__ . '/../public_html/index.php';

try {
    $db = Database::getInstance()->getPdo();
    
    echo "Debugging REPLYTO Address Book Save Issue\n";
    echo "=========================================\n\n";
    
    // Find a message that has REPLYTO kludge
    echo "1. Finding messages with REPLYTO kludges...\n";
    echo "-------------------------------------------\n";
    
    $stmt = $db->prepare("
        SELECT id, from_name, from_address, subject, message_text
        FROM netmail 
        WHERE message_text LIKE '%REPLYTO%' 
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute();
    $testMessage = $stmt->fetch();
    
    if (!$testMessage) {
        echo "   ❌ No messages found with REPLYTO kludges\n";
        echo "   Cannot test without a real message. Create a test message first.\n";
        exit(1);
    }
    
    echo "   ✅ Found test message:\n";
    echo "     ID: " . $testMessage['id'] . "\n";
    echo "     From: " . $testMessage['from_name'] . " (" . $testMessage['from_address'] . ")\n";
    echo "     Subject: " . $testMessage['subject'] . "\n\n";
    
    // Test the parseReplyToKludge function on this message
    echo "2. Testing parseReplyToKludge on actual message...\n";
    echo "-------------------------------------------------\n";
    
    $replyToData = parseReplyToKludge($testMessage['message_text']);
    if ($replyToData) {
        echo "   ✅ REPLYTO parsed successfully:\n";
        echo "     Address: " . $replyToData['address'] . "\n";
        echo "     Name: " . ($replyToData['name'] ?: 'NULL') . "\n\n";
    } else {
        echo "   ❌ Failed to parse REPLYTO from message\n";
        echo "   Message text preview:\n";
        echo "   " . substr(str_replace(["\r", "\n"], ["\\r", "\\n"], $testMessage['message_text']), 0, 200) . "...\n\n";
    }
    
    // Simulate the exact API call
    echo "3. Simulating API call /api/messages/netmail/" . $testMessage['id'] . "\n";
    echo "----------------------------------------------------------------\n";
    
    $handler = new MessageHandler();
    $message = $handler->getMessage($testMessage['id'], 'netmail', 1); // Using user ID 1
    
    if ($message) {
        echo "   ✅ MessageHandler returned message data\n";
        echo "   Original fields:\n";
        echo "     from_address: " . $message['from_address'] . "\n";
        echo "     reply_address: " . ($message['reply_address'] ?: 'NULL') . "\n";
        echo "     original_author_address: " . ($message['original_author_address'] ?: 'NULL') . "\n";
        
        // Now add the REPLYTO parsing (simulating our API modification)
        $replyToData = parseReplyToKludge($message['message_text']);
        if ($replyToData) {
            $message['replyto_address'] = $replyToData['address'];
            $message['replyto_name'] = $replyToData['name'];
            echo "   ✅ Added REPLYTO fields:\n";
            echo "     replyto_address: " . $message['replyto_address'] . "\n";
            echo "     replyto_name: " . ($message['replyto_name'] ?: 'NULL') . "\n";
        } else {
            echo "   ❌ No REPLYTO data to add\n";
        }
        
        echo "\n4. Testing frontend address priority logic...\n";
        echo "---------------------------------------------\n";
        
        // Simulate the JavaScript logic: message.replyto_address || message.reply_address || message.original_author_address || message.from_address
        $replyToAddress = $message['replyto_address'] ?? null;
        $replyAddress = $message['reply_address'] ?? null;
        $originalAuthorAddress = $message['original_author_address'] ?? null;
        $fromAddress = $message['from_address'] ?? null;
        
        $selectedAddress = $replyToAddress ?: ($replyAddress ?: ($originalAuthorAddress ?: $fromAddress));
        
        echo "   Priority evaluation:\n";
        echo "     1. replyto_address: " . ($replyToAddress ?: 'NULL') . "\n";
        echo "     2. reply_address: " . ($replyAddress ?: 'NULL') . "\n";
        echo "     3. original_author_address: " . ($originalAuthorAddress ?: 'NULL') . "\n";
        echo "     4. from_address: " . ($fromAddress ?: 'NULL') . "\n";
        echo "   \n";
        echo "   📍 SELECTED ADDRESS: " . $selectedAddress . "\n";
        
        if ($selectedAddress === $replyToAddress && $replyToAddress) {
            echo "   ✅ SUCCESS: REPLYTO address was selected!\n";
        } elseif ($replyToAddress && $selectedAddress !== $replyToAddress) {
            echo "   ❌ PROBLEM: REPLYTO address available but not selected\n";
        } else {
            echo "   ℹ️  INFO: No REPLYTO address available, using fallback\n";
        }
        
        echo "\n5. Checking what address book save would receive...\n";
        echo "--------------------------------------------------\n";
        echo "   saveToAddressBook('" . $message['from_name'] . "', '" . $selectedAddress . "')\n";
        echo "   \n";
        echo "   Expected behavior:\n";
        if ($replyToAddress) {
            echo "   - Should save: " . $message['from_name'] . " -> " . $replyToAddress . "\n";
            echo "   - This is the REPLYTO address from the kludge\n";
        } else {
            echo "   - Should save: " . $message['from_name'] . " -> " . $selectedAddress . "\n";
            echo "   - This is the fallback address (no REPLYTO available)\n";
        }
        
    } else {
        echo "   ❌ MessageHandler failed to return message\n";
    }
    
    echo "\n=== TROUBLESHOOTING CHECKLIST ===\n";
    echo "□ Is the API endpoint modification active? (Check /api/messages/netmail/{id})\n";
    echo "□ Is the parseReplyToKludge function working correctly?\n";
    echo "□ Is the frontend JavaScript using the updated priority logic?\n";
    echo "□ Is the browser cache cleared after template changes?\n";
    echo "□ Are there any JavaScript errors in browser console?\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}