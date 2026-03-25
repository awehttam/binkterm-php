<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Auth;
use BinktermPHP\Database;

Database::getInstance();

if (!headers_sent()) {
    session_start();
}

$auth = new Auth();
$user = $auth->requireAuth();

session_write_close();

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');

while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);
ignore_user_abort(true);

$count    = max(3, min(120, (int)($_GET['count'] ?? 15)));
$interval = max(100, min(5000, (int)($_GET['interval_ms'] ?? 1000)));
$userId   = (int)($user['user_id'] ?? $user['id'] ?? 0);

// Prime proxy/filter buffers so Apache has no excuse to hold the first event.
echo ':' . str_repeat(' ', 2048) . "\n\n";
echo "retry: 3000\n\n";
flush();

for ($i = 0; $i < $count; $i++) {
    if (connection_aborted()) {
        break;
    }

    $tsMs = (int)round(microtime(true) * 1000);

    echo "id: " . ($i + 1) . "\n";
    echo "event: tick\n";
    echo 'data: ' . json_encode([
        'seq'         => $i,
        'total'       => $count,
        'interval_ms' => $interval,
        'server_ts'   => $tsMs,
        'user_id'     => $userId,
    ]) . "\n\n";
    flush();

    if ($i < $count - 1) {
        usleep($interval * 1000);
    }
}

if (!connection_aborted()) {
    echo "event: done\n";
    echo "data: {}\n\n";
    flush();
}
