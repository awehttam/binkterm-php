#!/usr/bin/env php
<?php
/**
 * Binkp Test Client
 *
 * Connects to a binkp server as an arbitrary FTN address, for exercising
 * this system's hub/point (downlink) support end-to-end: authenticate as a
 * registered point/node (plaintext or CRAM-MD5), receive whatever the
 * server pushes (dumping received .pkt contents the same way the /binkp
 * admin queue viewer does), and optionally compose and send a test netmail.
 *
 * Unlike the original version of this script, this now requires the app's
 * autoloader, database connection, and config/binkp.json to be reachable
 * (it reuses BinkpFrame for wire framing, PacketInspector for packet
 * dumps, and BinkdProcessor::createOutboundPacket() for --compose-netmail).
 * It does not read or write anything else app-specific - the connection
 * address/port/credentials are always CLI-supplied, not looked up from
 * config, so it can still impersonate an arbitrary point/node.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Binkp\Protocol\BinkpFrame;
use BinktermPHP\Binkp\Protocol\PacketInspector;
use BinktermPHP\BinkdProcessor;

function showUsage()
{
    echo "Binkp Test Client - test connections to BinktermPHP as a point/downlink\n";
    echo "=========================================================================\n\n";
    echo "Usage: php binkp_test_client.php [options]\n\n";
    echo "Connection:\n";
    echo "  --host=HOST          Remote host to connect to (required)\n";
    echo "  --port=PORT          Remote port (default: 24554)\n";
    echo "  --address=ADDR       Our (test point's) FTN address (default: 1:999/999)\n";
    echo "  --password=PWD       Session password (default: empty)\n";
    echo "  --sysname=NAME       Our system name (default: Test System)\n";
    echo "  --sysop=NAME         Our sysop name (default: Test Sysop)\n";
    echo "  --location=LOC       Our location (default: Test Location)\n";
    echo "  --timeout=SEC        Connection/session timeout (default: 30)\n";
    echo "  --no-cram            Force plaintext password even if CRAM-MD5 is offered\n";
    echo "  --verbose            Show detailed frame data\n\n";
    echo "Sending:\n";
    echo "  --send-file=PATH     Send an existing file (e.g. a .pkt) as-is\n";
    echo "  --compose-netmail    Build and send a one-message test netmail packet\n";
    echo "  --to=ADDR            Destination address (required with --compose-netmail)\n";
    echo "  --to-name=NAME       Netmail To: name (default: sysop)\n";
    echo "  --subject=TEXT       Netmail subject (default: Test message)\n";
    echo "  --body=TEXT          Netmail body text\n";
    echo "  --body-file=PATH     Read netmail body from a file instead of --body\n\n";
    echo "Receiving:\n";
    echo "  --save-dir=PATH      Where to save received files (default: data/binkp_test_client/)\n";
    echo "  --no-dump            Don't print packet header/message dump for received .pkt files\n\n";
    echo "  --help               Show this help message\n\n";
    echo "Examples:\n";
    echo "  php binkp_test_client.php --host=localhost --address=1:153/149.1 --password=secret\n";
    echo "  php binkp_test_client.php --host=localhost --address=1:153/149.1 --password=secret \\\n";
    echo "      --compose-netmail --to=1:1/1 --subject=\"Hi\" --body=\"Test from a point\"\n";
    echo "  php binkp_test_client.php --host=localhost --address=1:153/149.1 --password=secret --no-cram\n\n";
}

function log_msg($message, $level = 'INFO')
{
    $timestamp = date('H:i:s');
    echo "[$timestamp] [$level] $message\n";
}

/**
 * Parse "OPT CRAM-MD5-<hex>" out of an M_NUL payload, if present.
 */
function parseCramChallenge(string $nulData): ?string
{
    if (preg_match('/CRAM-MD5-([0-9a-fA-F]+)/', $nulData, $m)) {
        return strtolower($m[1]);
    }
    return null;
}

function computeCramDigest(string $challengeHex, string $password): string
{
    return hash_hmac('md5', hex2bin($challengeHex), $password);
}

/**
 * Print a packet dump in the same shape as the /binkp admin queue viewer.
 */
