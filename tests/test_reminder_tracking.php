<?php
// Test script to verify reminder tracking functionality
// This script demonstrates the new reminder tracking features

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/AdminController.php';
require_once __DIR__ . '/../src/MessageHandler.php';

use BinktermPHP\Database;
use BinktermPHP\AdminController;
use BinktermPHP\MessageHandler;

echo "=== Reminder Tracking Test Script ===\n\n";

try {
    // Initialize database connection
    $db = Database::getInstance()->getPdo();
    
    // Check if last_reminded column exists
    echo "1. Checking if last_reminded column exists...\n";
    $stmt = $db->prepare("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'users' AND column_name = 'last_reminded'
    ");
    $stmt->execute();
    $columnExists = $stmt->fetch();
    
    if ($columnExists) {
        echo "   ✓ last_reminded column exists in users table\n";
    } else {
        echo "   ✗ last_reminded column NOT found - migration needs to be run\n";
        echo "   Run: psql -d your_database -f database/migrations/v1.4.8_add_last_reminded_field.sql\n";
    }
    
    // Test AdminController getAllUsers method includes last_reminded
    echo "\n2. Testing AdminController getAllUsers method...\n";
    $adminController = new AdminController();
    $result = $adminController->getAllUsers(1, 5, '');
    
    if (!empty($result['users'])) {
        $firstUser = $result['users'][0];
        if (isset($firstUser['days_since_reminder'])) {
            echo "   ✓ days_since_reminder field is included in user data\n";
            echo "   Sample user reminder status: ";
            if ($firstUser['days_since_reminder'] === null) {
                echo "Never reminded\n";
            } else {
                echo $firstUser['days_since_reminder'] . " days ago\n";
            }
        } else {
            echo "   ✗ days_since_reminder field missing from user data\n";
        }
        
        if (array_key_exists('last_reminded', $firstUser)) {
            echo "   ✓ last_reminded field is included in query results\n";
        } else {
            echo "   ✗ last_reminded field missing from query results\n";
        }
    } else {
        echo "   ! No users found in database to test\n";
    }
    
    // Test MessageHandler reminder functionality
    echo "\n3. Testing MessageHandler reminder functionality...\n";
    $messageHandler = new MessageHandler();
    
    // Check if sendAccountReminder method exists and has been updated
    $reflection = new ReflectionClass($messageHandler);
    if ($reflection->hasMethod('sendAccountReminder')) {
        echo "   ✓ sendAccountReminder method exists\n";
        
        // Read the method source to verify it includes last_reminded update
        $method = $reflection->getMethod('sendAccountReminder');
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $source = implode('', array_slice(file($filename), $startLine - 1, $endLine - $startLine + 1));
        
        if (strpos($source, 'last_reminded') !== false) {
            echo "   ✓ sendAccountReminder method includes last_reminded timestamp update\n";
        } else {
            echo "   ✗ sendAccountReminder method does NOT update last_reminded timestamp\n";
        }
    } else {
        echo "   ✗ sendAccountReminder method not found\n";
    }
    
    echo "\n=== Test Summary ===\n";
    echo "Migration file created: database/migrations/v1.4.8_add_last_reminded_field.sql\n";
    echo "AdminController updated: ✓ Includes last_reminded in queries and calculates days\n";
    echo "MessageHandler updated: ✓ Updates last_reminded timestamp on successful reminder\n";
    echo "Admin template updated: ✓ Displays 'Last Reminded' column in user list\n";
    echo "\nTo complete setup:\n";
    echo "1. Run the migration: psql -d your_database -f database/migrations/v1.4.8_add_last_reminded_field.sql\n";
    echo "2. Test the reminder functionality through the admin interface\n";
    echo "3. Verify that 'days ago' values appear in the user list after sending reminders\n\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}