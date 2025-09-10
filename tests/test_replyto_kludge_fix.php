<?php
require_once __DIR__ . '/../public_html/index.php';

// Test message with both quoted REPLYTO and proper kludge REPLYTO
$testMessage = "\x01MSGID: 1:234/567@fidonet 12345678
\x01REPLYTO 1:234/999 John Doe
Hello,

Someone wrote:
> REPLYTO 1:234/888 Fake User

This is a test message to verify that the REPLYTO parser
only picks up the kludge line, not the quoted text.

Best regards,
Test User";

echo "Testing REPLYTO kludge parsing fix...\n";
echo "Message contains:\n";
echo "- Proper kludge: \\x01REPLYTO 1:234/999 John Doe\n";
echo "- Quoted text: > REPLYTO 1:234/888 Fake User\n";
echo "\n";

$replyTo = parseReplyToKludge($testMessage);

if ($replyTo) {
    echo "✓ PASS: Found REPLYTO kludge\n";
    echo "  Address: " . $replyTo['address'] . "\n";
    echo "  Name: " . ($replyTo['name'] ?? 'null') . "\n";
    
    if ($replyTo['address'] === '1:234/999') {
        echo "✓ PASS: Correct address parsed (1:234/999)\n";
    } else {
        echo "✗ FAIL: Wrong address parsed, expected 1:234/999, got " . $replyTo['address'] . "\n";
    }
    
    if ($replyTo['name'] === 'John Doe') {
        echo "✓ PASS: Correct name parsed (John Doe)\n";
    } else {
        echo "✗ FAIL: Wrong name parsed, expected 'John Doe', got '" . ($replyTo['name'] ?? 'null') . "'\n";
    }
} else {
    echo "✗ FAIL: No REPLYTO kludge found\n";
}

// Test message with only quoted REPLYTO (should return null)
$testMessage2 = "Hello,

Someone wrote:
> REPLYTO 1:234/888 Fake User

This message has no proper REPLYTO kludge.

Best regards,
Test User";

echo "\nTesting message with only quoted REPLYTO...\n";
$replyTo2 = parseReplyToKludge($testMessage2);

if ($replyTo2 === null) {
    echo "✓ PASS: Correctly ignored quoted REPLYTO\n";
} else {
    echo "✗ FAIL: Incorrectly parsed quoted REPLYTO\n";
    echo "  Address: " . $replyTo2['address'] . "\n";
    echo "  Name: " . ($replyTo2['name'] ?? 'null') . "\n";
}

echo "\nTest complete.\n";
?>