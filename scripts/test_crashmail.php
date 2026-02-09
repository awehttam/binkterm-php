#!/usr/bin/env php
<?php
/**
 * Test script for sending crashmail to a remote binkp system
 * Simplified stream-based implementation
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Database;
use BinktermPHP\BinkdProcessor;

if ($argc < 3) {
    echo "Usage: php test_crashmail.php <remote_ip> <to_address> [--password=PASS]\n";
    exit(1);
}

$remoteIp = $argv[1];
$toAddress = $argv[2];
$password = '';

// Parse password option
for ($i = 3; $i < $argc; $i++) {
    if (preg_match('/^--password=(.*)$/', $argv[$i], $matches)) {
        $password = $matches[1];
    }
}

function log_msg($msg) {
    echo "[" . date('H:i:s') . "] $msg\n";
}

function sendFrame($stream, $isCommand, $opcode, $data, $debug = false) {
    $dataLen = strlen($data);

    // Correct binkp format: 2-byte length with command bit, then command byte, then data
    if ($isCommand) {
        $lengthWithFlag = 0x8000 | ($dataLen + 1); // +1 for command byte
        $frame = pack('n', $lengthWithFlag) . chr($opcode) . $data;
    } else {
        $lengthWithFlag = $dataLen;
        $frame = pack('n', $lengthWithFlag) . $data;
    }

    if ($debug) {
        $opcodeNames = [0 => 'M_NUL', 1 => 'M_ADR', 2 => 'M_PWD', 3 => 'M_FILE', 4 => 'M_OK', 5 => 'M_EOB', 6 => 'M_GOT'];
        $name = $opcodeNames[$opcode] ?? "CMD_{$opcode}";
        $hex = bin2hex(substr($frame, 0, min(20, strlen($frame))));
        $dataPreview = strlen($data) > 30 ? substr($data, 0, 30) . '...' : $data;
        $lengthWithFlag = $isCommand ? (0x8000 | ($dataLen + 1)) : $dataLen;
        echo "    → {$name}: length_with_flag=0x" . sprintf('%04X', $lengthWithFlag) . " opcode={$opcode} datalen={$dataLen} hex=[{$hex}...] data=\"{$dataPreview}\"\n";
    }

    $written = fwrite($stream, $frame);
    fflush($stream);

    if ($written != strlen($frame)) {
        throw new Exception("Failed to write frame");
    }

    return true;
}

function readFrame($stream, $timeout = 5) {
    // Check if there's data available first
    $read = [$stream];
    $write = null;
    $except = null;
    $result = stream_select($read, $write, $except, $timeout, 0);

    if ($result === false) {
        throw new Exception("stream_select failed");
    }

    if ($result === 0) {
        // Timeout - no data available
        return null;
    }

    // Data is available, read it
    stream_set_timeout($stream, $timeout);

    // Read 2-byte header (correct format!)
    $header = fread($stream, 2);
    if (strlen($header) < 2) {
        return null;
    }

    // Parse length with command bit
    $lengthWithFlag = unpack('n', $header)[1];
    $isCommand = ($lengthWithFlag & 0x8000) !== 0;
    $length = $lengthWithFlag & 0x7FFF;

    $opcode = 0;
    $data = '';

    if ($isCommand && $length > 0) {
        // Read command byte
        $cmdByte = fread($stream, 1);
        if (strlen($cmdByte) < 1) {
            return null;
        }
        $opcode = ord($cmdByte);
        $length--; // Remaining data length

        if ($length > 0) {
            $data = fread($stream, $length);
            if (strlen($data) < $length) {
                return null;
            }
        }
    } elseif (!$isCommand && $length > 0) {
        // Data frame
        $data = fread($stream, $length);
        if (strlen($data) < $length) {
            return null;
        }
    }

    return ['isCommand' => $isCommand, 'opcode' => $opcode, 'data' => $data];
}

function computeCramDigest($challenge, $password) {
    return hash_hmac('md5', hex2bin($challenge), $password);
}

echo "=== BinktermPHP Crashmail Test ===\n";
echo "Remote: {$remoteIp}:24554\n";
echo "To: {$toAddress}\n";
echo "Password: " . (empty($password) ? '(insecure)' : '(provided)') . "\n\n";

try {
    $config = BinkpConfig::getInstance();
    $systemName = $config->getSystemName();
    $sysopName = $config->getSystemSysop();
    $primaryAddress = $config->getSystemAddress();
    $myAddresses = $config->getMyAddresses();
    $myAddresses[] = $primaryAddress;

    // Create packet
    log_msg("Creating packet...");
    $tempDir = sys_get_temp_dir();
    $packetFile = $tempDir . '/test_' . substr(uniqid(), -8) . '.pkt';

    $message = [
        'from_address' => $primaryAddress,
        'to_address' => $toAddress,
        'from_name' => $sysopName,
        'to_name' => 'Sysop',
        'subject' => 'Test crashmail',
        'message_text' => "Test message from BinktermPHP\n\n--- BinktermPHP Test",
        'date_written' => date('Y-m-d H:i:s'),
        'attributes' => 0x0001 | 0x0100,
        'message_id' => null,
        'kludge_lines' => null,
    ];

    $processor = new BinkdProcessor();
    $processor->createOutboundPacket([$message], $toAddress, $packetFile);

    if (!file_exists($packetFile)) {
        throw new Exception("Failed to create packet");
    }

    $packetSize = filesize($packetFile);
    $packetName = basename($packetFile);
    log_msg("✓ Packet: {$packetName} ({$packetSize} bytes)");

    // Connect
    log_msg("Connecting...");
    $stream = @stream_socket_client(
        "tcp://{$remoteIp}:24554",
        $errno,
        $errstr,
        30,
        STREAM_CLIENT_CONNECT
    );

    if (!$stream) {
        throw new Exception("Connection failed: {$errstr}");
    }

    stream_set_blocking($stream, true);
    log_msg("✓ Connected\n");

    // Small delay to ensure connection is fully established
    usleep(100000); // 100ms

    // Send handshake with delays
    log_msg("Sending handshake...");
    sendFrame($stream, true, 0, "SYS {$systemName}", true); // M_NUL
    usleep(50000); // 50ms delay
    sendFrame($stream, true, 0, "ZYZ {$sysopName}", true);
    usleep(50000);
    sendFrame($stream, true, 0, "LOC Unknown", true);
    usleep(50000);
    sendFrame($stream, true, 0, "VER BinktermPHP/Test binkp/1.0", true);
    usleep(50000);
    sendFrame($stream, true, 0, "TIME " . gmdate('D, d M Y H:i:s') . " UTC", true);
    usleep(50000);
    sendFrame($stream, true, 1, implode(' ', $myAddresses), true); // M_ADR
    usleep(50000);

    // Receive handshake
    log_msg("Receiving handshake...");
    $cramChallenge = null;
    $gotOk = false;
    $sentPwd = false;

    $startTime = time();
    $frameCount = 0;
    while (!$gotOk && (time() - $startTime) < 30) {
        $frame = readFrame($stream, 2); // Shorter timeout

        if (!$frame) {
            // Show we're waiting every 10 attempts
            if ($frameCount % 10 == 0) {
                echo "  [Still waiting... " . (time() - $startTime) . "s elapsed]\n";
            }
            $frameCount++;
            usleep(100000); // 100ms
            continue;
        }

        $frameCount = 0; // Reset counter when we get data
        echo "  Received frame: command=" . ($frame['isCommand'] ? 'yes' : 'no') . " opcode=" . $frame['opcode'] . " len=" . strlen($frame['data']) . "\n";

        if (!$frame['isCommand']) {
            continue;
        }

        switch ($frame['opcode']) {
            case 0: // M_NUL
                if (preg_match('/CRAM-MD5-([0-9a-fA-F]+)/', $frame['data'], $m)) {
                    $cramChallenge = $m[1];
                    log_msg("  Got challenge");
                }
                break;

            case 1: // M_ADR
                log_msg("  Got address");

                // Send M_PWD after receiving M_ADR
                if (!$sentPwd) {
                    $digest = null;
                    if ($cramChallenge) {
                        $digest = computeCramDigest($cramChallenge, $password);
                        $pwdData = "CRAM-MD5-{$digest}";
                        log_msg("  Sending M_PWD (challenge={$cramChallenge}, digest={$digest})...");
                    } else {
                        $pwdData = $password;
                        log_msg("  Sending M_PWD (plain text, password_len=" . strlen($password) . ")...");
                    }

                    sendFrame($stream, true, 2, $pwdData, true); // M_PWD with debug
                    $sentPwd = true;
                }
                break;

            case 4: // M_OK
                log_msg("✓ Got M_OK\n");
                $gotOk = true;
                break;

            case 7: // M_ERR
                throw new Exception("Remote error: {$frame['data']}");
        }
    }

    if (!$gotOk) {
        throw new Exception("Handshake timeout");
    }

    // Send file
    log_msg("Sending file...");
    $timestamp = filemtime($packetFile);
    sendFrame($stream, true, 3, "{$packetName} {$packetSize} {$timestamp} 0"); // M_FILE

    $handle = fopen($packetFile, 'rb');
    while (!feof($handle)) {
        $chunk = fread($handle, 4096);
        if (strlen($chunk) > 0) {
            sendFrame($stream, false, 0, $chunk); // M_DATA
        }
    }
    fclose($handle);

    sendFrame($stream, true, 5, ''); // M_EOB
    log_msg("✓ File sent");

    // Wait for confirmation
    log_msg("Waiting for M_GOT...");
    $gotConfirm = false;
    $startTime = time();

    while (!$gotConfirm && (time() - $startTime) < 30) {
        $frame = readFrame($stream, 5);

        if (!$frame) {
            continue;
        }

        if ($frame['isCommand']) {
            if ($frame['opcode'] == 6) { // M_GOT
                log_msg("✓ Got M_GOT\n");
                $gotConfirm = true;
            } elseif ($frame['opcode'] == 5) { // M_EOB
                log_msg("✓ Got M_EOB");
            }
        }
    }

    if (!$gotConfirm) {
        throw new Exception("No M_GOT received");
    }

    fclose($stream);
    unlink($packetFile);

    echo "\n=== SUCCESS ===\n";
    echo "Crashmail sent successfully!\n";
    exit(0);

} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    if (isset($stream)) {
        @fclose($stream);
    }
    if (isset($packetFile) && file_exists($packetFile)) {
        @unlink($packetFile);
    }
    exit(1);
}
