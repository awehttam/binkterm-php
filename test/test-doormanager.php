#!/usr/bin/env php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

echo "Testing DoorManager...\n";

try {
    $dm = new BinktermPHP\DoorManager();
    echo "DoorManager created\n";

    $door = $dm->getDoor('lord');

    if ($door) {
        echo "Door found!\n";
        echo "Name: " . ($door['name'] ?? 'NO NAME') . "\n";
        echo "Enabled: " . (empty($door['config']['enabled']) ? 'NO' : 'YES') . "\n";
    } else {
        echo "Door NOT found\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
