<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;
use BinktermPHP\BinkdProcessor;

echo "REPLYADDR Kludge Test\n";
echo "=====================\n\n";

try {
    // Initialize database
    $db = Database::getInstance()->getPdo();
    
    // Clear any existing test data
    $db->exec("DELETE FROM netmail WHERE subject LIKE 'Test REPLYADDR%'");
    
    // Create a mock netmail message with REPLYADDR kludge
    $testMessage = [
        'origAddr' => '1:123/456',
        'destAddr' => '1:234/567', 
        'fromName' => 'Test Sender',
        'toName' => 'Test Recipient',
        'subject' => 'Test REPLYADDR Kludge',
        'dateTime' => '01 Jan 25  12:00:00',
        'text' => "\x01MSGID: 1:123/456 12345678\r\n\x01REPLYADDR 1:999/888\r\n\x01TZUTC: +0000\r\nThis is a test message with REPLYADDR kludge.\r\n\r\nThe reply should go to 1:999/888 instead of the original sender 1:123/456.",
        'attributes' => 0x0001
    ];
    
    echo "1. Testing netmail message processing with REPLYADDR kludge...\n";
    
    // Process the message using BinkdProcessor
    $processor = new BinkdProcessor();
    $packetInfo = [
        'origZone' => 1,
        'destZone' => 1,
        'origNet' => 123,
        'destNet' => 234,
        'origNode' => 456,
        'destNode' => 567,
        'year' => 2025,
        'month' => 1,
        'day' => 1,
        'hour' => 12,
        'minute' => 0,
        'second' => 0
    ];
    
    // Use reflection to access private storeNetmail method
    $reflection = new ReflectionClass($processor);
    $storeNetmailMethod = $reflection->getMethod('storeNetmail');
    $storeNetmailMethod->setAccessible(true);
    
    // Store the message
    $storeNetmailMethod->invoke($processor, $testMessage, $packetInfo);
    
    echo "   ✓ Message processed and stored\n\n";
    
    // Verify the message was stored correctly
    $stmt = $db->prepare("
        SELECT id, from_address, original_author_address, reply_address, subject
        FROM netmail 
        WHERE subject = 'Test REPLYADDR Kludge'
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute();
    $storedMessage = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$storedMessage) {
        throw new Exception("Test message not found in database");
    }
    
    echo "2. Verifying stored data:\n";
    echo "   - Message ID: {$storedMessage['id']}\n";
    echo "   - From Address: {$storedMessage['from_address']}\n";
    echo "   - Original Author Address: " . ($storedMessage['original_author_address'] ?: 'NULL') . "\n";
    echo "   - Reply Address: " . ($storedMessage['reply_address'] ?: 'NULL') . "\n\n";
    
    // Check if REPLYADDR was parsed correctly
    if ($storedMessage['reply_address'] === '1:999/888') {
        echo "   ✓ REPLYADDR kludge parsed correctly: {$storedMessage['reply_address']}\n";
    } else {
        throw new Exception("REPLYADDR not parsed correctly. Expected '1:999/888', got: " . ($storedMessage['reply_address'] ?: 'NULL'));
    }
    
    // Test reply address priority logic
    echo "\n3. Testing reply address priority logic...\n";
    
    // Simulate the compose route logic
    $replyToAddress = $storedMessage['reply_address'] ?: ($storedMessage['original_author_address'] ?: $storedMessage['from_address']);
    
    echo "   - Priority 1 (REPLYADDR): " . ($storedMessage['reply_address'] ?: 'NULL') . "\n";
    echo "   - Priority 2 (MSGID): " . ($storedMessage['original_author_address'] ?: 'NULL') . "\n"; 
    echo "   - Priority 3 (From Address): {$storedMessage['from_address']}\n";
    echo "   - Selected Reply Address: {$replyToAddress}\n\n";
    
    if ($replyToAddress === '1:999/888') {
        echo "   ✓ Reply address priority logic working correctly\n";
    } else {
        throw new Exception("Reply address priority logic failed. Expected '1:999/888', got: {$replyToAddress}");
    }
    
    // Test case 2: Message without REPLYADDR but with MSGID
    echo "\n4. Testing fallback to MSGID address...\n";
    
    $testMessage2 = [
        'origAddr' => '1:111/222',
        'destAddr' => '1:234/567',
        'fromName' => 'Test Sender 2', 
        'toName' => 'Test Recipient',
        'subject' => 'Test REPLYADDR Fallback',
        'dateTime' => '01 Jan 25  12:05:00',
        'text' => "\x01MSGID: 1:777/666 87654321\r\n\x01TZUTC: +0000\r\nThis message has MSGID but no REPLYADDR.\r\nReply should go to MSGID address 1:777/666.",
        'attributes' => 0x0001
    ];
    
    $storeNetmailMethod->invoke($processor, $testMessage2, $packetInfo);
    
    $stmt = $db->prepare("
        SELECT id, from_address, original_author_address, reply_address, subject
        FROM netmail 
        WHERE subject = 'Test REPLYADDR Fallback'
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute();
    $storedMessage2 = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $replyToAddress2 = $storedMessage2['reply_address'] ?: ($storedMessage2['original_author_address'] ?: $storedMessage2['from_address']);
    
    echo "   - Reply Address (should be MSGID): {$replyToAddress2}\n";
    
    if ($replyToAddress2 === '1:777/666') {
        echo "   ✓ Fallback to MSGID address working correctly\n";
    } else {
        throw new Exception("MSGID fallback failed. Expected '1:777/666', got: {$replyToAddress2}");
    }
    
    // Test case 3: Message without REPLYADDR or MSGID
    echo "\n5. Testing fallback to from_address...\n";
    
    $testMessage3 = [
        'origAddr' => '1:333/444', 
        'destAddr' => '1:234/567',
        'fromName' => 'Test Sender 3',
        'toName' => 'Test Recipient',
        'subject' => 'Test REPLYADDR Final Fallback',
        'dateTime' => '01 Jan 25  12:10:00',
        'text' => "This message has no kludge lines.\r\nReply should go to from_address 1:333/444.",
        'attributes' => 0x0001
    ];
    
    $storeNetmailMethod->invoke($processor, $testMessage3, $packetInfo);
    
    $stmt = $db->prepare("
        SELECT id, from_address, original_author_address, reply_address, subject
        FROM netmail 
        WHERE subject = 'Test REPLYADDR Final Fallback'
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute();
    $storedMessage3 = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $replyToAddress3 = $storedMessage3['reply_address'] ?: ($storedMessage3['original_author_address'] ?: $storedMessage3['from_address']);
    
    echo "   - Reply Address (should be from_address): {$replyToAddress3}\n";
    
    if ($replyToAddress3 === '1:333/444') {
        echo "   ✓ Fallback to from_address working correctly\n";
    } else {
        throw new Exception("from_address fallback failed. Expected '1:333/444', got: {$replyToAddress3}");
    }
    
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "✅ ALL TESTS PASSED!\n";
    echo "\nREPLYADDR kludge functionality is working correctly:\n";
    echo "  1. REPLYADDR kludges are parsed from incoming messages\n";
    echo "  2. Reply address priority works: REPLYADDR > MSGID > from_address\n";
    echo "  3. Fallback logic works when REPLYADDR is not present\n";
    echo "  4. Database storage includes the reply_address field\n";
    echo "\nThe implementation successfully follows FidoNet standards!\n";
    
    // Cleanup test data
    $db->exec("DELETE FROM netmail WHERE subject LIKE 'Test REPLYADDR%'");
    
} catch (Exception $e) {
    echo "\n❌ TEST FAILED: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    
    // Cleanup on error
    try {
        $db->exec("DELETE FROM netmail WHERE subject LIKE 'Test REPLYADDR%'");
    } catch (Exception $cleanupError) {
        // Ignore cleanup errors
    }
    
    exit(1);
}

echo "\n";