<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;

echo "=== ANALYZING WORKING ECHOMAIL ===\n";

// Initialize database
$db = Database::getInstance()->getPdo();

// Get a working echomail message from Awehttam
$stmt = $db->prepare("
    SELECT em.*, ea.tag as echoarea_tag 
    FROM echomail em
    JOIN echoareas ea ON em.echoarea_id = ea.id
    WHERE em.from_name = 'Awehttam'
    ORDER BY em.id DESC LIMIT 1
");
$stmt->execute();
$workingMessage = $stmt->fetch();

if (!$workingMessage) {
    echo "No working message found from Awehttam\n";
    exit(1);
}

// Get our message that's being treated as netmail
$stmt = $db->prepare("
    SELECT em.*, ea.tag as echoarea_tag 
    FROM echomail em
    JOIN echoareas ea ON em.echoarea_id = ea.id
    WHERE em.from_name = 'System Administrator'
    ORDER BY em.id DESC LIMIT 1
");
$stmt->execute();
$ourMessage = $stmt->fetch();

if (!$ourMessage) {
    echo "No message found from System Administrator\n";
    exit(1);
}

echo "=== WORKING MESSAGE (from Awehttam) ===\n";
echo "ID: " . $workingMessage['id'] . "\n";
echo "From Address: " . $workingMessage['from_address'] . "\n";
echo "From Name: " . $workingMessage['from_name'] . "\n";
echo "To Name: " . $workingMessage['to_name'] . "\n";
echo "Subject: " . $workingMessage['subject'] . "\n";
echo "Echoarea: " . $workingMessage['echoarea_tag'] . "\n";
echo "Date Written: " . $workingMessage['date_written'] . "\n";
echo "Date Received: " . $workingMessage['date_received'] . "\n";
echo "Message text (first 200 chars): " . substr($workingMessage['message_text'], 0, 200) . "\n";

echo "\n=== OUR MESSAGE (from System Administrator) ===\n";
echo "ID: " . $ourMessage['id'] . "\n";
echo "From Address: " . $ourMessage['from_address'] . "\n";
echo "From Name: " . $ourMessage['from_name'] . "\n";
echo "To Name: " . $ourMessage['to_name'] . "\n";
echo "Subject: " . $ourMessage['subject'] . "\n";
echo "Echoarea: " . $ourMessage['echoarea_tag'] . "\n";
echo "Date Written: " . $ourMessage['date_written'] . "\n";
echo "Date Received: " . $ourMessage['date_received'] . "\n";
echo "Message text (first 200 chars): " . substr($ourMessage['message_text'], 0, 200) . "\n";

echo "\n=== COMPARISON ===\n";
echo "Address difference: " . ($workingMessage['from_address'] === $ourMessage['from_address'] ? 'SAME' : 'DIFFERENT') . "\n";
echo "Working from: " . $workingMessage['from_address'] . "\n";
echo "Ours from: " . $ourMessage['from_address'] . "\n";

echo "To Name difference: " . ($workingMessage['to_name'] === $ourMessage['to_name'] ? 'SAME' : 'DIFFERENT') . "\n";

// Check if there are any visible control characters in the working message
echo "\n=== HEX ANALYSIS OF WORKING MESSAGE ===\n";
$workingHex = bin2hex(substr($workingMessage['message_text'], 0, 100));
$ourHex = bin2hex(substr($ourMessage['message_text'], 0, 100));

echo "Working message hex: " . $workingHex . "\n";
echo "Our message hex: " . $ourHex . "\n";

echo "\n=== ANALYSIS COMPLETE ===\n";