#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\MessageHandler;
use BinktermPHP\Database;
use BinktermPHP\Auth;

function showUsage()
{
    echo "Usage: php post_message.php [options]\n";
    echo "Post netmail or echomail messages from command line\n";
    echo "\n";
    echo "Required options:\n";
    echo "  --type=TYPE           Message type: 'netmail' or 'echomail'\n";
    echo "  --from=ADDRESS        From FTN address (e.g., 1:153/149.500)\n";
    echo "  --from-name=NAME      From name\n";
    echo "  --subject=SUBJECT     Message subject\n";
    echo "\n";
    echo "Netmail options:\n";
    echo "  --to=ADDRESS          To FTN address\n";
    echo "  --to-name=NAME        To name\n";
    echo "\n";
    echo "Echomail options:\n";
    echo "  --echoarea=TAG        Echo area tag (e.g., GENERAL)\n";
    echo "  --domain=DOMAIN       The network domain (e.g., fidonet)\n";
    echo "  --to-name=NAME        To name (optional, defaults to 'All')\n";
    echo "  --reply-to=ID         Reply to message ID (optional)\n";
    echo "\n";
    echo "Message content (choose one):\n";
    echo "  --text=TEXT           Message text directly\n";
    echo "  --file=FILE           Read message text from file\n";
    echo "  --stdin               Read message text from stdin\n";
    echo "\n";
    echo "Other options:\n";
    echo "  --user=USERNAME       Post as specific user (default: first user)\n";
    echo "  --list-users          List available users and exit\n";
    echo "  --list-areas          List available echo areas and exit\n";
    echo "  --help                Show this help message\n";
    echo "\n";
    echo "Examples:\n";
    echo "  # Send netmail\n";
    echo "  php post_message.php --type=netmail --from=1:153/149.500 \\\n";
    echo "    --from-name=\"John Doe\" --to=1:153/149 --to-name=\"Jane Smith\" \\\n";
    echo "    --subject=\"Test Message\" --text=\"Hello, this is a test!\"\n";
    echo "\n";
    echo "  # Post to echomail\n";
    echo "  php post_message.php --type=echomail --from=1:153/149.500 \\\n";
    echo "    --from-name=\"John Doe\" --echoarea=GENERAL --domain=fidonet \\\n";
    echo "    --subject=\"General Discussion\" --file=message.txt\n";
    echo "\n";
    echo "  # Read from stdin\n";
    echo "  echo \"Hello World\" | php post_message.php --type=netmail \\\n";
    echo "    --from=1:153/149.500 --from-name=\"John\" --to=1:153/149 \\\n";
    echo "    --to-name=\"Jane\" --subject=\"Quick Note\" --stdin\n";
    echo "\n";
}

function parseArgs($argv)
{
    $args = [];
    
    foreach ($argv as $arg) {
        if (strpos($arg, '--') === 0) {
            if (strpos($arg, '=') !== false) {
                list($key, $value) = explode('=', substr($arg, 2), 2);
                $args[$key] = $value;
            } else {
                $args[substr($arg, 2)] = true;
            }
        }
    }
    
    return $args;
}

function validateFtnAddress($address)
{
    // Basic FTN address validation: zone:net/node[.point]
    return preg_match('/^\d+:\d+\/\d+(?:\.\d+)?$/', $address);
}

function getUserByUsername($username)
{
    $db = Database::getInstance()->getPdo();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    return $stmt->fetch();
}

function getFirstUser()
{
    $db = Database::getInstance()->getPdo();
    $stmt = $db->query("SELECT * FROM users ORDER BY id LIMIT 1");
    return $stmt->fetch();
}

function listUsers()
{
    $db = Database::getInstance()->getPdo();
    $stmt = $db->query("SELECT username, real_name, fidonet_address FROM users ORDER BY username");
    $users = $stmt->fetchAll();
    
    echo "Available users:\n";
    echo str_pad("Username", 20) . str_pad("Real Name", 25) . "FTN Address\n";
    echo str_repeat("-", 65) . "\n";
    
    foreach ($users as $user) {
        echo str_pad($user['username'], 20) . 
             str_pad($user['real_name'] ?: 'N/A', 25) . 
             ($user['fidonet_address'] ?: 'N/A') . "\n";
    }
}

