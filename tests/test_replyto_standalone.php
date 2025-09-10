<?php

/**
 * Standalone test for enhanced REPLYTO functionality
 * This avoids including the web interface routes
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;
use BinktermPHP\BinkdProcessor;
use BinktermPHP\MessageHandler;

/**
 * Validate if an address is a proper FidoNet address
 */
function isValidFidonetAddress($address) {
    return preg_match('/^\d+:\d+\/\d+(?:\.\d+)?(?:@\w+)?$/', trim($address));
}

/**
 * Parse REPLYTO kludge line to extract address and name
 */
function parseReplyToKludge($messageText) {
    if (empty($messageText)) {
        return null;
    }
    
    $lines = preg_split('/\r\n|\r|\n/', $messageText);
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        
        // Look for REPLYTO kludge line (must have \x01 prefix)
        if (preg_match('/^\x01REPLYTO\s+(.+)$/i', $trimmed, $matches)) {
            $replyToData = trim($matches[1]);
            
            // Parse "address name" or just "address"
            if (preg_match('/^(\S+)(?:\s+(.+))?$/', $replyToData, $addressMatches)) {
                $address = trim($addressMatches[1]);
                $name = isset($addressMatches[2]) ? trim($addressMatches[2]) : null;
                
                // Only return if it's a valid FidoNet address
                if (isValidFidonetAddress($address)) {
                    return [
                        'address' => $address,
                        'name' => $name
                    ];
                }
            }
        }
    }
    
    return null;
}

echo "Testing enhanced REPLYTO kludge functionality...\n\n";

// Test 1: FidoNet address validation
echo "1. Testing FidoNet address validation...\n";

$testAddresses = [
    '2:460/256' => true,        // Valid FidoNet
    '1:234/567.8' => true,      // Valid with point
    '21:1/100@fidonet' => true, // Valid with domain
    'user@domain.com' => false, // Invalid UUCP
    'invalid' => false,         // Invalid format
    '123:456' => false,         // Missing node
];

foreach ($testAddresses as $address => $expected) {
    $result = isValidFidonetAddress($address);
    $resultBool = (bool)$result;
    if ($resultBool === $expected) {
        echo "   ✓ '$address' correctly identified as " . ($expected ? 'valid' : 'invalid') . "\n";
    } else {
        echo "   ✗ '$address' validation failed - Expected: " . ($expected ? 'true' : 'false') . ", Got: " . ($resultBool ? 'true' : 'false') . "\n";
        exit(1);
    }
}

// Test 2: REPLYTO parsing
echo "\n2. Testing REPLYTO parsing...\n";

$testCases = [
    [
        'name' => 'REPLYTO with address and name',
        'text' => "\x01REPLYTO 2:460/256 8421559770\n\nMessage body",
        'expected' => ['address' => '2:460/256', 'name' => '8421559770']
    ],
    [
        'name' => 'REPLYTO with address only',
        'text' => "\x01REPLYTO 1:234/567\n\nMessage body",
        'expected' => ['address' => '1:234/567', 'name' => null]
    ],
    [
        'name' => 'REPLYTO with UUCP address (should be rejected)',
        'text' => "\x01REPLYTO user@domain.com Gateway\n\nMessage body",
        'expected' => null
    ],
    [
        'name' => 'No REPLYTO kludge',
        'text' => "\x01MSGID: 1:234/567 12345678\n\nMessage body",
        'expected' => null
    ],
    [
        'name' => 'REPLYTO without \\x01 prefix',
        'text' => "REPLYTO 3:633/280 TestUser\n\nMessage body",
        'expected' => ['address' => '3:633/280', 'name' => 'TestUser']
    ]
];

foreach ($testCases as $test) {
    $result = parseReplyToKludge($test['text']);
    if ($result === $test['expected']) {
        echo "   ✓ {$test['name']}\n";
    } else {
        echo "   ✗ {$test['name']} - Expected: " . json_encode($test['expected']) . ", Got: " . json_encode($result) . "\n";
        exit(1);
    }
}

// Test 3: Database integration
echo "\n3. Testing database integration...\n";

$db = Database::getInstance()->getPdo();
$db->exec("DELETE FROM netmail WHERE subject LIKE 'TEST ENHANCED REPLYTO%'");

