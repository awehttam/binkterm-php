#!/usr/bin/env php
<?php
/**
 * PHP-FPM Buffering Test
 *
 * Connects directly to PHP-FPM via FastCGI (bypassing nginx/apache),
 * authenticates as a real user by pulling a session from the database,
 * and measures time-to-first-byte vs total response time to diagnose
 * whether output buffering is occurring at the PHP-FPM layer.
 *
 * Usage:
 *   php tests/test_fpm_buffering.php [options]
 *
 * Options:
 *   --fpm-host   PHP-FPM host or unix socket path (default: 127.0.0.1)
 *   --fpm-port   PHP-FPM TCP port (default: 9000; ignored for unix sockets)
 *   --fpm-socket Unix socket path, e.g. /run/php/php8.2-fpm.sock
 *   --uri        Request URI to test (default: /)
 *   --script     Absolute path to PHP script on disk (default: autodetected)
 *   --user       Username to use (pulls their most recent active session)
 *   --raw        Dump raw response headers + first 2KB of body
 *   --help       Show this help
 *
 * Examples:
 *   php tests/test_fpm_buffering.php --uri /echomail
 *   php tests/test_fpm_buffering.php --fpm-socket /run/php/php8.2-fpm.sock --uri /api/echomail/areas
 *   php tests/test_fpm_buffering.php --user admin --uri /admin
 */

// ── Config ────────────────────────────────────────────────────────────────────

$opts = parseArgs($argv);

$fpmHost   = $opts['fpm-host']   ?? '127.0.0.1';
$fpmPort   = (int)($opts['fpm-port'] ?? 9000);
$fpmSocket = $opts['fpm-socket'] ?? null;
$uri       = $opts['uri']        ?? '/';
$wantUser  = $opts['user']       ?? null;
$showRaw   = isset($opts['raw']);
$docRoot   = $opts['doc-root']   ?? realpath(__DIR__ . '/../public_html');
$script    = $opts['script']     ?? ($docRoot . '/index.php');

if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1000));
    exit(0);
}

// ── Bootstrap (DB only, no web stack needed) ──────────────────────────────────

$envFile = __DIR__ . '/../.env';
$env = [];
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v);
    }
}

$dbHost = $env['DB_HOST'] ?? 'localhost';
$dbPort = $env['DB_PORT'] ?? '5432';
$dbName = $env['DB_NAME'] ?? 'binktest';
$dbUser = $env['DB_USER'] ?? 'binktest';
$dbPass = $env['DB_PASS'] ?? '';

// ── Fetch session from DB ─────────────────────────────────────────────────────

echo "Connecting to PostgreSQL {$dbHost}:{$dbPort}/{$dbName}...\n";

try {
    $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}";
    $db = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die("DB connection failed: " . $e->getMessage() . "\n");
}

