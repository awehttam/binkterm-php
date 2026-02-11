#!/usr/bin/env php
<?php
/**
 * Clean Up Expired Door Sessions
 *
 * Finds sessions that have exceeded their expiration time and terminates them.
 * Should be run periodically via cron or scheduled task.
 *
 * Usage: php scripts/cleanup_expired_sessions.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;
use BinktermPHP\DoorSessionManager;

echo "=== Door Session Cleanup ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $db = Database::getInstance()->getPdo();
    $sessionManager = new DoorSessionManager(null, true);

    // Find expired sessions that are still marked as active
    $stmt = $db->query("
        SELECT session_id, user_id, door_id, node_number,
               started_at, expires_at, bridge_pid, dosbox_pid
        FROM door_sessions
        WHERE ended_at IS NULL
          AND expires_at < NOW()
        ORDER BY expires_at
    ");

    $expiredSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($expiredSessions)) {
        echo "No expired sessions found.\n";
        exit(0);
    }

    echo "Found " . count($expiredSessions) . " expired session(s):\n\n";

    foreach ($expiredSessions as $session) {
        echo "Session: {$session['session_id']}\n";
        echo "  User ID: {$session['user_id']}\n";
        echo "  Door: {$session['door_id']}\n";
        echo "  Node: {$session['node_number']}\n";
        echo "  Started: {$session['started_at']}\n";
        echo "  Expired: {$session['expires_at']}\n";
        echo "  Bridge PID: {$session['bridge_pid']}\n";
        echo "  DOSBox PID: {$session['dosbox_pid']}\n";

        // Check if processes are still running
        $bridgeRunning = $sessionManager->isProcessRunning($session['bridge_pid']);
        $dosboxRunning = $sessionManager->isProcessRunning($session['dosbox_pid']);

        echo "  Bridge status: " . ($bridgeRunning ? "RUNNING" : "dead") . "\n";
        echo "  DOSBox status: " . ($dosboxRunning ? "RUNNING" : "dead") . "\n";

        // Terminate the session
        echo "  Action: Terminating session...\n";
        $success = $sessionManager->endSession($session['session_id']);

        if ($success) {
            echo "  Result: ✓ Session terminated successfully\n";
        } else {
            echo "  Result: ✗ Failed to terminate session\n";
        }

        echo "\n";
    }

    echo "Cleanup complete.\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
