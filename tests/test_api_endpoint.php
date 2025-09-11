<?php
/**
 * Test the actual API endpoint to see if it returns replyto_address
 */

// Get the message ID with REPLYTO
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Database.php';

use BinktermPHP\Database;

try {
    $db = Database::getInstance()->getPdo();
    
    $stmt = $db->prepare("SELECT id FROM netmail WHERE message_text LIKE '%REPLYTO%' ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $testMessage = $stmt->fetch();
    
    if (!$testMessage) {
        echo "No test message found\n";
        exit(1);
    }
    
    $messageId = $testMessage['id'];
    echo "Testing API endpoint for message ID: $messageId\n";
    echo "URL: http://localhost/api/messages/netmail/$messageId\n\n";
    
    // Make a simple HTTP request to test the API
    $url = "http://localhost/api/messages/netmail/$messageId";
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => [
                'Content-Type: application/json',
                'Cookie: binktermphp_session=your_session_cookie_here' // You'd need a real session
            ]
        ]
    ]);
    
    echo "Making HTTP request to API endpoint...\n";
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        echo "❌ HTTP request failed. This might be because:\n";
        echo "   - Web server is not running\n";
        echo "   - Authentication is required\n";
        echo "   - URL is incorrect\n\n";
        
        echo "Alternative: Check the browser developer tools when viewing message $messageId\n";
        echo "Look at the Network tab for the API call and check if 'replyto_address' is in the response.\n";
    } else {
        echo "✅ API Response received:\n";
        echo "Raw response:\n";
        echo $response . "\n\n";
        
        $data = json_decode($response, true);
        if ($data) {
            echo "Parsed JSON:\n";
            if (isset($data['replyto_address'])) {
                echo "✅ replyto_address found: " . $data['replyto_address'] . "\n";
            } else {
                echo "❌ replyto_address NOT found in response\n";
            }
            
            if (isset($data['from_address'])) {
                echo "✅ from_address: " . $data['from_address'] . "\n";
            }
        } else {
            echo "❌ Could not parse JSON response\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}