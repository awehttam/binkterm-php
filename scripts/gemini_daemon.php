#!/usr/bin/env php
<?php

/**
 * Gemini Capsule Server Daemon
 *
 * Serves user Gemini capsules over TLS on port 1965 (configurable).
 *
 * Routes:
 *   gemini://host/                          BBS home — directory of users with published capsules
 *   gemini://host/home/{username}/          User capsule index (index.gmi or auto-listing)
 *   gemini://host/home/{username}/{file}    Specific published capsule file
 *
 * Usage:
 *   php scripts/gemini_daemon.php [options]
 *
 * Options:
 *   --host=ADDR      Bind address (default: GEMINI_BIND_HOST env or 0.0.0.0)
 *   --port=PORT      Bind port (default: GEMINI_PORT env or 1965)
 *   --daemon         Run as background daemon
 *   --pid-file=FILE  Write PID to file (default: data/run/gemini_daemon.pid)
 *   --log-file=FILE  Log file path (default: data/logs/gemini_daemon.log)
 *   --help           Show this help
 */

chdir(__DIR__ . '/../');
require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Config;
use BinktermPHP\Database;

// ── Argument parsing ──────────────────────────────────────────────────────────

/**
 * Parse --key=value and --flag style CLI arguments.
 */
function parseArgs(array $argv): array
{
    $args = [];
    foreach ($argv as $arg) {
        if (strpos($arg, '--') === 0) {
            if (strpos($arg, '=') !== false) {
                [$key, $value] = explode('=', substr($arg, 2), 2);
                $args[$key] = $value;
            } else {
                $args[substr($arg, 2)] = true;
            }
        }
    }
    return $args;
}

$args = parseArgs($argv);

if (!empty($args['help'])) {
    echo "Usage: php scripts/gemini_daemon.php [options]\n";
    echo "  --host=ADDR      Bind address (default: 0.0.0.0)\n";
    echo "  --port=PORT      Listen port (default: 1965)\n";
    echo "  --daemon         Run as background daemon\n";
    echo "  --pid-file=FILE  PID file path (default: data/run/gemini_daemon.pid)\n";
    echo "  --log-file=FILE  Log file path (default: data/logs/gemini_daemon.log)\n";
    echo "  --help           Show this help\n";
    exit(0);
}

$host       = $args['host']     ?? Config::env('GEMINI_BIND_HOST', '0.0.0.0');
$port       = (int)($args['port'] ?? Config::env('GEMINI_PORT', '1965'));
$daemonMode = !empty($args['daemon']);
$pidFile    = $args['pid-file'] ?? __DIR__ . '/../data/run/gemini_daemon.pid';
$logFile    = $args['log-file'] ?? __DIR__ . '/../data/logs/gemini_daemon.log';

$certDir  = __DIR__ . '/../data/gemini';
$certPath = $certDir . '/server.crt';
$keyPath  = $certDir . '/server.key';

// ── Logging ───────────────────────────────────────────────────────────────────

function logMsg(string $level, string $message, string $logFile): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $message . "\n";
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    if (!defined('DAEMON_MODE')) {
        echo $line;
    }
}

// ── TLS certificate ───────────────────────────────────────────────────────────

/**
 * Generate a self-signed TLS certificate if one doesn't already exist.
 * Gemini uses Trust-On-First-Use (TOFU), so self-signed certs are standard.
 *
 * Tries the openssl CLI first (reliable on Windows + OpenSSL 3.x), then falls
 * back to PHP's openssl_* functions.
 */
