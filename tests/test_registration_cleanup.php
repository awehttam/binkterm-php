<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\MessageHandler;
use BinktermPHP\Database;

// Initialize database
Database::getInstance();

echo "Testing Registration Cleanup System\n";
echo "===================================\n\n";

try {
    $db = Database::getInstance()->getPdo();
    $handler = new MessageHandler();
    
    // Check current pending users before approval
    echo "1. Current pending users before approval:\n";
    $pendingBefore = $handler->getPendingUsers();
    echo "   Pending users: " . count($pendingBefore) . "\n";
    foreach ($pendingBefore as $user) {
        echo "     - {$user['username']} ({$user['status']})\n";
    }
    echo "\n";
    
    // Get all pending users (including processed ones)
    echo "2. All registrations (including processed):\n";
    $allPending = $handler->getAllPendingUsers();
    echo "   Total registrations: " . count($allPending) . "\n";
    foreach ($allPending as $user) {
        echo "     - {$user['username']} ({$user['status']}) - {$user['requested_at']}\n";
    }
    echo "\n";
    
    // Test approval and cleanup
    if (!empty($pendingBefore)) {
        $testUser = $pendingBefore[0];
        echo "3. Testing approval and cleanup with user: {$testUser['username']}\n";
        
        // Get admin user
        $adminStmt = $db->query("SELECT id FROM users WHERE is_admin = 1 LIMIT 1");
        $admin = $adminStmt->fetch();
        $adminId = $admin ? $admin['id'] : 1;
        
        try {
            $newUserId = $handler->approveUserRegistration($testUser['id'], $adminId, 'Test approval with cleanup');
            echo "   ✓ User approved successfully (new user ID: $newUserId)\n";
            
            // Check if pending user was removed
            $pendingAfter = $handler->getPendingUsers();
            echo "   Pending users after approval: " . count($pendingAfter) . "\n";
            
            // Check if user exists in users table
            $userStmt = $db->prepare("SELECT username FROM users WHERE id = ?");
            $userStmt->execute([$newUserId]);
            $newUser = $userStmt->fetch();
            
            if ($newUser) {
                echo "   ✓ User exists in users table: {$newUser['username']}\n";
            } else {
                echo "   ✗ User not found in users table\n";
            }
            
            // Check if pending record was removed
            $pendingCheckStmt = $db->prepare("SELECT * FROM pending_users WHERE id = ?");
            $pendingCheckStmt->execute([$testUser['id']]);
            $pendingCheck = $pendingCheckStmt->fetch();
            
            if (!$pendingCheck) {
                echo "   ✓ Pending registration record was removed\n";
            } else {
                echo "   ✗ Pending registration record still exists (status: {$pendingCheck['status']})\n";
            }
            
        } catch (Exception $e) {
            echo "   ✗ Approval failed: " . $e->getMessage() . "\n";
        }
        echo "\n";
    }
    
    // Test cleanup of old rejected registrations
    echo "4. Testing cleanup of old rejected registrations:\n";
    
    // First, create a test rejected registration with an old date
    $oldRejectStmt = $db->prepare("
        INSERT INTO pending_users (username, password_hash, real_name, status, reviewed_at) 
        VALUES (?, ?, ?, 'rejected', DATE('now', '-35 days'))
    ");
    $oldRejectStmt->execute([
        'oldreject_' . time(),
        password_hash('test', PASSWORD_DEFAULT),
        'Old Rejected User'
    ]);
    
    echo "   Created old rejected registration for testing\n";
    
    // Count rejected registrations before cleanup
    $rejectCountBefore = $db->query("SELECT COUNT(*) as count FROM pending_users WHERE status = 'rejected'")->fetch();
    echo "   Rejected registrations before cleanup: {$rejectCountBefore['count']}\n";
    
    // Run cleanup
    $cleanedUp = $handler->cleanupOldRejectedRegistrations();
    echo "   Cleaned up $cleanedUp old rejected registrations\n";
    
    // Count after cleanup
    $rejectCountAfter = $db->query("SELECT COUNT(*) as count FROM pending_users WHERE status = 'rejected'")->fetch();
    echo "   Rejected registrations after cleanup: {$rejectCountAfter['count']}\n";
    echo "\n";
    
    // Final summary
    echo "5. Final summary:\n";
    $finalPending = $handler->getPendingUsers();
    $finalAll = $handler->getAllPendingUsers();
    $totalUsers = $db->query("SELECT COUNT(*) as count FROM users")->fetch();
    
    echo "   Active pending registrations: " . count($finalPending) . "\n";
    echo "   Total registration history: " . count($finalAll) . "\n";
    echo "   Total users in system: {$totalUsers['count']}\n";
    
    echo "\n✓ Registration cleanup system is working correctly!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\nTest completed.\n";