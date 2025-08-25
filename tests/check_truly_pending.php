<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\MessageHandler;

$handler = new MessageHandler();
$pending = $handler->getPendingUsers();

echo "Truly pending users (status = 'pending'): " . count($pending) . "\n";

foreach ($pending as $u) {
    echo "  ID {$u['id']}: {$u['username']} - {$u['real_name']}\n";
}

echo "\nIf this shows 0 users, then there are no pending registrations to approve/reject.\n";
echo "The 400 error happens when trying to reject users who are already processed.\n";