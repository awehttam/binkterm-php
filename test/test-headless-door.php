#!/usr/bin/env php
<?php
/**
 * Test DOSBox Door Session Manager - Headless Mode
 *
 * This script tests launching a door game session in headless (production) mode.
 * DOSBox will run minimized/hidden without a visible window.
 *
 * Usage: php test-headless-door.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\DoorSessionManager;
use BinktermPHP\Database;

// Initialize database (needed for drop file generation)
try {
    $db = Database::getInstance()->getPdo();
} catch (Exception $e) {
    echo "Database connection failed: " . $e->getMessage() . "\n";
    echo "This is expected if you haven't set up the database yet.\n";
    echo "The test will continue with mock data.\n\n";
}

// Test user data
$userData = [
    'real_name' => 'Test User',
    'location' => 'Test City, ST',
    'security_level' => 100,
    'ansi_enabled' => true,
];

echo "=== DOSBox Door Session Manager - Headless Mode Test ===\n";
echo "This test will launch LORD in headless mode (no visible DOSBox window).\n";
echo "Connect your browser to the WebSocket port shown below to play.\n\n";

try {
    // Create session manager in HEADLESS mode
    $manager = new DoorSessionManager(null, true);

    echo "Starting door session in HEADLESS mode...\n";
    $session = $manager->startSession(1, 'lord', $userData);

    echo "\n✓ Session started successfully!\n\n";
    echo "Session Information:\n";
    echo "  Session ID: {$session['session_id']}\n";
    echo "  Node: {$session['node']}\n";
    echo "  TCP Port (DOSBox): {$session['tcp_port']}\n";
    echo "  WebSocket Port (Browser): {$session['ws_port']}\n";
    echo "  Bridge PID: {$session['bridge_pid']}\n";
    echo "  DOSBox PID: {$session['dosbox_pid']}\n";
    echo "  Started: " . date('Y-m-d H:i:s', $session['started_at']) . "\n";
    echo "\n";

    echo "DOSBox is running in HEADLESS mode (minimized/hidden).\n";
    echo "Check Task Manager to verify dosbox-x.exe is running.\n\n";

    echo "To connect and play:\n";
    echo "  1. Open dosbox-bridge/test-client.html in your browser\n";
    echo "  2. Set WebSocket Port to: {$session['ws_port']}\n";
    echo "  3. Click 'Connect'\n";
    echo "  4. Play LORD!\n\n";

    echo "Press Enter to end the session and cleanup...";
    fgets(STDIN);

    echo "\nEnding session...\n";
    $manager->endSession($session['session_id']);

    echo "✓ Session ended and cleaned up\n";

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\nTest complete!\n";
