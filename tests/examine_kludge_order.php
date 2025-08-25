<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\MessageHandler;
use BinktermPHP\Database;
use BinktermPHP\BinkdProcessor;

// Initialize database
Database::getInstance();

echo "=== EXAMINING KLUDGE LINE ORDER ===\n";

// Create test message
$messageHandler = new MessageHandler();
$result = $messageHandler->postEchomail(1, 'LOCALTEST', 'All', 'Kludge Order Test', 'Testing the order of kludge lines.');

if (!$result) {
    echo "Failed to create message\n";
    exit(1);
}

// Get the message
$db = Database::getInstance()->getPdo();
$stmt = $db->prepare("SELECT em.*, ea.tag as echoarea_tag FROM echomail em JOIN echoareas ea ON em.echoarea_id = ea.id ORDER BY em.id DESC LIMIT 1");
$stmt->execute();
$message = $stmt->fetch();

// Prepare for packet creation
$message['attributes'] = 0x0000;
$message['is_echomail'] = true;
$message['to_address'] = '1:153/149';

// Create processor and packet
$processor = new BinkdProcessor();
$packetFile = $processor->createOutboundPacket([$message], '1:153/149');

if (!file_exists($packetFile)) {
    echo "Packet file not found\n";
    exit(1);
}

$content = file_get_contents($packetFile);

// Find message text start
$messageStart = 58; // Packet header
$messageStart += 2;  // Message type
$messageStart += 14; // Message header

// Skip null-terminated strings
$pos = $messageStart;
function skipNullString($content, &$pos) {
    while ($pos < strlen($content) && ord($content[$pos]) !== 0) {
        $pos++;
    }
    $pos++; // Skip null terminator
}

skipNullString($content, $pos); // Date
skipNullString($content, $pos); // To
skipNullString($content, $pos); // From  
skipNullString($content, $pos); // Subject

// Now we're at the message text
$messageText = '';
while ($pos < strlen($content) && ord($content[$pos]) !== 0) {
    $messageText .= $content[$pos];
    $pos++;
}

echo "Message text starts at byte: $pos\n";
echo "First 200 bytes of message text:\n";
echo bin2hex(substr($messageText, 0, 200)) . "\n\n";

// Parse lines to show exact order
$lines = explode("\r\n", $messageText);
echo "Kludge/control line order:\n";
foreach ($lines as $i => $line) {
    if (strlen($line) == 0) {
        echo sprintf("%2d: (empty line)\n", $i + 1);
    } elseif (strlen($line) > 0 && ord($line[0]) === 0x01) {
        $kludge = substr($line, 1);
        echo sprintf("%2d: ^A%s\n", $i + 1, $kludge);
    } elseif (strpos($line, '---') === 0) {
        echo sprintf("%2d: TEARLINE: %s\n", $i + 1, $line);
    } elseif (strpos($line, ' * Origin:') === 0) {
        echo sprintf("%2d: ORIGIN: %s\n", $i + 1, $line);
    } elseif (strpos($line, 'SEEN-BY:') === 0) {
        echo sprintf("%2d: SEENBY: %s\n", $i + 1, $line);
    } else {
        echo sprintf("%2d: TEXT: %s\n", $i + 1, substr($line, 0, 50) . (strlen($line) > 50 ? '...' : ''));
    }
}

// Check if AREA is first
$firstLine = isset($lines[0]) ? $lines[0] : '';
if (strlen($firstLine) > 0 && ord($firstLine[0]) === 0x01 && strpos($firstLine, 'AREA:') === 1) {
    echo "\n✓ AREA kludge is first line of message text\n";
} else {
    echo "\n✗ AREA kludge is NOT first line of message text\n";
    echo "First line: " . bin2hex($firstLine) . "\n";
}

unlink($packetFile);
echo "\n=== EXAMINATION COMPLETE ===\n";