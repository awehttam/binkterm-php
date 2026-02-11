#!/usr/bin/env php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\DoorSessionManager;

$sessionManager = new DoorSessionManager(null, true);
$sessions = $sessionManager->getActiveSessions();

echo "Verifying session processes:\n";
echo "============================\n\n";

foreach ($sessions as $session) {
    echo "Session: {$session['session_id']}\n";
    echo "  User: {$session['user_id']}\n";
    echo "  Bridge PID: {$session['bridge_pid']} - ";
    echo $sessionManager->isProcessRunning($session['bridge_pid']) ? "RUNNING\n" : "DEAD\n";
    echo "  DOSBox PID: {$session['dosbox_pid']} - ";
    echo $sessionManager->isProcessRunning($session['dosbox_pid']) ? "RUNNING\n" : "DEAD\n";
    echo "\n";
}
