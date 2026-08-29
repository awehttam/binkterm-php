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
 * BinktermPHP NNTP Server Daemon
 *
 * Serves FTN echoareas as NNTP newsgroups (RFC 3977) to standard newsreaders.
 * Read-only in this release; posting is gated behind config/nntp.json allow_posting.
 *
 * Transport is configured via .env (a daemon restart applies changes):
 *   NNTP_BIND_HOST      bind address (default 0.0.0.0)
 *   NNTP_PORT           plaintext + STARTTLS port (default 119)
 *   NNTP_TLS_PORT       implicit-TLS port (default 563; empty string disables)
 *   NNTP_TLS_CERT_PATH  PEM cert or combined cert+key (default data/nntp/server.crt)
 *   NNTP_TLS_KEY_PATH   PEM private key (default data/nntp/server.key)
 *
 * Behaviour (enable/disable, rate limits, ...) is in config/nntp.json, managed
 * through Admin -> NNTP Server.
 *
 * Usage:
 *   php scripts/nntp_server.php [options]
 *     --host=ADDR        Bind address (default: NNTP_BIND_HOST or 0.0.0.0)
 *     --port=PORT        Plaintext/STARTTLS port (default: NNTP_PORT or 119)
 *     --tls-port=PORT    Implicit TLS port (default: NNTP_TLS_PORT or 563; 0 disables)
 *     --daemon           Run as a background daemon (requires pcntl)
 *     --no-console       Disable console logging
 *     --pid-file=FILE    PID file path (default: data/run/nntpd.pid)
 *     --log-file=FILE    Log file path (default: data/logs/nntpd.log)
 *     --log-level=LEVEL  Log level (default: INFO)
 *     --help             Show this help
 */

chdir(__DIR__ . '/../');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Binkp\Logger;
use BinktermPHP\Config;
use BinktermPHP\Nntp\NntpServer;

function nntpParseArgs(array $argv): array
{
    $args = [];
    foreach ($argv as $arg) {
        if (strpos($arg, '--') !== 0) {
            continue;
        }
        if (strpos($arg, '=') !== false) {
            [$key, $value] = explode('=', substr($arg, 2), 2);
            $args[$key] = $value;
        } else {
            $args[substr($arg, 2)] = true;
        }
    }
    return $args;
}

function nntpDaemonize(): void
{
    $pid = pcntl_fork();
    if ($pid === -1) {
        fwrite(STDERR, "Could not fork\n");
        exit(1);
    }
    if ($pid) {
        echo "NNTP daemon started with PID: {$pid}\n";
        exit(0);
    }
    if (posix_setsid() === -1) {
        fwrite(STDERR, "Could not detach from terminal\n");
        exit(1);
    }
    fclose(STDIN);
    fclose(STDOUT);
    fclose(STDERR);
    fopen('/dev/null', 'r');
    fopen('/dev/null', 'a');
    fopen('/dev/null', 'a');
}

$args = nntpParseArgs($argv);

if (isset($args['help'])) {
    $doc = file_get_contents(__FILE__);
    if (preg_match('/\* Usage:.*?(?=\*\/)/s', $doc, $m)) {
        echo preg_replace('/^\s*\*\s?/m', '', $m[0]) . "\n";
    }
    exit(0);
}

$host = (string)($args['host'] ?? Config::env('NNTP_BIND_HOST', '0.0.0.0'));
$plainPort = (int)($args['port'] ?? Config::env('NNTP_PORT', '119'));

$tlsPortRaw = $args['tls-port'] ?? Config::env('NNTP_TLS_PORT', '563');
$tlsPort = ($tlsPortRaw === '' || $tlsPortRaw === false) ? 0 : (int)$tlsPortRaw;

$certDir = __DIR__ . '/../data/nntp';
$certPath = (string)Config::env('NNTP_TLS_CERT_PATH', $certDir . '/server.crt');
$keyPath = (string)Config::env('NNTP_TLS_KEY_PATH', $certDir . '/server.key');
$externalCert = ((string)Config::env('NNTP_TLS_CERT_PATH', '')) !== '';

$pidFile = (string)($args['pid-file'] ?? (__DIR__ . '/../data/run/nntpd.pid'));
$logFile = (string)($args['log-file'] ?? Config::getLogPath('nntpd.log'));
$logLevel = (string)($args['log-level'] ?? 'INFO');
$logToConsole = !isset($args['no-console']);

$logger = new Logger($logFile, $logLevel, $logToConsole);

if (isset($args['daemon'])) {
    if (!function_exists('pcntl_fork')) {
        fwrite(STDERR, "--daemon requires the pcntl extension, which is not available here.\n");
        exit(1);
    }
    $logger->setLogToConsole(false);
    nntpDaemonize();
} else {
    echo "\033]0;BinktermPHP NNTP Daemon\007";
}

$pidDir = dirname($pidFile);
if (!is_dir($pidDir)) {
    @mkdir($pidDir, 0755, true);
}
@file_put_contents($pidFile, (string)getmypid());
@chmod($pidFile, 0644);

register_shutdown_function(static function () use ($pidFile): void {
    if (file_exists($pidFile) && (int)trim((string)file_get_contents($pidFile)) === getmypid()) {
        @unlink($pidFile);
    }
});

$server = new NntpServer($host, $plainPort, $tlsPort, $certPath, $keyPath, $externalCert, $logger);
$server->run();
