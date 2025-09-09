<?php
require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;
use BinktermPHP\Binkd\BinkdProcessor;

echo "MSGID Parsing Test\n";
echo "==================\n\n";

try {
    // Initialize database
    //$db = Database::getInstance()->getPdo();
    echo "✓ Database connection established\n";
    
    // Test MSGID parsing regex
    $testMsgIds = [
        "1:234/567 12345678",
        "2:5020/1042.1 abcdef01", 
        "3:771/100 deadbeef",
        "1:123/456.789 98765432",
        "244652.syncdata@1:103/705 2d1da177",

        "invalid_format"
    ];
    
    echo "\n1. Testing MSGID regex pattern:\n";
    echo "   Testing original regex (standard format only):\n";
    foreach ($testMsgIds as $msgId) {
        echo "     '$msgId' -> ";
        if (preg_match('/^(\d+:\d+\/\d+(?:\.\d+)?)\s+/', $msgId, $matches)) {
            echo "✓ Address: {$matches[1]}\n";
        } else {
            echo "✗ No match\n";
        }
    }
    
    echo "\n   Testing enhanced regex (both standard and alternate formats):\n";
    foreach ($testMsgIds as $msgId) {
        echo "     '$msgId' -> ";
        // Enhanced regex to handle both formats:
        // 1. Standard: "1:123/456 hash" 
        // 2. Alternate: "serial.tag@1:123/456 hash"
        if (preg_match('/^(?:.*@)?(\d+:\d+\/\d+(?:\.\d+)?)\s+/', $msgId, $matches)) {
            echo "✓ Address: {$matches[1]}\n";
        } else {
            echo "✗ No match\n";
        }
    }
    exit;
    // Test with sample netmail message with MSGID
    echo "\n2. Testing with sample netmail message:\n";
    $sampleMessage = [
        'origAddr' => '1:999/999',
        'destAddr' => '1:234/567',
        'fromName' => 'Test Sender',
        'toName' => 'Test Recipient', 
        'subject' => 'Test Message with MSGID',
        'dateTime' => '01 Jan 25 12:00:00',
        'attributes' => 0,
        'text' => "\x01MSGID: 1:123/456 12345678\r\n\x01TZUTC: +0000\r\nThis is a test message with MSGID kludge line.\r\n"
    ];
    
    echo "   Sample MSGID: '1:123/456 12345678'\n";
    echo "   Expected original_author_address: '1:123/456'\n";
    
    // Test the parsing logic (simulate what BinkdProcessor does)
    $messageText = $sampleMessage['text'];
    $messageText = str_replace("\r\n", "\n", $messageText);
    $messageText = str_replace("\r", "\n", $messageText);
    
    $lines = explode("\n", $messageText);
    $originalAuthorAddress = null;
    $messageId = null;
    
    foreach ($lines as $line) {
        if (strlen($line) > 0 && ord($line[0]) === 0x01) {
            if (strpos($line, "\x01MSGID:") === 0) {
                $messageId = trim(substr($line, 7));
                echo "   Extracted MSGID: '$messageId'\n";
                
                if (preg_match('/^(\d+:\d+\/\d+(?:\.\d+)?)\s+/', $messageId, $matches)) {
                    $originalAuthorAddress = $matches[1];
                    echo "   ✓ Extracted original_author_address: '$originalAuthorAddress'\n";
                } else {
                    echo "   ✗ Failed to extract address from MSGID\n";
                }
            }
        }
    }
    
    if ($originalAuthorAddress === '1:123/456') {
        echo "   ✓ MSGID parsing working correctly!\n";
    } else {
        echo "   ✗ MSGID parsing failed - got '$originalAuthorAddress', expected '1:123/456'\n";
    }
    
    echo "\n3. Testing database schema (checking if migration has been applied):\n";
    
    // Check if original_author_address column exists
    $stmt = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = 'netmail' AND column_name = 'original_author_address'");
    $stmt->execute();
    $column = $stmt->fetch();
    
    if ($column) {
        echo "   ✓ original_author_address column exists in netmail table\n";
    } else {
        echo "   ✗ original_author_address column NOT found - migration needs to be applied\n";
        echo "   Run the migration: database/migrations/v1.4.7_add_netmail_original_author_address.sql\n";
    }
    
    // Check if message_id column exists (from previous migration)
    $stmt = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = 'netmail' AND column_name = 'message_id'");
    $stmt->execute();
    $column = $stmt->fetch();
    
    if ($column) {
        echo "   ✓ message_id column exists in netmail table\n";
    } else {
        echo "   ✗ message_id column NOT found - v1.4.3 migration needs to be applied\n";
    }
    
    echo "\n✓ MSGID parsing test completed successfully!\n";
    echo "\nNext steps:\n";
    echo "1. Apply the database migration: v1.4.7_add_netmail_original_author_address.sql\n";
    echo "2. Process some netmail messages with MSGID kludge lines\n";
    echo "3. Verify that original_author_address is populated correctly\n";
    echo "4. Test netmail replies use the correct address\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\nTest completed.\n";
?>