function dumpPacket(string $filepath): void
{
    $result = PacketInspector::inspect($filepath);
    if (empty($result['success'])) {
        log_msg("Packet dump failed: " . ($result['error'] ?? 'unknown error'), 'WARNING');
        return;
    }

    $p = $result['packet'];
    echo "\n--- Packet Header: " . basename($filepath) . " ---\n";
    echo "  From:           {$p['orig_address']}\n";
    echo "  To:             {$p['dest_address']}\n";
    echo "  Date:           {$p['created']}\n";
    echo "  Size:           {$p['file_size']} bytes\n";
    echo "  Packet Version: {$p['packet_version']}\n";
    echo "  Product Code:   {$p['product_code']}\n";
    echo "  Password:       " . ($p['has_password'] ? 'yes' : 'none') . "\n";

    $messages = $result['messages'] ?? [];
    echo "\n  Messages: " . count($messages) . "\n";
    foreach ($messages as $i => $m) {
        $flags = empty($m['flags']) ? '' : ' [' . implode(',', $m['flags']) . ']';
        printf(
            "    #%d  %s -> %s  \"%s\"  %s%s\n",
            $i + 1,
            $m['from'],
            $m['to'],
            $m['subject'],
            $m['date'],
            $flags
        );
    }
    echo "\n";
}

