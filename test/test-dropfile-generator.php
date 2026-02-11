#!/usr/bin/env php
<?php
/**
 * Test Drop File Generator
 *
 * Tests the DoorDropFile class by generating a sample DOOR.SYS file
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\DoorDropFile;

echo "=== Door Drop File Generator Test ===\n\n";

// Sample user data
$userData = [
    'id' => 42,
    'real_name' => 'John Doe',
    'location' => 'Anytown, USA',
    'security_level' => 110,
    'total_logins' => 1234,
    'last_login' => '2025-01-15 14:30:00',
    'ansi_enabled' => true,
    'birthdate' => '1990-01-15',
];

// Sample session data
$sessionData = [
    'com_port' => 'COM1:',
    'node' => 1,
    'baud_rate' => 115200,
    'time_remaining' => 7200, // 2 hours in seconds
];

// Generate session ID
$sessionId = DoorDropFile::generateSessionId($userData['id'], $sessionData['node']);
echo "Session ID: $sessionId\n\n";

// Create drop file generator
$dropFile = new DoorDropFile();

// Generate DOOR.SYS
echo "Generating DOOR.SYS...\n";
$dropFilePath = $dropFile->generateDoorSys($userData, $sessionData, $sessionId);

echo "Drop file created: $dropFilePath\n\n";

// Display the generated file
echo "=== DOOR.SYS Contents ===\n";
echo file_get_contents($dropFilePath);
echo "\n=== End of DOOR.SYS ===\n\n";

// Show session path
$sessionPath = $dropFile->getSessionPath($sessionId);
echo "Session directory: $sessionPath\n";
echo "Files in session directory:\n";
foreach (glob($sessionPath . '/*') as $file) {
    echo "  - " . basename($file) . "\n";
}

// Cleanup test
echo "\nCleaning up session...\n";
if ($dropFile->cleanupSession($sessionId)) {
    echo "✓ Session cleaned up successfully\n";
} else {
    echo "✗ Failed to cleanup session\n";
}

echo "\n=== Test Complete ===\n";
