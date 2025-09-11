<?php

/**
 * Debug the actual content of message 288 to see REPLYTO format
 */

require_once __DIR__ . '/vendor/autoload.php';

$srcFiles = glob(__DIR__ . '/src/*.php');
foreach ($srcFiles as $file) {
    require_once $file;
}

try {
    $db = \BinktermPHP\Database::getInstance()->getPdo();
    
    $stmt = $db->prepare("SELECT id, message_text, kludge_lines, from_name, from_address FROM echomail WHERE id = ?");
    $stmt->execute([288]);
    $message = $stmt->fetch();
    
    if (!$message) {
        echo "Message 288 not found.\n";
        exit;
    }
    
    echo "=== FULL MESSAGE TEXT ===\n";
    echo $message['message_text'];
    echo "\n\n=== FULL KLUDGE LINES ===\n";
    echo $message['kludge_lines'];
    echo "\n\n=== RAW HEX DUMP OF MESSAGE TEXT (first 500 chars) ===\n";
    echo bin2hex(substr($message['message_text'], 0, 500));
    echo "\n\n=== RAW HEX DUMP OF KLUDGE LINES (first 500 chars) ===\n";
    echo bin2hex(substr($message['kludge_lines'], 0, 500));
    echo "\n\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

?>