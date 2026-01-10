<?php
// Script to generate test echomail messages
// Run with: php tests/generate_test_echomail.php [--echo=ECHOAREA] [--count=NUMBER] [--user-id=ID]

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';
use BinktermPHP\MessageHandler;
use BinktermPHP\Database;

echo "=== Echomail Test Message Generator ===\n\n";

// Parse command line arguments
$options = getopt('', ['echo:', 'count:', 'user-id:', 'help']);

// Show help if no arguments provided or --help flag used
if ($argc === 1 || isset($options['help'])) {
    echo "Usage: php tests/generate_test_echomail.php [OPTIONS]\n\n";
    echo "Options:\n";
    echo "  --echo=ECHOAREA    Echo area tag (default: LOCALTEST)\n";
    echo "  --count=NUMBER     Number of messages to generate (default: 10)\n";
    echo "  --user-id=ID       User ID to post from (default: 1)\n";
    echo "  --help             Show this help message\n\n";
    echo "Examples:\n";
    echo "  php tests/generate_test_echomail.php --echo=TESTING --count=50\n";
    echo "  php tests/generate_test_echomail.php --echo=LOCALTEST --count=100 --user-id=2\n\n";
    exit(0);
}

// Configuration
$echoareaTag = $options['echo'] ?? 'LOCALTEST';
$messageCount = isset($options['count']) ? intval($options['count']) : 10;
$fromUserId = isset($options['user-id']) ? intval($options['user-id']) : 1;

// Validate inputs
if ($messageCount < 1) {
    echo "Error: Message count must be at least 1\n";
    exit(1);
}

if ($messageCount > 10000) {
    echo "Warning: Generating more than 10,000 messages. This may take a while.\n";
    echo "Continue? (y/n): ";
    $handle = fopen("php://stdin", "r");
    $response = trim(fgets($handle));
    fclose($handle);

    if (strtolower($response) !== 'y') {
        echo "Aborted.\n";
        exit(0);
    }
}

echo "Configuration:\n";
echo "  Echo area: $echoareaTag\n";
echo "  Message count: $messageCount\n";
echo "  From user ID: $fromUserId\n\n";

// Initialize database and message handler
try {
    Database::getInstance();
    $messageHandler = new MessageHandler();

    // Verify user exists
    $db = Database::getInstance()->getPdo();
    $userStmt = $db->prepare("SELECT username, real_name FROM users WHERE id = ?");
    $userStmt->execute([$fromUserId]);
    $user = $userStmt->fetch();

    if (!$user) {
        echo "Error: User ID $fromUserId not found\n";
        echo "Tip: Use --user-id=ID to specify a valid user ID\n";
        exit(1);
    }

    echo "Posting as: {$user['real_name']} ({$user['username']})\n\n";

    // Sample data for variety
    $subjects = [
        'Test Message',
        'Hello World',
        'Testing Echomail',
        'Sample Post',
        'Network Test',
        'BinkTerm Test',
        'Echo Area Check',
        'Message Flow Test',
        'System Check',
        'Daily Test',
        'Weekly Update',
        'Monthly Report',
        'Status Update',
        'Information Share',
        'Community Notice',
        'Technical Discussion',
        'Feature Request',
        'Bug Report',
        'General Question',
        'Announcement'
    ];

    $toNames = [
        'All',
        'Everyone',
        'Moderator',
        'Sysop',
        'Admin',
        'John Doe',
        'Jane Smith',
        'Bob Johnson',
        'Alice Williams'
    ];

    $messageTemplates = [
        "This is a test message posted at %s.\n\nThis message is part of automated testing for the BinkTerm PHP echomail system.",
        "Hello from the test message generator!\n\nMessage number %d generated on %s.",
        "Testing echomail functionality.\n\nThis automated message helps verify that the echomail system is working correctly.",
        "Lorem ipsum dolor sit amet, consectetur adipiscing elit.\n\nSed do eiusmod tempor incididunt ut labore et dolore magna aliqua.",
        "Quick test message for echo area validation.\n\nTimestamp: %s",
        "The quick brown fox jumps over the lazy dog.\n\nThis pangram contains every letter of the alphabet.",
        "Network connectivity test message.\n\nIf you can read this, the echomail system is functioning properly.",
        "Automated system check in progress.\n\nMessage generated: %s",
        "Testing message threading and display.\n\nThis is message %d in the test sequence.",
        "Greetings from the automated test suite!\n\nEchomail generation working as expected."
    ];

    $batchSize = 50;
    $created = 0;
    $failed = 0;

    echo "Generating messages...\n";

    $startTime = microtime(true);

    for ($i = 1; $i <= $messageCount; $i++) {
        // Select random subject and to-name
        $subject = $subjects[array_rand($subjects)];
        $toName = $toNames[array_rand($toNames)];

        // Create message text with some variety
        $template = $messageTemplates[array_rand($messageTemplates)];
        $timestamp = date('Y-m-d H:i:s');
        $messageText = sprintf($template, $timestamp, $i, $timestamp);

        // Add some variety to message length
        if (rand(1, 10) > 7) {
            $messageText .= "\n\n--- Additional Content ---\n";
            $messageText .= "This message includes some additional content to test variable message lengths.\n";
            $messageText .= "Line 1: " . str_repeat('x', rand(20, 60)) . "\n";
            $messageText .= "Line 2: " . str_repeat('y', rand(20, 60)) . "\n";
            $messageText .= "Line 3: " . str_repeat('z', rand(20, 60)) . "\n";
        }

        try {
            $result = $messageHandler->postEchomail(
                $fromUserId,
                $echoareaTag,
                $toName,
                $subject,
                $messageText
            );

            if ($result) {
                $created++;
            } else {
                $failed++;
                echo "  Warning: Failed to post message $i (no exception thrown)\n";
            }
        } catch (Exception $e) {
            $failed++;
            echo "  Error posting message $i: " . $e->getMessage() . "\n";
        }

        // Show progress
        if ($i % $batchSize === 0) {
            $elapsed = microtime(true) - $startTime;
            $rate = $i / $elapsed;
            $remaining = ($messageCount - $i) / $rate;
            echo sprintf(
                "  Progress: %d/%d (%.1f%%) - %.1f msg/sec - ETA: %d seconds\n",
                $i,
                $messageCount,
                ($i / $messageCount) * 100,
                $rate,
                $remaining
            );
        }
    }

    $elapsed = microtime(true) - $startTime;

    echo "\n=== Summary ===\n";
    echo "Total messages requested: $messageCount\n";
    echo "Successfully created: $created\n";
    echo "Failed: $failed\n";
    echo "Time elapsed: " . number_format($elapsed, 2) . " seconds\n";
    echo "Average rate: " . number_format($created / $elapsed, 1) . " messages/second\n";

    if ($created > 0) {
        echo "\n✓ Test messages generated successfully in echo area '$echoareaTag'\n";
    } else {
        echo "\n✗ No messages were created. Check the errors above.\n";
        exit(1);
    }

} catch (Exception $e) {
    echo "\n❌ Fatal Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

