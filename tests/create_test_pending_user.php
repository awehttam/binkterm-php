<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Binktest\Database;

$db = Database::getInstance()->getPdo();

echo "Creating a test pending user for rejection testing:\n";

$username = 'testpending_' . time();
$insertStmt = $db->prepare("
    INSERT INTO pending_users (username, password_hash, email, real_name, reason, ip_address, user_agent, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
");

$insertStmt->execute([
    $username,
    password_hash('testpassword', PASSWORD_DEFAULT),
    'testpending@example.com',
    'Test Pending User',
    'Testing the rejection system in admin interface',
    '127.0.0.1',
    'Test User Agent'
]);

$newUserId = $db->lastInsertId();

echo "✓ Created pending user:\n";
echo "  ID: $newUserId\n";
echo "  Username: $username\n";
echo "  Status: pending\n";
echo "\nYou can now test rejecting user ID $newUserId in the admin interface.\n";