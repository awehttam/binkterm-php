#!/usr/bin/env php
<?php
/**
 * Test script for sending crashmail to a remote binkp system
 * Usage: php test_crashmail.php <remote_ip> <to_address>
 * Example: php test_crashmail.php 146.120.73.67 21:1/100
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Binkp\Protocol\BinkpClient;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Binkp\Logger;
use BinktermPHP\Database;
use BinktermPHP\BinkdProcessor;

function showUsage() {
    echo "Usage: php test_crashmail.php <remote_ip> <to_address> [options]\n";
    echo "\n";
    echo "Arguments:\n";
    echo "  remote_ip     IP address of remote binkp server\n";
    echo "  to_address    Destination FTN address (e.g., 21:1/100)\n";
    echo "\n";
    echo "Options:\n";
    echo "  --port=PORT      Remote port (default: 24554)\n";
    echo "  --password=PASS  Password for authentication (use '-' for insecure)\n";
    echo "  --from=ADDRESS   Override sender address (default: system primary)\n";
    echo "  --to-name=NAME   Recipient name (default: 'Sysop')\n";
    echo "  --subject=TEXT   Subject line (default: 'Test crashmail')\n";
    echo "  --help           Show this help\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php test_crashmail.php 146.120.73.67 21:1/100\n";
    echo "  php test_crashmail.php 146.120.73.67 21:1/100 --password=-\n";
    echo "  php test_crashmail.php 146.120.73.67 1:153/150 --to-name=awehttam\n";
    echo "  php test_crashmail.php 192.168.1.100 21:1/100 --password=secret --port=24555\n";
    echo "\n";
}

// Parse arguments
if ($argc < 3 || in_array('--help', $argv)) {
    showUsage();
    exit(0);
}

$remoteIp = $argv[1];
$toAddress = $argv[2];

// Parse options
$options = [
    'port' => 24554,
    'password' => '-',
    'from' => null,
    'to-name' => 'Sysop',
    'subject' => 'Test crashmail from BinktermPHP'
];

for ($i = 3; $i < $argc; $i++) {
    if (preg_match('/^--(\w+)=(.+)$/', $argv[$i], $matches)) {
        $key = $matches[1];
        $value = $matches[2];
        if (isset($options[$key])) {
            $options[$key] = $value;
        }
    }
}

echo "=== BinktermPHP Crashmail Test ===\n";
echo "Remote: {$remoteIp}:{$options['port']}\n";
echo "To: {$toAddress}\n";
echo "Password: " . ($options['password'] === '-' ? '(insecure)' : '(provided)') . "\n";
echo "\n";

try {
    // Initialize config and database
    $config = BinkpConfig::getInstance();
    $db = Database::getInstance()->getPdo();

    // Get system info
    $systemName = $config->getSystemName();
    $sysopName = $config->getSystemSysop();
    $primaryAddress = $config->getSystemAddress();

    $fromAddress = $options['from'] ?? $primaryAddress;

    echo "From: {$sysopName} ({$fromAddress})\n";
    echo "System: {$systemName}\n";
    echo "\n";

    // Validate addresses
    if (!preg_match('/^\d+:\d+\/\d+(?:\.\d+)?(?:@\w+)?$/', $toAddress)) {
        throw new Exception("Invalid destination address format: {$toAddress}");
    }

    if (!preg_match('/^\d+:\d+\/\d+(?:\.\d+)?(?:@\w+)?$/', $fromAddress)) {
        throw new Exception("Invalid source address format: {$fromAddress}");
    }

    // Create test netmail message
    echo "Creating test netmail packet...\n";

    $messageText = "This is a test crashmail message sent from BinktermPHP.\n";
    $messageText .= "\n";
    $messageText .= "Timestamp: " . date('Y-m-d H:i:s') . "\n";
    $messageText .= "Remote IP: {$remoteIp}\n";
    $messageText .= "Connection: " . ($options['password'] === '-' ? 'Insecure' : 'Authenticated') . "\n";
    $messageText .= "\n";
    $messageText .= "If you receive this message, the binkp connection is working!\n";
    $messageText .= "\n";
    $messageText .= "--- BinktermPHP Test Script\n";

    // Create packet using BinkdProcessor
    $tempDir = sys_get_temp_dir();
    $packetFile = $tempDir . '/test_' . substr(uniqid(), -8) . '.pkt';

    // Prepare message data
    $message = [
        'from_address' => $fromAddress,
        'to_address' => $toAddress,
        'from_name' => $sysopName,
        'to_name' => $options['to-name'],
        'subject' => $options['subject'],
        'message_text' => $messageText,
        'date_written' => date('Y-m-d H:i:s'),
        'attributes' => 0x0001 | 0x0100, // CRASH | PRIVATE
        'message_id' => null,
        'kludge_lines' => null,
    ];

    // Use BinkdProcessor to create the packet
    $binkdProcessor = new BinkdProcessor();
    $binkdProcessor->createOutboundPacket([$message], $toAddress, $packetFile);

    if (!file_exists($packetFile)) {
        throw new Exception("Failed to create packet file");
    }

    $packetSize = filesize($packetFile);
    echo "✓ Packet created: " . basename($packetFile) . " ({$packetSize} bytes)\n";
    echo "\n";

    // Setup logger
    $logFile = __DIR__ . '/../data/logs/test_crashmail.log';
    $logger = new Logger($logFile, 'DEBUG', true);

    echo "Connecting to {$remoteIp}:{$options['port']}...\n";
    echo "(Check {$logFile} for detailed logs)\n";
    echo "\n";

    // Create socket and connect
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($socket === false) {
        throw new Exception('Failed to create socket: ' . socket_strerror(socket_last_error()));
    }

    $timeout = 30;
    socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $timeout, 'usec' => 0]);
    socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $timeout, 'usec' => 0]);

    $logger->log('INFO', "Connecting to {$remoteIp}:{$options['port']}...");
    if (!socket_connect($socket, $remoteIp, $options['port'])) {
        $error = socket_strerror(socket_last_error($socket));
        socket_close($socket);
        throw new Exception("Failed to connect: {$error}");
    }

    $logger->log('INFO', "Connected! Starting binkp session...");

    // Create BinkpSession
    $session = new \BinktermPHP\Binkp\Protocol\BinkpSession($socket, true, $config);
    $session->setLogger($logger);
    $session->setSessionType('crash_outbound');
    $session->setUplinkPassword($options['password']);

    // Add packet to send
    $session->addFileToSend($packetFile, basename($packetFile));

    // Perform handshake
    $session->handshake();
    $logger->log('INFO', "Handshake completed");

    // Process session (send/receive files)
    if (!$session->processSession()) {
        throw new Exception('Session processing failed');
    }

    echo "\n";
    echo "✓ SUCCESS! Crashmail sent successfully!\n";
    echo "\n";
    echo "Session details:\n";
    echo "  - Connected to: {$remoteIp}:{$options['port']}\n";
    echo "  - Remote system: " . ($session->getRemoteSystemName() ?: 'Unknown') . "\n";
    echo "  - Authentication: " . $session->getAuthMethod() . "\n";
    echo "  - Session type: " . $session->getSessionType() . "\n";
    echo "  - Packet sent: " . basename($packetFile) . "\n";
    echo "\n";

    $session->close();

    // Clean up packet file
    if (file_exists($packetFile)) {
        unlink($packetFile);
        echo "✓ Packet file cleaned up\n";
    }

    exit(0);

} catch (Exception $e) {
    echo "\n";
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    echo "\n";

    if (isset($packetFile) && file_exists($packetFile)) {
        unlink($packetFile);
    }

    exit(1);
}
