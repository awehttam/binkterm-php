#!/usr/bin/env php
<?php

chdir(__DIR__ . '/..');

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;

function showUsage(): void
{
    echo "Chat Cleanup\n\n";
    echo "Usage: php scripts/chat_cleanup.php [options]\n\n";
    echo "Options:\n";
    echo "  --limit=N         Keep most recent N messages per room/DM (default: 500)\n";
    echo "  --max-age-days=N  Delete messages older than N days\n";
    echo "  --help            Show this help message\n\n";
}

$options = getopt('', ['limit:', 'max-age-days:', 'help']);
if (isset($options['help'])) {
    showUsage();
    exit(0);
}

$limit = isset($options['limit']) ? (int) $options['limit'] : 500;
$maxAgeDays = isset($options['max-age-days']) ? (int) $options['max-age-days'] : null;

if ($limit < 0) {
    $limit = 0;
}

$db = Database::getInstance()->getPdo();

if ($maxAgeDays !== null && $maxAgeDays > 0) {
    $stmt = $db->prepare("DELETE FROM chat_messages WHERE created_at < NOW() - INTERVAL '1 day' * ?");
    $stmt->execute([$maxAgeDays]);
    echo "Deleted " . $stmt->rowCount() . " messages older than {$maxAgeDays} days\n";
}

if ($limit > 0) {
    $stmt = $db->prepare("
        WITH ranked AS (
            SELECT id,
                   row_number() OVER (PARTITION BY room_id ORDER BY id DESC) AS rn
            FROM chat_messages
            WHERE room_id IS NOT NULL
        )
        DELETE FROM chat_messages
        WHERE id IN (SELECT id FROM ranked WHERE rn > ?)
    ");
    $stmt->execute([$limit]);
    echo "Trimmed " . $stmt->rowCount() . " room messages beyond {$limit}\n";

    $stmt = $db->prepare("
        WITH ranked AS (
            SELECT id,
                   row_number() OVER (
                       PARTITION BY LEAST(from_user_id, to_user_id), GREATEST(from_user_id, to_user_id)
                       ORDER BY id DESC
                   ) AS rn
            FROM chat_messages
            WHERE room_id IS NULL
        )
        DELETE FROM chat_messages
        WHERE id IN (SELECT id FROM ranked WHERE rn > ?)
    ");
    $stmt->execute([$limit]);
    echo "Trimmed " . $stmt->rowCount() . " direct messages beyond {$limit}\n";
}
