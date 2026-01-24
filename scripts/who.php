#!/usr/bin/php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Auth;

$minutes = 15;

// Parse arguments
foreach ($argv as $i => $arg) {
    if ($i === 0) continue;
    if (preg_match('/^--minutes=(\d+)$/', $arg, $m)) {
        $minutes = (int) $m[1];
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php who.php [--minutes=N]\n";
        echo "  --minutes=N   Show users active within N minutes (default: 15)\n";
        exit(0);
    }
}

$auth = new Auth();
$users = $auth->getOnlineUsers($minutes);

if (empty($users)) {
    echo "No users online.\n";
    exit(0);
}

echo sprintf("%-20s %-20s %-15s %s\n", "Username", "Location", "IP Address", "Last Activity");
echo str_repeat("-", 75) . "\n";

foreach ($users as $user) {
    $username = $user['username'] ?? 'unknown';
    $location = $user['location'] ?? '-';
    $ipAddress = $user['ip_address'] ?? '-';
    $lastActivity = $user['last_activity'] ?? '-';

    if ($lastActivity && $lastActivity !== '-') {
        $dt = new DateTime($lastActivity);
        $lastActivity = $dt->format('Y-m-d H:i:s');
    }

    echo sprintf("%-20s %-20s %-15s %s\n", $username, $location, $ipAddress, $lastActivity);
}

echo "\n" . count($users) . " user(s) online.\n";
