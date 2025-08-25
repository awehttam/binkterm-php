<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;

echo "=== COMPARING WITH WORKING MESSAGE ===\n";

// Get a working received message
$db = Database::getInstance()->getPdo();
$stmt = $db->prepare("
    SELECT message_text, from_name, subject 
    FROM echomail 
    WHERE from_name = 'Awehttam' 
    ORDER BY id DESC LIMIT 1
");
$stmt->execute();
$workingMessage = $stmt->fetch();

if (!$workingMessage) {
    echo "No working message found\n";
    exit(1);
}

echo "Working message from Awehttam:\n";
echo "Subject: " . $workingMessage['subject'] . "\n";
echo "Raw message text (first 500 chars):\n";
echo substr($workingMessage['message_text'], 0, 500) . "\n\n";

echo "Hex dump of first 200 bytes:\n";
echo bin2hex(substr($workingMessage['message_text'], 0, 200)) . "\n\n";

// Parse the working message structure
$lines = explode("\n", str_replace("\r\n", "\n", $workingMessage['message_text']));
echo "Working message structure:\n";
foreach ($lines as $i => $line) {
    if ($i > 10) break; // Only show first 10 lines
    
    if (strlen($line) == 0) {
        echo sprintf("%2d: (empty)\n", $i + 1);
    } elseif (strlen($line) > 0 && ord($line[0]) === 0x01) {
        $kludge = substr($line, 1);
        echo sprintf("%2d: ^A%s\n", $i + 1, $kludge);
    } else {
        echo sprintf("%2d: TEXT: %s\n", $i + 1, substr($line, 0, 60));
    }
}

echo "\n=== KEY QUESTIONS ===\n";
echo "1. Does the working message have kludge lines? " . (strpos($workingMessage['message_text'], "\x01") !== false ? "YES" : "NO") . "\n";
echo "2. Does it have an AREA kludge? " . (strpos($workingMessage['message_text'], "\x01AREA:") !== false ? "YES" : "NO") . "\n";
echo "3. First character is SOH (0x01)? " . (strlen($workingMessage['message_text']) > 0 && ord($workingMessage['message_text'][0]) === 0x01 ? "YES" : "NO") . "\n";
echo "4. Contains tearline (---)? " . (strpos($workingMessage['message_text'], "---") !== false ? "YES" : "NO") . "\n";
echo "5. Contains origin line? " . (strpos($workingMessage['message_text'], "* Origin:") !== false ? "YES" : "NO") . "\n";

echo "\n=== ANALYSIS COMPLETE ===\n";