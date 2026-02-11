#!/usr/bin/env php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;

try {
    $db = Database::getInstance()->getPdo();

    echo "Active door sessions:\n";
    echo "====================\n\n";

    $stmt = $db->query("
        SELECT session_id, user_id, node_number, ended_at, started_at
        FROM door_sessions
        WHERE ended_at IS NULL
        ORDER BY started_at DESC
    ");

    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($sessions)) {
        echo "No active sessions\n";
    } else {
        foreach ($sessions as $session) {
            echo "Session: {$session['session_id']}\n";
            echo "  User ID: {$session['user_id']}\n";
            echo "  Node: {$session['node_number']}\n";
            echo "  Started: {$session['started_at']}\n";
            echo "\n";
        }
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
