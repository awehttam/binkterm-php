#!/usr/bin/env php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

echo "Testing door launch...\n\n";

// Simulate user data
$userId = 1;
$doorName = 'lord';
$userData = [
    'id' => $userId,
    'real_name' => 'Test User',
    'location' => 'BinktermPHP BBS',
    'security_level' => 30,
    'total_logins' => 1,
    'last_login' => date('Y-m-d H:i:s'),
    'ansi_enabled' => true,
];

try {
    $sessionManager = new BinktermPHP\DoorSessionManager(null, true);
    echo "DoorSessionManager created\n";

    $session = $sessionManager->startSession($userId, $doorName, $userData);
    echo "Session started successfully!\n\n";

    echo "Session ID: {$session['session_id']}\n";
    echo "Node: {$session['node']}\n";
    echo "WS Port: {$session['ws_port']}\n";
    echo "TCP Port: {$session['tcp_port']}\n";
    echo "Bridge PID: {$session['bridge_pid']}\n";
    echo "DOSBox PID: {$session['dosbox_pid']}\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
