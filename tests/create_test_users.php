<?php
// Script to create 2000 test users for pagination testing
// Run with: php tests/create_test_users.php

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database.php';

use BinktermPHP\Database;

echo "=== Creating 2000 Test Users ===\n";

try {
    $db = Database::getInstance()->getPdo();
    
    // Check if test users already exist
    $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE username LIKE 'test%'");
    $checkStmt->execute();
    $existingCount = $checkStmt->fetch()['count'];
    
    if ($existingCount > 0) {
        echo "Warning: Found $existingCount existing test users.\n";
        echo "Continue anyway? (y/n): ";
        $handle = fopen("php://stdin", "r");
        $response = trim(fgets($handle));
        fclose($handle);
        
        if (strtolower($response) !== 'y') {
            echo "Aborted.\n";
            exit(1);
        }
    }
    
    // Prepare the insert statement
    $insertStmt = $db->prepare("
        INSERT INTO users (username, password_hash, real_name, email, is_active, is_admin, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $batchSize = 100; // Insert in batches for better performance
    $totalUsers = 2000;
    $created = 0;
    
    // Sample data arrays for variety
    $firstNames = ['John', 'Jane', 'Bob', 'Alice', 'Charlie', 'Diana', 'Eve', 'Frank', 'Grace', 'Henry'];
    $lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez'];
    $domains = ['example.com', 'test.org', 'demo.net', 'sample.info'];
    
    echo "Creating users in batches of $batchSize...\n";
    
    $db->beginTransaction();
    
    for ($i = 1; $i <= $totalUsers; $i++) {
        $username = "test" . str_pad($i, 4, '0', STR_PAD_LEFT); // test0001, test0002, etc.
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        $firstName = $firstNames[array_rand($firstNames)];
        $lastName = $lastNames[array_rand($lastNames)];
        $realName = "$firstName $lastName";
        $email = strtolower($firstName . '.' . $lastName . $i . '@' . $domains[array_rand($domains)]);
        $isActive = (rand(0, 100) > 10) ? 1 : 0; // 90% active, 10% inactive
        $isAdmin = 0; // No admin test users
        
        // Random creation dates over the past 2 years
        $randomDays = rand(0, 730);
        $createdAt = date('Y-m-d H:i:s', strtotime("-$randomDays days"));
        
        $insertStmt->execute([
            $username,
            $passwordHash,
            $realName,
            $email,
            $isActive,
            $isAdmin,
            $createdAt
        ]);
        
        $created++;
        
        // Show progress
        if ($i % $batchSize === 0) {
            echo "Created $i users...\n";
        }
        
        // Commit batch
        if ($i % $batchSize === 0) {
            $db->commit();
            $db->beginTransaction();
        }
    }
    
    // Commit final batch
    $db->commit();
    
    echo "\n✅ Successfully created $created test users!\n";
    echo "Usernames range from test0001 to test" . str_pad($totalUsers, 4, '0', STR_PAD_LEFT) . "\n";
    echo "Password for all test users: testpass123\n";
    echo "\nUser distribution:\n";
    
    // Show some statistics
    $statsStmt = $db->prepare("
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN is_active = true THEN 1 END) as active,
            COUNT(CASE WHEN is_active = false THEN 1 END) as inactive
        FROM users 
        WHERE username LIKE 'test%'
    ");
    $statsStmt->execute();
    $stats = $statsStmt->fetch();
    
    echo "- Total test users: {$stats['total']}\n";
    echo "- Active: {$stats['active']}\n";
    echo "- Inactive: {$stats['inactive']}\n";
    
    echo "\nYou can now test the pagination functionality in the admin interface!\n";
    echo "Use tests/delete_test_users.php to clean up when done.\n\n";
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}