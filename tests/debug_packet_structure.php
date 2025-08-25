<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\MessageHandler;
use BinktermPHP\Database;
use BinktermPHP\BinkdProcessor;

// Initialize database
Database::getInstance();

echo "=== DEBUGGING PACKET STRUCTURE ===\n";

// Create a test echomail message
$messageHandler = new MessageHandler();
echo "1. Posting test echomail message...\n";

$result = $messageHandler->postEchomail(
    1,                    // fromUserId
    'LOCALTEST',         // echoareaTag
    'All',               // toName
    'Packet Debug Test', // subject
    'Testing packet structure for echomail detection.'  // messageText
);

if (!$result) {
    echo "Failed to post echomail\n";
    exit(1);
}

echo "2. Message posted successfully\n";

// Let me manually create a packet to examine its structure
$processor = new BinkdProcessor();

// Get the latest message
$db = Database::getInstance()->getPdo();
$stmt = $db->prepare("
    SELECT em.*, ea.tag as echoarea_tag 
    FROM echomail em
    JOIN echoareas ea ON em.echoarea_id = ea.id
    ORDER BY em.id DESC LIMIT 1
");
$stmt->execute();
$message = $stmt->fetch();

if (!$message) {
    echo "No message found\n";
    exit(1);
}

echo "3. Retrieved message ID: " . $message['id'] . "\n";

// Prepare message for packet creation
$message['message_text'] = $message['message_text']; // Don't add AREA: line
$message['attributes'] = 0x0000; // No private flag
$message['is_echomail'] = true;
$message['to_address'] = '1:153/149'; // Uplink address

echo "4. Message attributes: " . $message['attributes'] . "\n";
echo "5. Is echomail flag: " . ($message['is_echomail'] ? 'YES' : 'NO') . "\n";
echo "6. Echoarea tag: " . $message['echoarea_tag'] . "\n";
echo "7. Message text starts: " . substr($message['message_text'], 0, 50) . "...\n";

try {
    // Create packet file
    $packetFile = $processor->createOutboundPacket([$message], '1:153/149');
    echo "8. Created packet file: $packetFile\n";
    
    // Read and analyze the packet
    if (file_exists($packetFile)) {
        $content = file_get_contents($packetFile);
        $size = strlen($content);
        echo "9. Packet size: $size bytes\n";
        
        // Show hex dump of first 100 bytes
        echo "10. Packet header (first 100 bytes):\n";
        echo bin2hex(substr($content, 0, 100)) . "\n";
        
        // Look for AREA kludge line
        $areaPos = strpos($content, "\x01AREA:");
        if ($areaPos !== false) {
            echo "11. Found AREA kludge line at position: $areaPos\n";
            $areaLine = substr($content, $areaPos, 50);
            echo "12. AREA kludge content: " . bin2hex($areaLine) . "\n";
            echo "13. AREA kludge readable: " . str_replace(["\x01", "\r", "\n"], ["^A", "\\r", "\\n"], $areaLine) . "\n";
        } else {
            echo "11. ERROR: No AREA kludge line found in packet!\n";
        }
        
        // Clean up
        unlink($packetFile);
    } else {
        echo "8. ERROR: Packet file not created\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "=== DEBUG COMPLETE ===\n";