$testMessage = [
    'origAddr' => '1:234/567',
    'destAddr' => '1:123/456', 
    'fromName' => 'Test User',
    'toName' => 'Sysop',
    'subject' => 'TEST ENHANCED REPLYTO Message',
    'dateTime' => date('d M y  H:i:s'),
    'text' => "\x01MSGID: 1:234/567 12345678\r\n\x01REPLYTO 2:460/256 8421559770\r\n\r\nThis is a test message.",
    'attributes' => 1
];

$testPacketInfo = ['origZone' => 1, 'destZone' => 1];

$processor = new BinkdProcessor();
$reflection = new ReflectionClass($processor);
$storeNetmailMethod = $reflection->getMethod('storeNetmail');
$storeNetmailMethod->setAccessible(true);

try {
    $storeNetmailMethod->invoke($processor, $testMessage, $testPacketInfo);
    echo "   ✓ Message stored successfully\n";
} catch (Exception $e) {
    echo "   ✗ Failed to store message: " . $e->getMessage() . "\n";
    exit(1);
}

$stmt = $db->prepare("SELECT * FROM netmail WHERE subject = ? ORDER BY id DESC LIMIT 1");
$stmt->execute(['TEST ENHANCED REPLYTO Message']);
$storedMessage = $stmt->fetch();

if (!$storedMessage) {
    echo "   ✗ Message not found in database\n";
    exit(1);
}

// Test 4: Enhanced compose logic simulation
echo "\n4. Testing compose logic simulation...\n";

$handler = new MessageHandler();
$messageForReply = $handler->getMessage($storedMessage['id'], 'netmail', 1);

if (!$messageForReply) {
    echo "   ✗ Could not retrieve message\n";
    exit(1);
}

$replyToData = parseReplyToKludge($messageForReply['message_text']);

if (!$replyToData || $replyToData['address'] !== '2:460/256' || $replyToData['name'] !== '8421559770') {
    echo "   ✗ REPLYTO parsing failed for stored message\n";
    echo "     Got: " . json_encode($replyToData) . "\n";
    exit(1);
}

echo "   ✓ Enhanced REPLYTO parsing works correctly\n";
echo "   ✓ Address: {$replyToData['address']}, Name: {$replyToData['name']}\n";

// Test 5: Fallback with invalid address
echo "\n5. Testing fallback with UUCP address...\n";

$testMessage2 = [
    'origAddr' => '1:234/567',
    'destAddr' => '1:123/456', 
    'fromName' => 'Gateway User',
    'toName' => 'Sysop',
    'subject' => 'TEST ENHANCED REPLYTO FALLBACK',
    'dateTime' => date('d M y  H:i:s'),
    'text' => "\x01MSGID: 1:234/567 87654321\r\n\x01REPLYTO user@domain.com Gateway\r\n\r\nUUCP message.",
    'attributes' => 1
];

$storeNetmailMethod->invoke($processor, $testMessage2, $testPacketInfo);

$stmt = $db->prepare("SELECT * FROM netmail WHERE subject = ? ORDER BY id DESC LIMIT 1");
$stmt->execute(['TEST ENHANCED REPLYTO FALLBACK']);
$fallbackMessage = $stmt->fetch();

$fallbackForReply = $handler->getMessage($fallbackMessage['id'], 'netmail', 1);
$fallbackReplyToData = parseReplyToKludge($fallbackForReply['message_text']);

if ($fallbackReplyToData !== null) {
    echo "   ✗ UUCP address should have been rejected\n";
    exit(1);
}

echo "   ✓ UUCP address correctly rejected (fallback will be used)\n";

// Clean up
echo "\n6. Cleaning up...\n";
$db->exec("DELETE FROM netmail WHERE subject LIKE 'TEST ENHANCED REPLYTO%'");
echo "   ✓ Test data cleaned up\n";

echo "\n=== ALL TESTS PASSED ===\n";
echo "Enhanced REPLYTO functionality working correctly:\n";
echo "✓ Parses REPLYTO address and name from kludge lines\n";
echo "✓ Validates FidoNet addresses (rejects UUCP)\n";
echo "✓ Falls back gracefully for invalid addresses\n";
echo "✓ Integrates properly with database storage\n\n";

?>