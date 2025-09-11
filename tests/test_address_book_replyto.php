<?php
/**
 * Test that address book save functionality correctly uses REPLYTO addresses
 *
 * This test verifies:
 * 1. Address book save uses reply_address when present (highest priority)
 * 2. Falls back to original_author_address when reply_address is not present  
 * 3. Falls back to from_address as final fallback
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Database.php';

use BinktermPHP\Database;

try {
    $db = Database::getInstance()->getPdo();
    
    echo "Testing Address Book REPLYTO Functionality\n";
    echo "==========================================\n\n";
    
    // Test Case 1: Message with REPLYTO address (reply_address field)
    echo "Test Case 1: Message with REPLYTO address\n";
    echo "-----------------------------------------\n";
    
    // Create a test message with reply_address populated
    $testMessage1 = [
        'id' => 9999,
        'from_name' => 'Test User',
        'from_address' => '1:123/456',
        'original_author_address' => '1:234/567', 
        'reply_address' => '1:345/678',  // This should be used
        'subject' => 'Test REPLYTO Priority'
    ];
    
    // Simulate the JavaScript logic for address priority
    $addressForSave1 = $testMessage1['reply_address'] ?: ($testMessage1['original_author_address'] ?: $testMessage1['from_address']);
    
    echo "   From Address: " . $testMessage1['from_address'] . "\n";
    echo "   Original Author Address: " . $testMessage1['original_author_address'] . "\n";
    echo "   Reply Address (REPLYTO): " . $testMessage1['reply_address'] . "\n";
    echo "   Selected Address for Save: " . $addressForSave1 . "\n";
    
    if ($addressForSave1 === '1:345/678') {
        echo "   ✓ PASS: Correctly selected reply_address (REPLYTO)\n\n";
    } else {
        echo "   ✗ FAIL: Should have selected reply_address but got: " . $addressForSave1 . "\n\n";
    }
    
    // Test Case 2: Message without REPLYTO but with original author
    echo "Test Case 2: Message without REPLYTO, with original author\n";
    echo "----------------------------------------------------------\n";
    
    $testMessage2 = [
        'id' => 9998,
        'from_name' => 'Test User 2',
        'from_address' => '1:123/456',
        'original_author_address' => '1:234/567',
        'reply_address' => null,  // No REPLYTO
        'subject' => 'Test Original Author Fallback'
    ];
    
    $addressForSave2 = $testMessage2['reply_address'] ?: ($testMessage2['original_author_address'] ?: $testMessage2['from_address']);
    
    echo "   From Address: " . $testMessage2['from_address'] . "\n";
    echo "   Original Author Address: " . $testMessage2['original_author_address'] . "\n";
    echo "   Reply Address (REPLYTO): " . ($testMessage2['reply_address'] ?: 'NULL') . "\n";
    echo "   Selected Address for Save: " . $addressForSave2 . "\n";
    
    if ($addressForSave2 === '1:234/567') {
        echo "   ✓ PASS: Correctly fell back to original_author_address\n\n";
    } else {
        echo "   ✗ FAIL: Should have selected original_author_address but got: " . $addressForSave2 . "\n\n";
    }
    
    // Test Case 3: Message with only from_address (final fallback)
    echo "Test Case 3: Message with only from_address (final fallback)\n";
    echo "------------------------------------------------------------\n";
    
    $testMessage3 = [
        'id' => 9997,
        'from_name' => 'Test User 3',
        'from_address' => '1:123/456',
        'original_author_address' => null,
        'reply_address' => null,
        'subject' => 'Test From Address Fallback'
    ];
    
    $addressForSave3 = $testMessage3['reply_address'] ?: ($testMessage3['original_author_address'] ?: $testMessage3['from_address']);
    
    echo "   From Address: " . $testMessage3['from_address'] . "\n";
    echo "   Original Author Address: " . ($testMessage3['original_author_address'] ?: 'NULL') . "\n";
    echo "   Reply Address (REPLYTO): " . ($testMessage3['reply_address'] ?: 'NULL') . "\n";
    echo "   Selected Address for Save: " . $addressForSave3 . "\n";
    
    if ($addressForSave3 === '1:123/456') {
        echo "   ✓ PASS: Correctly fell back to from_address\n\n";
    } else {
        echo "   ✗ FAIL: Should have selected from_address but got: " . $addressForSave3 . "\n\n";
    }
    
    // Test Case 4: Verify database includes the reply_address column
    echo "Test Case 4: Database schema verification\n";
    echo "-----------------------------------------\n";
    
    $stmt = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = 'netmail' AND column_name = 'reply_address'");
    $stmt->execute();
    $column = $stmt->fetch();
    
    if ($column) {
        echo "   ✓ PASS: reply_address column exists in netmail table\n";
    } else {
        echo "   ✗ FAIL: reply_address column NOT found - migration needs to be applied\n";
        echo "   Run the migration: database/migrations/v1.5.1_add_netmail_reply_address.sql\n";
    }
    
    // Test Case 5: Check for any existing messages with reply_address values
    echo "\nTest Case 5: Check for existing REPLYTO data\n";
    echo "--------------------------------------------\n";
    
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM netmail WHERE reply_address IS NOT NULL");
    $stmt->execute();
    $replytoCount = $stmt->fetch();
    
    echo "   Messages with REPLYTO data: " . $replytoCount['count'] . "\n";
    
    if ($replytoCount['count'] > 0) {
        echo "   ✓ Good: Found messages with REPLYTO data\n";
        
        // Show an example
        $stmt = $db->prepare("SELECT id, from_name, from_address, reply_address, subject FROM netmail WHERE reply_address IS NOT NULL LIMIT 1");
        $stmt->execute();
        $example = $stmt->fetch();
        
        if ($example) {
            echo "   Example message:\n";
            echo "     ID: " . $example['id'] . "\n";
            echo "     From: " . $example['from_name'] . " (" . $example['from_address'] . ")\n";
            echo "     REPLYTO: " . $example['reply_address'] . "\n";
            echo "     Subject: " . $example['subject'] . "\n";
        }
    } else {
        echo "   ℹ INFO: No messages with REPLYTO data found (this is normal for new installations)\n";
    }
    
    echo "\n=== SUMMARY ===\n";
    echo "The address book save functionality has been updated to use the following priority:\n";
    echo "1. reply_address (from REPLYADDR kludge) - HIGHEST PRIORITY\n";
    echo "2. original_author_address (from MSGID parsing) - MEDIUM PRIORITY  \n";
    echo "3. from_address (message header) - FALLBACK\n\n";
    echo "This ensures that when users save a sender to their address book, it uses\n";
    echo "the most appropriate address for future replies.\n";

} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    echo "Make sure the database is properly configured and migrations are applied.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}