function listEchoAreas()
{
    $db = Database::getInstance()->getPdo();
    $stmt = $db->query("
        SELECT tag, description, message_count, domain, is_local
        FROM echoareas
        WHERE is_active = TRUE
        ORDER BY
            CASE
                WHEN COALESCE(is_local, FALSE) = TRUE THEN 0
                WHEN LOWER(domain) = 'lovlynet' THEN 1
                ELSE 2
            END,
            tag
    ");
    $areas = $stmt->fetchAll();
    
    echo "Available echo areas:\n";
    echo str_pad("Tag", 20) . str_pad("Domain", 10).str_pad("Description", 40) . "Messages\n";
    echo str_repeat("-", 70) . "\n";
    
    foreach ($areas as $area) {
        echo str_pad($area['tag'], 20) .
             str_pad($area['domain'], 10).
             str_pad(substr($area['description'] ?: 'N/A', 0, 39), 40) . 
             $area['message_count'] . "\n";
    }
}

function readMessageText($args)
{
    if (isset($args['text'])) {
        return $args['text'];
    } elseif (isset($args['file'])) {
        $filename = $args['file'];
        if (!file_exists($filename)) {
            throw new Exception("File not found: {$filename}");
        }
        if (!is_readable($filename)) {
            throw new Exception("File not readable: {$filename}");
        }
        return file_get_contents($filename);
    } elseif (isset($args['stdin'])) {
        $text = '';
        while (($line = fgets(STDIN)) !== false) {
            $text .= $line;
        }
        return rtrim($text);
    } else {
        throw new Exception("No message text specified. Use --text, --file, or --stdin");
    }
}

function validateNetmailArgs($args)
{
    $required = ['to', 'to-name'];
    foreach ($required as $field) {
        if (!isset($args[$field])) {
            throw new Exception("Missing required netmail field: --{$field}");
        }
    }
    
    if (!validateFtnAddress($args['to'])) {
        throw new Exception("Invalid FTN address format: {$args['to']}");
    }
}

function validateEchomailArgs($args)
{
    if (!isset($args['echoarea'])) {
        throw new Exception("Missing required echomail field: --echoarea");
    }
    
    // Check if echoarea exists
    $db = Database::getInstance()->getPdo();
    $stmt = $db->prepare("SELECT * FROM echoareas WHERE tag = ? AND domain=? AND is_active = TRUE");
    $stmt->execute([$args['echoarea'], $args['domain']]);
    $area = $stmt->fetch();
    
    if (!$area) {
        throw new Exception("Echo area not found or inactive: {$args['echoarea']}");
    }
}

function postNetmail($args, $user, $messageText)
{
    $handler = new MessageHandler();
    
    echo "Posting netmail...\n";
    echo "  From: {$args['from-name']} <{$args['from']}>\n";
    echo "  To: {$args['to-name']} <{$args['to']}>\n";
    echo "  Subject: {$args['subject']}\n";
    echo "  Text: " . strlen($messageText) . " characters\n";
    
    $result = $handler->sendNetmail(
        $user['id'],
        $args['to'],
        $args['to-name'],
        $args['subject'],
        $messageText,
        $args['from-name']
    );
    
    if ($result) {
        echo "✓ Netmail posted successfully\n";
        return true;
    } else {
        echo "✗ Failed to post netmail\n";
        return false;
    }
}

function postEchomail($args, $user, $messageText)
{
    $handler = new MessageHandler();
    $toName = $args['to-name'] ?? 'All';
    $replyToId = isset($args['reply-to']) ? intval($args['reply-to']) : null;
    
    echo "Posting echomail...\n";
    echo "  From: {$args['from-name']} <{$args['from']}>\n";
    echo "  To: {$toName}\n";
    echo "  Echo area: {$args['echoarea']}\n";
    echo "  Subject: {$args['subject']}\n";
    if ($replyToId) {
        echo "  Reply to: message #{$replyToId}\n";
    }
    echo "  Text: " . strlen($messageText) . " characters\n";
    
    $result = $handler->postEchomail(
        $user['id'],
        $args['echoarea'],
        $args['domain'],
        $toName,
        $args['subject'],
        $messageText,
        $replyToId
    );
    
    if ($result) {
        echo "✓ Echomail posted successfully\n";
        return true;
    } else {
        echo "✗ Failed to post echomail\n";
        return false;
    }
}

// Main execution
$args = parseArgs($argv);

if (isset($args['help'])) {
    showUsage();
    exit(0);
}

try {
    // Initialize database
    Database::getInstance();
    
    if (isset($args['list-users'])) {
        listUsers();
        exit(0);
    }
    
    if (isset($args['list-areas'])) {
        listEchoAreas();
        exit(0);
    }
    
    // Validate required arguments
    $required = ['type', 'from', 'from-name', 'subject'];
    foreach ($required as $field) {
        if (!isset($args[$field])) {
            echo "Error: Missing required argument: --{$field}\n";
            showUsage();
            exit(1);
        }
    }
    
    // Validate message type
    if (!in_array($args['type'], ['netmail', 'echomail'])) {
        echo "Error: Invalid message type. Must be 'netmail' or 'echomail'\n";
        exit(1);
    }
    
    // Validate from address
    if (!validateFtnAddress($args['from'])) {
        echo "Error: Invalid from FTN address format: {$args['from']}\n";
        exit(1);
    }
    
    // Get user
    if (isset($args['user'])) {
        $user = getUserByUsername($args['user']);
        if (!$user) {
            echo "Error: User not found: {$args['user']}\n";
            exit(1);
        }
    } else {
        $user = getFirstUser();
        if (!$user) {
            echo "Error: No users found in database\n";
            exit(1);
        }
    }
    
    echo "Using user: {$user['username']} ({$user['fidonet_address']})\n";
    
    // Validate type-specific arguments
    if ($args['type'] === 'netmail') {
        validateNetmailArgs($args);
    } else {
        validateEchomailArgs($args);
    }
    
    // Read message text
    $messageText = readMessageText($args);
    if (empty(trim($messageText))) {
        echo "Error: Message text is empty\n";
        exit(1);
    }
    
    // Post the message
    if ($args['type'] === 'netmail') {
        $success = postNetmail($args, $user, $messageText);
    } else {
        $success = postEchomail($args, $user, $messageText);
    }
    
    exit($success ? 0 : 1);
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}