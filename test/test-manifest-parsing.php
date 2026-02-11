#!/usr/bin/env php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

echo "Testing door manifest parsing...\n\n";

$scanner = new BinktermPHP\DosBoxDoorManifest(__DIR__ . '/..');
$manifest = $scanner->getDoorManifest('lord');

if ($manifest) {
    echo "Manifest loaded successfully!\n\n";
    echo "Door ID: {$manifest['door_id']}\n";
    echo "Name: {$manifest['name']}\n";
    echo "Executable: {$manifest['executable']}\n";
    echo "Launch Command: " . ($manifest['launch_command'] ?? 'NOT SET') . "\n";
    echo "Directory: {$manifest['directory']}\n\n";

    // Test command generation
    $node = 1;
    $launchCmd = $manifest['launch_command'] ?? "call {$manifest['executable']} {node}";
    $launchCmd = str_replace('{node}', $node, $launchCmd);
    $launchCmd = str_replace('{dropfile}', "DOOR{$node}.SYS", $launchCmd);

    echo "Generated command for node 1:\n";
    echo "  $launchCmd\n";
} else {
    echo "ERROR: Failed to load manifest!\n";
}
