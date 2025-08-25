<?php

// Simulate the API endpoint directly
require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Auth;
use BinktermPHP\MessageHandler;
use BinktermPHP\Database;

// Initialize database
Database::getInstance();

echo "Testing Admin API Endpoints Direct Call\n";
echo "=======================================\n\n";

try {
    // Simulate the /api/admin/pending-users endpoint
    echo "1. Testing /api/admin/pending-users endpoint logic:\n";
    
    $auth = new Auth();
    
    // Check if we have a valid admin session
    $sessionId = 'test_admin_session'; // This would normally come from cookie
    
    // For testing, let's simulate the admin user directly
    $db = Database::getInstance()->getPdo();
    $userStmt = $db->query("SELECT * FROM users WHERE is_admin = 1 LIMIT 1");
    $user = $userStmt->fetch();
    
    if (!$user) {
        echo "   ✗ No admin user found\n";
        exit(1);
    }
    
    if (!$user['is_admin']) {
        echo "   ✗ User is not admin\n";
        exit(1);
    }
    
    echo "   ✓ Admin user found: {$user['username']}\n";
    
    $handler = new MessageHandler();
    $users = $handler->getPendingUsers();
    
    $response = ['success' => true, 'users' => $users];
    echo "   ✓ API response generated successfully\n";
    echo "   Users count: " . count($users) . "\n";
    
    // Test JSON encoding
    $json = json_encode($response);
    if ($json === false) {
        echo "   ✗ JSON encoding failed: " . json_last_error_msg() . "\n";
    } else {
        echo "   ✓ JSON encoding successful\n";
        echo "   JSON length: " . strlen($json) . " bytes\n";
    }
    echo "\n";
    
    // Test the /api/admin/users endpoint logic
    echo "2. Testing /api/admin/users endpoint logic:\n";
    
    $stmt = $db->query("
        SELECT id, username, real_name, email, created_at, last_login, is_active, is_admin
        FROM users 
        ORDER BY created_at DESC
    ");
    $allUsers = $stmt->fetchAll();
    
    $response2 = ['success' => true, 'users' => $allUsers];
    echo "   ✓ All users query successful\n";
    echo "   Users count: " . count($allUsers) . "\n";
    
    $json2 = json_encode($response2);
    if ($json2 === false) {
        echo "   ✗ JSON encoding failed: " . json_last_error_msg() . "\n";
    } else {
        echo "   ✓ JSON encoding successful\n";
        echo "   JSON length: " . strlen($json2) . " bytes\n";
    }
    echo "\n";
    
    // Show sample data
    echo "3. Sample pending user data:\n";
    if (!empty($users)) {
        $firstUser = $users[0];
        foreach ($firstUser as $key => $value) {
            if (strlen($value) > 50) {
                $value = substr($value, 0, 50) . '...';
            }
            echo "   $key: $value\n";
        }
    } else {
        echo "   No pending users to display\n";
    }
    echo "\n";
    
    echo "4. Sample regular user data:\n";
    if (!empty($allUsers)) {
        $firstRegularUser = $allUsers[0];
        foreach ($firstRegularUser as $key => $value) {
            if (is_string($value) && strlen($value) > 50) {
                $value = substr($value, 0, 50) . '...';
            }
            echo "   $key: " . ($value ?? 'NULL') . "\n";
        }
    } else {
        echo "   No users to display\n";
    }
    
    echo "\nDirect API test completed successfully.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}