#!/usr/bin/env php
<?php

/*
 * Copyright Matthew Asham and BinktermPHP Contributors
 *
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the
 * following conditions are met:
 *
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 *
 */

/**
 * Manual probe client for the BinktermPHP NNTP server (scripts/nntp_server.php).
 *
 * Connects, authenticates, and exercises the read-only command surface
 * (CAPABILITIES -> AUTHINFO -> LIST -> GROUP -> ARTICLE -> OVER), printing the
 * wire dialogue. Not an automated test — a diagnostic aid.
 *
 * Usage:
 *   php scripts/nntp_test_client.php --host=127.0.0.1 --port=8119 \
 *       --user=alice --pass=secret [--group=Local.GENERAL] [--tls] [--starttls]
 */

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

$host = $args['host'] ?? '127.0.0.1';
$port = (int)($args['port'] ?? 8119);
$user = $args['user'] ?? null;
$pass = $args['pass'] ?? null;
$group = $args['group'] ?? null;
$useTls = isset($args['tls']);
$useStartTls = isset($args['starttls']);

$transport = $useTls ? "tls://{$host}:{$port}" : "tcp://{$host}:{$port}";
$ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);

$sock = @stream_socket_client($transport, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
if ($sock === false) {
    fwrite(STDERR, "connect failed: {$errstr} ({$errno})\n");
    exit(1);
}
stream_set_timeout($sock, 10);

function readReply($sock, bool $multiline = false): string
{
    $status = fgets($sock);
    echo 'S: ' . rtrim($status) . "\n";
    if (!$multiline) {
        return $status;
    }
    $code = (int)substr(ltrim($status), 0, 3);
    if ($code >= 400) {
        return $status;
    }
    while (($line = fgets($sock)) !== false) {
        if (rtrim($line, "\r\n") === '.') {
            break;
        }
        echo '   ' . rtrim($line) . "\n";
    }
    return $status;
}

function cmd($sock, string $line, bool $multiline = false): string
{
    echo "C: {$line}\n";
    fwrite($sock, $line . "\r\n");
    return readReply($sock, $multiline);
}

readReply($sock);
cmd($sock, 'CAPABILITIES', true);

if ($useStartTls) {
    $r = cmd($sock, 'STARTTLS');
    if ((int)substr($r, 0, 3) === 382) {
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fwrite(STDERR, "STARTTLS handshake failed\n");
            exit(1);
        }
        echo "-- TLS established --\n";
        cmd($sock, 'CAPABILITIES', true);
    }
}

cmd($sock, 'LIST ACTIVE', true); // expect 480 before auth

if ($user !== null && $pass !== null) {
    cmd($sock, 'AUTHINFO USER ' . $user);
    cmd($sock, 'AUTHINFO PASS ' . $pass);
    cmd($sock, 'LIST ACTIVE', true);
    cmd($sock, 'LIST NEWSGROUPS', true);

    if ($group === null) {
        // Grab the first group from LIST ACTIVE.
        fwrite($sock, "LIST ACTIVE\r\n");
        $status = fgets($sock);
        if ((int)substr(ltrim($status), 0, 3) === 215) {
            $first = fgets($sock);
            if ($first !== false && rtrim($first) !== '.') {
                $group = strtok(trim($first), ' ');
            }
            while (($l = fgets($sock)) !== false && rtrim($l, "\r\n") !== '.') {
                // drain
            }
        }
    }

    if ($group !== null) {
        echo "-- using group {$group} --\n";
        $r = cmd($sock, 'GROUP ' . $group);
        if ((int)substr($r, 0, 3) === 211) {
            $bits = preg_split('/\s+/', trim($r));
            $low = (int)($bits[2] ?? 1);
            $high = (int)($bits[3] ?? $low);
            cmd($sock, "OVER {$low}-{$high}", true);
            cmd($sock, 'ARTICLE ' . $low, true);
            cmd($sock, 'HEAD ' . $high, true);
        }
    }
}

cmd($sock, 'QUIT');
fclose($sock);
