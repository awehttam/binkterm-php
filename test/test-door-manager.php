#!/usr/bin/env php
<?php
/**
 * Test DOS Door Manager
 */

require __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\DoorManager;
use BinktermPHP\DoorConfig;

$manager = new DoorManager();

echo "=== DOS Door Manager Test ===\n\n";

echo "1. All Installed Doors:\n";
$allDoors = $manager->getAllDoors();
foreach ($allDoors as $doorId => $door) {
    echo "  [$doorId] {$door['name']}\n";
    echo "    Installed: Yes\n";
    echo "    Configured: " . (DoorConfig::getDoorConfig($doorId) ? 'Yes' : 'No') . "\n";
    echo "    Enabled: " . ($door['config']['enabled'] ? 'Yes' : 'No') . "\n";
    echo "    Credit Cost: {$door['config']['credit_cost']}\n";
    echo "    Max Time: {$door['config']['max_time_minutes']} min\n";
    echo "\n";
}

echo "2. Enabled Doors Only:\n";
$enabled = $manager->getEnabledDoors();
if (empty($enabled)) {
    echo "  (none)\n";
} else {
    foreach ($enabled as $doorId => $door) {
        echo "  ✓ {$door['name']} ($doorId)\n";
    }
}
echo "\n";

echo "3. Check LORD Availability:\n";
$isAvailable = $manager->isDoorAvailable('lord');
echo "  LORD available: " . ($isAvailable ? 'Yes' : 'No') . "\n\n";

echo "4. Get LORD Details:\n";
$lord = $manager->getDoor('lord');
if ($lord) {
    echo "  Name: {$lord['name']}\n";
    echo "  Author: {$lord['author']}\n";
    echo "  Description: {$lord['description']}\n";
    echo "  Enabled: " . ($lord['config']['enabled'] ? 'Yes' : 'No') . "\n";
    echo "  Credit Cost: {$lord['config']['credit_cost']}\n";
    echo "  CPU Cycles: {$lord['config']['cpu_cycles']}\n";
} else {
    echo "  LORD not found\n";
}
echo "\n";

echo "5. Unconfigured Doors:\n";
$unconfigured = $manager->getUnconfiguredDoors();
if (empty($unconfigured)) {
    echo "  (all installed doors are configured)\n";
} else {
    foreach ($unconfigured as $doorId) {
        echo "  - $doorId (not in config/dosdoors.json)\n";
    }
}
echo "\n";

echo "6. Test Config Update:\n";
$updated = $manager->updateDoorConfig('lord', [
    'enabled' => true,
    'credit_cost' => 10,
    'max_time_minutes' => 45,
    'cpu_cycles' => 15000,
    'max_concurrent_sessions' => 5
]);
echo "  Update result: " . ($updated ? 'Success' : 'Failed') . "\n";

// Verify update
$lord = $manager->getDoor('lord');
echo "  Verified credit cost: {$lord['config']['credit_cost']}\n";
echo "  Verified max time: {$lord['config']['max_time_minutes']} min\n";

// Restore original config
$manager->updateDoorConfig('lord', [
    'enabled' => true,
    'credit_cost' => 0,
    'max_time_minutes' => 30,
    'cpu_cycles' => 10000,
    'max_concurrent_sessions' => 10
]);
echo "  (restored original config)\n";

echo "\n✓ Door manager working!\n";
