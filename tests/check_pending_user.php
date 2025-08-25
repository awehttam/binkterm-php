<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Binktest\Database;

$db = Database::getInstance()->getPdo();

echo "Checking pending user with ID 2:\n";
$stmt = $db->query('SELECT * FROM pending_users WHERE id = 2');
$user = $stmt->fetch();

if ($user) {
    echo "User found:\n";
    echo "  ID: {$user['id']}\n";
    echo "  Username: {$user['username']}\n";
    echo "  Status: {$user['status']}\n";
    echo "  Real Name: {$user['real_name']}\n";
    echo "  Requested: {$user['requested_at']}\n";
} else {
    echo "User with ID 2 not found.\n";
}

echo "\nAll pending users:\n";
$allStmt = $db->query('SELECT id, username, status FROM pending_users ORDER BY id');
$all = $allStmt->fetchAll();

foreach ($all as $u) {
    echo "  ID {$u['id']}: {$u['username']} ({$u['status']})\n";
}

echo "Total pending users: " . count($all) . "\n";