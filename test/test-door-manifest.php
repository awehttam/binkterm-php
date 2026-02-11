#!/usr/bin/env php
<?php
/**
 * Test DOSBox Door Manifest Scanner
 */

require __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\DosBoxDoorManifest;

$scanner = new DosBoxDoorManifest();

echo "=== DOS Door Manifest Scanner ===\n\n";

echo "Scanning for installed doors...\n";
$doors = $scanner->scanInstalledDoors();

echo "Found " . count($doors) . " door(s):\n\n";

foreach ($doors as $doorId => $manifest) {
    echo "Door ID: $doorId\n";
    echo "  Name: {$manifest['name']}\n";
    echo "  Short Name: {$manifest['short_name']}\n";
    echo "  Author: {$manifest['author']}\n";
    echo "  Version: {$manifest['game_version']}\n";
    echo "  Description: {$manifest['description']}\n";
    echo "  Genre: " . implode(', ', $manifest['genre']) . "\n";
    echo "  Players: {$manifest['players']}\n";
    echo "\n  Technical:\n";
    echo "    Executable: {$manifest['executable']}\n";
    echo "    Directory: {$manifest['directory']}\n";
    echo "    Drop File: {$manifest['dropfile_format']}\n";
    echo "    FOSSIL Required: " . ($manifest['fossil_required'] ? 'Yes' : 'No') . "\n";
    echo "    Max Nodes: {$manifest['max_nodes']}\n";
    echo "    Time Per Day: {$manifest['time_per_day']} minutes\n";
    echo "\n  Configuration:\n";
    echo "    Enabled: " . ($manifest['config']['enabled'] ? 'Yes' : 'No') . "\n";
    echo "    Credit Cost: {$manifest['config']['credit_cost']}\n";
    echo "    Max Time: {$manifest['config']['max_time_minutes']} minutes\n";
    echo "    CPU Cycles: {$manifest['config']['cpu_cycles']}\n";
    echo "\n";
}

echo "\n=== Test Individual Door Lookup ===\n";
$lord = $scanner->getDoorManifest('lord');
if ($lord) {
    echo "✓ LORD manifest loaded successfully\n";
    echo "  Name: {$lord['name']}\n";
} else {
    echo "✗ LORD manifest not found\n";
}

echo "\n=== Test Door Exists Check ===\n";
echo "LORD installed: " . ($scanner->isDoorInstalled('lord') ? 'Yes' : 'No') . "\n";
echo "FAKE installed: " . ($scanner->isDoorInstalled('fake') ? 'Yes' : 'No') . "\n";

echo "\n✓ Manifest scanner working!\n";
