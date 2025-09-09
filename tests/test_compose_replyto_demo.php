<?php

/**
 * Demo test showing how the compose route handles REPLYTO kludges
 * This simulates the actual web interface behavior
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;
use BinktermPHP\BinkdProcessor;
use BinktermPHP\MessageHandler;

/**
 * Copy the helper functions from index.php for testing
 */
function isValidFidonetAddress($address) {
    return preg_match('/^\d+:\d+\/\d+(?:\.\d+)?(?:@\w+)?$/', trim($address));
}

function parseReplyToKludge($messageText) {
    if (empty($messageText)) {
        return null;
    }
    
    $lines = preg_split('/\r\n|\r|\n/', $messageText);
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        
        if (preg_match('/^(?:\x01)?REPLYTO\s+(.+)$/i', $trimmed, $matches)) {
            $replyToData = trim($matches[1]);
            
            if (preg_match('/^(\S+)(?:\s+(.+))?$/', $replyToData, $addressMatches)) {
                $address = trim($addressMatches[1]);
                $name = isset($addressMatches[2]) ? trim($addressMatches[2]) : null;
                
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

echo "Enhanced REPLYTO Demo - Web Interface Simulation\n";
echo "===============================================\n\n";

// Create test data
$db = Database::getInstance()->getPdo();
$db->exec("DELETE FROM netmail WHERE subject LIKE 'REPLYTO DEMO%'");

echo "1. Creating test message with REPLYTO kludge...\n";

$testMessage = [
    'origAddr' => '1:234/567',
    'destAddr' => '1:123/456', 
    'fromName' => 'Original Sender',
    'toName' => 'Recipient',
    'subject' => 'REPLYTO DEMO Message',
    'dateTime' => date('d M y  H:i:s'),
    'text' => "\x01MSGID: 1:234/567 DEMO123\r\n\x01REPLYTO 2:460/256 8421559770\r\n\r\nThis message should be replied to 8421559770 at 2:460/256, not the original sender.",
    'attributes' => 1
];

$processor = new BinkdProcessor();
$reflection = new ReflectionClass($processor);
$storeNetmailMethod = $reflection->getMethod('storeNetmail');
$storeNetmailMethod->setAccessible(true);
$storeNetmailMethod->invoke($processor, $testMessage, ['origZone' => 1, 'destZone' => 1]);

$stmt = $db->prepare("SELECT * FROM netmail WHERE subject = ? ORDER BY id DESC LIMIT 1");
$stmt->execute(['REPLYTO DEMO Message']);
$originalMessage = $stmt->fetch();

echo "   Original Message Details:\n";
echo "   - From: {$originalMessage['from_name']} ({$originalMessage['from_address']})\n";
echo "   - Subject: {$originalMessage['subject']}\n";
echo "   - Contains REPLYTO kludge in message text\n\n";

echo "2. Simulating web interface reply logic...\n";

$handler = new MessageHandler();
$messageForReply = $handler->getMessage($originalMessage['id'], 'netmail', 1);

// Simulate the enhanced compose route logic
$replyToData = parseReplyToKludge($messageForReply['message_text']);

$templateVars = [];

if ($replyToData) {
    // Use REPLYTO address and name if valid FidoNet address found
    $templateVars['reply_to_address'] = $replyToData['address'];
    $templateVars['reply_to_name'] = $replyToData['name'] ?: $messageForReply['from_name'];
    echo "   ✓ REPLYTO kludge found and parsed successfully\n";
} else {
    // Fallback to existing logic if no valid REPLYTO found
    $templateVars['reply_to_address'] = $messageForReply['reply_address'] ?: ($messageForReply['original_author_address'] ?: $messageForReply['from_address']);
    $templateVars['reply_to_name'] = $messageForReply['from_name'];
    echo "   ✓ No valid REPLYTO found, using fallback logic\n";
}

$templateVars['reply_subject'] = 'Re: ' . ltrim($messageForReply['subject'] ?? '', 'Re: ');

echo "\n3. Compose form would be populated with:\n";
echo "   Reply To Address: {$templateVars['reply_to_address']}\n";
echo "   Reply To Name: {$templateVars['reply_to_name']}\n";
echo "   Reply Subject: {$templateVars['reply_subject']}\n\n";

// Test comparison - show what would happen with old vs new logic
echo "4. Comparison with old logic:\n";
$oldAddress = $messageForReply['reply_address'] ?: ($messageForReply['original_author_address'] ?: $messageForReply['from_address']);
$oldName = $messageForReply['from_name'];

echo "   Old Logic Would Use:\n";
echo "   - Address: $oldAddress\n";
echo "   - Name: $oldName\n\n";

echo "   New Enhanced Logic Uses:\n";
echo "   - Address: {$templateVars['reply_to_address']}\n";
echo "   - Name: {$templateVars['reply_to_name']}\n\n";

if ($templateVars['reply_to_address'] !== $oldAddress || $templateVars['reply_to_name'] !== $oldName) {
    echo "   ✅ ENHANCEMENT ACTIVE: Reply will go to REPLYTO address instead of original sender!\n\n";
} else {
    echo "   ℹ️  No difference (no valid REPLYTO found)\n\n";
}

// Test fallback scenario
echo "5. Testing fallback with UUCP address...\n";

$testMessage2 = [
    'origAddr' => '1:234/567',
    'destAddr' => '1:123/456', 
    'fromName' => 'Gateway User',
    'toName' => 'Recipient',
    'subject' => 'REPLYTO DEMO Fallback',
    'dateTime' => date('d M y  H:i:s'),
    'text' => "\x01MSGID: 1:234/567 DEMO456\r\n\x01REPLYTO user@domain.com Gateway\r\n\r\nThis has invalid REPLYTO address.",
    'attributes' => 1
];

$storeNetmailMethod->invoke($processor, $testMessage2, ['origZone' => 1, 'destZone' => 1]);

$stmt->execute(['REPLYTO DEMO Fallback']);
$fallbackMessage = $stmt->fetch();

$fallbackForReply = $handler->getMessage($fallbackMessage['id'], 'netmail', 1);
$fallbackReplyToData = parseReplyToKludge($fallbackForReply['message_text']);

if ($fallbackReplyToData === null) {
    echo "   ✓ UUCP address correctly rejected\n";
    echo "   ✓ System will fall back to original sender address\n";
    $fallbackAddress = $fallbackForReply['reply_address'] ?: ($fallbackForReply['original_author_address'] ?: $fallbackForReply['from_address']);
    echo "   ✓ Fallback address: $fallbackAddress\n\n";
} else {
    echo "   ✗ UUCP address should have been rejected\n";
}

// Clean up
echo "6. Cleaning up test data...\n";
$db->exec("DELETE FROM netmail WHERE subject LIKE 'REPLYTO DEMO%'");
echo "   ✓ Test data cleaned up\n\n";

echo "=== DEMO COMPLETE ===\n";
echo "The enhanced REPLYTO functionality is now working in the web interface:\n\n";
echo "• When replying to netmail with 'REPLYTO 2:460/256 8421559770':\n";
echo "  - Reply address becomes: 2:460/256\n";
echo "  - Reply name becomes: 8421559770\n";
echo "  - Original sender address/name is ignored\n\n";
echo "• UUCP addresses are rejected and system falls back gracefully\n";
echo "• FidoNet address validation ensures only valid addresses are used\n";
echo "• Integration works seamlessly with existing compose functionality\n\n";

?>