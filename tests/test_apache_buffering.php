#!/usr/bin/env php
<?php
/**
 * Apache Buffering Test
 *
 * Makes a raw HTTP/1.1 request directly to Apache (bypassing any CDN/proxy),
 * authenticates as a real user by pulling a session from the database,
 * and streams the response body as it arrives so buffering is visible
 * in real time. Timing summary is printed at the end.
 *
 * Usage:
 *   php tests/test_apache_buffering.php [options]
 *
 * Options:
 *   --host     Apache host (default: 127.0.0.1)
 *   --port     Apache port (default: 81; 443 when --tls is set)
 *   --tls      Use TLS/HTTPS (sets default port to 443)
 *   --no-verify  Skip TLS certificate verification (for self-signed certs)
 *   --uri      Request URI to test (default: /)
 *   --session  Use this session ID directly (skips DB lookup)
 *   --user     Username to use (pulls their most recent active session)
 *   --vhost    HTTP Host header value (default: claudes.lovelybits.org)
 *   --help     Show this help
 *
 * Examples:
 *   php tests/test_apache_buffering.php --uri /echomail
 *   php tests/test_apache_buffering.php --user admin --uri /admin
 *   php tests/test_apache_buffering.php --tls --uri /echomail
 *   php tests/test_apache_buffering.php --tls --no-verify --uri /echomail
 *   php tests/test_apache_buffering.php --tls --host claudes.lovelybits.org --uri /
 */

// ── Argument parser ───────────────────────────────────────────────────────────

function parseArgs(array $argv): array
{
    $result = [];
    $i = 1;
    while ($i < count($argv)) {
        $arg = $argv[$i];
        if (str_starts_with($arg, '--')) {
            $key = substr($arg, 2);
            if (str_contains($key, '=')) {
                [$key, $val] = explode('=', $key, 2);
                $result[$key] = $val;
            } elseif (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '--')) {
                $result[$key] = $argv[$i + 1];
                $i++;
            } else {
                $result[$key] = true;
            }
        }
        $i++;
    }
    return $result;
}

$opts = parseArgs($argv);

if (isset($opts['help'])) {
    $lines = file(__FILE__);
    foreach (array_slice($lines, 1, 20) as $l) echo $l;
    exit(0);
}

$useTls     = isset($opts['tls']);
$noVerify   = isset($opts['no-verify']);
$apacheHost = $opts['host']  ?? '127.0.0.1';
$apachePort = (int)($opts['port'] ?? ($useTls ? 443 : 81));
$uri        = $opts['uri']     ?? '/';
$vhost      = $opts['vhost']   ?? 'claudes.lovelybits.org';
$wantUser   = $opts['user']    ?? null;
$wantSessId = $opts['session'] ?? null;

// ── Load .env ─────────────────────────────────────────────────────────────────

$envFile = __DIR__ . '/../.env';
$env = [];
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v);
    }
}

// ── Resolve session ID ────────────────────────────────────────────────────────