function generateSelfSignedCert(string $certDir, string $certPath, string $keyPath, string $logFile): void
{
    if (file_exists($certPath) && file_exists($keyPath)) {
        return;
    }

    if (!is_dir($certDir)) {
        mkdir($certDir, 0750, true);
    }

    logMsg('INFO', 'Generating self-signed TLS certificate...', $logFile);

    $cn = 'localhost';
    try {
        $siteUrl = Config::getSiteUrl();
        $parsed  = parse_url($siteUrl);
        if (!empty($parsed['host'])) {
            $cn = $parsed['host'];
        }
    } catch (\Exception $e) {
        // fall back to localhost
    }

    $opensslCnf = realpath(__DIR__ . '/../config/gemini_openssl.cnf');

    // ── Attempt 1: openssl CLI ────────────────────────────────────────────────
    // PHP's openssl_pkey_export() produces PKCS#8 keys that OpenSSL 3.x's file
    // decoder sometimes rejects with "DECODER routines::unsupported".
    // The CLI uses the full provider stack and generates files it can load back.
    if ($opensslCnf !== false && generateCertViaCli($certDir, $certPath, $keyPath, $cn, $opensslCnf, $logFile)) {
        return;
    }

    // ── Attempt 2: PHP openssl_* functions ────────────────────────────────────
    $opensslCfg = $opensslCnf ? ['config' => $opensslCnf] : [];

    $pkey = openssl_pkey_new(array_merge([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ], $opensslCfg));

    if ($pkey === false) {
        throw new \RuntimeException('openssl_pkey_new() failed — check that the openssl PHP extension is enabled');
    }

    $csr = openssl_csr_new(
        ['commonName' => $cn],
        $pkey,
        array_merge(['digest_alg' => 'sha256'], $opensslCfg)
    );

    if ($csr === false) {
        throw new \RuntimeException('openssl_csr_new() failed');
    }

    $cert = openssl_csr_sign($csr, null, $pkey, 3650, array_merge(['digest_alg' => 'sha256'], $opensslCfg));

    if ($cert === false) {
        throw new \RuntimeException('openssl_csr_sign() failed');
    }

    $certPem = '';
    $keyPem  = '';
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($pkey, $keyPem);

    // Combined PEM (cert + key): PHP ssl:// context loads both from one file
    file_put_contents($certPath, $certPem . $keyPem);
    file_put_contents($keyPath, $keyPem);
    chmod($certPath, 0600);
    chmod($keyPath, 0600);

    logMsg('INFO', "Certificate generated for CN={$cn} (PHP), stored in {$certDir}/", $logFile);
}

/**
 * Generate a self-signed cert+key using the openssl command-line tool.
 * Returns true on success, false if the CLI is unavailable or fails.
 */
function generateCertViaCli(
    string $certDir,
    string $certPath,
    string $keyPath,
    string $cn,
    string $opensslCnf,
    string $logFile
): bool {
    // On Windows, the -subj value needs "//" prefix to avoid being parsed as a flag
    $subj = (PHP_OS_FAMILY === 'Windows') ? '//CN=' . $cn : '/CN=' . $cn;

    // Build Subject Alternative Name — required by modern Gemini clients (e.g. Lagrange).
    // CN alone is not sufficient; clients validate against the SAN extension.
    $sanParts = [];
    if (filter_var($cn, FILTER_VALIDATE_IP)) {
        $sanParts[] = 'IP:' . $cn;
    } else {
        $sanParts[] = 'DNS:' . $cn;
    }
    // Always include localhost/127.0.0.1 so local dev tools work without cert errors
    if ($cn !== 'localhost') {
        $sanParts[] = 'DNS:localhost';
        $sanParts[] = 'IP:127.0.0.1';
    } else {
        $sanParts[] = 'IP:127.0.0.1';
    }
    $san = implode(',', $sanParts);

    $cmd = implode(' ', [
        'openssl', 'req',
        '-x509',
        '-newkey', 'rsa:2048',
        '-keyout', escapeshellarg($keyPath),
        '-out',    escapeshellarg($certPath),
        '-days',   '3650',
        '-nodes',
        '-config', escapeshellarg($opensslCnf),
        '-subj',   escapeshellarg($subj),
        '-addext', escapeshellarg("subjectAltName={$san}"),
        '2>&1',
    ]);

    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0 || !file_exists($certPath) || !file_exists($keyPath)) {
        logMsg('DEBUG', 'openssl CLI unavailable or failed: ' . implode(' | ', $output), $logFile);
        return false;
    }

    // Append key to cert file — PHP ssl:// local_cert can load a combined PEM
    $certPem = file_get_contents($certPath);
    $keyPem  = file_get_contents($keyPath);
    file_put_contents($certPath, $certPem . $keyPem);
    chmod($certPath, 0600);
    chmod($keyPath, 0600);

    logMsg('INFO', "Certificate generated for CN={$cn} (openssl CLI), stored in {$certDir}/", $logFile);
    return true;
}

