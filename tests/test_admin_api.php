<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Auth;
use BinktermPHP\MessageHandler;
use BinktermPHP\Database;

// Initialize database
Database::getInstance();

echo "Testing Admin API Endpoints\n";
echo "===========================\n\n";

try {
    // Test direct method calls first
    echo "1. Testing MessageHandler::getPendingUsers():\n";
    $handler = new MessageHandler();
    $pendingUsers = $handler->getPendingUsers();
    echo "   ✓ Found " . count($pendingUsers) . " pending users\n";
    
    foreach ($pendingUsers as $user) {
        echo "     - {$user['username']} ({$user['status']})\n";
    }
    echo "\n";
    
    // Test database query for all users
    echo "2. Testing direct database query for all users:\n";
    $db = Database::getInstance()->getPdo();
    $stmt = $db->query("
        SELECT id, username, real_name, email, created_at, last_login, is_active, is_admin
        FROM users 
        ORDER BY created_at DESC
    ");
    $users = $stmt->fetchAll();
    echo "   ✓ Found " . count($users) . " users\n";
    
    foreach ($users as $user) {
        echo "     - {$user['username']} (Admin: " . ($user['is_admin'] ? 'Yes' : 'No') . ")\n";
    }
    echo "\n";
    
    // Test admin user check
    echo "3. Testing admin user authentication:\n";
    $adminStmt = $db->query("SELECT * FROM users WHERE is_admin = 1 LIMIT 1");
    $admin = $adminStmt->fetch();
    
    if ($admin) {
        echo "   ✓ Found admin user: {$admin['username']}\n";
        echo "     ID: {$admin['id']}\n";
        echo "     Is Admin: " . ($admin['is_admin'] ? 'Yes' : 'No') . "\n";
    } else {
        echo "   ✗ No admin user found!\n";
    }
    echo "\n";
    
    // Test session validation
    echo "4. Testing Auth class:\n";
    $auth = new Auth();
    echo "   ✓ Auth class instantiated successfully\n";
    
    // Check sessions table
    $sessionStmt = $db->query("SELECT COUNT(*) as count FROM user_sessions");
    $sessionCount = $sessionStmt->fetch();
    echo "   Active sessions: {$sessionCount['count']}\n";
    echo "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "Admin API Test completed.\n";