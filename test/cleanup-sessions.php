#!/usr/bin/env php
<?php
/**
 * Clean up all active door sessions
 */

require_once __DIR__ . '/../vendor/autoload.php';

$db = BinktermPHP\Database::getInstance()->getPdo();

echo "Cleaning up active door sessions...\n\n";

$stmt = $db->query('SELECT * FROM door_sessions WHERE ended_at IS NULL');
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($sessions)) {
    echo "No active sessions to clean up.\n";
    exit(0);
}

foreach ($sessions as $session) {
    echo "Ending session: {$session['session_id']}\n";
    echo "  Door: {$session['door_id']}\n";
    echo "  Bridge PID: {$session['bridge_pid']}\n";
    echo "  DOSBox PID: {$session['dosbox_pid']}\n";

    // Kill processes if still running
    if ($session['bridge_pid']) {
        exec("taskkill /F /PID {$session['bridge_pid']} 2>&1", $output, $result);
        echo "  Killed bridge process\n";
    }

    if ($session['dosbox_pid']) {
        exec("taskkill /F /PID {$session['dosbox_pid']} 2>&1", $output, $result);
        echo "  Killed DOSBox process\n";
    }

    // Update database
    $updateStmt = $db->prepare("
        UPDATE door_sessions
        SET ended_at = NOW(), exit_status = 'cleanup'
        WHERE session_id = ?
    ");
    $updateStmt->execute([$session['session_id']]);

    echo "  Session ended in database\n\n";
}

echo "Cleanup complete!\n";
