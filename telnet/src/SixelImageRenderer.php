<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\Config;
use BinktermPHP\Version;

/**
 * SixelImageRenderer - Fetches images and converts them to Sixel graphics for terminal display.
 *
 * Resolves local /echomail-images/ paths against the configured API base URL, and
 * fetches external URLs directly with SSRF protection. Images are downloaded to a
 * temporary file on disk (avoiding large in-memory byte strings) and passed directly
 * to img2sixel by file path. The temp file is removed after conversion.
 *
 * Binary discovery order:
 *   1. IMG2SIXEL_PATH env var (if set and executable)
 *   2. /usr/bin/img2sixel
 *   3. /usr/local/bin/img2sixel
 *   4. /opt/libsixel/bin/im2sixel
 */
class SixelImageRenderer
{
    /** @var string[] Known installation paths probed when IMG2SIXEL_PATH is not set */
    private static array $SEARCH_PATHS = [
        '/usr/bin/img2sixel',
        '/usr/local/bin/img2sixel',
        '/opt/libsixel/bin/im2sixel',
    ];

    /** @var string|null Resolved path to the img2sixel binary, or null if not found */
    private ?string $binaryPath;

    /** @var string API base URL used to resolve relative image paths */
    private string $apiBase;

    /**
     * @param string $apiBase The BBS API base URL (e.g. "https://example.com")
     */
    public function __construct(string $apiBase)
    {
        $this->apiBase    = rtrim($apiBase, '/');
        $this->binaryPath = $this->discoverBinary();
    }

    /**
     * Returns true if the img2sixel binary was found and is executable.
     */
    public function isAvailable(): bool
    {
        return $this->binaryPath !== null;
    }