if ($wantUser !== null) {
    $stmt = $db->prepare("
        SELECT s.session_id, u.username, u.real_name, u.is_admin
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
        SELECT s.session_id, u.username, u.real_name, u.is_admin
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

echo "Using session for: {$username}{$isAdmin}\n";
echo "Session ID: " . substr($sessionId, 0, 16) . "...\n\n";

// ── FastCGI request ───────────────────────────────────────────────────────────

// Parse URI into path + query string
$parsedUri  = parse_url($uri);
$requestUri = $parsedUri['path'] ?? '/';
$queryStr   = $parsedUri['query'] ?? '';

// Build FastCGI params (CGI/1.1 environment)
$params = [
    'REQUEST_METHOD'  => 'GET',
    'SCRIPT_FILENAME' => $script,
    'SCRIPT_NAME'     => $requestUri,
    'REQUEST_URI'     => $uri,
    'QUERY_STRING'    => $queryStr,
    'SERVER_SOFTWARE' => 'test_fpm_buffering/1.0',
    'REMOTE_ADDR'     => '127.0.0.1',
    'REMOTE_PORT'     => '12345',
    'SERVER_ADDR'     => '127.0.0.1',
    'SERVER_PORT'     => '80',
    'SERVER_NAME'     => 'localhost',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
    'HTTP_HOST'       => 'localhost',
    'HTTP_COOKIE'     => 'binktermphp_session=' . $sessionId,
    'HTTP_USER_AGENT' => 'FPM-BufferingTest/1.0',
    'HTTP_ACCEPT'     => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
    'CONTENT_TYPE'    => '',
    'CONTENT_LENGTH'  => '0',
];

$target = $fpmSocket
    ? "unix://{$fpmSocket}"
    : "tcp://{$fpmHost}:{$fpmPort}";

echo "PHP-FPM target : {$target}\n";
echo "Script         : {$script}\n";
echo "Request URI    : {$uri}\n";
echo str_repeat('-', 60) . "\n";

$tConnect = microtime(true);

$sock = @stream_socket_client($target, $errno, $errstr, 5.0);
if (!$sock) {
    die("Cannot connect to PHP-FPM ({$target}): [{$errno}] {$errstr}\n");
}

stream_set_timeout($sock, 30);

$tConnected = microtime(true);
printf("Connected in         : %.1f ms\n", ($tConnected - $tConnect) * 1000);

// Build and send FastCGI request
$requestId = 1;
$data = fcgiBuildBeginRequest($requestId)
      . fcgiBuildParams($requestId, $params)
      . fcgiBuildStdin($requestId, '');

fwrite($sock, $data);

$tSent = microtime(true);
printf("Request sent at      : %.1f ms\n", ($tSent - $tConnect) * 1000);

// Read response, streaming body as it arrives; collect headers + stats for summary
$headerBuf     = '';   // accumulates raw FCGI_STDOUT until headers are separated
$responseHeaders = ''; // HTTP response headers (text before \r\n\r\n)
$headersDone   = false;
$bodyBytes     = 0;
$stderr        = '';
$firstByte     = null;
$tEnd          = null;
$appStatus     = null;
$done          = false;

echo str_repeat('-', 60) . "\n";

while (!$done && !feof($sock)) {
    $header = fread($sock, 8);
    if ($header === false || strlen($header) < 8) break;

    $rec        = unpack('Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength', $header);
    $type       = $rec['type'];
    $contentLen = $rec['contentLength'];
    $paddingLen = $rec['paddingLength'];

    $content = '';
    $remaining = $contentLen;
    while ($remaining > 0) {
        $chunk = fread($sock, $remaining);
        if ($chunk === false || $chunk === '') break;
        $content .= $chunk;
        $remaining -= strlen($chunk);
    }
    if ($paddingLen > 0) fread($sock, $paddingLen);

    switch ($type) {
        case 6: // FCGI_STDOUT
            if ($content === '') break;

            if ($firstByte === null) {
                $firstByte = microtime(true);
            }

            if (!$headersDone) {
                // Buffer until we find the header/body separator
                $headerBuf .= $content;
                $sep = strpos($headerBuf, "\r\n\r\n");
                if ($sep === false) $sep = strpos($headerBuf, "\n\n");
                if ($sep !== false) {
                    $sepLen = (substr($headerBuf, $sep, 2) === "\r\n") ? 4 : 2;
                    $responseHeaders = substr($headerBuf, 0, $sep);
                    $body = substr($headerBuf, $sep + $sepLen);
                    $headersDone = true;
                    if ($body !== '') {
                        echo $body;
                        $bodyBytes += strlen($body);
                    }
                }
            } else {
                echo $content;
                $bodyBytes += strlen($content);
            }
            break;

        case 7: // FCGI_STDERR
            $stderr .= $content;
            break;

        case 3: // FCGI_END_REQUEST
            if (strlen($content) >= 4) {
                $r = unpack('NappStatus', $content);
                $appStatus = $r['appStatus'];
            }
            $tEnd = microtime(true);
            $done = true;
            break;
    }
}

fclose($sock);

// ── Summary ───────────────────────────────────────────────────────────────────

$tEnd = $tEnd ?? microtime(true);

echo "\n" . str_repeat('=', 60) . "\n";

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
printf("Body size            : %d bytes\n", $bodyBytes);
if ($appStatus !== null) {
    echo "App exit status      : {$appStatus}\n";
}

echo str_repeat('-', 60) . "\n";
echo "Response headers:\n";
foreach (explode("\n", $responseHeaders) as $hLine) {
    echo "  " . rtrim($hLine) . "\n";
}

// Detect notable headers
$xAccelBuf = null;
foreach (explode("\n", $responseHeaders) as $hLine) {
    $lower = strtolower(trim($hLine));
    if (str_starts_with($lower, 'x-accel-buffering:')) {
        $xAccelBuf = trim(substr($lower, strlen('x-accel-buffering:')));
    }
}
if ($xAccelBuf !== null) {
    echo "\nX-Accel-Buffering    : {$xAccelBuf}\n";
}
if (str_contains($responseHeaders, 'X-PHP-OB-Level')) {
    preg_match('/X-PHP-OB-Level:\s*(\S+)/i', $responseHeaders, $m);
    echo "PHP OB level         : " . ($m[1] ?? 'unknown') . "\n";
}

if ($stderr !== '') {
    echo str_repeat('-', 60) . "\n";
    echo "STDERR from PHP-FPM:\n";
    echo $stderr . "\n";
}

// ── FastCGI helpers ───────────────────────────────────────────────────────────

/**
 * Build FCGI_BEGIN_REQUEST record (role=RESPONDER, flags=0).
 */
function fcgiBuildBeginRequest(int $requestId): string
{
    $content = pack('nnCCCC',
        1,       // FCGI_RESPONDER
        0,       // flags (0 = close connection after response)
        0, 0, 0, 0  // reserved
    );
    return fcgiRecord(1, $requestId, $content); // type 1 = FCGI_BEGIN_REQUEST
}

/**
 * Build FCGI_PARAMS records from an associative array.
 */
function fcgiBuildParams(int $requestId, array $params): string
{
    $encoded = '';
    foreach ($params as $name => $value) {
        $name  = (string)$name;
        $value = (string)$value;
        $nLen  = strlen($name);
        $vLen  = strlen($value);
        // Name length
        $encoded .= $nLen < 128 ? chr($nLen) : pack('N', $nLen | 0x80000000);
        // Value length
        $encoded .= $vLen < 128 ? chr($vLen) : pack('N', $vLen | 0x80000000);
        $encoded .= $name . $value;
    }

    // Non-empty PARAMS record followed by empty PARAMS record (signals end)
    return fcgiRecord(4, $requestId, $encoded)  // type 4 = FCGI_PARAMS
         . fcgiRecord(4, $requestId, '');
}

/**
 * Build FCGI_STDIN record(s). Empty stdin signals end of request body.
 */
function fcgiBuildStdin(int $requestId, string $body): string
{
    $out = '';
    if ($body !== '') {
        $out .= fcgiRecord(5, $requestId, $body);
    }
    $out .= fcgiRecord(5, $requestId, ''); // empty = end of STDIN
    return $out;
}

/**
 * Pack a single FastCGI record.
 * Records > 65535 bytes are split automatically.
 */
function fcgiRecord(int $type, int $requestId, string $content): string
{
    $out = '';
    $offset = 0;
    $len = strlen($content);
    do {
        $chunkLen = min($len - $offset, 65535);
        $chunk    = substr($content, $offset, $chunkLen);
        // Pad to 8-byte boundary
        $padding  = (8 - ($chunkLen % 8)) % 8;
        $out .= pack('CCnnCC',
            1,           // version
            $type,
            $requestId,
            $chunkLen,
            $padding,
            0            // reserved
        );
        $out .= $chunk . str_repeat("\0", $padding);
        $offset += $chunkLen;
    } while ($offset < $len);

    return $out;
}

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
