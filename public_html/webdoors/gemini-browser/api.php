<?php

/**
 * Gemini Browser WebDoor — self-contained API
 *
 * Handles all server-side logic: Gemini protocol fetch, bookmarks.
 * Depends only on the WebDoor SDK helpers; does not touch core BBS routes.
 *
 * Actions (GET ?action=<name>):
 *   config          — return door configuration and user info
 *   home_page       — return the built-in start page as gemtext
 *   fetch           — proxy-fetch a gemini:// URL (?url=gemini://…)
 *   bookmark_list   — return bookmarks for current user
 *   bookmark_add    — POST {url, title} — add a bookmark
 *   bookmark_remove — POST {url}        — remove a bookmark
 */

require_once __DIR__ . '/../_doorsdk/php/helpers.php';

header('Content-Type: application/json; charset=utf-8');

// ── Auth ──────────────────────────────────────────────────────────────────────
$user = WebDoorSDK\requireAuth();

// ── Door enabled check ────────────────────────────────────────────────────────
if (!WebDoorSDK\isDoorEnabled('gemini-browser')) {
    WebDoorSDK\jsonError('Gemini Browser is not enabled', 403);
}

// ── Route ─────────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'config':
        handleConfig();
        break;

    case 'home_page':
        handleHomePage();
        break;

    case 'fetch':
        handleFetch();
        break;

    case 'bookmark_list':
        handleBookmarkList();
        break;

    case 'bookmark_add':
        handleBookmarkAdd();
        break;

    case 'bookmark_remove':
        handleBookmarkRemove();
        break;

    default:
        WebDoorSDK\jsonError('Unknown action', 400);
}

// ── Action: config ────────────────────────────────────────────────────────────

/**
 * Return door configuration for the client.
 */
function handleConfig(): void
{
    $cfg = WebDoorSDK\getDoorConfig('gemini-browser');
    WebDoorSDK\jsonResponse([
        'home_url'     => $cfg['home_url']     ?? 'about:home',
        'max_redirects'=> (int)($cfg['max_redirects'] ?? 5),
        'timeout'      => (int)($cfg['timeout']       ?? 15),
    ]);
}

// ── Action: home_page ─────────────────────────────────────────────────────────

/**
 * Return the built-in start page as a synthetic gemtext response.
 * The client requests this URL as "about:home".
 */
function handleHomePage(): void
{
    $body = implode("\n", [
        '# Geminispace — Start Page',
        '',
        'Welcome to Geminispace — a lightweight, privacy-focused corner of the internet.',
        'No ads. No tracking. No JavaScript. Just text and links.',
        '',
        '## Search',
        '=> gemini://kennedy.gemi.dev/ Kennedy — Gemini Search Engine',
        '',
        '## About Gemini',
        '=> gemini://geminiprotocol.net/ The Gemini Protocol — Official Specification',
        '=> gemini://gemini.circumlunar.space/ Project Gemini Homepage',
        '',
        '## News & Aggregators',
        '=> gemini://gemini.circumlunar.space/capcom/ CAPCOM — Gemini Feed Aggregator',
        '=> gemini://rawtext.club/ Rawtext Club — Community Articles',
        '',
        '## Community Spaces',
        '=> gemini://tilde.team/ Tilde Team — Shared Unix Community',
        '=> gemini://cosmic.voyage/ Cosmic Voyage — Collaborative Sci-Fi Fiction',
        '',
        '## Software',
        '=> gemini://skyjake.fi/lagrange/ Lagrange — Native Gemini Browser',
    ]);

    WebDoorSDK\jsonResponse([
        'success' => true,
        'status'  => 20,
        'meta'    => 'text/gemini; charset=utf-8',
        'mime'    => 'text/gemini',
        'body'    => $body,
        'url'     => 'about:home',
    ]);
}

// ── Action: fetch ─────────────────────────────────────────────────────────────

/**
 * Proxy-fetch a Gemini URL and return the parsed response as JSON.
 */
function handleFetch(): void
{
    $url = trim($_GET['url'] ?? '');
    if ($url === '') {
        WebDoorSDK\jsonError('url parameter is required', 400);
    }

    $cfg          = WebDoorSDK\getDoorConfig('gemini-browser');
    $maxRedirects = (int)($cfg['max_redirects']     ?? 5);
    $timeout      = (int)($cfg['timeout']           ?? 15);
    $maxBytes     = (int)($cfg['max_response_bytes'] ?? 10485760);
    $blockPrivate = (bool)($cfg['block_private_ranges'] ?? true);

    $result = geminiGet($url, $maxRedirects, $timeout, $maxBytes, $blockPrivate);
    WebDoorSDK\jsonResponse($result);
}

// ── Action: bookmark_list ─────────────────────────────────────────────────────

/**
 * Return the current user's bookmarks.
 */
