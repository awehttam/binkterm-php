#!/usr/bin/env php
<?php
/**
 * Check door sessions
 */

require_once __DIR__ . '/../vendor/autoload.php';

$db = BinktermPHP\Database::getInstance()->getPdo();

echo "Latest door session:\n";
echo "===================\n\n";

$stmt = $db->query('SELECT * FROM door_sessions ORDER BY started_at DESC LIMIT 1');
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    foreach ($row as $key => $value) {
        echo str_pad($key . ':', 20) . $value . "\n";
    }
} else {
    echo "No sessions found.\n";
}

echo "\n\nAll active sessions:\n";
echo "===================\n\n";

$stmt = $db->query('SELECT session_id, user_id, door_id, node_number, tcp_port, ws_port, dosbox_pid, bridge_pid, started_at FROM door_sessions WHERE ended_at IS NULL');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($rows) {
    foreach ($rows as $row) {
        echo "Session: {$row['session_id']}\n";
        echo "  Door: {$row['door_id']}\n";
        echo "  Node: {$row['node_number']}\n";
        echo "  TCP Port: {$row['tcp_port']}\n";
        echo "  WS Port: {$row['ws_port']}\n";
        echo "  DOSBox PID: {$row['dosbox_pid']}\n";
        echo "  Bridge PID: {$row['bridge_pid']}\n";
        echo "  Started: {$row['started_at']}\n\n";
    }
} else {
    echo "No active sessions.\n";
}