if ($wantSessId !== null) {
    // Use the supplied session ID directly — no DB needed
    $sessionId = $wantSessId;
    $username  = '(supplied)';
    $isAdmin   = '';
    echo "Using supplied session : " . substr($sessionId, 0, 16) . "...\n\n";
} else {
    $dbHost = $env['DB_HOST'] ?? 'localhost';
    $dbPort = $env['DB_PORT'] ?? '5432';
    $dbName = $env['DB_NAME'] ?? 'binktest';
    $dbUser = $env['DB_USER'] ?? 'binktest';
    $dbPass = $env['DB_PASS'] ?? '';

    echo "Connecting to PostgreSQL {$dbHost}:{$dbPort}/{$dbName}...\n";

    try {
        $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}";
        $db  = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        die("DB connection failed: " . $e->getMessage() . "\n");
    }

    if ($wantUser !== null) {
        $stmt = $db->prepare("
            SELECT s.session_id, u.username, u.is_admin
            FROM user_sessions s
            JOIN users u ON s.user_id = u.id
            WHERE LOWER(u.username) = LOWER(?)
              AND s.expires_at > NOW()
              AND s.service = 'web'
              AND u.is_active = TRUE
            ORDER BY s.last_activity DESC
            LIMIT 1
        ");
        $stmt->execute([$wantUser]);
    } else {
        $stmt = $db->query("
            SELECT s.session_id, u.username, u.is_admin
            FROM user_sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.expires_at > NOW()
              AND s.service = 'web'
              AND u.is_active = TRUE
            ORDER BY s.last_activity DESC
            LIMIT 1
        ");
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $who = $wantUser ? "user '{$wantUser}'" : "any active user";
        die("No active web session found for {$who}. Log in via the browser first.\n");
    }

    $sessionId = $row['session_id'];
    $username  = $row['username'];
    $isAdmin   = $row['is_admin'] ? ' (admin)' : '';

    echo "Using session for    : {$username}{$isAdmin}\n";
    echo "Session ID           : " . substr($sessionId, 0, 16) . "...\n\n";
}

// ── Build HTTP request ────────────────────────────────────────────────────────

$parsedUri  = parse_url($uri);
$path       = $parsedUri['path'] ?? '/';
$query      = isset($parsedUri['query']) ? '?' . $parsedUri['query'] : '';
$requestUri = $path . $query;

$request = implode("\r\n", [
    "GET {$requestUri} HTTP/1.1",
    "Host: {$vhost}",
    "Cookie: binktermphp_session={$sessionId}",
    "User-Agent: ApacheBufferingTest/1.0",
    "Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8",
    "Accept-Encoding: identity",   // disable compression so we see raw bytes
    "Connection: close",
    "",
    "",
]);

// ── Connect and send ──────────────────────────────────────────────────────────

$scheme = $useTls ? 'https' : 'http';
echo "Apache target        : {$scheme}://{$apacheHost}:{$apachePort}\n";
echo "Request URI          : {$requestUri}\n";
echo "Virtual host         : {$vhost}\n";
if ($useTls && $noVerify) echo "TLS verify           : disabled\n";
echo str_repeat('-', 60) . "\n";

$tConnect = microtime(true);

$streamProto = $useTls ? 'ssl' : 'tcp';
$context = stream_context_create($useTls ? [
    'ssl' => [
        'verify_peer'       => !$noVerify,
        'verify_peer_name'  => !$noVerify,
        'allow_self_signed' => $noVerify,
        'SNI_enabled'       => true,
        'peer_name'         => $vhost,
    ],
] : []);

$sock = @stream_socket_client("{$streamProto}://{$apacheHost}:{$apachePort}", $errno, $errstr, 5.0, STREAM_CLIENT_CONNECT, $context);
if (!$sock) {
    die("Cannot connect to Apache ({$scheme}://{$apacheHost}:{$apachePort}): [{$errno}] {$errstr}\n");
}

stream_set_timeout($sock, 30);
// Disable read buffering so we see data as it arrives
stream_set_read_buffer($sock, 0);

$tConnected = microtime(true);
printf("Connected in         : %.1f ms\n", ($tConnected - $tConnect) * 1000);

fwrite($sock, $request);

$tSent = microtime(true);
printf("Request sent at      : %.1f ms\n", ($tSent - $tConnect) * 1000);

// ── Read response ─────────────────────────────────────────────────────────────

$firstByte       = null;
$responseHeaders = '';
$headersDone     = false;
$headerBuf       = '';
$bodyBytes       = 0;
$isChunked       = false;
$contentLength   = null;
$statusLine      = '';

while (!feof($sock)) {
    $chunk = fread($sock, 4096);
    if ($chunk === false || $chunk === '') {
        $info = stream_get_meta_data($sock);
        if ($info['timed_out']) {
            echo "\n[read timeout]\n";
        }
        break;
    }

    if ($firstByte === null) {
        $firstByte = microtime(true);
    }

    if (!$headersDone) {
        $headerBuf .= $chunk;
        $sep = strpos($headerBuf, "\r\n\r\n");
        if ($sep !== false) {
            $responseHeaders = substr($headerBuf, 0, $sep);
            $body            = substr($headerBuf, $sep + 4);
            $headersDone     = true;

            // Extract status line
            $statusLine = strtok($responseHeaders, "\n");

            // Detect transfer encoding and content-length
            $lowerHeaders = strtolower($responseHeaders);
            $isChunked    = str_contains($lowerHeaders, 'transfer-encoding: chunked');
            if (preg_match('/content-length:\s*(\d+)/i', $responseHeaders, $m)) {
                $contentLength = (int)$m[1];
            }

            if ($body !== '') {
                if ($isChunked) {
                    $body = decodeChunkedPartial($body);
                }
                echo $body;
                $bodyBytes += strlen($body);
            }
        }
    } else {
        if ($isChunked) {
            $chunk = decodeChunkedPartial($chunk);
        }
        echo $chunk;
        $bodyBytes += strlen($chunk);
    }
}

fclose($sock);

$tEnd = microtime(true);

// ── Summary ───────────────────────────────────────────────────────────────────

echo "\n" . str_repeat('=', 60) . "\n";
echo "Status               : " . trim($statusLine) . "\n";
if ($firstByte !== null) {
    printf("Time to first byte   : %.1f ms\n", ($firstByte - $tConnect) * 1000);
}
printf("Total response time  : %.1f ms\n", ($tEnd - $tConnect) * 1000);
if ($firstByte !== null) {
    printf("Body transfer time   : %.1f ms\n", ($tEnd - $firstByte) * 1000);
    $bufferingGap = ($firstByte - $tSent) * 1000;
    printf("Send→first-byte gap  : %.1f ms  %s\n",
        $bufferingGap,
        $bufferingGap > 200 ? ' ← possible buffering delay' : ' ← looks fine'
    );
}
printf("Body size            : %d bytes%s\n",
    $bodyBytes,
    $contentLength !== null ? " (Content-Length: {$contentLength})" : ($isChunked ? ' (chunked)' : '')
);

echo str_repeat('-', 60) . "\n";
echo "Response headers:\n";
foreach (explode("\n", $responseHeaders) as $hLine) {
    echo "  " . rtrim($hLine) . "\n";
}

// Note any buffering-related headers
$notable = [];
foreach (explode("\n", $responseHeaders) as $hLine) {
    $lower = strtolower(trim($hLine));
    if (str_starts_with($lower, 'x-accel-buffering:') ||
        str_starts_with($lower, 'transfer-encoding:') ||
        str_starts_with($lower, 'x-php-ob-level:')) {
        $notable[] = '  ' . trim($hLine);
    }
}
if ($notable) {
    echo "\nNotable buffering headers:\n";
    foreach ($notable as $h) echo $h . "\n";
}

exit(0);

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Decode as much chunked transfer-encoding data as possible from a buffer.
 * Returns the decoded body bytes (may be partial if buffer ends mid-chunk).
 */
function decodeChunkedPartial(string $buf): string
{
    $out = '';
    $pos = 0;
    $len = strlen($buf);

    while ($pos < $len) {
        // Find end of chunk-size line
        $crlf = strpos($buf, "\r\n", $pos);
        if ($crlf === false) break;

        $sizeLine  = substr($buf, $pos, $crlf - $pos);
        $chunkSize = hexdec(strtok($sizeLine, ';')); // strip chunk extensions
        $pos       = $crlf + 2;

        if ($chunkSize === 0) break; // last chunk

        if ($pos + $chunkSize > $len) {
            // Partial chunk — take what we have
            $out .= substr($buf, $pos);
            break;
        }

        $out .= substr($buf, $pos, $chunkSize);
        $pos += $chunkSize + 2; // skip trailing CRLF
    }

    return $out;
}
