<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Auth;
use BinktermPHP\Database;

// Initialize database
Database::getInstance();

// Test the statistics API endpoints directly
echo "Testing Statistics API Endpoints\n";
echo "================================\n\n";

// Initialize auth (you might need to set up a test user session)
$auth = new Auth();

// Test 1: Direct database queries
echo "1. Testing direct database queries:\n";
try {
    $db = Database::getInstance()->getPdo();
    
    // Test netmail count
    $netmailCount = $db->query("SELECT COUNT(*) as count FROM netmail")->fetch()['count'];
    echo "   - Total netmail messages: $netmailCount\n";
    
    // Test echomail count
    $echomailCount = $db->query("SELECT COUNT(*) as count FROM echomail")->fetch()['count'];
    echo "   - Total echomail messages: $echomailCount\n";
    
    // Test echoareas count
    $areasCount = $db->query("SELECT COUNT(*) as count FROM echoareas")->fetch()['count'];
    echo "   - Total echo areas: $areasCount\n";
    
    echo "   ✓ Database queries working\n\n";
    
} catch (Exception $e) {
    echo "   ✗ Database error: " . $e->getMessage() . "\n\n";
}

// Test 2: Check if BinkpConfig can be loaded
echo "2. Testing BinkpConfig:\n";
try {
    $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
    $systemAddress = $binkpConfig->getSystemAddress();
    echo "   - System address: $systemAddress\n";
    echo "   ✓ BinkpConfig working\n\n";
} catch (Exception $e) {
    echo "   ✗ BinkpConfig error: " . $e->getMessage() . "\n\n";
}

// Test 3: Simulate the netmail stats endpoint logic
echo "3. Testing netmail statistics logic:\n";
try {
    $db = Database::getInstance()->getPdo();
    
    // This simulates what the stats endpoint should do
    // For testing, we'll use user ID 1 if it exists
    $userStmt = $db->query("SELECT * FROM users LIMIT 1");
    $user = $userStmt->fetch();
    
    if ($user) {
        $userId = $user['id'];
        echo "   - Testing with user ID: $userId\n";
        
        // Total messages
        $totalStmt = $db->prepare("SELECT COUNT(*) as count FROM netmail WHERE user_id = ?");
        $totalStmt->execute([$userId]);
        $total = $totalStmt->fetch()['count'];
        echo "   - Total messages for user: $total\n";
        
        // Unread messages
        $unreadStmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM netmail n
            LEFT JOIN message_read_status mrs ON (mrs.message_id = n.id AND mrs.message_type = 'netmail' AND mrs.user_id = ?)
            WHERE n.user_id = ? AND mrs.read_at IS NULL
        ");
        $unreadStmt->execute([$userId, $userId]);
        $unread = $unreadStmt->fetch()['count'];
        echo "   - Unread messages for user: $unread\n";
        
        echo "   ✓ Netmail stats logic working\n\n";
    } else {
        echo "   ⚠ No users found in database\n\n";
    }
    
} catch (Exception $e) {
    echo "   ✗ Netmail stats error: " . $e->getMessage() . "\n\n";
}

// Test 4: Check current database schema
echo "4. Checking database schema:\n";
try {
    $db = Database::getInstance()->getPdo();
    
    $tables = ['netmail', 'echomail', 'echoareas', 'users', 'message_read_status'];
    foreach ($tables as $table) {
        $stmt = $db->query("SELECT COUNT(*) as count FROM $table");
        $count = $stmt->fetch()['count'];
        echo "   - Table '$table': $count records\n";
    }
    echo "   ✓ Database schema accessible\n\n";
    
} catch (Exception $e) {
    echo "   ✗ Schema check error: " . $e->getMessage() . "\n\n";
}

echo "Test completed.\n";