#!/usr/bin/env php
<?php
/**
 * Test script for binkp plaintext authentication
 * Tests connection and plaintext password authentication only (no CRAM-MD5)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Binkp\Config\BinkpConfig;

if ($argc < 3) {
    echo "Usage: php test_plaintext_auth.php <remote_ip> <my_address> [--password=PASS]\n";
    echo "\n";
    echo "This script tests PLAINTEXT binkp authentication only.\n";
    echo "It will ignore any CRAM-MD5 challenges and force plaintext M_PWD.\n";
    echo "\n";
    echo "Arguments:\n";
    echo "  remote_ip          IP address of remote binkp server\n";
    echo "  my_address         FTN address to claim (e.g., 954:11/1)\n";
    echo "\n";
    echo "Options:\n";
    echo "  --password=PASS    Password to send (empty for insecure session)\n";
    echo "\n";
    echo "Example:\n";
    echo "  php test_plaintext_auth.php 192.168.1.100 954:11/1 --password=mysecret\n";
    exit(1);
}

$remoteIp = $argv[1];
$myAddress = $argv[2];
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
        $opcodeNames = [0 => 'M_NUL', 1 => 'M_ADR', 2 => 'M_PWD', 3 => 'M_FILE', 4 => 'M_OK', 5 => 'M_EOB', 6 => 'M_GOT', 7 => 'M_ERR'];
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

    // Read 2-byte header
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

echo "=== BinktermPHP Plaintext Authentication Test ===\n";
echo "Remote: {$remoteIp}:24554\n";
echo "My Address: {$myAddress}\n";
echo "Password: " . (empty($password) ? '(none - insecure session)' : '(provided)') . "\n";
echo "Mode: PLAINTEXT ONLY (ignoring CRAM-MD5)\n\n";

try {
    $config = BinkpConfig::getInstance();
    $systemName = $config->getSystemName();
    $sysopName = $config->getSystemSysop();

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
    sendFrame($stream, true, 1, $myAddress, true); // M_ADR
    usleep(50000);

    // Receive handshake
    log_msg("Receiving handshake...");
    $gotCramChallenge = false;
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
                    $gotCramChallenge = true;
                    log_msg("  Got CRAM-MD5 challenge (will be ignored)");
                }
                break;

            case 1: // M_ADR
                log_msg("  Got M_ADR: {$frame['data']}");

                // Send plaintext M_PWD after receiving M_ADR
                if (!$sentPwd) {
                    log_msg("  Sending plaintext M_PWD (password_len=" . strlen($password) . ")...");
                    sendFrame($stream, true, 2, $password, true); // M_PWD with plaintext password
                    $sentPwd = true;
                }
                break;

            case 4: // M_OK
                log_msg("✓ Got M_OK: {$frame['data']}\n");
                $gotOk = true;
                break;

            case 7: // M_ERR
                throw new Exception("Remote error: {$frame['data']}");
        }
    }

    if (!$gotOk) {
        throw new Exception("Handshake timeout - no M_OK received");
    }

    // Send M_EOB to cleanly end the session
    log_msg("Sending M_EOB to end session...");
    sendFrame($stream, true, 5, '', true); // M_EOB
    usleep(100000);

    fclose($stream);

    echo "\n=== SUCCESS ===\n";
    echo "Plaintext authentication succeeded!\n";
    if ($gotCramChallenge) {
        echo "Note: Remote offered CRAM-MD5 but plaintext was used instead.\n";
    }
    exit(0);

} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    if (isset($stream)) {
        @fclose($stream);
    }
    exit(1);
}
