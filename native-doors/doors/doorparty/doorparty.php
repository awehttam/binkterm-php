#!/usr/bin/env php
<?php
$tag = getenv('SYSTEM_TAG');
$username = getenv('DOOR_USER_NAME') ?: 'guest';
$host = 'doorparty-connector';
$port = 9999;

if (!$tag) {
    fwrite(STDOUT, "SYSOP: SYSTEM_TAG not configured\r\n");
    exit(1);
}

system('stty -icanon -echo min 1 time 0');

$clientUserName = 'l33test';
$serverUserName = "[$tag]$username";
$termType = (getenv('TERM') ?: 'xterm') . '/38400';

$socket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 10);
if (!$socket) {
    fwrite(STDOUT, "SYSOP: Could not connect to DoorParty connector: $errstr ($errno)\r\n");
    exit(1);
}

stream_set_blocking($socket, true);

$handshake = "\x00" . $clientUserName . "\x00" . $serverUserName . "\x00" . $termType . "\x00";
fwrite($socket, $handshake);

fread($socket, 1);

stream_set_blocking($socket, false);
stream_set_blocking(STDIN, false);

$logFile = fopen('/tmp/doorparty_debug.log', 'a');
fwrite($logFile, "=== New session ===\n");

while (true) {
    $read = [$socket, STDIN];
    $write = null;
    $except = null;
    $result = stream_select($read, $write, $except, 1);

    if ($result === false) {
        fwrite($logFile, "stream_select failed\n");
        break;
    }

    if (in_array($socket, $read, true)) {
        $data = fread($socket, 4096);
        if ($data === '' || $data === false) {
            fwrite($logFile, "SOCKET closed/EOF\n");
            break;
        }
        fwrite(STDOUT, $data);
    }

    if (in_array(STDIN, $read, true)) {
        $data = fread(STDIN, 4096);
        if ($data === '' || $data === false) {
            fwrite($logFile, "STDIN closed/EOF\n");
            break;
        }
        fwrite($logFile, "STDIN received " . strlen($data) . " byte(s): " . bin2hex($data) . "\n");
        fflush($logFile);
        fwrite($socket, $data);
    }
}

fclose($logFile);
fclose($socket);
exit(0);
