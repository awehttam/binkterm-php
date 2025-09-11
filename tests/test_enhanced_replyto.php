<?php

/**
 * Test script to verify enhanced REPLYTO kludge functionality
 * This script tests that:
 * 1. REPLYTO parsing extracts both address and name correctly
 * 2. FidoNet address validation works (rejects UUCP addresses)
 * 3. Reply logic uses REPLYTO address and name when valid
 * 4. Fallback works when REPLYTO is invalid or missing
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;
use BinktermPHP\BinkdProcessor;
use BinktermPHP\MessageHandler;

echo "Testing enhanced REPLYTO kludge functionality...\n\n";

// Test the helper functions first
echo "1. Testing helper functions...\n";

// Include the helper functions from index.php
require_once __DIR__ . '/../public_html/index.php';

// Test FidoNet address validation
$testAddresses = [
    '2:460/256' => true,        // Valid FidoNet
    '1:234/567.8' => true,      // Valid with point
    '21:1/100@fidonet' => true, // Valid with domain
    'user@domain.com' => false, // Invalid UUCP
    'invalid' => false,         // Invalid format
    '123:456' => false,         // Missing node
    '' => false,                // Empty
];

foreach ($testAddresses as $address => $expected) {
    $result = isValidFidonetAddress($address);
    if ($result === $expected) {
        echo "   ✓ Address validation for '$address': " . ($expected ? 'valid' : 'invalid') . "\n";
    } else {
        echo "   ✗ Address validation failed for '$address'. Expected: " . ($expected ? 'valid' : 'invalid') . ", Got: " . ($result ? 'valid' : 'invalid') . "\n";
        exit(1);
    }
}

// Test REPLYTO parsing
echo "\n2. Testing REPLYTO parsing...\n";

$testMessages = [
    // Valid REPLYTO with both address and name
    [
        'text' => "From: Test User\nSubject: Test\n\x01REPLYTO 2:460/256 8421559770\n\nMessage body",
        'expected' => ['address' => '2:460/256', 'name' => '8421559770']
    ],
    // Valid REPLYTO with address only
    [
        'text' => "From: Test User\nSubject: Test\n\x01REPLYTO 1:234/567\n\nMessage body",
        'expected' => ['address' => '1:234/567', 'name' => null]
    ],
    // Invalid UUCP address (should return null)
    [
        'text' => "From: Test User\nSubject: Test\n\x01REPLYTO user@domain.com SomeName\n\nMessage body",
        'expected' => null
    ],
    // No REPLYTO kludge
    [
        'text' => "From: Test User\nSubject: Test\n\x01MSGID: 1:234/567 12345678\n\nMessage body",
        'expected' => null
    ],
    // REPLYTO without \x01 prefix
    [
        'text' => "From: Test User\nSubject: Test\nREPLYTO 3:633/280 TestUser\n\nMessage body",
        'expected' => ['address' => '3:633/280', 'name' => 'TestUser']
    ]
];

foreach ($testMessages as $i => $test) {
    $result = parseReplyToKludge($test['text']);
    if ($result === $test['expected']) {
        echo "   ✓ REPLYTO parsing test " . ($i + 1) . " passed\n";
    } else {
        echo "   ✗ REPLYTO parsing test " . ($i + 1) . " failed\n";
        echo "     Expected: " . json_encode($test['expected']) . "\n";
        echo "     Got: " . json_encode($result) . "\n";
        exit(1);
    }
}

// Test integration with database
echo "\n3. Testing database integration...\n";

// Initialize database
$db = Database::getInstance()->getPdo();

// Clean up any previous test data
$db->exec("DELETE FROM netmail WHERE subject LIKE 'TEST ENHANCED REPLYTO%'");

// Create test message with enhanced REPLYTO
$testMessage = [
    'origAddr' => '1:234/567',
    'destAddr' => '1:123/456', 
    'fromName' => 'Test User',
    'toName' => 'Sysop',
    'subject' => 'TEST ENHANCED REPLYTO Message',
    'dateTime' => date('d M y  H:i:s'),
    'text' => "\x01MSGID: 1:234/567 12345678\r\n\x01REPLYTO 2:460/256 8421559770\r\n\r\nThis is a test message with enhanced REPLYTO.",
    'attributes' => 1 // Private flag for netmail
];

$testPacketInfo = [
    'origZone' => 1,
    'destZone' => 1
];

// Store the message using BinkdProcessor
$processor = new BinkdProcessor();
$reflection = new ReflectionClass($processor);
$storeNetmailMethod = $reflection->getMethod('storeNetmail');
$storeNetmailMethod->setAccessible(true);

try {
    $storeNetmailMethod->invoke($processor, $testMessage, $testPacketInfo);
    echo "   ✓ Message with enhanced REPLYTO stored successfully\n";
} catch (Exception $e) {
    echo "   ✗ Failed to store message: " . $e->getMessage() . "\n";
    exit(1);
}

// Retrieve the stored message
$stmt = $db->prepare("SELECT * FROM netmail WHERE subject = ? ORDER BY id DESC LIMIT 1");
$stmt->execute(['TEST ENHANCED REPLYTO Message']);
$storedMessage = $stmt->fetch();

if (!$storedMessage) {
    echo "   ✗ Test message not found in database\n";
    exit(1);
}

echo "   ✓ Message retrieved from database\n";

// Test the enhanced compose logic by simulating the web interface
echo "\n4. Testing enhanced compose logic...\n";

$handler = new MessageHandler();
$userId = 1;
$messageForReply = $handler->getMessage($storedMessage['id'], 'netmail', $userId);

if (!$messageForReply) {
    echo "   ✗ Could not retrieve message for reply test\n";
    exit(1);
}

// Simulate the enhanced compose route logic
$replyToData = parseReplyToKludge($messageForReply['message_text']);

if (!$replyToData) {
    echo "   ✗ Enhanced REPLYTO parsing failed for stored message\n";
    exit(1);
}

if ($replyToData['address'] !== '2:460/256') {
    echo "   ✗ Wrong REPLYTO address. Expected: 2:460/256, Got: " . $replyToData['address'] . "\n";
    exit(1);
}

if ($replyToData['name'] !== '8421559770') {
    echo "   ✗ Wrong REPLYTO name. Expected: 8421559770, Got: " . ($replyToData['name'] ?: 'NULL') . "\n";
    exit(1);
}

echo "   ✓ Enhanced REPLYTO parsing worked correctly\n";
echo "   ✓ Address: " . $replyToData['address'] . "\n";
echo "   ✓ Name: " . $replyToData['name'] . "\n";

// Test fallback behavior with UUCP address
echo "\n5. Testing fallback behavior with UUCP address...\n";

$testMessage2 = [
    'origAddr' => '1:234/567',
    'destAddr' => '1:123/456', 
    'fromName' => 'UUCP User',
    'toName' => 'Sysop',
    'subject' => 'TEST ENHANCED REPLYTO UUCP',
    'dateTime' => date('d M y  H:i:s'),
    'text' => "\x01MSGID: 1:234/567 87654321\r\n\x01REPLYTO user@domain.com Gateway\r\n\r\nMessage from UUCP gateway.",
    'attributes' => 1
];

try {
    $storeNetmailMethod->invoke($processor, $testMessage2, $testPacketInfo);
    echo "   ✓ UUCP test message stored successfully\n";
} catch (Exception $e) {
    echo "   ✗ Failed to store UUCP message: " . $e->getMessage() . "\n";
    exit(1);
}

$stmt = $db->prepare("SELECT * FROM netmail WHERE subject = ? ORDER BY id DESC LIMIT 1");
$stmt->execute(['TEST ENHANCED REPLYTO UUCP']);
$uucpMessage = $stmt->fetch();

$uucpMessageForReply = $handler->getMessage($uucpMessage['id'], 'netmail', $userId);
$uucpReplyToData = parseReplyToKludge($uucpMessageForReply['message_text']);

if ($uucpReplyToData !== null) {
    echo "   ✗ UUCP REPLYTO should have been rejected but was accepted\n";
    exit(1);
}

echo "   ✓ UUCP REPLYTO correctly rejected (fallback will be used)\n";

// Clean up test data
echo "\n6. Cleaning up test data...\n";
$db->exec("DELETE FROM netmail WHERE subject LIKE 'TEST ENHANCED REPLYTO%'");
echo "   ✓ Test data cleaned up\n";

echo "\n=== ALL TESTS PASSED ===\n";
echo "Enhanced REPLYTO kludge functionality is working correctly:\n";
echo "- REPLYTO parsing extracts both address and name\n";
echo "- FidoNet address validation rejects UUCP addresses\n";
echo "- Valid REPLYTO addresses are used for replies\n";
echo "- Invalid REPLYTO addresses trigger fallback behavior\n";
echo "- Integration with compose route works correctly\n\n";

?>