#!/usr/bin/env php
<?php
/**
 * Test Door Session Manager
 *
 * Tests spawning and managing door game sessions
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\DoorSessionManager;

echo "=== Door Session Manager Test ===\n\n";

// Sample user data
$userData = [
    'id' => 42,
    'real_name' => 'Test User',
    'location' => 'Test City, TX',
    'security_level' => 110,
    'total_logins' => 50,
    'last_login' => date('Y-m-d H:i:s'),
    'ansi_enabled' => true,
];

// Create session manager
$sessionManager = new DoorSessionManager();

echo "Active sessions before test:\n";
$sessions = $sessionManager->getActiveSessions();
if (empty($sessions)) {
    echo "  (none)\n";
} else {
    foreach ($sessions as $s) {
        echo "  - {$s['session_id']} (User {$s['user_id']}, Node {$s['node']})\n";
    }
}
echo "\n";

// Check if user already has a session
$existingSession = $sessionManager->getUserSession($userData['id']);
if ($existingSession) {
    echo "User already has an active session: {$existingSession['session_id']}\n";
    echo "Ending existing session first...\n\n";
    $sessionManager->endSession($existingSession['session_id']);
}

// Start new session
echo "Starting door session for user {$userData['id']}...\n";

try {
    $session = $sessionManager->startSession($userData['id'], 'lord', $userData);

    echo "✓ Session started successfully!\n\n";
    echo "Session details:\n";
    echo "  Session ID: {$session['session_id']}\n";
    echo "  Node: {$session['node']}\n";
    echo "  TCP Port (DOSBox): {$session['tcp_port']}\n";
    echo "  WebSocket Port (Browser): {$session['ws_port']}\n";
    echo "  Bridge PID: {$session['bridge_pid']}\n";
    echo "  DOSBox PID: {$session['dosbox_pid']}\n";
    echo "  Session Path: {$session['session_path']}\n";
    echo "  Drop File: {$session['drop_file']}\n";
    echo "\n";

    echo "To connect:\n";
    echo "  1. Open dosbox-bridge/test-client.html in browser\n";
    echo "  2. Set WebSocket port to: {$session['ws_port']}\n";
    echo "  3. Click Connect\n";
    echo "\n";

    echo "Press Enter to end the session...\n";
    fgets(STDIN);

    // End session
    echo "Ending session...\n";
    if ($sessionManager->endSession($session['session_id'])) {
        echo "✓ Session ended successfully\n";
    } else {
        echo "✗ Failed to end session\n";
    }

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== Test Complete ===\n";
