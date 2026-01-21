#!/usr/bin/env php
<?php
/**
 * Standalone Binkp Test Client
 *
 * Connects directly to a binkp server for testing and debugging.
 * Does not use the application's database or config files.
 */

// Frame command constants
const M_NUL  = 0;  // Site information
const M_ADR  = 1;  // List of addresses
const M_PWD  = 2;  // Session password
const M_FILE = 3;  // File information
const M_OK   = 4;  // Password accepted
const M_EOB  = 5;  // End of batch
const M_GOT  = 6;  // File received
const M_ERR  = 7;  // Error
const M_BSY  = 8;  // Busy
const M_GET  = 9;  // Get file
const M_SKIP = 10; // Skip file

$commandNames = [
    M_NUL  => 'M_NUL',
    M_ADR  => 'M_ADR',
    M_PWD  => 'M_PWD',
    M_FILE => 'M_FILE',
    M_OK   => 'M_OK',
    M_EOB  => 'M_EOB',
    M_GOT  => 'M_GOT',
    M_ERR  => 'M_ERR',
    M_BSY  => 'M_BSY',
    M_GET  => 'M_GET',
    M_SKIP => 'M_SKIP',
];

function showUsage() {
    echo "Binkp Test Client - Standalone binkp connection tester\n";
    echo "======================================================\n\n";
    echo "Usage: php binkp_test_client.php [options]\n\n";
    echo "Options:\n";
    echo "  --host=HOST        Remote host to connect to (required)\n";
    echo "  --port=PORT        Remote port (default: 24554)\n";
    echo "  --address=ADDR     Our FTN address (default: 1:1/0)\n";
    echo "  --password=PWD     Session password (default: empty)\n";
    echo "  --sysname=NAME     Our system name (default: Test System)\n";
    echo "  --sysop=NAME       Our sysop name (default: Test Sysop)\n";
    echo "  --location=LOC     Our location (default: Test Location)\n";
    echo "  --timeout=SEC      Connection timeout (default: 30)\n";
    echo "  --send-file=PATH   Send a file to the remote system\n";
    echo "  --verbose          Show detailed frame data\n";
    echo "  --help             Show this help message\n\n";
    echo "Examples:\n";
    echo "  php binkp_test_client.php --host=bbs.example.com --address=1:123/456\n";
    echo "  php binkp_test_client.php --host=localhost --port=24554 --password=secret\n";
    echo "  php binkp_test_client.php --host=bbs.example.com --send-file=packet.pkt\n\n";
}

function log_msg($message, $level = 'INFO') {
    $timestamp = date('H:i:s');
    echo "[$timestamp] [$level] $message\n";
}

function createFrame($command, $data = '') {
    $dataLen = strlen($data);
    $frameLen = $dataLen + 1; // +1 for command byte

    // Set high bit to indicate command frame
    $header = pack('n', $frameLen | 0x8000);

    return $header . chr($command) . $data;
}

function createDataFrame($data) {
    $dataLen = strlen($data);
    $header = pack('n', $dataLen); // No high bit for data frame
    return $header . $data;
}

function readFrame($socket, $timeout = 30) {
    global $commandNames;

    // Set socket timeout
    stream_set_timeout($socket, $timeout);

    // Read 2-byte header
    $header = fread($socket, 2);
    if ($header === false || strlen($header) < 2) {
        return null;
    }

    $headerVal = unpack('n', $header)[1];
    $isCommand = ($headerVal & 0x8000) !== 0;
    $length = $headerVal & 0x7FFF;

    if ($length === 0) {
        return ['is_command' => $isCommand, 'command' => null, 'data' => ''];
    }

    // Read frame data
    $data = '';
    $remaining = $length;
    while ($remaining > 0) {
        $chunk = fread($socket, $remaining);
        if ($chunk === false || strlen($chunk) === 0) {
            break;
        }
        $data .= $chunk;
        $remaining -= strlen($chunk);
    }

    if ($isCommand && strlen($data) > 0) {
        $command = ord($data[0]);
        $commandData = substr($data, 1);
        return [
            'is_command' => true,
            'command' => $command,
            'command_name' => $commandNames[$command] ?? "UNKNOWN($command)",
            'data' => $commandData
        ];
    }

    return ['is_command' => false, 'command' => null, 'data' => $data];
}

function sendFrame($socket, $command, $data = '') {
    global $commandNames;
    $frame = createFrame($command, $data);
    $written = fwrite($socket, $frame);
    $cmdName = $commandNames[$command] ?? "UNKNOWN($command)";
    log_msg("SENT: $cmdName" . ($data ? " [$data]" : ""));
    return $written;
}

function sendDataFrame($socket, $data) {
    $frame = createDataFrame($data);
    return fwrite($socket, $frame);
}

// Parse command line arguments
$options = [
    'host' => null,
    'port' => 24554,
    'address' => '1:1/0',
    'password' => '',
    'sysname' => 'Test System',
    'sysop' => 'Test Sysop',
    'location' => 'Test Location',
    'timeout' => 30,
    'send-file' => null,
    'verbose' => false,
    'help' => false,
];

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];

    if ($arg === '--help' || $arg === '-h') {
        $options['help'] = true;
    } elseif ($arg === '--verbose' || $arg === '-v') {
        $options['verbose'] = true;
    } elseif (preg_match('/^--(\w+(?:-\w+)*)=(.*)$/', $arg, $matches)) {
        $key = $matches[1];
        $value = $matches[2];
        if (array_key_exists($key, $options)) {
            $options[$key] = $value;
        } else {
            echo "Unknown option: --$key\n";
            exit(1);
        }
    } elseif (preg_match('/^--(\w+(?:-\w+)*)$/', $arg, $matches)) {
        $key = $matches[1];
        if (array_key_exists($key, $options)) {
            $options[$key] = true;
        }
    }
}

