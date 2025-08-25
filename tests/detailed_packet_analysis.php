<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\MessageHandler;
use BinktermPHP\Database;
use BinktermPHP\BinkdProcessor;

// Initialize database
Database::getInstance();

echo "=== DETAILED PACKET ANALYSIS ===\n";

// Post a test message
$messageHandler = new MessageHandler();
echo "1. Creating test echomail message...\n";

$result = $messageHandler->postEchomail(
    1,
    'LOCALTEST',
    'All',
    'Packet Analysis Test',
    'Analyzing packet structure in detail.'
);

if (!$result) {
    echo "Failed to create message\n";
    exit(1);
}

// Get the message
$db = Database::getInstance()->getPdo();
$stmt = $db->prepare("
    SELECT em.*, ea.tag as echoarea_tag 
    FROM echomail em
    JOIN echoareas ea ON em.echoarea_id = ea.id
    ORDER BY em.id DESC LIMIT 1
");
$stmt->execute();
$message = $stmt->fetch();

// Prepare for packet creation
$message['attributes'] = 0x0000;
$message['is_echomail'] = true;
$message['to_address'] = '1:153/149';

// Create processor and packet
$processor = new BinkdProcessor();
$packetFile = $processor->createOutboundPacket([$message], '1:153/149');

echo "2. Created packet: $packetFile\n";

if (!file_exists($packetFile)) {
    echo "Packet file not found\n";
    exit(1);
}

$content = file_get_contents($packetFile);
$size = strlen($content);

echo "3. Packet size: $size bytes\n\n";

echo "=== PACKET HEADER ANALYSIS (58 bytes) ===\n";
$header = substr($content, 0, 58);
$headerData = unpack('vorigNode/vdestNode/vyear/vmonth/vday/vhour/vminute/vsecond/vbaud/vpacketVersion/vorigNet/vdestNet', substr($header, 0, 24));

printf("Origin: %d:%d/%d\n", 1, $headerData['origNet'], $headerData['origNode']);
printf("Destination: %d:%d/%d\n", 1, $headerData['destNet'], $headerData['destNode']);
printf("Date: %04d-%02d-%02d %02d:%02d:%02d\n", 
    $headerData['year'], $headerData['month'] + 1, $headerData['day'],
    $headerData['hour'], $headerData['minute'], $headerData['second']);
printf("Packet version: %d\n", $headerData['packetVersion']);

// Check zone info
if (strlen($header) >= 38) {
    $zoneData = unpack('vorigZone/vdestZone', substr($header, 34, 4));
    printf("Zone info (FSC-39): Orig=%d, Dest=%d\n", $zoneData['origZone'], $zoneData['destZone']);
}

echo "\n=== MESSAGE STRUCTURE ANALYSIS ===\n";
$messageStart = 58;
$msgType = unpack('v', substr($content, $messageStart, 2))[1];
printf("Message type: %d (should be 2)\n", $msgType);

$msgHeader = unpack('vorigNode/vdestNode/vorigNet/vdestNet/vattr/vcost', substr($content, $messageStart + 2, 14));
printf("Message header - From: %d:%d/%d, To: %d:%d/%d\n", 
    1, $msgHeader['origNet'], $msgHeader['origNode'],
    1, $msgHeader['destNet'], $msgHeader['destNode']);
printf("Message attributes: 0x%04X (%s)\n", $msgHeader['attr'], 
    $msgHeader['attr'] & 0x0001 ? 'PRIVATE/NETMAIL' : 'PUBLIC/ECHOMAIL');

// Read null-terminated strings
$pos = $messageStart + 16; // After type + header

function readNullString($content, &$pos) {
    $start = $pos;
    while ($pos < strlen($content) && ord($content[$pos]) !== 0) {
        $pos++;
    }
    $result = substr($content, $start, $pos - $start);
    $pos++; // Skip null terminator
    return $result;
}

$dateTime = readNullString($content, $pos);
$toName = readNullString($content, $pos);
$fromName = readNullString($content, $pos);
$subject = readNullString($content, $pos);

printf("DateTime: '%s'\n", $dateTime);
printf("To: '%s'\n", $toName);
printf("From: '%s'\n", $fromName);
printf("Subject: '%s'\n", $subject);

echo "\n=== MESSAGE TEXT ANALYSIS ===\n";
$messageText = '';
while ($pos < strlen($content) && ord($content[$pos]) !== 0) {
    $messageText .= $content[$pos];
    $pos++;
}

echo "Message text length: " . strlen($messageText) . " bytes\n";
echo "First 100 bytes (hex): " . bin2hex(substr($messageText, 0, 100)) . "\n";

// Look for kludge lines
$lines = explode("\n", str_replace("\r\n", "\n", $messageText));
echo "\nKludge lines found:\n";
foreach ($lines as $i => $line) {
    if (strlen($line) > 0 && ord($line[0]) === 0x01) {
        $kludgeLine = substr($line, 1); // Remove SOH
        echo "  ^A$kludgeLine\n";
    } elseif ($i < 5) { // Show first few non-kludge lines
        echo "  TEXT: " . substr($line, 0, 50) . (strlen($line) > 50 ? '...' : '') . "\n";
    }
}

// Check for AREA kludge specifically
if (strpos($messageText, "\x01AREA:") !== false) {
    echo "\n✓ AREA kludge line found\n";
} else {
    echo "\n✗ AREA kludge line MISSING\n";
}

// Check for tearline and origin
if (strpos($messageText, "---") !== false) {
    echo "✓ Tearline found\n";
} else {
    echo "✗ Tearline missing\n";
}

if (strpos($messageText, "* Origin:") !== false) {
    echo "✓ Origin line found\n";
} else {
    echo "✗ Origin line missing\n";
}

// Cleanup
unlink($packetFile);

echo "\n=== ANALYSIS COMPLETE ===\n";