function handleBookmarkList(): void
{
    $data = WebDoorSDK\storageLoad('gemini-browser');
    WebDoorSDK\jsonResponse(['bookmarks' => $data['bookmarks'] ?? []]);
}

// ── Action: bookmark_add ──────────────────────────────────────────────────────

/**
 * Add a bookmark for the current user.
 */
function handleBookmarkAdd(): void
{
    $input = json_decode((string)file_get_contents('php://input'), true) ?? [];
    $url   = trim((string)($input['url']   ?? ''));
    $title = trim((string)($input['title'] ?? ''));

    if ($url === '') {
        WebDoorSDK\jsonError('url is required', 400);
    }
    if (!str_starts_with($url, 'gemini://')) {
        WebDoorSDK\jsonError('Only gemini:// URLs can be bookmarked', 400);
    }
    if ($title === '') {
        $title = $url;
    }

    $data      = WebDoorSDK\storageLoad('gemini-browser');
    $bookmarks = $data['bookmarks'] ?? [];

    // Deduplicate
    foreach ($bookmarks as $bm) {
        if ($bm['url'] === $url) {
            WebDoorSDK\jsonResponse(['bookmarks' => $bookmarks]);
        }
    }

    $bookmarks[] = [
        'url'   => $url,
        'title' => $title,
        'added' => date('Y-m-d H:i:s'),
    ];

    WebDoorSDK\storageSave('gemini-browser', ['bookmarks' => $bookmarks]);
    WebDoorSDK\jsonResponse(['bookmarks' => $bookmarks]);
}

// ── Action: bookmark_remove ───────────────────────────────────────────────────

/**
 * Remove a bookmark by URL for the current user.
 */
function handleBookmarkRemove(): void
{
    $input = json_decode((string)file_get_contents('php://input'), true) ?? [];
    $url   = trim((string)($input['url'] ?? ''));

    if ($url === '') {
        WebDoorSDK\jsonError('url is required', 400);
    }

    $data      = WebDoorSDK\storageLoad('gemini-browser');
    $bookmarks = $data['bookmarks'] ?? [];
    $bookmarks = array_values(array_filter($bookmarks, fn($bm) => $bm['url'] !== $url));

    WebDoorSDK\storageSave('gemini-browser', ['bookmarks' => $bookmarks]);
    WebDoorSDK\jsonResponse(['bookmarks' => $bookmarks]);
}

// ── Gemini protocol client ────────────────────────────────────────────────────

/**
 * Fetch a Gemini URL, following redirects up to $maxRedirects times.
 *
 * @param string $url
 * @param int    $maxRedirects
 * @param int    $timeout         Seconds for connect + read
 * @param int    $maxBytes        Maximum response body size
 * @param bool   $blockPrivate    Block RFC-1918 / loopback destinations
 * @param int    $redirectCount   Current redirect depth (internal)
 * @return array
 */