// ── Daemonization ─────────────────────────────────────────────────────────────

function daemonize(string $pidFile, string $logFile): void
{
    if (!function_exists('pcntl_fork')) {
        logMsg('WARNING', 'pcntl extension not available — running in foreground', $logFile);
        return;
    }

    $pid = pcntl_fork();
    if ($pid < 0) {
        throw new \RuntimeException('pcntl_fork() failed');
    }
    if ($pid > 0) {
        exit(0); // parent exits
    }

    posix_setsid();

    $pid2 = pcntl_fork();
    if ($pid2 < 0) {
        throw new \RuntimeException('Second pcntl_fork() failed');
    }
    if ($pid2 > 0) {
        exit(0);
    }

    // Redirect standard streams
    fclose(STDIN);
    fclose(STDOUT);
    fclose(STDERR);
    fopen('/dev/null', 'r');
    fopen('/dev/null', 'w');
    fopen('/dev/null', 'w');

    define('DAEMON_MODE', true);
    writePidFile($pidFile);
}

function writePidFile(string $pidFile): void
{
    $dir = dirname($pidFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($pidFile, getmypid());
}

function cleanupPidFile(string $pidFile): void
{
    if (file_exists($pidFile)) {
        unlink($pidFile);
    }
}

// ── Gemini response helpers ───────────────────────────────────────────────────

/**
 * Write a Gemini status line and optional body to a socket.
 *
 * @param resource $socket
 * @param int    $status  Gemini status code (e.g. 20, 51)
 * @param string $meta    MIME type for success, error message otherwise
 * @param string $body    Response body (for status 2x)
 */
function geminiRespond($socket, int $status, string $meta, string $body = ''): void
{
    fwrite($socket, "{$status} {$meta}\r\n");
    if ($body !== '') {
        fwrite($socket, $body);
    }
}

// ── Route handlers ────────────────────────────────────────────────────────────

/**
 * BBS home page — list users who have at least one published capsule.
 *
 * @param resource $socket
 * @param string $geminiHost  The hostname used in gemini:// links
 */
function handleHomePage($socket, string $geminiHost): void
{
    try {
        $db = Database::getInstance()->getPdo();

        $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        $bbsName = $binkpConfig->getSystemName();
        $siteUrl = Config::getSiteUrl();

        $stmt = $db->query(
            'SELECT DISTINCT u.username
             FROM users u
             JOIN gemini_capsule_files f ON f.user_id = u.id
             WHERE f.is_published = TRUE AND u.is_active = TRUE
             ORDER BY u.username'
        );
        $users = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $lines = [
            "# {$bbsName}",
            '',
            "=> {$siteUrl}/ Visit {$bbsName} on the web",
            '',
            '## Gemini Capsule Directory',
            '',
            'These BBS users have published their Gemini capsules here.',
            '',
        ];

        if (empty($users)) {
            $lines[] = 'No capsules have been published yet.';
        } else {
            foreach ($users as $username) {
                $lines[] = "=> gemini://{$geminiHost}/home/{$username}/ {$username}";
            }
        }

        geminiRespond($socket, 20, 'text/gemini; charset=utf-8', implode("\n", $lines) . "\n");
    } catch (\Exception $e) {
        geminiRespond($socket, 40, 'Temporary server error');
    }
}

/**
 * User capsule index — serve index.gmi if published, otherwise auto-list files.
 *
 * @param resource $socket
 * @param string $username
 * @param string $geminiHost
 */
function handleUserIndex($socket, string $username, string $geminiHost): void
{
    try {
        $db = Database::getInstance()->getPdo();

        // Look up user (case-insensitive)
        $stmt = $db->prepare(
            'SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND is_active = TRUE'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            geminiRespond($socket, 51, 'Not Found');
            return;
        }

        $userId = (int)$user['id'];

        // Try to serve index.gmi if published
        $stmt = $db->prepare(
            "SELECT content FROM gemini_capsule_files
             WHERE user_id = ? AND filename = 'index.gmi' AND is_published = TRUE"
        );
        $stmt->execute([$userId]);
        $index = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($index) {
            geminiRespond($socket, 20, 'text/gemini; charset=utf-8', $index['content']);
            return;
        }

        // Auto-generate directory listing of published files
        $stmt = $db->prepare(
            'SELECT filename FROM gemini_capsule_files
             WHERE user_id = ? AND is_published = TRUE
             ORDER BY filename'
        );
        $stmt->execute([$userId]);
        $files = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($files)) {
            geminiRespond($socket, 51, 'Not Found');
            return;
        }

        $lines = [
            "# {$username}'s Capsule",
            '',
        ];
        foreach ($files as $filename) {
            $lines[] = "=> /home/{$username}/{$filename} {$filename}";
        }

        geminiRespond($socket, 20, 'text/gemini; charset=utf-8', implode("\n", $lines) . "\n");
    } catch (\Exception $e) {
        geminiRespond($socket, 40, 'Temporary server error');
    }
}

