<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Auth;

echo "Testing Authentication and Route Pattern\n";
echo "=======================================\n\n";

try {
    // Test route pattern
    echo "1. Testing route pattern:\n";
    $pattern = '[A-Za-z0-9._-]+';
    $testValues = ['HOROSCOPE', 'test.area', 'area-name', 'area_name', 'Horoscope'];
    
    foreach ($testValues as $value) {
        $matches = preg_match('/^' . $pattern . '$/', $value);
        echo "   '$value' matches pattern: " . ($matches ? 'YES' : 'NO') . "\n";
    }
    echo "\n";
    
    // Test authentication
    echo "2. Testing authentication:\n";
    $auth = new Auth();
    
    // Check if there's a current user
    try {
        $user = $auth->getCurrentUser();
        if ($user) {
            echo "   ✓ Current user found: " . ($user['username'] ?? 'unknown') . "\n";
            echo "   User ID field: " . ($user['user_id'] ?? $user['id'] ?? 'none') . "\n";
        } else {
            echo "   ✗ No current user session\n";
        }
    } catch (Exception $e) {
        echo "   ✗ Auth error: " . $e->getMessage() . "\n";
    }
    echo "\n";
    
    // Test if requireAuth would work
    echo "3. Testing requireAuth (this might throw exception):\n";
    try {
        $user = $auth->requireAuth();
        echo "   ✓ requireAuth succeeded\n";
        echo "   User: " . json_encode($user) . "\n";
    } catch (Exception $e) {
        echo "   ✗ requireAuth failed: " . $e->getMessage() . "\n";
        echo "   This could be why the API is failing!\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\nTest completed.\n";