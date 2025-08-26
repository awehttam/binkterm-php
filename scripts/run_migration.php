<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;

echo "Running nodelist migration...\n";

try {
    $db = Database::getInstance()->getPdo();
    $sql = file_get_contents(__DIR__ . '/../database/migrations/add_nodelist_support.sql');
    $db->exec($sql);
    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}