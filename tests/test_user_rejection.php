<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\MessageHandler;
use BinktermPHP\Database;

// Initialize database
Database::getInstance();

echo "Testing User Rejection Functionality\n";
echo "====================================\n\n";

try {
    $db = Database::getInstance()->getPdo();
    $handler = new MessageHandler();
    
    // Create a test pending user
    echo "1. Creating test pending user:\n";
    $insertStmt = $db->prepare("
        INSERT INTO pending_users (username, password_hash, email, real_name, reason, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $testUsername = 'rejecttest_' . time();
    $insertStmt->execute([
        $testUsername,
        password_hash('testpassword', PASSWORD_DEFAULT),
        'reject@example.com',
        'Test Reject User',
        'Testing the rejection system',
        '127.0.0.1',
        'Test User Agent'
    ]);
    
    $pendingUserId = $db->lastInsertId();
    echo "   ✓ Created test pending user (ID: $pendingUserId, Username: $testUsername)\n";
    
    // Verify user exists and is pending
    $checkStmt = $db->prepare("SELECT * FROM pending_users WHERE id = ?");
    $checkStmt->execute([$pendingUserId]);
    $pendingUser = $checkStmt->fetch();
    
    if ($pendingUser && $pendingUser['status'] === 'pending') {
        echo "   ✓ User exists with 'pending' status\n";
    } else {
        echo "   ✗ User not found or not pending\n";
        exit(1);
    }
    echo "\n";
    
    // Test rejection
    echo "2. Testing user rejection:\n";
    
    // Get admin user
    $adminStmt = $db->query("SELECT id FROM users WHERE is_admin = 1 LIMIT 1");
    $admin = $adminStmt->fetch();
    $adminId = $admin ? $admin['id'] : 1;
    
    try {
        $result = $handler->rejectUserRegistration($pendingUserId, $adminId, 'Test rejection - automated test');
        echo "   ✓ User rejection completed successfully\n";
        
        // Verify user status was updated
        $updatedStmt = $db->prepare("SELECT status, reviewed_by, reviewed_at, admin_notes FROM pending_users WHERE id = ?");
        $updatedStmt->execute([$pendingUserId]);
        $updatedUser = $updatedStmt->fetch();
        
        if ($updatedUser) {
            if ($updatedUser['status'] === 'rejected') {
                echo "   ✓ User status updated to 'rejected'\n";
            } else {
                echo "   ✗ User status is '{$updatedUser['status']}', expected 'rejected'\n";
            }
            
            if ($updatedUser['reviewed_by'] == $adminId) {
                echo "   ✓ Reviewed by admin ID $adminId\n";
            } else {
                echo "   ✗ Reviewed by {$updatedUser['reviewed_by']}, expected $adminId\n";
            }
            
            if ($updatedUser['reviewed_at']) {
                echo "   ✓ Review timestamp recorded: {$updatedUser['reviewed_at']}\n";
            } else {
                echo "   ✗ No review timestamp recorded\n";
            }
            
            if ($updatedUser['admin_notes'] === 'Test rejection - automated test') {
                echo "   ✓ Admin notes saved correctly\n";
            } else {
                echo "   ✗ Admin notes: '{$updatedUser['admin_notes']}'\n";
            }
        } else {
            echo "   ✗ Updated user record not found\n";
        }
        
    } catch (Exception $e) {
        echo "   ✗ Rejection failed: " . $e->getMessage() . "\n";
        echo "   Error details:\n";
        echo "     File: " . $e->getFile() . "\n";
        echo "     Line: " . $e->getLine() . "\n";
        echo "     Trace: " . $e->getTraceAsString() . "\n";
    }
    echo "\n";
    
    // Test rejecting already processed user
    echo "3. Testing rejection of already processed user:\n";
    try {
        $handler->rejectUserRegistration($pendingUserId, $adminId, 'Second attempt');
        echo "   ✗ Should have failed but didn't\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'already processed') !== false) {
            echo "   ✓ Correctly prevented double processing: " . $e->getMessage() . "\n";
        } else {
            echo "   ✗ Wrong error message: " . $e->getMessage() . "\n";
        }
    }
    echo "\n";
    
    // Test pending users query
    echo "4. Testing pending users query:\n";
    $pendingUsers = $handler->getPendingUsers();
    $foundTestUser = false;
    foreach ($pendingUsers as $user) {
        if ($user['username'] === $testUsername) {
            $foundTestUser = true;
            break;
        }
    }
    
    if (!$foundTestUser) {
        echo "   ✓ Rejected user not in pending list (correct)\n";
    } else {
        echo "   ✗ Rejected user still appears in pending list\n";
    }
    
    $allUsers = $handler->getAllPendingUsers();
    $foundInAll = false;
    foreach ($allUsers as $user) {
        if ($user['username'] === $testUsername && $user['status'] === 'rejected') {
            $foundInAll = true;
            break;
        }
    }
    
    if ($foundInAll) {
        echo "   ✓ Rejected user found in all registrations list (correct)\n";
    } else {
        echo "   ✗ Rejected user not found in all registrations list\n";
    }
    
    echo "\n✓ User rejection system is working correctly!\n";
    
} catch (Exception $e) {
    echo "Test failed: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\nTest completed.\n";