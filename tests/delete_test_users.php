<?php
// Script to delete all test users (usernames starting with "test")
// Run with: php tests/delete_test_users.php

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database.php';

use BinktermPHP\Database;

echo "=== Deleting Test Users ===\n";

try {
    $db = Database::getInstance()->getPdo();
    
    // First, check how many test users exist
    $countStmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE username LIKE 'test%'");
    $countStmt->execute();
    $testUserCount = $countStmt->fetch()['count'];
    
    if ($testUserCount === 0) {
        echo "No test users found (usernames starting with 'test').\n";
        exit(0);
    }
    
    echo "Found $testUserCount test users to delete.\n";
    
    // Show some examples of what will be deleted
    $exampleStmt = $db->prepare("
        SELECT username, real_name, email, created_at 
        FROM users 
        WHERE username LIKE 'test%' 
        ORDER BY username 
        LIMIT 5
    ");
    $exampleStmt->execute();
    $examples = $exampleStmt->fetchAll();
    
    echo "\nExamples of users that will be deleted:\n";
    foreach ($examples as $user) {
        echo "- {$user['username']} ({$user['real_name']}) - {$user['email']}\n";
    }
    
    if ($testUserCount > 5) {
        echo "... and " . ($testUserCount - 5) . " more\n";
    }
    
    // Confirmation prompt
    echo "\n⚠️  WARNING: This will permanently delete $testUserCount users!\n";
    echo "Are you sure you want to continue? (type 'DELETE' to confirm): ";
    
    $handle = fopen("php://stdin", "r");
    $response = trim(fgets($handle));
    fclose($handle);
    
    if ($response !== 'DELETE') {
        echo "Aborted. No users were deleted.\n";
        exit(0);
    }
    
    echo "\nDeleting test users...\n";
    
    // Start transaction for safety
    $db->beginTransaction();
    
    // Delete related data first (if any foreign key constraints exist)
    
    // Delete sessions for test users
    $sessionDeleteStmt = $db->prepare("
        DELETE FROM sessions 
        WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'test%')
    ");
    $sessionResult = $sessionDeleteStmt->execute();
    $sessionsDeleted = $sessionDeleteStmt->rowCount();
    echo "Deleted $sessionsDeleted sessions for test users.\n";
    
    // Delete netmail for test users
    $netmailDeleteStmt = $db->prepare("
        DELETE FROM netmail 
        WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'test%')
    ");
    $netmailResult = $netmailDeleteStmt->execute();
    $netmailDeleted = $netmailDeleteStmt->rowCount();
    echo "Deleted $netmailDeleted netmail messages for test users.\n";
    
    // Delete message read status for test users
    $readStatusDeleteStmt = $db->prepare("
        DELETE FROM message_read_status 
        WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'test%')
    ");
    $readStatusResult = $readStatusDeleteStmt->execute();
    $readStatusDeleted = $readStatusDeleteStmt->rowCount();
    echo "Deleted $readStatusDeleted message read status records for test users.\n";
    
    // Delete shared messages by test users
    $sharedDeleteStmt = $db->prepare("
        DELETE FROM shared_messages 
        WHERE shared_by_user_id IN (SELECT id FROM users WHERE username LIKE 'test%')
    ");
    $sharedResult = $sharedDeleteStmt->execute();
    $sharedDeleted = $sharedDeleteStmt->rowCount();
    echo "Deleted $sharedDeleted shared message records for test users.\n";
    
    // Delete saved messages for test users
    $savedDeleteStmt = $db->prepare("
        DELETE FROM saved_messages 
        WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'test%')
    ");
    $savedResult = $savedDeleteStmt->execute();
    $savedDeleted = $savedDeleteStmt->rowCount();
    echo "Deleted $savedDeleted saved message records for test users.\n";
    
    // Finally, delete the test users themselves
    $userDeleteStmt = $db->prepare("DELETE FROM users WHERE username LIKE 'test%'");
    $userResult = $userDeleteStmt->execute();
    $usersDeleted = $userDeleteStmt->rowCount();
    
    // Commit all deletions
    $db->commit();
    
    echo "\n✅ Successfully deleted $usersDeleted test users!\n";
    echo "\nSummary of deleted records:\n";
    echo "- Users: $usersDeleted\n";
    echo "- Sessions: $sessionsDeleted\n";
    echo "- Netmail messages: $netmailDeleted\n";
    echo "- Message read status: $readStatusDeleted\n";
    echo "- Shared messages: $sharedDeleted\n";
    echo "- Saved messages: $savedDeleted\n";
    
    // Verify cleanup
    $verifyStmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE username LIKE 'test%'");
    $verifyStmt->execute();
    $remainingCount = $verifyStmt->fetch()['count'];
    
    if ($remainingCount === 0) {
        echo "\n✅ Cleanup verified: No test users remaining.\n";
    } else {
        echo "\n⚠️  Warning: $remainingCount test users still remain in database.\n";
    }
    
    echo "\nTest user cleanup completed!\n\n";
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}