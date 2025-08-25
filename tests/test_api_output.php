<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Auth;
use BinktermPHP\MessageHandler;
use BinktermPHP\Database;

// Initialize database
Database::getInstance();

echo "Testing API Output for HOROSCOPE vs Working Areas\n";
echo "================================================\n\n";

// Simulate exactly what the API endpoint does
function simulateAPIEndpoint($echoarea, $page = 1, $filter = 'all') {
    try {
        // Start output buffering to catch any output/errors
        ob_start();
        
        // Simulate the exact API endpoint logic
        $auth = new Auth();
        
        // For testing, we'll simulate a logged-in user (ID 1)
        $db = Database::getInstance()->getPdo();
        $userStmt = $db->query("SELECT * FROM users WHERE id = 1");
        $user = $userStmt->fetch();
        
        if (!$user) {
            ob_end_clean();
            return ['error' => 'No test user found'];
        }
        
        // Simulate what the API does
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        
        // URL decode the echoarea parameter (like the API does)
        $echoarea = urldecode($echoarea);
        
        $handler = new MessageHandler();
        $page = intval($page);
        $result = $handler->getEchomail($echoarea, $page, 25, $userId, $filter);
        
        // Capture any output that might have been generated
        $output = ob_get_contents();
        ob_end_clean();
        
        // If there was unexpected output, include it
        if (!empty($output)) {
            $result['_debug_output'] = $output;
        }
        
        return $result;
        
    } catch (Exception $e) {
        ob_end_clean();
        return [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ];
    }
}

// Test working areas vs HOROSCOPE
$testAreas = ['COOKING', 'WEATHER', 'HOROSCOPE'];

foreach ($testAreas as $area) {
    echo "Testing '$area':\n";
    echo str_repeat('-', 20) . "\n";
    
    $result = simulateAPIEndpoint($area, 1, 'all');
    
    if (isset($result['error'])) {
        echo "   ✗ ERROR: {$result['error']}\n";
        if (isset($result['trace'])) {
            echo "   Trace: " . substr($result['trace'], 0, 200) . "...\n";
        }
        if (isset($result['file'])) {
            echo "   File: {$result['file']}:{$result['line']}\n";
        }
    } else {
        echo "   ✓ SUCCESS\n";
        echo "   Messages returned: " . count($result['messages'] ?? []) . "\n";
        echo "   Total: " . ($result['pagination']['total'] ?? 0) . "\n";
        echo "   Unread count: " . ($result['unreadCount'] ?? 0) . "\n";
        
        if (isset($result['_debug_output'])) {
            echo "   Debug output: '" . $result['_debug_output'] . "'\n";
        }
        
        // Show JSON size
        $json = json_encode($result);
        echo "   JSON size: " . strlen($json) . " bytes\n";
        
        // Show first 200 characters of JSON
        echo "   JSON preview: " . substr($json, 0, 200) . "...\n";
    }
    echo "\n";
}

// Test if there are any PHP errors when processing HOROSCOPE specifically
echo "Detailed error testing for HOROSCOPE:\n";
echo str_repeat('-', 40) . "\n";

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Test with error capture
set_error_handler(function($severity, $message, $file, $line) {
    echo "PHP Error: $message in $file:$line\n";
    return false;
});

try {
    echo "Testing MessageHandler directly with detailed error capture...\n";
    $handler = new MessageHandler();
    $result = $handler->getEchomail('HOROSCOPE', 1, 25, 1, 'all');
    
    echo "Direct call succeeded:\n";
    echo "   Messages: " . count($result['messages']) . "\n";
    echo "   JSON encode test: ";
    
    $json = json_encode($result);
    if ($json === false) {
        echo "FAILED - JSON encode error: " . json_last_error_msg() . "\n";
        
        // Check each message individually
        echo "   Testing individual messages:\n";
        foreach ($result['messages'] as $i => $message) {
            $msgJson = json_encode($message);
            if ($msgJson === false) {
                echo "   Message $i FAILED: " . json_last_error_msg() . "\n";
                echo "   Message data: " . print_r($message, true) . "\n";
            } else {
                echo "   Message $i OK\n";
            }
        }
    } else {
        echo "SUCCESS\n";
        echo "   JSON length: " . strlen($json) . " bytes\n";
    }
    
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

restore_error_handler();

echo "\nAPI output test completed.\n";