    /**
     * Download an image to a temp file, convert it with img2sixel, return the Sixel data.
     *
     * The temporary file is created, passed to img2sixel by path, and deleted before
     * this method returns — regardless of success or failure.
     *
     * @param  string   $url       Image URL or absolute-path reference (e.g. /echomail-images/…)
     * @param  int      $maxWidth  Maximum pixel width passed to img2sixel --width
     * @param  string   $error     Set to a human-readable message on failure
     * @param  int|null $maxHeight Maximum pixel height passed to img2sixel --height (null = no limit)
     * @return string|null         Sixel byte string ready to write to the terminal, or null on failure
     */
    public function fetchAndConvert(string $url, int $maxWidth, string &$error, ?int $maxHeight = null): ?string
    {
        if ($this->binaryPath === null) {
            $error = 'img2sixel binary not found (install libsixel-bin)';
            return null;
        }

        $tmpFile = $this->downloadToTempFile($url, $error);
        if ($tmpFile === null) {
            return null;
        }

        try {
            return $this->convertFile($tmpFile, $maxWidth, $error, $maxHeight);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Write Sixel data to the terminal connection followed by a cursor newline reset.
     *
     * Uses a non-blocking write with a timeout for the sixel payload so that a
     * terminal that stops reading mid-stream (e.g. SyncTERM hitting its internal
     * sixel buffer limit) cannot cause the server process to hang indefinitely.
     *
     * @param resource $conn      Terminal socket
     * @param string   $sixelData Sixel byte string from fetchAndConvert()
     */
    public function writeSixel($conn, string $sixelData): void
    {
        $timeout  = (int)\BinktermPHP\Config::env('SIXEL_WRITE_TIMEOUT', '15');
        $deadline = time() + $timeout;
        $total    = strlen($sixelData);
        $offset   = 0;

        // Write the sixel payload in non-blocking mode with a per-chunk timeout.
        // Blocking mode can stall indefinitely if the remote terminal fills its
        // receive buffer (SyncTERM freezes when its sixel renderer hits its limit).
        stream_set_blocking($conn, false);
        while ($offset < $total) {
            if (time() >= $deadline) {
                break;  // terminal stopped reading — abort remainder
            }
            $write = [$conn]; $read = $except = null;
            $ready = @stream_select($read, $write, $except, 1, 0);
            if ($ready === false || $ready === 0) {
                continue;
            }
            $written = @fwrite($conn, substr($sixelData, $offset));
            if ($written === false || $written === 0) {
                break;
            }
            $offset += $written;
        }
        stream_set_blocking($conn, true);

        // Explicitly terminate DCS/sixel mode before emitting any plain-text bytes.
        // img2sixel normally ends its output with ST (ESC \), but sending it again is
        // a no-op for terminals already in normal mode, while protecting against
        // terminals (e.g. SyncTERM) that may still be parsing sixel data.
        TelnetUtils::safeWrite($conn, "\033\\");
        // Move cursor to a clean line below the image
        TelnetUtils::safeWrite($conn, "\r\n");
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Download an image URL to a temporary file on disk.
     *
     * Returns the temp file path on success, or null (and sets $error) on failure.
     * The caller is responsible for unlinking the returned file.
     *
     * @param  string $url
     * @param  string $error
     * @return string|null Temp file path
     */
    private function downloadToTempFile(string $url, string &$error): ?string
    {
        $url = $this->resolveUrl($url);

        if (!preg_match('/^https?:\/\//i', $url)) {
            $error = 'Unsupported URL scheme (only http/https allowed)';
            return null;
        }

        // SSRF check — skip for our own origin
        if (!$this->isLocalUrl($url)) {
            $host = parse_url($url, PHP_URL_HOST);
            if ($host === false || $host === null || !$this->isPublicHost($host, $error)) {
                return null;
            }
        }

        $timeout  = (int)Config::env('SIXEL_FETCH_TIMEOUT', '10');
        $maxBytes = (int)Config::env('SIXEL_IMAGE_MAX_BYTES', '5242880');

        $ctx = stream_context_create([
            'http' => [
                'timeout'         => $timeout,
                'follow_location' => 1,
                'max_redirects'   => 3,
                'user_agent'      => 'BinktermPHP/' . Version::getVersion(),
                'ignore_errors'   => true,
            ],
            'ssl'  => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $src = @fopen($url, 'rb', false, $ctx);
        if (!is_resource($src)) {
            $error = 'Failed to connect to URL';
            return null;
        }

        // Validate Content-Type before writing to disk
        $meta = stream_get_meta_data($src);
        foreach (($meta['wrapper_data'] ?? []) as $header) {
            if (is_string($header) && stripos($header, 'Content-Type:') === 0) {
                $ct = trim(substr($header, strlen('Content-Type:')));
                // Strip parameters (e.g. "; charset=…")
                $ct = strtok($ct, ';');
                if ($ct !== false && stripos(trim($ct), 'image/') !== 0) {
                    fclose($src);
                    $error = 'Unexpected Content-Type: ' . trim($ct);
                    return null;
                }
                break;
            }
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'binkimg_');
        if ($tmpFile === false) {
            fclose($src);
            $error = 'Could not create temporary file';
            return null;
        }

        $dst     = fopen($tmpFile, 'wb');
        $written = 0;

        while (!feof($src)) {
            $chunk = fread($src, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $written += strlen($chunk);
            if ($written > $maxBytes) {
                fclose($src);
                fclose($dst);
                @unlink($tmpFile);
                $error = 'Image exceeds maximum size (' . number_format($maxBytes) . ' bytes)';
                return null;
            }
            fwrite($dst, $chunk);
        }

        fclose($src);
        fclose($dst);

        if ($written === 0) {
            @unlink($tmpFile);
            $error = 'Empty response from URL';
            return null;
        }

        return $tmpFile;
    }

    /**
     * Run img2sixel on a file path and return the Sixel output.
     *
     * @param  string   $imagePath Absolute path to an image file on disk
     * @param  int      $maxWidth  Pixel width cap for img2sixel --width
     * @param  string   $error     Set to a human-readable message on failure
     * @param  int|null $maxHeight Pixel height cap for img2sixel --height (null = no limit)
     * @return string|null         Sixel byte string, or null on failure
     */
    private function convertFile(string $imagePath, int $maxWidth, string &$error, ?int $maxHeight = null): ?string
    {
        $timeout = (int)Config::env('SIXEL_CONVERT_TIMEOUT', '15');

        $cmd = [
            $this->binaryPath,
            '--width=' . max(1, $maxWidth),
            '--colors=256',
        ];

        if ($maxHeight !== null && $maxHeight > 0) {
            $cmd[] = '--height=' . $maxHeight;
        }

        $cmd[] = $imagePath;

        $descriptors = [
            0 => ['pipe', 'rb'],
            1 => ['pipe', 'wb'],
            2 => ['pipe', 'wb'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            $error = 'Failed to start img2sixel process';
            return null;
        }

        // stdin not needed — img2sixel reads from the file path argument
        fclose($pipes[0]);

        // On Windows, stream_select() does not work for proc_open pipes.
        // Use sequential blocking reads instead. img2sixel's stderr output is
        // always tiny (error messages only), so reading stdout first cannot
        // deadlock — stderr will never fill its pipe buffer.
        if (PHP_OS_FAMILY === 'Windows') {
            $output   = '';
            $stderr   = '';
            $deadline = time() + $timeout;

            stream_set_blocking($pipes[1], true);
            stream_set_blocking($pipes[2], true);

            while (!feof($pipes[1])) {
                if (time() > $deadline) {
                    proc_terminate($proc);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($proc);
                    $error = 'img2sixel timed out after ' . $timeout . 's';
                    return null;
                }
                $chunk = @fread($pipes[1], 65536);
                if ($chunk !== false && $chunk !== '') {
                    $output .= $chunk;
                }
            }
            fclose($pipes[1]);

            while (!feof($pipes[2])) {
                $chunk = @fread($pipes[2], 65536);
                if ($chunk !== false && $chunk !== '') {
                    $stderr .= $chunk;
                }
            }
            fclose($pipes[2]);

            $exitCode = proc_close($proc);

            if ($exitCode !== 0) {
                $stderrTrim = trim($stderr);
                $error = 'img2sixel failed (exit ' . $exitCode . ')' . ($stderrTrim !== '' ? ': ' . $stderrTrim : '');
                return null;
            }

            if ($output === '') {
                $error = 'img2sixel produced no output';
                return null;
            }

            return $output;
        }

        // Read stdout and stderr concurrently using non-blocking reads to avoid
        // deadlock: if img2sixel fills the stderr pipe buffer and we're waiting for
        // stdout EOF, the process hangs because nobody is draining stderr.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output    = '';
        $stderr    = '';
        $deadline  = time() + $timeout;
        $stdoutEof = false;
        $stderrEof = false;

        while (!$stdoutEof || !$stderrEof) {
            if (time() > $deadline) {
                proc_terminate($proc);
                if (!$stdoutEof) { fclose($pipes[1]); }
                if (!$stderrEof) { fclose($pipes[2]); }
                proc_close($proc);
                $error = 'img2sixel timed out after ' . $timeout . 's';
                return null;
            }

            $read = [];
            if (!$stdoutEof) { $read[] = $pipes[1]; }
            if (!$stderrEof) { $read[] = $pipes[2]; }

            $write = $except = null;
            $ready = @stream_select($read, $write, $except, 1, 0);

            if ($ready === false) {
                break;
            }

            foreach ($read as $pipe) {
                $chunk = @fread($pipe, 65536);
                if ($chunk === false || $chunk === '') {
                    if ($pipe === $pipes[1]) {
                        fclose($pipes[1]);
                        $stdoutEof = true;
                    } else {
                        fclose($pipes[2]);
                        $stderrEof = true;
                    }
                } else {
                    if ($pipe === $pipes[1]) {
                        $output .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            }
        }

        $exitCode = proc_close($proc);

        if ($exitCode !== 0) {
            $stderrTrim = trim((string)$stderr);
            $error = 'img2sixel failed (exit ' . $exitCode . ')' . ($stderrTrim !== '' ? ': ' . $stderrTrim : '');
            return null;
        }

        if ($output === false || $output === '') {
            $error = 'img2sixel produced no output';
            return null;
        }

        return $output;
    }

    /**
     * Resolve a possibly-relative path to a full URL.
     * Paths beginning with '/' are prefixed with the API base URL.
     */
    private function resolveUrl(string $url): string
    {
        if (str_starts_with($url, '/')) {
            return $this->apiBase . $url;
        }
        return $url;
    }

    /**
     * Returns true if the URL's origin matches our own API base.
     */
    private function isLocalUrl(string $url): bool
    {
        return str_starts_with($url, $this->apiBase . '/') || $url === $this->apiBase;
    }

    /**
     * Verify that a hostname resolves only to public (non-RFC-1918, non-loopback) IPs.
     *
     * @param  string $host
     * @param  string $error Set on failure
     * @return bool
     */
    private function isPublicHost(string $host, string &$error): bool
    {
        $ips = gethostbynamel($host);
        if ($ips === false || empty($ips)) {
            $error = 'Could not resolve hostname: ' . $host;
            return false;
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $error = 'URL resolves to a private or reserved address';
                return false;
            }
        }
        return true;
    }

    /**
     * Discover the img2sixel binary path.
     *
     * Checks IMG2SIXEL_PATH env var first, then probes each entry in SEARCH_PATHS.
     * Returns null if no executable binary is found.
     */
    private function discoverBinary(): ?string
    {
        $override = Config::env('IMG2SIXEL_PATH', '');
        if ($override !== '') {
            return is_executable($override) ? $override : null;
        }

        foreach (self::$SEARCH_PATHS as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
