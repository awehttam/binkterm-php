#!/usr/bin/env php
<?php
/**
 * Insert LORD as the first DOSBox door game
 */

require __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;

$db = Database::getInstance()->getPdo();

echo "=== Inserting LORD Door Game ===\n\n";

// Check if LORD already exists
$stmt = $db->prepare("SELECT id FROM dosbox_doors WHERE door_id = ?");
$stmt->execute(['lord']);
if ($stmt->fetch()) {
    echo "✓ LORD already exists in database\n";
    exit(0);
}

// Insert LORD
$config = [
    'timeLimit' => 7200,  // 2 hours in seconds
    'cpuCycles' => 10000,
    'maxSessions' => 10,
    'dropFileType' => 'DOOR.SYS',
    'commandLine' => 'start {node}',
    'workingDir' => '/doors/lord'
];

$stmt = $db->prepare("
    INSERT INTO dosbox_doors (door_id, name, description, executable, path, config, enabled)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

$stmt->execute([
    'lord',
    'Legend of the Red Dragon',
    'Legend of the Red Dragon (LORD) is a classic door game where players battle monsters, duel other players, and quest to defeat the Red Dragon. Created by Seth Able Robinson in 1989, LORD became one of the most popular BBS door games of all time.',
    'LORD.EXE',
    'dosbox-bridge/dos/doors/lord',
    json_encode($config),
    true  // enabled
]);

echo "✓ Inserted LORD door game\n";
echo "  Door ID: lord\n";
echo "  Name: Legend of the Red Dragon\n";
echo "  Path: dosbox-bridge/dos/doors/lord\n";
echo "  Enabled: Yes\n\n";

// Verify insertion
$stmt = $db->prepare("SELECT * FROM dosbox_doors WHERE door_id = ?");
$stmt->execute(['lord']);
$door = $stmt->fetch(PDO::FETCH_ASSOC);

echo "=== Verification ===\n";
echo "Database ID: {$door['id']}\n";
echo "Configuration:\n";
$config = json_decode($door['config'], true);
foreach ($config as $key => $value) {
    echo "  $key: $value\n";
}

echo "\n✓ LORD door game ready!\n";