/**
 * Serve a specific published capsule file.
 *
 * @param resource $socket
 * @param string $username
 * @param string $filename
 */
function handleUserFile($socket, string $username, string $filename): void
{
    // Validate filename format
    if (!preg_match('/^[a-zA-Z0-9_\-]+\.(gmi|gemini)$/', $filename)) {
        geminiRespond($socket, 51, 'Not Found');
        return;
    }

    try {
        $db = Database::getInstance()->getPdo();

        // Look up user (case-insensitive)
        $stmt = $db->prepare(
            'SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND is_active = TRUE'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            geminiRespond($socket, 51, 'Not Found');
            return;
        }

        $stmt = $db->prepare(
            'SELECT content, filename FROM gemini_capsule_files
             WHERE user_id = ? AND filename = ? AND is_published = TRUE'
        );
        $stmt->execute([(int)$user['id'], $filename]);
        $file = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$file) {
            geminiRespond($socket, 51, 'Not Found');
            return;
        }

        $ext  = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
        $mime = in_array($ext, ['gmi', 'gemini'])
            ? 'text/gemini; charset=utf-8'
            : 'text/plain; charset=utf-8';

        geminiRespond($socket, 20, $mime, $file['content']);
    } catch (\Exception $e) {
        geminiRespond($socket, 40, 'Temporary server error');
    }
}

// ── Request router ────────────────────────────────────────────────────────────

/**
 * Handle a single Gemini connection: read request, route, respond, close.
 * The socket is already TLS-encrypted (handshake completed by ssl:// accept).
 *
 * @param resource $socket    Accepted TLS client socket
 * @param string   $geminiHost
 * @param string   $logFile
 */
function handleConnection($socket, string $geminiHost, string $logFile): void
{
    stream_set_timeout($socket, 10);

    // Read request line (max 1026 bytes: 1024 URL + \r\n)
    $requestLine = fgets($socket, 1028);
    if ($requestLine === false) {
        fclose($socket);
        return;
    }

    $requestLine = rtrim($requestLine, "\r\n");

    if (strlen($requestLine) === 0) {
        geminiRespond($socket, 59, 'Bad Request');
        fclose($socket);
        return;
    }

    // Validate gemini:// scheme
    if (!preg_match('/^gemini:\/\//i', $requestLine)) {
        geminiRespond($socket, 59, 'Only gemini:// URLs are supported');
        fclose($socket);
        return;
    }

    $parsed = parse_url($requestLine);
    $path   = $parsed['path'] ?? '/';
    if ($path === '') {
        $path = '/';
    }

    logMsg('INFO', "Request: {$requestLine}", $logFile);

    // Route
    if ($path === '/' || $path === '') {
        handleHomePage($socket, $geminiHost);
    } elseif (preg_match('#^/home/([^/]+)/$#', $path, $m)) {
        handleUserIndex($socket, $m[1], $geminiHost);
    } elseif (preg_match('#^/home/([^/]+)/([^/]+)$#', $path, $m)) {
        handleUserFile($socket, $m[1], $m[2]);
    } else {
        geminiRespond($socket, 51, 'Not Found');
    }

    fclose($socket);
}

