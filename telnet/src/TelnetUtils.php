<?php

namespace BinktermPHP\TelnetServer;

/**
 * TelnetUtils - Shared utility functions for telnet daemon
 *
 * This class provides static utility methods used across multiple telnet handler classes.
 * Methods handle message sending, text formatting, quoting, and terminal output.
 */
class TelnetUtils
{
    // ANSI color constants
    public const ANSI_RESET = "\033[0m";
    public const ANSI_BOLD = "\033[1m";
    public const ANSI_DIM = "\033[2m";
    public const ANSI_BLUE = "\033[34m";
    public const ANSI_CYAN = "\033[36m";
    public const ANSI_GREEN = "\033[32m";
    public const ANSI_YELLOW = "\033[33m";
    public const ANSI_MAGENTA = "\033[35m";
    public const ANSI_RED = "\033[31m";
    /**
     * Send a netmail or echomail message via API
     *
     * @param string $apiBase Base URL for API requests
     * @param string $session Session token for authentication
     * @param array $payload Message data to send (to, from, subject, body, etc.)
     * @return array ['success' => bool, 'error' => string|null]
     */
    public static function sendMessage(string $apiBase, string $session, array $payload): array
    {
        $result = self::apiRequest($apiBase, 'POST', '/api/messages/send', $payload, $session);
        $success = ($result['status'] ?? 0) === 200 && !empty($result['data']['success']);
        $error = null;

        if (!$success) {
            // Try to get error message from API response
            if (!empty($result['data']['error'])) {
                $error = $result['data']['error'];
            } elseif (!empty($result['data']['message'])) {
                $error = $result['data']['message'];
            } elseif (!empty($result['error'])) {
                $error = 'Network error: ' . $result['error'];
            } else {
                $error = 'HTTP ' . ($result['status'] ?? 'unknown');
            }
        }

        return ['success' => $success, 'error' => $error];
    }

