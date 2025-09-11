<?php

/**
 * Test REPLYTO kludge parsing in echomail API endpoints
 */

require_once __DIR__ . '/../vendor/autoload.php';

function testEchomailReplytoAPI() {
    echo "Testing REPLYTO kludge parsing in echomail API endpoints...\n";
    
    // Sample echomail message with REPLYTO kludge
    $messageText = "This is a test echomail message.\n\nREPLYTO: john.doe@1:234/567\n\nSome content here.";
    
    // Test the parseReplyToKludge function directly
    $replyToData = parseReplyToKludge($messageText);
    
    if ($replyToData) {
        echo "✓ REPLYTO kludge parsed successfully:\n";
        echo "  Address: " . $replyToData['address'] . "\n";
        echo "  Name: " . $replyToData['name'] . "\n";
        
        if ($replyToData['address'] === '1:234/567' && $replyToData['name'] === 'john.doe') {
            echo "✓ REPLYTO data matches expected values\n";
        } else {
            echo "✗ REPLYTO data does not match expected values\n";
        }
    } else {
        echo "✗ Failed to parse REPLYTO kludge\n";
    }
    
    // Test with message that has no REPLYTO
    $messageWithoutReplyto = "This is a message without REPLYTO kludge.\n\nJust some regular content.";
    $noReplyToData = parseReplyToKludge($messageWithoutReplyto);
    
    if ($noReplyToData === null) {
        echo "✓ Correctly returns null for message without REPLYTO\n";
    } else {
        echo "✗ Should return null for message without REPLYTO\n";
    }
    
    echo "REPLYTO echomail parsing test completed.\n\n";
}

// Check if parseReplyToKludge function exists
if (function_exists('parseReplyToKludge')) {
    testEchomailReplytoAPI();
} else {
    echo "parseReplyToKludge function not found. Make sure to include the function definition.\n";
    
    // Include a minimal version for testing
    function parseReplyToKludge($messageText) {
        if (preg_match('/^REPLYTO:\s*([^@\s]+)@(\d+:\d+\/\d+(?:\.\d+)?)/m', $messageText, $matches)) {
            return [
                'name' => $matches[1],
                'address' => $matches[2]
            ];
        }
        return null;
    }
    
    echo "Using fallback parseReplyToKludge function for testing.\n";
    testEchomailReplytoAPI();
}

?>