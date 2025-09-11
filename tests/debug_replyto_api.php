<?php

/**
 * Debug REPLYTO functionality in echomail API
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/public_html/index.php';

// Connect to database to check message content directly
$db = Database::getInstance()->getPdo();

echo "Debugging REPLYTO functionality for echomail message 288...\n\n";

// Get message 288 from database
$stmt = $db->prepare("SELECT id, message_text, kludge_lines, from_name, from_address FROM echomail WHERE id = ?");
$stmt->execute([288]);
$message = $stmt->fetch();

if (!$message) {
    echo "Message 288 not found in database.\n";
    exit;
}

echo "Message 288 found:\n";
echo "From: " . $message['from_name'] . " (" . $message['from_address'] . ")\n";
echo "Message text length: " . strlen($message['message_text']) . " characters\n";
echo "Kludge lines length: " . strlen($message['kludge_lines'] ?? '') . " characters\n\n";

// Check for REPLYTO in message_text
echo "=== Checking message_text for REPLYTO ===\n";
if (strpos($message['message_text'], 'REPLYTO') !== false) {
    echo "✓ Found 'REPLYTO' in message_text\n";
    // Show lines containing REPLYTO
    $lines = preg_split('/\r\n|\r|\n/', $message['message_text']);
    foreach ($lines as $i => $line) {
        if (stripos($line, 'replyto') !== false) {
            echo "Line " . ($i + 1) . ": " . trim($line) . "\n";
        }
    }
} else {
    echo "✗ No 'REPLYTO' found in message_text\n";
}

// Check for REPLYTO in kludge_lines
echo "\n=== Checking kludge_lines for REPLYTO ===\n";
if (!empty($message['kludge_lines']) && strpos($message['kludge_lines'], 'REPLYTO') !== false) {
    echo "✓ Found 'REPLYTO' in kludge_lines\n";
    $lines = preg_split('/\r\n|\r|\n/', $message['kludge_lines']);
    foreach ($lines as $i => $line) {
        if (stripos($line, 'replyto') !== false) {
            echo "Line " . ($i + 1) . ": " . trim($line) . "\n";
        }
    }
} else {
    echo "✗ No 'REPLYTO' found in kludge_lines (or kludge_lines is empty)\n";
}

// Test parseReplyToKludge function on this message
echo "\n=== Testing parseReplyToKludge function ===\n";

// Test on message_text
$replyToData = parseReplyToKludge($message['message_text']);
if ($replyToData) {
    echo "✓ parseReplyToKludge found REPLYTO in message_text:\n";
    echo "  Address: " . $replyToData['address'] . "\n";
    echo "  Name: " . $replyToData['name'] . "\n";
} else {
    echo "✗ parseReplyToKludge found no REPLYTO in message_text\n";
}

// Test on kludge_lines if they exist
if (!empty($message['kludge_lines'])) {
    $replyToDataKludge = parseReplyToKludge($message['kludge_lines']);
    if ($replyToDataKludge) {
        echo "✓ parseReplyToKludge found REPLYTO in kludge_lines:\n";
        echo "  Address: " . $replyToDataKludge['address'] . "\n";
        echo "  Name: " . $replyToDataKludge['name'] . "\n";
    } else {
        echo "✗ parseReplyToKludge found no REPLYTO in kludge_lines\n";
    }
}

// Show first few lines of message content for manual inspection
echo "\n=== First 10 lines of message_text ===\n";
$lines = preg_split('/\r\n|\r|\n/', $message['message_text']);
for ($i = 0; $i < min(10, count($lines)); $i++) {
    echo ($i + 1) . ": " . $lines[$i] . "\n";
}

if (!empty($message['kludge_lines'])) {
    echo "\n=== First 10 lines of kludge_lines ===\n";
    $kludgeLines = preg_split('/\r\n|\r|\n/', $message['kludge_lines']);
    for ($i = 0; $i < min(10, count($kludgeLines)); $i++) {
        echo ($i + 1) . ": " . $kludgeLines[$i] . "\n";
    }
}

echo "\nDebug complete.\n";

?>