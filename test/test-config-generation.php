#!/usr/bin/env php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

echo "Testing config generation...\n\n";

// Simulate what startDosBox does
$basePath = __DIR__ . '/..';
$doorName = 'lord';
$node = 1;

// Load door manifest
$manifestScanner = new BinktermPHP\DosBoxDoorManifest($basePath);
$doorManifest = $manifestScanner->getDoorManifest($doorName);

if (!$doorManifest) {
    die("ERROR: Failed to load manifest\n");
}

echo "Manifest loaded\n";
echo "Directory: {$doorManifest['directory']}\n";
echo "Executable: {$doorManifest['executable']}\n";
echo "Launch Command: " . ($doorManifest['launch_command'] ?? 'NOT SET') . "\n\n";

// Extract the DOS path
$fullDir = $doorManifest['directory'];
$dosPath = str_replace('dosbox-bridge/dos', '', $fullDir);
$dosPath = str_replace('/', '\\', $dosPath);

echo "DOS Path: $dosPath\n\n";

// Get launch command
if (!empty($doorManifest['launch_command'])) {
    $launchCmd = $doorManifest['launch_command'];
} else {
    $executable = $doorManifest['executable'];
    if (strtoupper(pathinfo($executable, PATHINFO_EXTENSION)) === 'BAT') {
        $launchCmd = "call " . strtolower($executable) . " {node}";
    } else {
        $launchCmd = strtolower($executable) . " {node}";
    }
}

// Replace macros
$dropFileName = "DOOR" . $node . ".SYS";
$launchCmd = str_replace('{node}', $node, $launchCmd);
$launchCmd = str_replace('{dropfile}', $dropFileName, $launchCmd);

echo "Launch Command: $launchCmd\n\n";

// Build door commands
$doorCommands = "cd $dosPath\n";
$doorCommands .= $launchCmd;

echo "Door Commands:\n";
echo $doorCommands . "\n\n";

// Load base config
$configPath = $basePath . '/dosbox-bridge/dosbox-bridge-production.conf';
$baseConfig = file_get_contents($configPath);

// Replace placeholder
$sessionConfig = str_replace(
    '# Door-specific commands will be appended here',
    $doorCommands,
    $baseConfig
);

// Show the autoexec section
preg_match('/\[autoexec\](.*?)$/s', $sessionConfig, $matches);
if (isset($matches[1])) {
    echo "Generated [autoexec] section:\n";
    echo "[autoexec]" . $matches[1];
}