if ($options['help']) {
    showUsage();
    exit(0);
}

if (!$options['host']) {
    echo "Error: --host is required\n\n";
    showUsage();
    exit(1);
}

// Main client logic
log_msg("Binkp Test Client Starting");
log_msg("Connecting to {$options['host']}:{$options['port']}...");

$socket = @stream_socket_client(
    "tcp://{$options['host']}:{$options['port']}",
    $errno,
    $errstr,
    $options['timeout']
);

if (!$socket) {
    log_msg("Connection failed: $errstr ($errno)", 'ERROR');
    exit(1);
}

log_msg("Connected successfully!");
stream_set_timeout($socket, $options['timeout']);

// Send our system info
log_msg("Sending system information...");
sendFrame($socket, M_NUL, "SYS {$options['sysname']}");
sendFrame($socket, M_NUL, "ZYZ {$options['sysop']}");
sendFrame($socket, M_NUL, "LOC {$options['location']}");
sendFrame($socket, M_NUL, "VER BinkpTestClient/1.0 binkp/1.0");
sendFrame($socket, M_NUL, "TIME " . gmdate('D, d M Y H:i:s') . " UTC");

// Send our address
log_msg("Sending address: {$options['address']}");
sendFrame($socket, M_ADR, $options['address']);

// Read frames from server
log_msg("Waiting for server response...");

$authenticated = false;
$remoteAddress = null;
$gotRemoteAddress = false;
$sessionComplete = false;

while (!$sessionComplete && !feof($socket)) {
    $frame = readFrame($socket, $options['timeout']);

    if (!$frame) {
        log_msg("No frame received or timeout", 'WARNING');
        break;
    }

    if ($frame['is_command']) {
        $cmdName = $frame['command_name'];
        $data = $frame['data'];

        log_msg("RECV: $cmdName" . ($data ? " [$data]" : ""));

        switch ($frame['command']) {
            case M_NUL:
                // Info frame, just log it
                if ($options['verbose']) {
                    log_msg("  System info: $data", 'DEBUG');
                }
                break;

            case M_ADR:
                $remoteAddress = $data;
                $gotRemoteAddress = true;
                log_msg("Remote address: $remoteAddress");

                // Now send password
                log_msg("Sending password...");
                sendFrame($socket, M_PWD, $options['password']);
                break;

            case M_OK:
                log_msg("Authentication successful!", 'SUCCESS');
                $authenticated = true;

                // If we have a file to send, do it now
                if ($options['send-file'] && file_exists($options['send-file'])) {
                    $filePath = $options['send-file'];
                    $fileName = basename($filePath);
                    $fileSize = filesize($filePath);
                    $fileTime = filemtime($filePath);

                    log_msg("Sending file: $fileName ($fileSize bytes)");

                    // Send M_FILE
                    $fileInfo = "$fileName $fileSize $fileTime 0";
                    sendFrame($socket, M_FILE, $fileInfo);

                    // Send file data
                    $handle = fopen($filePath, 'rb');
                    $bytesSent = 0;
                    while (!feof($handle)) {
                        $chunk = fread($handle, 4096);
                        if ($chunk !== false && strlen($chunk) > 0) {
                            sendDataFrame($socket, $chunk);
                            $bytesSent += strlen($chunk);
                        }
                    }
                    fclose($handle);
                    log_msg("Sent $bytesSent bytes of file data");

                    // Wait for M_GOT
                    log_msg("Waiting for M_GOT...");
                } else {
                    // No file to send, send EOB
                    log_msg("No files to send, sending EOB");
                    sendFrame($socket, M_EOB);
                }
                break;

            case M_GOT:
                log_msg("File received confirmation: $data");
                // Send EOB after file is confirmed
                sendFrame($socket, M_EOB);
                break;

            case M_EOB:
                log_msg("End of batch received");
                if ($authenticated) {
                    log_msg("Session complete");
                    $sessionComplete = true;
                }
                break;

            case M_ERR:
                log_msg("Error from server: $data", 'ERROR');
                $sessionComplete = true;
                break;

            case M_BSY:
                log_msg("Server busy: $data", 'WARNING');
                $sessionComplete = true;
                break;

            case M_FILE:
                // Server wants to send us a file
                log_msg("Server sending file: $data");
                // Parse file info: name size time offset
                $parts = explode(' ', $data);
                if (count($parts) >= 3) {
                    $fileName = $parts[0];
                    $fileSize = (int)$parts[1];
                    log_msg("Receiving file $fileName ($fileSize bytes)...");

                    // For testing, we'll skip the file
                    sendFrame($socket, M_SKIP, $data);
                    log_msg("Skipped file (test mode)");
                }
                break;

            default:
                log_msg("Unhandled command: $cmdName", 'WARNING');
        }
    } else {
        // Data frame
        $dataLen = strlen($frame['data']);
        log_msg("RECV: DATA [$dataLen bytes]");
    }
}

fclose($socket);

log_msg("Connection closed");
log_msg("Session " . ($authenticated ? "succeeded" : "failed"));

exit($authenticated ? 0 : 1);