    /**
     * Make an API request to the BBS
     *
     * @param string $base Base URL for API
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path API endpoint path
     * @param array|null $payload Request payload for POST/PUT
     * @param string|null $session Session token for authentication
     * @param int $maxRetries Maximum retry attempts (default 3)
     * @return array ['status' => int, 'data' => array, 'error' => string|null]
     */
    public static function apiRequest(string $base, string $method, string $path, ?array $payload, ?string $session, int $maxRetries = 3): array
    {
        $url = rtrim($base, '/') . $path;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $attempt++;

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            if ($method === 'POST' || $method === 'PUT') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if ($payload !== null) {
                    $json = json_encode($payload);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Content-Length: ' . strlen($json)
                    ]);
                }
            }

            // Add session cookie if provided
            if ($session) {
                curl_setopt($ch, CURLOPT_COOKIE, "binktermphp_session={$session}");
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                // Network error - retry if attempts remain
                if ($attempt < $maxRetries) {
                    usleep(500000); // 0.5 second delay before retry
                    continue;
                }
                return [
                    'status' => 0,
                    'data' => [],
                    'error' => $error ?: 'Network error'
                ];
            }

            $data = json_decode($response, true);
            if ($data === null) {
                $data = ['raw' => $response];
            }

            return [
                'status' => $httpCode,
                'data' => $data,
                'error' => null
            ];
        }

        // Should never reach here, but just in case
        return [
            'status' => 0,
            'data' => [],
            'error' => 'Max retries exceeded'
        ];
    }

    /**
     * Quote message text for replies
     *
     * Formats the original message body with quote markers (>) and attribution line.
     *
     * @param string $body Original message body to quote
     * @param string $author Author of the original message
     * @return string Quoted message text with attribution
     */
    public static function quoteMessage(string $body, string $author): string
    {
        $lines = explode("\n", $body);
        $quoted = [];
        $quoted[] = '';
        $quoted[] = "On " . date('Y-m-d') . ", {$author} wrote:";
        $quoted[] = '';
        foreach ($lines as $line) {
            $quoted[] = '> ' . $line;
        }
        $quoted[] = '';
        $quoted[] = '';
        return implode("\n", $quoted);
    }

    /**
     * Normalize subject line by removing RE: prefixes
     *
     * @param string $subject Subject line to normalize
     * @return string Subject with RE: prefix removed
     */
    public static function normalizeSubject(string $subject): string
    {
        return preg_replace('/^Re:\\s*/i', '', trim($subject));
    }

    /**
     * Calculate messages per page based on terminal height
     *
     * Accounts for headers, prompts, and UI elements to determine
     * how many messages can fit on screen at once.
     *
     * @param array $state Terminal state containing 'rows' key
     * @return int Number of messages that fit per page (minimum 5)
     */
    public static function getMessagesPerPage(array &$state): int
    {
        $rows = $state['rows'] ?? 24;
        // Be very conservative: header (1), messages (N), blank (1), prompt (1-2), input (1), safety (2) = N + 7
        // So N = rows - 7
        $perPage = max(5, $rows - 7);

        // Log in debug mode
        if (!empty($GLOBALS['telnet_debug'])) {
            echo "[" . date('Y-m-d H:i:s') . "] List view: Screen rows={$rows}, messages per page={$perPage}\n";
        }

        return $perPage;
    }

    /**
     * Write text with word wrapping to terminal
     *
     * Handles line breaks and wraps long lines to fit terminal width.
     *
     * @param resource $conn Socket connection to write to
     * @param string $text Text to write (may contain newlines)
     * @param int $width Terminal width for wrapping
     * @return void
     */
    public static function writeWrapped($conn, string $text, int $width): void
    {
        $lines = preg_split("/\\r?\\n/", $text);
        foreach ($lines as $line) {
            if ($line === '') {
                self::writeLine($conn, '');
                continue;
            }
            $wrapped = wordwrap($line, max(20, $width), "\r\n", true);
            self::safeWrite($conn, $wrapped . "\r\n");
        }
    }

    /**
     * Write a line of text to terminal with CRLF
     *
     * @param resource $conn Socket connection
     * @param string $text Text to write (default empty string for blank line)
     * @return void
     */
    public static function writeLine($conn, string $text = ''): void
    {
        self::safeWrite($conn, $text . "\r\n");
    }

    /**
     * Write data to socket connection with error suppression
     *
     * @param resource $conn Socket connection
     * @param string $data Data to write
     * @return void
     */
    public static function safeWrite($conn, string $data): void
    {
        if (!is_resource($conn)) {
            return;
        }
        $prev = error_reporting();
        error_reporting($prev & ~E_NOTICE);
        @fwrite($conn, $data);
        error_reporting($prev);
    }

    /**
     * Write wrapped text with "more" pagination for long messages
     *
     * Pauses after each page and waits for keypress to continue.
     * User can press 'q' to quit early.
     *
     * @param resource $conn Socket connection to write to
     * @param string $text Text to write with pagination
     * @param int $width Terminal width for wrapping
     * @param int $height Terminal height for pagination
     * @param array $state Terminal state (passed by reference for readRawChar)
     * @return void
     */
    public static function writeWrappedWithMore($conn, string $text, int $width, int $height, array &$state): void
    {
        // Reserve lines for message header, separator, and prompt
        $linesPerPage = max(10, $height - 6);
        $currentLine = 0;

        $lines = preg_split("/\\r?\\n/", $text);
        $wrappedLines = [];

        // First, wrap all lines
        foreach ($lines as $line) {
            if ($line === '') {
                $wrappedLines[] = '';
                continue;
            }
            $wrapped = wordwrap($line, max(20, $width), "\n", true);
            $parts = explode("\n", $wrapped);
            foreach ($parts as $part) {
                $wrappedLines[] = $part;
            }
        }

        // Now output with pagination
        foreach ($wrappedLines as $line) {
            // Check if we need to pause for "more"
            if ($currentLine > 0 && $currentLine % $linesPerPage === 0) {
                // Show "-- More --" prompt
                self::safeWrite($conn, self::colorize("\r\n-- More -- (press any key to continue, q to quit) ", self::ANSI_YELLOW . self::ANSI_BOLD));

                // Read a single character
                $char = self::readRawChar($conn, $state);

                // Clear the "-- More --" line
                self::safeWrite($conn, "\r\033[K");

                // If user pressed 'q' or 'Q', stop displaying
                if ($char !== null && strtolower($char) === 'q') {
                    self::writeLine($conn, '');
                    self::writeLine($conn, self::colorize('[Message display interrupted]', self::ANSI_DIM));
                    return;
                }
            }

            self::safeWrite($conn, $line . "\r\n");
            $currentLine++;
        }
    }

    /**
     * Colorize text with ANSI escape codes
     *
     * @param string $text Text to colorize
     * @param string $color ANSI color code(s)
     * @return string Colorized text with reset code
     */
    public static function colorize(string $text, string $color): string
    {
        return $color . $text . self::ANSI_RESET;
    }

    /**
     * Read a single raw character from connection
     *
     * Handles pushback buffer and checks for connection validity.
     *
     * @param resource $conn Socket connection
     * @param array $state Terminal state (contains pushback buffer)
     * @return string|null Single character or null if connection closed
     */
    public static function readRawChar($conn, array &$state): ?string
    {
        // Check if connection is still valid
        if (!is_resource($conn) || feof($conn)) {
            return null;
        }

        if (!empty($state['pushback'])) {
            $char = $state['pushback'][0];
            $state['pushback'] = substr($state['pushback'], 1);
            return $char;
        }

        $char = fread($conn, 1);
        if ($char === false || $char === '') {
            return null;
        }

        return $char;
    }
}
