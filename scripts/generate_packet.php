#!/usr/bin/env php
<?php
/**
 * Standalone FidoNet Packet Generator
 *
 * Creates FTS-0001 compatible .pkt files for testing.
 * Does not require database or application config.
 */

function showUsage() {
    echo "FidoNet Packet Generator - Create test .pkt files\n";
    echo "=================================================\n\n";
    echo "Usage: php generate_packet.php [options]\n\n";
    echo "Options:\n";
    echo "  --from-addr=ADDR     Origin FTN address (required, e.g., 1:123/456)\n";
    echo "  --to-addr=ADDR       Destination FTN address (required, e.g., 1:234/567)\n";
    echo "  --from-name=NAME     Sender name (default: Test Sender)\n";
    echo "  --to-name=NAME       Recipient name (default: Test Recipient)\n";
    echo "  --subject=SUBJ       Message subject (default: Test Message)\n";
    echo "  --text=TEXT          Message body text (default: test content)\n";
    echo "  --text-file=PATH     Read message body from file\n";
    echo "  --echoarea=TAG       Create echomail for this area (adds AREA: line)\n";
    echo "  --output=PATH        Output file path (default: ./test_<timestamp>.pkt)\n";
    echo "  --help               Show this help message\n\n";
    echo "Examples:\n";
    echo "  # Simple netmail packet\n";
    echo "  php generate_packet.php --from-addr=1:123/456 --to-addr=1:234/567\n\n";
    echo "  # Netmail with custom content\n";
    echo "  php generate_packet.php --from-addr=1:123/456 --to-addr=1:234/567 \\\n";
    echo "      --from-name=\"John Doe\" --to-name=\"Jane Smith\" \\\n";
    echo "      --subject=\"Hello\" --text=\"This is a test message.\"\n\n";
    echo "  # Echomail packet\n";
    echo "  php generate_packet.php --from-addr=1:123/456 --to-addr=1:234/567 \\\n";
    echo "      --echoarea=GENERAL --subject=\"Test Post\"\n\n";
    echo "  # Read body from file\n";
    echo "  php generate_packet.php --from-addr=1:123/456 --to-addr=1:234/567 \\\n";
    echo "      --text-file=message.txt --output=outbound/test.pkt\n\n";
}

function parseAddress($addr) {
    // Parse FTN address: zone:net/node or zone:net/node.point
    $addr = trim($addr);

    // Handle zone:net/node.point format
    if (preg_match('/^(\d+):(\d+)\/(\d+)(?:\.(\d+))?$/', $addr, $matches)) {
        return [
            'zone' => (int)$matches[1],
            'net' => (int)$matches[2],
            'node' => (int)$matches[3],
            'point' => isset($matches[4]) ? (int)$matches[4] : 0,
        ];
    }

    return null;
}

function writePacketHeader($handle, $origAddr, $destAddr) {
    $now = time();

    // Standard FTS-0001 58-byte packet header
    $header = pack('vvvvvvvvvvvv',
        $origAddr['node'],       // 0-1:   Origin node
        $destAddr['node'],       // 2-3:   Destination node
        (int)date('Y', $now),    // 4-5:   Year
        (int)date('n', $now) - 1,// 6-7:   Month (0-based)
        (int)date('j', $now),    // 8-9:   Day
        (int)date('G', $now),    // 10-11: Hour
        (int)date('i', $now),    // 12-13: Minute
        (int)date('s', $now),    // 14-15: Second
        0,                       // 16-17: Baud rate
        2,                       // 18-19: Packet version (2)
        $origAddr['net'],        // 20-21: Origin net
        $destAddr['net']         // 22-23: Destination net
    );

    // Product code low (8 bytes)
    $header .= str_pad('', 8, "\0");

    // Product code high (2 bytes)
    $header .= str_pad('', 2, "\0");

    // FSC-39 zone information (4 bytes)
    $header .= pack('vv', $origAddr['zone'], $destAddr['zone']);

    // Password (8 bytes)
    $header .= str_pad('', 8, "\0");

    // Reserved (6 bytes)
    $header .= str_pad('', 6, "\0");

    // FSC-48 zone info (4 bytes)
    $header .= pack('vv', $origAddr['zone'], $destAddr['zone']);

    // Auxiliary net (2 bytes)
    $header .= str_pad('', 2, "\0");

    if (strlen($header) !== 58) {
        throw new Exception('Invalid packet header length: ' . strlen($header));
    }

    fwrite($handle, $header);
}

