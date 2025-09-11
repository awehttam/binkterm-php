<?php
/**
 * Test the final REPLYTO logic for address book save
 */

echo "Testing Final REPLYTO Address Book Logic\n";
echo "========================================\n\n";

// Test the JavaScript logic in PHP to verify it works correctly
function simulateJavaScript($message) {
    // Simulate: const replyToAddress = message.replyto_address || message.reply_address || message.original_author_address || message.from_address;
    $replyToAddress = $message['replyto_address'] ?: ($message['reply_address'] ?: ($message['original_author_address'] ?: $message['from_address']));
    
    // Simulate: const replyToName = message.replyto_name || message.from_name;
    $replyToName = $message['replyto_name'] ?: $message['from_name'];
    
    return [
        'address' => $replyToAddress,
        'name' => $replyToName
    ];
}

// Test cases
$testCases = [
    [
        'name' => 'Message WITH REPLYTO kludge data',
        'message' => [
            'from_name' => 'Aug',
            'from_address' => '2:460/256',
            'reply_address' => null,
            'original_author_address' => '2:460/256',
            'replyto_address' => '2:460/256',
            'replyto_name' => '1223717052'
        ],
        'expected_address' => '2:460/256',
        'expected_name' => '1223717052'
    ],
    [
        'name' => 'Message WITHOUT REPLYTO kludge data',
        'message' => [
            'from_name' => 'TestUser',
            'from_address' => '1:123/456',
            'reply_address' => null,
            'original_author_address' => '1:234/567',
            'replyto_address' => null,
            'replyto_name' => null
        ],
        'expected_address' => '1:234/567',
        'expected_name' => 'TestUser'
    ],
    [
        'name' => 'Message with database reply_address',
        'message' => [
            'from_name' => 'TestUser',
            'from_address' => '1:123/456',
            'reply_address' => '1:987/654',
            'original_author_address' => '1:234/567',
            'replyto_address' => null,
            'replyto_name' => null
        ],
        'expected_address' => '1:987/654',
        'expected_name' => 'TestUser'
    ],
    [
        'name' => 'REPLYTO overrides database fields',
        'message' => [
            'from_name' => 'TestUser',
            'from_address' => '1:123/456',
            'reply_address' => '1:987/654',
            'original_author_address' => '1:234/567',
            'replyto_address' => '1:555/777',
            'replyto_name' => 'ReplyToUser'
        ],
        'expected_address' => '1:555/777',
        'expected_name' => 'ReplyToUser'
    ]
];

foreach ($testCases as $i => $test) {
    echo ($i + 1) . ". " . $test['name'] . "\n";
    echo str_repeat("-", strlen($test['name']) + 3) . "\n";
    
    $result = simulateJavaScript($test['message']);
    
    echo "   Input:\n";
    echo "     from_name: " . $test['message']['from_name'] . "\n";
    echo "     from_address: " . $test['message']['from_address'] . "\n";
    echo "     reply_address: " . ($test['message']['reply_address'] ?: 'NULL') . "\n";
    echo "     original_author_address: " . ($test['message']['original_author_address'] ?: 'NULL') . "\n";
    echo "     replyto_address: " . ($test['message']['replyto_address'] ?: 'NULL') . "\n";
    echo "     replyto_name: " . ($test['message']['replyto_name'] ?: 'NULL') . "\n";
    echo "\n";
    
    echo "   Result:\n";
    echo "     Selected Address: " . $result['address'] . "\n";
    echo "     Selected Name: " . $result['name'] . "\n";
    echo "\n";
    
    $addressMatch = $result['address'] === $test['expected_address'];
    $nameMatch = $result['name'] === $test['expected_name'];
    
    if ($addressMatch && $nameMatch) {
        echo "   ✅ PASS: Both address and name match expected values\n";
    } else {
        echo "   ❌ FAIL:\n";
        if (!$addressMatch) {
            echo "     Expected address: " . $test['expected_address'] . ", Got: " . $result['address'] . "\n";
        }
        if (!$nameMatch) {
            echo "     Expected name: " . $test['expected_name'] . ", Got: " . $result['name'] . "\n";
        }
    }
    
    echo "   📞 saveToAddressBook('" . $result['name'] . "', '" . $result['address'] . "')\n";
    echo "\n";
}

echo "=== SUMMARY ===\n";
echo "The address book save functionality will now:\n";
echo "1. Use replyto_address + replyto_name when REPLYTO kludge is present\n";
echo "2. Fall back to database fields (reply_address, original_author_address) if no REPLYTO\n";
echo "3. Use from_address + from_name as final fallback\n";
echo "4. This ensures the most appropriate contact information is saved\n";
echo "\nNext step: Test this in the browser by viewing a message with REPLYTO kludge!\n";