// Parse command line arguments
$options = [
    'host' => null,
    'port' => 24554,
    'address' => '1:999/999',
    'password' => '',
    'sysname' => 'Test System',
    'sysop' => 'Test Sysop',
    'location' => 'Test Location',
    'timeout' => 30,
    'no-cram' => false,
    'send-file' => null,
    'compose-netmail' => false,
    'to' => null,
    'to-name' => 'sysop',
    'subject' => 'Test message',
    'body' => 'This is a test message from binkp_test_client.php.',
    'body-file' => null,
    'save-dir' => null,
    'no-dump' => false,
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

if (!$options['host']) {
    echo "Error: --host is required\n\n";
    showUsage();
    exit(1);
}

if ($options['compose-netmail'] && !$options['to']) {
    echo "Error: --compose-netmail requires --to=ADDR\n\n";
    exit(1);
}

$saveDir = $options['save-dir'] ?: (__DIR__ . '/../data/binkp_test_client');
if (!is_dir($saveDir)) {
    mkdir($saveDir, 0755, true);
}

// Build the outbound file list up front (so we can fail fast on bad input
// before even opening the socket).
$outboundFiles = [];
if ($options['send-file']) {
    if (!file_exists($options['send-file'])) {
        echo "Error: --send-file path does not exist: {$options['send-file']}\n";
        exit(1);
    }
    $outboundFiles[] = $options['send-file'];
}
if ($options['compose-netmail']) {
    $body = $options['body'];
    if ($options['body-file']) {
        if (!file_exists($options['body-file'])) {
            echo "Error: --body-file path does not exist: {$options['body-file']}\n";
            exit(1);
        }
        $body = file_get_contents($options['body-file']);
    }

    $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'binkp_test_client_' . uniqid('', true) . '.pkt';
    $message = [
        'from_address' => $options['address'],
        'to_address' => $options['to'],
        'from_name' => $options['sysop'],
        'to_name' => $options['to-name'],
        'subject' => $options['subject'],
        'message_text' => $body,
        'date_written' => date('Y-m-d H:i:s'),
        'attributes' => 0x0001, // private/netmail
        'is_echomail' => false,
    ];

    log_msg("Composing test netmail: {$options['address']} -> {$options['to']} \"{$options['subject']}\"");
    (new BinkdProcessor())->createOutboundPacket([$message], $options['to'], $tmpPath);
    $outboundFiles[] = $tmpPath;
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

function sendCmd($socket, int $command, string $data = ''): void
{
    BinkpFrame::createCommand($command, $data)->writeToSocket($socket);
}

function sendData($socket, string $data): void
{
    BinkpFrame::createData($data)->writeToSocket($socket);
}

// Send our system info + address
log_msg("Sending system information...");
sendCmd($socket, BinkpFrame::M_NUL, "SYS {$options['sysname']}");
sendCmd($socket, BinkpFrame::M_NUL, "ZYZ {$options['sysop']}");
sendCmd($socket, BinkpFrame::M_NUL, "LOC {$options['location']}");
sendCmd($socket, BinkpFrame::M_NUL, "VER BinkpTestClient/2.0 binkp/1.1");
sendCmd($socket, BinkpFrame::M_NUL, "TIME " . gmdate('D, d M Y H:i:s') . " UTC");

log_msg("Sending address: {$options['address']}");
sendCmd($socket, BinkpFrame::M_ADR, $options['address']);

// ── Handshake phase ──────────────────────────────────────────────────────
$cramChallenge = null;
$sentPassword = false;
$authenticated = false;
$handshakeFailed = false;
$deadline = time() + (int)$options['timeout'];

while (!$authenticated && !$handshakeFailed && time() < $deadline) {
    $frame = BinkpFrame::parseFromSocket($socket, true);
    if ($frame === null) {
        $diag = BinkpFrame::getLastReadDiagnostics();
        if (($diag['reason'] ?? null) === 'eof') {
            log_msg("Connection closed during handshake", 'ERROR');
            $handshakeFailed = true;
            break;
        }
        usleep(50000);
        continue;
    }

    if (!$frame->isCommand()) {
        continue;
    }

    $cmd = $frame->getCommand();
    $data = $frame->getData();
    if ($options['verbose']) {
        log_msg("RECV: " . ($data !== '' ? "cmd={$cmd} [{$data}]" : "cmd={$cmd}"));
    }

    switch ($cmd) {
        case BinkpFrame::M_NUL:
            $challenge = parseCramChallenge($data);
            if ($challenge !== null) {
                $cramChallenge = $challenge;
                log_msg("Server offers CRAM-MD5 authentication");
            }
            if ($options['verbose']) {
                log_msg("  System info: $data", 'DEBUG');
            }
            break;

        case BinkpFrame::M_ADR:
            log_msg("Remote address(es): $data");
            if (!$sentPassword) {
                if ($cramChallenge !== null && !$options['no-cram']) {
                    $digest = computeCramDigest($cramChallenge, $options['password']);
                    sendCmd($socket, BinkpFrame::M_PWD, "CRAM-MD5-{$digest}");
                    log_msg("Sending CRAM-MD5 password response...");
                } else {
                    sendCmd($socket, BinkpFrame::M_PWD, $options['password']);
                    log_msg($cramChallenge !== null
                        ? "Sending plaintext password (--no-cram forced)..."
                        : "Sending plaintext password...");
                }
                $sentPassword = true;
            }
            break;

        case BinkpFrame::M_OK:
            log_msg("Authentication successful! ($data)", 'SUCCESS');
            $authenticated = true;
            break;

        case BinkpFrame::M_ERR:
            log_msg("Authentication failed: $data", 'ERROR');
            $handshakeFailed = true;
            break;

        case BinkpFrame::M_BSY:
            log_msg("Server busy: $data", 'ERROR');
            $handshakeFailed = true;
            break;

        default:
            log_msg("Unexpected command during handshake: $cmd", 'WARNING');
    }
}

if (!$authenticated) {
    log_msg("Handshake did not complete", 'ERROR');
    fclose($socket);
    exit(1);
}

// ── File transfer phase ──────────────────────────────────────────────────
$haveSentEob = false;
$haveReceivedEob = false;
$sentAllFiles = false;
$receivedFiles = [];
$sentFiles = [];

$currentSendIndex = 0;
$waitingForGot = null; // filename we're waiting to hear M_GOT for

/** @var resource|null $recvHandle */
$recvHandle = null;
$recvMeta = null; // ['name'=>, 'size'=>, 'received'=>, 'tmp_path'=>]

function sendNextOutboundFile($socket, array $outboundFiles, int &$index, ?string &$waitingForGot, array &$sentFiles): bool
{
    if ($index >= count($outboundFiles)) {
        return false;
    }
    $path = $outboundFiles[$index];
    $index++;

    $filename = basename($path);
    $size = filesize($path);
    $mtime = filemtime($path);

    log_msg("Sending file: $filename ($size bytes)");
    sendCmd($socket, BinkpFrame::M_FILE, "$filename $size $mtime 0");

    $handle = fopen($path, 'rb');
    $sent = 0;
    while (!feof($handle)) {
        $chunk = fread($handle, 4096);
        if ($chunk === false || $chunk === '') {
            break;
        }
        sendData($socket, $chunk);
        $sent += strlen($chunk);
    }
    fclose($handle);
    log_msg("Sent $sent bytes of file data for $filename");

    $waitingForGot = $filename;
    $sentFiles[] = $filename;
    return true;
}

// Kick off sending, if we have anything queued.
if (!empty($outboundFiles)) {
    sendNextOutboundFile($socket, $outboundFiles, $currentSendIndex, $waitingForGot, $sentFiles);
} else {
    sendCmd($socket, BinkpFrame::M_EOB);
    $haveSentEob = true;
    log_msg("Nothing to send, sent EOB");
}

$sessionTimeout = max(30, (int)$options['timeout']);
$loopStart = time();
$lastActivity = time();
$idleCloseGraceSeconds = 3;
$readyToCloseSince = null;
$terminated = false;
// Bound how many times we'll reply to a received EOB. Real binkd needs at
// most one extra (normally-empty) round after the first exchange before it
// closes on its own (see src/Binkp/CLAUDE.md); replying unconditionally
// forever risks an EOB ping-pong with any peer that also always-replies
// (observed in testing) since neither side ever falls silent first.
$eobRepliesSent = 0;
$maxEobReplies = 3;

while (!$terminated) {
    $hasActiveTransfer = $recvMeta !== null;
    $inactivity = time() - $lastActivity;

    // Once both EOBs are done and nothing is mid-transfer, give the peer a
    // few seconds to close first (matches BinkpSession's documented binkd
    // interop behavior - closing first ourselves can get logged as a failed
    // session on some binkd builds even though the transfer succeeded).
    if ($haveSentEob && $haveReceivedEob && !$hasActiveTransfer && $waitingForGot === null) {
        if ($readyToCloseSince === null) {
            log_msg("EOB exchange complete, waiting up to {$idleCloseGraceSeconds}s for peer to close first", 'DEBUG');
            $readyToCloseSince = time();
        } elseif (time() - $readyToCloseSince >= $idleCloseGraceSeconds) {
            log_msg("Peer did not close, closing ourselves");
            break;
        }
    } else {
        $readyToCloseSince = null;
    }

    if (!$hasActiveTransfer && (time() - $loopStart) >= $sessionTimeout && $readyToCloseSince === null) {
        log_msg("Session timeout waiting for EOB exchange to complete", 'WARNING');
        break;
    }
    if ($inactivity >= $sessionTimeout) {
        log_msg("No activity for {$inactivity}s, closing", 'WARNING');
        break;
    }

    $frame = BinkpFrame::parseFromSocket($socket, true);
    if ($frame === null) {
        $diag = BinkpFrame::getLastReadDiagnostics();
        if (($diag['reason'] ?? null) === 'eof') {
            if ($haveSentEob && $haveReceivedEob) {
                log_msg("Peer closed the connection after EOB exchange - session complete");
            } else {
                log_msg("Peer closed the connection before EOB exchange completed", 'WARNING');
            }
            break;
        }
        usleep(100000);
        continue;
    }

    $lastActivity = time();
    // Don't let a redundant EOB we've already stopped replying to (see
    // $maxEobReplies above) keep restarting the close-grace countdown -
    // otherwise a looping peer would keep us open indefinitely even though
    // we've deliberately gone silent on our end.
    $isIgnoredEobPing = $frame->isCommand()
        && $frame->getCommand() === BinkpFrame::M_EOB
        && $eobRepliesSent >= $maxEobReplies;
    if (!$isIgnoredEobPing) {
        $readyToCloseSince = null;
    }

    if ($frame->isCommand()) {
        $cmd = $frame->getCommand();
        $data = $frame->getData();
        if ($options['verbose']) {
            log_msg("RECV: cmd={$cmd}" . ($data !== '' ? " [{$data}]" : ""));
        }

        switch ($cmd) {
            case BinkpFrame::M_FILE:
                // Server is pushing us a file.
                $parts = explode(' ', $data, 4);
                $recvName = basename($parts[0] ?? 'unknown.pkt');
                $recvSize = isset($parts[1]) ? (int)$parts[1] : 0;
                $recvTime = isset($parts[2]) ? (int)$parts[2] : time();

                log_msg("Server sending file: $recvName ($recvSize bytes)");
                $tmpPath = $saveDir . DIRECTORY_SEPARATOR . $recvName . '.tmp';
                $recvHandle = fopen($tmpPath, 'wb');
                $recvMeta = ['name' => $recvName, 'size' => $recvSize, 'time' => $recvTime, 'received' => 0, 'tmp_path' => $tmpPath];

                if ($recvSize === 0) {
                    // Zero-byte file - nothing more to receive, confirm immediately.
                    fclose($recvHandle);
                    finishReceivedFile($recvMeta, $saveDir, $socket, $options, $receivedFiles);
                    $recvHandle = null;
                    $recvMeta = null;
                }
                break;

            case BinkpFrame::M_GOT:
                log_msg("File confirmed by peer: $data");
                $waitingForGot = null;
                // Send the next queued file, if any; otherwise we're done sending.
                if (!sendNextOutboundFile($socket, $outboundFiles, $currentSendIndex, $waitingForGot, $sentFiles)) {
                    if (!$haveSentEob) {
                        sendCmd($socket, BinkpFrame::M_EOB);
                        $haveSentEob = true;
                        log_msg("All files sent, sent EOB");
                    }
                }
                break;

            case BinkpFrame::M_EOB:
                log_msg("Received EOB");
                $haveReceivedEob = true;
                if ($eobRepliesSent < $maxEobReplies) {
                    sendCmd($socket, BinkpFrame::M_EOB);
                    $eobRepliesSent++;
                    if (!$haveSentEob) {
                        $haveSentEob = true;
                    }
                } else {
                    log_msg("Already replied to EOB {$maxEobReplies} time(s), not replying again (peer appears to be looping)", 'WARNING');
                }
                break;

            case BinkpFrame::M_ERR:
                log_msg("Error from server: $data", 'ERROR');
                $terminated = true;
                break;

            case BinkpFrame::M_BSY:
                log_msg("Server busy: $data", 'WARNING');
                $terminated = true;
                break;

            case BinkpFrame::M_NUL:
                if ($options['verbose']) {
                    log_msg("M_NUL during transfer: $data", 'DEBUG');
                }
                break;

            default:
                log_msg("Unhandled command: $cmd", 'WARNING');
        }
    } else {
        // Data frame - part of a file we're receiving.
        $chunk = $frame->getData();
        if ($recvHandle !== null && $recvMeta !== null) {
            fwrite($recvHandle, $chunk);
            $recvMeta['received'] += strlen($chunk);

            if ($recvMeta['received'] >= $recvMeta['size']) {
                fclose($recvHandle);
                finishReceivedFile($recvMeta, $saveDir, $socket, $options, $receivedFiles);
                $recvHandle = null;
                $recvMeta = null;
            }
        } else {
            log_msg("Received unexpected data frame (" . strlen($chunk) . " bytes) with no active file", 'WARNING');
        }
    }
}

if ($recvHandle !== null) {
    fclose($recvHandle);
}

fclose($socket);

log_msg("Connection closed");
log_msg("Files sent: " . count($sentFiles) . (empty($sentFiles) ? '' : ' (' . implode(', ', $sentFiles) . ')'));
log_msg("Files received: " . count($receivedFiles) . (empty($receivedFiles) ? '' : ' (' . implode(', ', $receivedFiles) . ')'));
log_msg("Session " . (($haveSentEob && $haveReceivedEob) ? "succeeded" : "did not complete cleanly"));

exit(($haveSentEob && $haveReceivedEob) ? 0 : 1);

/**
 * Finalize a fully-received file: rename from .tmp, send M_GOT, and dump
 * .pkt contents unless --no-dump was given.
 */
function finishReceivedFile(array $meta, string $saveDir, $socket, array $options, array &$receivedFiles): void
{
    $finalPath = $saveDir . DIRECTORY_SEPARATOR . $meta['name'];
    rename($meta['tmp_path'], $finalPath);

    log_msg("Received file: {$meta['name']} ({$meta['received']} bytes) -> $finalPath");
    sendCmd($socket, BinkpFrame::M_GOT, "{$meta['name']} {$meta['size']} {$meta['time']}");
    $receivedFiles[] = $meta['name'];

    if (!$options['no-dump'] && preg_match('/\.pkt$/i', $meta['name'])) {
        dumpPacket($finalPath);
    }
}