// ── Main ──────────────────────────────────────────────────────────────────────

// Ensure log directory exists
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Generate TLS cert if needed
generateSelfSignedCert($certDir, $certPath, $keyPath, $logFile);

// Resolve to clean absolute paths — OpenSSL's C layer on Windows cannot resolve
// paths containing ".." (e.g. "scripts/../data/gemini/server.crt"), which causes
// silent cert-load failures and the server to send 0 bytes during TLS handshake.
$certPath = realpath($certPath) ?: $certPath;
$keyPath  = realpath($keyPath)  ?: $keyPath;
logMsg('DEBUG', "Using cert: {$certPath}", $logFile);

// Daemonize if requested
if ($daemonMode) {
    daemonize($pidFile, $logFile);
} else {
    writePidFile($pidFile);
}

// Derive hostname for gemini:// links from SITE_URL
$geminiHost = 'localhost';
try {
    $parsed = parse_url(Config::getSiteUrl());
    if (!empty($parsed['host'])) {
        $geminiHost = $parsed['host'];
    }
} catch (\Exception $e) {
    // fall back to localhost
}

// Build SSL stream context.
// local_cert points to the combined PEM (cert + key in one file); no local_pk needed.
// Combined PEM avoids OpenSSL 3.x PKCS#8 decoder issues with separate local_pk files.
$sslCtx = stream_context_create([
    'ssl' => [
        'local_cert'        => $certPath,
        'allow_self_signed' => true,
        'verify_peer'       => false,
        'verify_peer_name'  => false,
    ],
]);

// Open TLS server socket (ssl:// performs the handshake at accept time)
$server = @stream_socket_server(
    "ssl://{$host}:{$port}",
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $sslCtx
);

if ($server === false) {
    logMsg('ERROR', "Failed to bind ssl://{$host}:{$port} — {$errstr} (errno {$errno})", $logFile);
    cleanupPidFile($pidFile);
    exit(1);
}

logMsg('INFO', "Gemini capsule server listening on ssl://{$host}:{$port}", $logFile);
logMsg('INFO', "Serving capsules at gemini://{$geminiHost}/", $logFile);

// Signal handlers
if (function_exists('pcntl_signal')) {
    $shutdown = function () use ($server, $pidFile, $logFile) {
        logMsg('INFO', 'Shutting down...', $logFile);
        fclose($server);
        cleanupPidFile($pidFile);
        exit(0);
    };
    pcntl_signal(SIGTERM, $shutdown);
    pcntl_signal(SIGINT,  $shutdown);
    pcntl_signal(SIGCHLD, function () {
        while (function_exists('pcntl_waitpid') && pcntl_waitpid(-1, $status, WNOHANG) > 0) {
            // reap zombie children
        }
    });
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
    }
}

// Accept loop
while (true) {
    // TLS handshake happens during stream_socket_accept with ssl:// server
    $client = @stream_socket_accept($server, 30);
    if ($client === false) {
        // Log OpenSSL errors (TLS handshake failures) so we can diagnose them
        $sslErr = openssl_error_string();
        if ($sslErr) {
            logMsg('WARNING', "Accept/TLS error: {$sslErr}", $logFile);
        }
        continue;
    }

    if (!function_exists('pcntl_fork')) {
        // No fork available — handle inline (single-connection at a time)
        handleConnection($client, $geminiHost, $logFile);
        continue;
    }

    $pid = pcntl_fork();
    if ($pid < 0) {
        logMsg('ERROR', 'pcntl_fork() failed', $logFile);
        fclose($client);
        continue;
    }

    if ($pid === 0) {
        // Child: handle connection then exit
        fclose($server);
        handleConnection($client, $geminiHost, $logFile);
        exit(0);
    }

    // Parent: close our copy of the client socket
    fclose($client);
}
