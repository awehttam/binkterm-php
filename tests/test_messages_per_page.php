<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\MessageHandler;
use BinktermPHP\Database;

// Initialize database
Database::getInstance();

echo "Testing Messages Per Page User Setting\n";
echo "=====================================\n\n";

try {
    $db = Database::getInstance()->getPdo();
    
    // Check current user settings
    echo "1. Current user settings:\n";
    $stmt = $db->query("SELECT user_id, messages_per_page FROM user_settings");
    $settings = $stmt->fetchAll();
    
    if (empty($settings)) {
        echo "   No user settings found. Creating test setting...\n";
        // Create a test setting for user ID 1
        $insertStmt = $db->prepare("INSERT INTO user_settings (user_id, messages_per_page) VALUES (1, 10) ON CONFLICT (user_id) DO UPDATE SET messages_per_page = EXCLUDED.messages_per_page");
        $insertStmt->execute();
        echo "   Created test setting: User 1 -> 10 messages per page\n";
    } else {
        foreach ($settings as $setting) {
            echo "   User {$setting['user_id']}: {$setting['messages_per_page']} messages per page\n";
        }
    }
    echo "\n";
    
    // Test MessageHandler with different page sizes
    echo "2. Testing MessageHandler with user settings:\n";
    $handler = new MessageHandler();
    
    // Test with user ID 1 (should use their setting)
    echo "   Testing with User ID 1:\n";
    $result1 = $handler->getNetmail(1, 1);  // Using null limit to get user setting
    echo "     Page size used: " . ($result1['pagination']['limit'] ?? 'N/A') . "\n";
    echo "     Messages returned: " . count($result1['messages']) . "\n";
    echo "     Total messages: " . ($result1['pagination']['total'] ?? 'N/A') . "\n";
    echo "     Total pages: " . ($result1['pagination']['pages'] ?? 'N/A') . "\n";
    echo "\n";
    
    // Test with different messages_per_page values
    echo "3. Testing different messages_per_page values:\n";
    $testValues = [5, 10, 15, 50];
    
    foreach ($testValues as $pageSize) {
        echo "   Setting user 1 to $pageSize messages per page:\n";
        $updateStmt = $db->prepare("UPDATE user_settings SET messages_per_page = ? WHERE user_id = 1");
        $updateStmt->execute([$pageSize]);
        
        // Test netmail
        $netmailResult = $handler->getNetmail(1, 1);
        echo "     Netmail - Limit: " . ($netmailResult['pagination']['limit'] ?? 'N/A');
        echo ", Pages: " . ($netmailResult['pagination']['pages'] ?? 'N/A') . "\n";
        
        // Test echomail
        $echomailResult = $handler->getEchomail('COOKING', 1, null, 1);
        echo "     Echomail - Limit: " . ($echomailResult['pagination']['limit'] ?? 'N/A');
        echo ", Pages: " . ($echomailResult['pagination']['pages'] ?? 'N/A') . "\n";
        echo "\n";
    }
    
    // Test user without settings
    echo "4. Testing user without settings (should use default):\n";
    $result2 = $handler->getNetmail(999, 1);  // User 999 doesn't exist
    echo "   Default limit used: " . ($result2['pagination']['limit'] ?? 'N/A') . "\n";
    
    // Reset user 1 to default
    echo "\n5. Resetting user 1 to default (25):\n";
    $resetStmt = $db->prepare("UPDATE user_settings SET messages_per_page = 25 WHERE user_id = 1");
    $resetStmt->execute();
    echo "   Reset complete.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\nTest completed.\n";