<?php
/**
 * Test the enhanced address book descriptions
 */

echo "Testing Enhanced Address Book Descriptions\n";
echo "==========================================\n\n";

// Test the description generation logic
function generateDescription($messageType, $fromName, $fromAddress, $originalFromName, $originalFromAddress) {
    $description = "Added from {$messageType} message";
    
    if ($originalFromName && $originalFromAddress) {
        if ($messageType === 'netmail') {
            if ($fromName !== $originalFromName || $fromAddress !== $originalFromAddress) {
                // REPLYTO was used - show both original and reply-to info
                $description = "Added from netmail message. Original sender: {$originalFromName} ({$originalFromAddress}), Reply-to: {$fromName} ({$fromAddress})";
            } else {
                // No REPLYTO - just show sender info
                $description = "Added from netmail message. Sender: {$originalFromName} ({$originalFromAddress})";
            }
        } else {
            // Echomail
            $description = "Added from echomail message. Sender: {$originalFromName} ({$originalFromAddress})";
        }
    }
    
    return $description;
}

$testCases = [
    [
        'name' => 'Netmail with REPLYTO (different name and address)',
        'type' => 'netmail',
        'fromName' => '1223717052',
        'fromAddress' => '2:460/256', 
        'originalFromName' => 'Aug',
        'originalFromAddress' => '2:460/256',
        'expected' => 'Added from netmail message. Original sender: Aug (2:460/256), Reply-to: 1223717052 (2:460/256)'
    ],
    [
        'name' => 'Netmail with REPLYTO (different address only)',
        'type' => 'netmail',
        'fromName' => 'TestUser',
        'fromAddress' => '1:555/777',
        'originalFromName' => 'TestUser', 
        'originalFromAddress' => '1:123/456',
        'expected' => 'Added from netmail message. Original sender: TestUser (1:123/456), Reply-to: TestUser (1:555/777)'
    ],
    [
        'name' => 'Netmail without REPLYTO (same name and address)',
        'type' => 'netmail',
        'fromName' => 'TestUser',
        'fromAddress' => '1:123/456',
        'originalFromName' => 'TestUser',
        'originalFromAddress' => '1:123/456',
        'expected' => 'Added from netmail message. Sender: TestUser (1:123/456)'
    ],
    [
        'name' => 'Echomail message',
        'type' => 'echomail',
        'fromName' => 'EchoUser',
        'fromAddress' => '1:234/567',
        'originalFromName' => 'EchoUser',
        'originalFromAddress' => '1:234/567',
        'expected' => 'Added from echomail message. Sender: EchoUser (1:234/567)'
    ],
    [
        'name' => 'Fallback case (no original info)',
        'type' => 'netmail',
        'fromName' => 'TestUser',
        'fromAddress' => '1:123/456',
        'originalFromName' => null,
        'originalFromAddress' => null,
        'expected' => 'Added from netmail message'
    ]
];

foreach ($testCases as $i => $test) {
    echo ($i + 1) . ". " . $test['name'] . "\n";
    echo str_repeat("-", strlen($test['name']) + 3) . "\n";
    
    $result = generateDescription(
        $test['type'],
        $test['fromName'],
        $test['fromAddress'], 
        $test['originalFromName'],
        $test['originalFromAddress']
    );
    
    echo "   Input:\n";
    echo "     Message Type: " . $test['type'] . "\n";
    echo "     Save Name: " . $test['fromName'] . "\n";
    echo "     Save Address: " . $test['fromAddress'] . "\n";
    echo "     Original Name: " . ($test['originalFromName'] ?: 'NULL') . "\n";
    echo "     Original Address: " . ($test['originalFromAddress'] ?: 'NULL') . "\n";
    echo "\n";
    
    echo "   Generated Description:\n";
    echo "     \"" . $result . "\"\n";
    echo "\n";
    
    if ($result === $test['expected']) {
        echo "   ✅ PASS: Description matches expected format\n";
    } else {
        echo "   ❌ FAIL: Description does not match expected\n";
        echo "     Expected: \"" . $test['expected'] . "\"\n";
        echo "     Got:      \"" . $result . "\"\n";
    }
    echo "\n";
}

echo "=== FUNCTION CALLS THAT WILL BE GENERATED ===\n";
echo "Based on the updated templates, here are the function calls:\n\n";

echo "1. Netmail with REPLYTO:\n";
echo "   saveToAddressBook('1223717052', '2:460/256', 'Aug', '2:460/256')\n";
echo "   → Description: \"Added from netmail message. Original sender: Aug (2:460/256), Reply-to: 1223717052 (2:460/256)\"\n\n";

echo "2. Netmail without REPLYTO:\n";
echo "   saveToAddressBook('TestUser', '1:123/456', 'TestUser', '1:123/456')\n";
echo "   → Description: \"Added from netmail message. Sender: TestUser (1:123/456)\"\n\n";

echo "3. Echomail:\n";
echo "   saveToAddressBook('EchoUser', '1:234/567', 'EchoUser', '1:234/567')\n";
echo "   → Description: \"Added from echomail message. Sender: EchoUser (1:234/567)\"\n\n";

echo "=== BENEFITS ===\n";
echo "✓ Users can see the original sender when REPLYTO is used\n";
echo "✓ Clear distinction between original sender and reply-to address\n";
echo "✓ Reference information helps identify message context\n";
echo "✓ Consistent format across netmail and echomail\n";