function writeMessage($handle, $origAddr, $destAddr, $fromName, $toName, $subject, $text, $echoarea = null) {
    // Message type (2 = stored message)
    fwrite($handle, pack('v', 2));

    // Message attributes
    $attrs = $echoarea ? 0x0000 : 0x0001; // 0x0001 = Private for netmail
    fwrite($handle, pack('v', $attrs));

    // Cost
    fwrite($handle, pack('v', 0));

    // Addresses in message header
    fwrite($handle, pack('vvvv',
        $origAddr['node'],
        $destAddr['node'],
        $origAddr['net'],
        $destAddr['net']
    ));

    // Date/time string (null-terminated, max 20 chars)
    $dateStr = date('d M y  H:i:s');
    fwrite($handle, str_pad($dateStr, 20, "\0"));

    // To name (null-terminated, max 36 chars)
    fwrite($handle, substr($toName, 0, 35) . "\0");

    // From name (null-terminated, max 36 chars)
    fwrite($handle, substr($fromName, 0, 35) . "\0");

    // Subject (null-terminated, max 72 chars)
    fwrite($handle, substr($subject, 0, 71) . "\0");

    // Message text with kludges
    $fullText = '';

    // Add AREA: line for echomail
    if ($echoarea) {
        $fullText .= "AREA: {$echoarea}\r";
    }

    // Add standard kludges
    $msgid = sprintf("%d:%d/%d.%d %08x",
        $origAddr['zone'], $origAddr['net'], $origAddr['node'], $origAddr['point'],
        time()
    );
    $fullText .= "\x01MSGID: {$msgid}\r";
    $fullText .= "\x01PID: PacketGenerator 1.0\r";
    $fullText .= "\x01CHRS: UTF-8 4\r";
    $fullText .= "\x01TZUTC: " . date('O') . "\r";

    // Add message body
    $fullText .= $text;

    // Ensure proper line endings
    $fullText = str_replace(["\r\n", "\n"], "\r", $fullText);

    // Add tearline and origin for echomail
    if ($echoarea) {
        $fullText .= "\r--- PacketGenerator 1.0\r";
        $fullText .= sprintf(" * Origin: Test System (%d:%d/%d)\r",
            $origAddr['zone'], $origAddr['net'], $origAddr['node']
        );
    }

    // Null-terminate message text
    fwrite($handle, $fullText . "\0");
}

// Parse command line arguments
$options = [
    'from-addr' => null,
    'to-addr' => null,
    'from-name' => 'Test Sender',
    'to-name' => 'Test Recipient',
    'subject' => 'Test Message',
    'text' => "This is a test message generated by PacketGenerator.\r\rHave a nice day!",
    'text-file' => null,
    'echoarea' => null,
    'output' => null,
    'help' => false,
];

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];

    if ($arg === '--help' || $arg === '-h') {
        $options['help'] = true;
    } elseif (preg_match('/^--(\w+(?:-\w+)*)=(.*)$/', $arg, $matches)) {
        $key = $matches[1];
        $value = $matches[2];
        if (array_key_exists($key, $options)) {
            $options[$key] = $value;
        } else {
            echo "Unknown option: --$key\n";
            exit(1);
        }
    }
}

if ($options['help']) {
    showUsage();
    exit(0);
}

// Validate required options
if (!$options['from-addr']) {
    echo "Error: --from-addr is required\n\n";
    showUsage();
    exit(1);
}

if (!$options['to-addr']) {
    echo "Error: --to-addr is required\n\n";
    showUsage();
    exit(1);
}

// Parse addresses
$fromAddr = parseAddress($options['from-addr']);
if (!$fromAddr) {
    echo "Error: Invalid from address format. Use zone:net/node (e.g., 1:123/456)\n";
    exit(1);
}

$toAddr = parseAddress($options['to-addr']);
if (!$toAddr) {
    echo "Error: Invalid to address format. Use zone:net/node (e.g., 1:123/456)\n";
    exit(1);
}

// Read text from file if specified
if ($options['text-file']) {
    if (!file_exists($options['text-file'])) {
        echo "Error: Text file not found: {$options['text-file']}\n";
        exit(1);
    }
    $options['text'] = file_get_contents($options['text-file']);
}

// Determine output path
$outputPath = $options['output'] ?? './test_' . time() . '.pkt';

// Create the packet
echo "Creating packet...\n";
echo "  From: {$options['from-addr']} ({$options['from-name']})\n";
echo "  To:   {$options['to-addr']} ({$options['to-name']})\n";
echo "  Subject: {$options['subject']}\n";
if ($options['echoarea']) {
    echo "  Echoarea: {$options['echoarea']}\n";
}
echo "  Output: $outputPath\n";

try {
    $handle = fopen($outputPath, 'wb');
    if (!$handle) {
        throw new Exception("Cannot create output file: $outputPath");
    }

    // Write packet header
    writePacketHeader($handle, $fromAddr, $toAddr);

    // Write message
    writeMessage($handle, $fromAddr, $toAddr,
        $options['from-name'], $options['to-name'],
        $options['subject'], $options['text'],
        $options['echoarea']
    );

    // Write packet terminator
    fwrite($handle, pack('v', 0));

    fclose($handle);

    $size = filesize($outputPath);
    echo "\nPacket created successfully!\n";
    echo "  File: $outputPath\n";
    echo "  Size: $size bytes\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
