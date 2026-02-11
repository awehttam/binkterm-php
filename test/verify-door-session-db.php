#!/usr/bin/env php
<?php
/**
 * Verify door sessions are being tracked in database
 */

require __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;

$db = Database::getInstance()->getPdo();

echo "=== Active Door Sessions ===\n\n";

$stmt = $db->query("
    SELECT
        ds.session_id,
        ds.user_id,
        u.username,
        ds.door_id,
        dd.name as door_name,
        ds.node_number,
        ds.tcp_port,
        ds.ws_port,
        ds.started_at,
        ds.expires_at
    FROM door_sessions ds
    LEFT JOIN users u ON ds.user_id = u.id
    LEFT JOIN dosbox_doors dd ON ds.door_id = dd.door_id
    WHERE ds.ended_at IS NULL
    ORDER BY ds.started_at DESC
");

$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($sessions)) {
    echo "No active sessions\n\n";
} else {
    echo "Found " . count($sessions) . " active session(s):\n\n";
    foreach ($sessions as $session) {
        echo "Session ID: {$session['session_id']}\n";
        echo "  User: {$session['username']} (ID: {$session['user_id']})\n";
        echo "  Door: {$session['door_name']} ({$session['door_id']})\n";
        echo "  Node: {$session['node_number']}\n";
        echo "  Ports: TCP={$session['tcp_port']}, WS={$session['ws_port']}\n";
        echo "  Started: {$session['started_at']}\n";
        echo "  Expires: {$session['expires_at']}\n";
        echo "\n";
    }
}

echo "=== Session Event Logs ===\n\n";

$stmt = $db->query("
    SELECT
        dsl.session_id,
        dsl.event_type,
        dsl.event_data,
        dsl.created_at
    FROM door_session_logs dsl
    ORDER BY dsl.created_at DESC
    LIMIT 10
");

$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($logs)) {
    echo "No session logs\n";
} else {
    echo "Recent events (last 10):\n\n";
    foreach ($logs as $log) {
        $data = json_decode($log['event_data'], true);
        $dataStr = json_encode($data, JSON_PRETTY_PRINT);
        echo "[{$log['created_at']}] {$log['event_type']}\n";
        echo "  Session: {$log['session_id']}\n";
        echo "  Data: {$dataStr}\n\n";
    }
}

echo "=== Session History (last 5 ended) ===\n\n";

$stmt = $db->query("
    SELECT
        ds.session_id,
        u.username,
        dd.name as door_name,
        ds.started_at,
        ds.ended_at,
        ds.exit_status,
        EXTRACT(EPOCH FROM (ds.ended_at - ds.started_at)) as duration_seconds
    FROM door_sessions ds
    LEFT JOIN users u ON ds.user_id = u.id
    LEFT JOIN dosbox_doors dd ON ds.door_id = dd.door_id
    WHERE ds.ended_at IS NOT NULL
    ORDER BY ds.ended_at DESC
    LIMIT 5
");

$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($history)) {
    echo "No session history\n";
} else {
    foreach ($history as $session) {
        $duration = round($session['duration_seconds']);
        echo "Session: {$session['session_id']}\n";
        echo "  User: {$session['username']}\n";
        echo "  Door: {$session['door_name']}\n";
        echo "  Duration: {$duration} seconds\n";
        echo "  Exit: {$session['exit_status']}\n";
        echo "  Ended: {$session['ended_at']}\n\n";
    }
}