function geminiGet(
    string $url,
    int    $maxRedirects,
    int    $timeout,
    int    $maxBytes,
    bool   $blockPrivate,
    int    $redirectCount = 0
): array {
    if ($redirectCount > $maxRedirects) {
        return geminiError('Too many redirects', 0, $url);
    }

    // ── Validate URL ──────────────────────────────────────────────────────────
    if (!preg_match('/^gemini:\/\//i', $url)) {
        return geminiError('Only gemini:// URLs are supported', 0, $url);
    }

    $parsed = parse_url($url);
    if (!$parsed) {
        return geminiError('Invalid URL', 0, $url);
    }

    $host = $parsed['host'] ?? '';
    $port = (int)($parsed['port'] ?? 1965);
    $path = $parsed['path'] ?? '/';
    if ($path === '') $path = '/';
    if (isset($parsed['query'])) $path .= '?' . $parsed['query'];

    if ($host === '') {
        return geminiError('Invalid URL: missing host', 0, $url);
    }

    // Only allow the standard Gemini port to prevent SSRF on other services
    if ($port !== 1965) {
        return geminiError('Only the default Gemini port (1965) is allowed', 0, $url);
    }

    // ── SSRF protection ───────────────────────────────────────────────────────
    // Resolve once and reuse the IP for the connection to prevent DNS rebinding:
    // if we validated the hostname and then connected by hostname, a second DNS
    // lookup inside stream_socket_client() could return a different (private) IP.
    $resolved = gethostbyname($host);
    if ($blockPrivate) {
        if (!isPublicIp($resolved)) {
            return geminiError('Access to private or reserved network addresses is not permitted', 0, $url);
        }
    }

    // ── Build canonical request URL ───────────────────────────────────────────
    $requestUrl = 'gemini://' . $host . $path;
    if (strlen($requestUrl) > 1024) {
        return geminiError('URL exceeds the Gemini maximum of 1024 bytes', 0, $url);
    }

    // ── Open TLS connection ───────────────────────────────────────────────────
    // Gemini uses a Trust-On-First-Use (TOFU) certificate model, not CA validation.
    // Connect using the pre-resolved IP (not the hostname) so no second DNS lookup
    // can occur. peer_name is not used for CA validation here (verify_peer=false)
    // but set it anyway for any SNI extension that may be sent.
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
            'peer_name'         => $host,
        ],
    ]);

    $socket = @stream_socket_client(
        "ssl://{$resolved}:{$port}",
        $errno, $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $ctx
    );

    if ($socket === false) {
        $sslErr = openssl_error_string() ?: '';
        $detail = $sslErr ? " [{$sslErr}]" : '';
        return geminiError("Connection failed: {$errstr} (errno {$errno}){$detail}", 0, $url);
    }

    stream_set_timeout($socket, $timeout);

    // ── Send request ──────────────────────────────────────────────────────────
    fwrite($socket, $requestUrl . "\r\n");

    // ── Read response header ──────────────────────────────────────────────────
    // Format: "<STATUS> <META>\r\n"  (max 1029 bytes per spec)
    $headerLine = fgets($socket, 1030);
    if ($headerLine === false) {
        fclose($socket);
        return geminiError('No response from server', 0, $url);
    }
    $headerLine = rtrim($headerLine, "\r\n");

    if (strlen($headerLine) < 3) {
        fclose($socket);
        return geminiError('Invalid response header from server', 0, $url);
    }

    $status = (int)substr($headerLine, 0, 2);
    $meta   = ltrim(substr($headerLine, 3)); // skip STATUS + space

    // ── Read body (success responses only) ───────────────────────────────────
    $body      = '';
    $truncated = false;

    if ($status >= 20 && $status < 30) {
        $bytesRead = 0;
        while (!feof($socket)) {
            $chunk = fread($socket, 8192);
            if ($chunk === false || $chunk === '') break;
            $body      .= $chunk;
            $bytesRead += strlen($chunk);
            if ($bytesRead >= $maxBytes) {
                $truncated = true;
                break;
            }
        }
    }

    fclose($socket);

    // ── Follow redirects ──────────────────────────────────────────────────────
    if ($status >= 30 && $status < 40 && $meta !== '') {
        $redirectTarget = geminiResolveUrl($url, $meta);
        WebDoorSDK\log('gemini-browser', "Redirect ({$status}): {$url} -> {$redirectTarget}");
        return geminiGet($redirectTarget, $maxRedirects, $timeout, $maxBytes, $blockPrivate, $redirectCount + 1);
    }

    // ── Character encoding normalisation for text content ────────────────────
    $mimeType = ($status >= 20 && $status < 30) ? ($meta ?: 'text/gemini; charset=utf-8') : '';
    $isText   = str_starts_with(strtolower(explode(';', $mimeType)[0]), 'text/');

    if ($isText && $body !== '') {
        if (preg_match('/charset=([^\s;]+)/i', $mimeType, $cm)) {
            $charset = strtolower($cm[1]);
            if ($charset !== 'utf-8' && function_exists('mb_convert_encoding')) {
                $body = mb_convert_encoding($body, 'UTF-8', strtoupper($charset));
            }
        }
    }

    if ($truncated) {
        $body .= "\n\n[Response truncated: size limit reached]";
    }

    return [
        'success' => true,
        'status'  => $status,
        'meta'    => $meta,
        'mime'    => $mimeType,
        'body'    => $isText ? $body : null,
        'url'     => $url,
    ];
}

/**
 * Build an error response array.
 *
 * @param string $message
 * @param int    $status
 * @param string $url
 * @return array
 */
function geminiError(string $message, int $status, string $url): array
{
    return [
        'success' => false,
        'error'   => $message,
        'status'  => $status,
        'url'     => $url,
    ];
}

/**
 * Resolve a (possibly relative) URL against a Gemini base URL.
 *
 * @param string $base
 * @param string $relative
 * @return string
 */
function geminiResolveUrl(string $base, string $relative): string
{
    // Already absolute?
    if (preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', $relative)) {
        return $relative;
    }

    $p      = parse_url($base);
    $scheme = $p['scheme'] ?? 'gemini';
    $host   = $p['host']   ?? '';
    $port   = isset($p['port']) ? ':' . $p['port'] : '';

    if (str_starts_with($relative, '/')) {
        return "{$scheme}://{$host}{$port}{$relative}";
    }

    $basePath = $p['path'] ?? '/';
    $dir      = rtrim(dirname($basePath), '/') . '/';

    return "{$scheme}://{$host}{$port}{$dir}{$relative}";
}

/**
 * Return true if $ip is a publicly routable address.
 * Uses PHP's FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE.
 * Returns false for anything that isn't a valid, public IP (loopback,
 * link-local, RFC-1918, unresolvable hostnames, etc.).
 *
 * @param string $ip  The result of gethostbyname() on the target host
 * @return bool
 */
function isPublicIp(string $ip): bool
{
    // gethostbyname() returns the hostname unchanged when resolution fails
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    return filter_var($ip, FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}
