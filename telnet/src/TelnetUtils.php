<?php

namespace BinktermPHP\TelnetServer;

/**
 * TelnetUtils - Shared utility functions for telnet daemon
 *
 * This class provides static utility methods used across multiple telnet handler classes.
 * Methods handle API requests, text formatting, terminal output, and ANSI screen display.
 * Mail-specific utilities have been moved to MailUtils.
 */
class TelnetUtils
{
    // ANSI color constants
    public const ANSI_RESET = "\033[0m";
    public const ANSI_BOLD = "\033[1m";
    public const ANSI_DIM = "\033[2m";
    public const ANSI_BLUE = "\033[34m";
    public const ANSI_BG_BLUE = "\033[44m";
    public const ANSI_BG_RED = "\033[41m";
    public const ANSI_BG_WHITE = "\033[47m";
    public const ANSI_CYAN = "\033[36m";
    public const ANSI_GREEN = "\033[32m";
    public const ANSI_YELLOW = "\033[33m";
    public const ANSI_MAGENTA = "\033[35m";
    public const ANSI_RED = "\033[31m";

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
    public static function writeWrappedWithMore($conn, string $text, int $width, int $height, array &$state, int $reservedLines = 6): void
    {
        // Reserve lines for message header, separator, and prompt
        $linesPerPage = max(3, $height - $reservedLines);
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
     * Write wrapped text with pagination and a fixed header on each page
     *
     * Clears the screen for each page, renders header lines, and shows a "more" prompt.
     *
     * @param resource $conn Socket connection to write to
     * @param string $text Text to write with pagination
     * @param int $width Terminal width for wrapping
     * @param int $height Terminal height for pagination
     * @param array $state Terminal state (passed by reference for readRawChar)
     * @param array $headerLines Array of header lines (already colorized if desired)
     * @return void
     */
    public static function writeWrappedWithHeader($conn, string $text, int $width, int $height, array &$state, array $headerLines): void
    {
        $headerCount = count($headerLines);
        $linesPerPage = max(3, $height - $headerCount - 1);

        $lines = preg_split("/\\r?\\n/", $text);
        $wrappedLines = [];

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

        $total = count($wrappedLines);
        $offset = 0;
        while ($offset < $total) {
            // Clear screen and render header
            self::safeWrite($conn, "\033[2J\033[H");
            foreach ($headerLines as $line) {
                self::safeWrite($conn, $line . "\r\n");
            }

            $pageLines = array_slice($wrappedLines, $offset, $linesPerPage);
            foreach ($pageLines as $line) {
                self::safeWrite($conn, $line . "\r\n");
            }

            $offset += $linesPerPage;
            if ($offset >= $total) {
                break;
            }

            self::safeWrite($conn, self::colorize("\r\n-- More -- (press any key to continue, q to quit) ", self::ANSI_YELLOW . self::ANSI_BOLD));
            $char = self::readRawChar($conn, $state);
            self::safeWrite($conn, "\r\033[K");
            if ($char !== null && strtolower($char) === 'q') {
                self::writeLine($conn, '');
                self::writeLine($conn, self::colorize('[Message display interrupted]', self::ANSI_DIM));
                return;
            }
        }
    }

    /**
     * Wrap text into lines for display.
     *
     * @param string $text
     * @param int $width
     * @return array
     */
    public static function wrapTextLines(string $text, int $width): array
    {
        $lines = preg_split("/\\r?\\n/", $text);
        $wrappedLines = [];
        $wrapWidth = max(10, $width);

        foreach ($lines as $line) {
            if ($line === '') {
                $wrappedLines[] = '';
                continue;
            }
            $wrapped = wordwrap($line, $wrapWidth, "\n", true);
            $parts = explode("\n", $wrapped);
            foreach ($parts as $part) {
                $wrappedLines[] = $part;
            }
        }

        if ($wrappedLines === []) {
            $wrappedLines[] = '';
        }

        return $wrappedLines;
    }

    /**
     * Render a full-screen view with a fixed header and status bar.
     *
     * @param resource $conn
     * @param array $headerLines
     * @param array $bodyLines
     * @param string $statusLine
     * @param int $rows
     * @return void
     */
    public static function renderFullScreen($conn, array $headerLines, array $bodyLines, string $statusLine, int $rows): void
    {
        $headerCount = count($headerLines);
        $bodyHeight = max(1, $rows - $headerCount - 1);

        self::safeWrite($conn, "\033[2J\033[H");
        // Hide cursor while rendering to avoid scroll
        self::safeWrite($conn, "\033[?25l");

        foreach ($headerLines as $line) {
            self::safeWrite($conn, $line . "\r\n");
        }

        for ($i = 0; $i < $bodyHeight; $i++) {
            $line = $bodyLines[$i] ?? '';
            self::safeWrite($conn, $line . "\r\n");
        }

        // Render status line without adding an extra line (avoid scroll)
        self::safeWrite($conn, $statusLine . "\r");
        // Park cursor at top-left
        self::safeWrite($conn, "\033[H");
    }

    /**
     * Show or hide the cursor.
     *
     * @param resource $conn
     * @param bool $visible
     * @return void
     */
    public static function setCursorVisible($conn, bool $visible): void
    {
        self::safeWrite($conn, $visible ? "\033[?25h" : "\033[?25l");
    }

    /**
     * Build a colored status bar line with a white background.
     *
     * @param array $segments Array of ['text' => string, 'color' => string]
     * @param int $width
     * @return string
     */
    public static function buildStatusBar(array $segments, int $width): string
    {
        $bg = self::ANSI_BG_WHITE;
        $blue = self::ANSI_BLUE;
        $reset = self::ANSI_RESET;

        $plain = '';
        foreach ($segments as $segment) {
            $plain .= $segment['text'] ?? '';
        }
        $pad = max(0, $width - strlen($plain));

        $line = '';
        foreach ($segments as $segment) {
            $text = $segment['text'] ?? '';
            $color = $segment['color'] ?? $blue;
            $line .= $bg . $color . $text;
        }
        if ($pad > 0) {
            $line .= $bg . $blue . str_repeat(' ', $pad);
        }

        return $line . $reset;
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
     * Convert locale code to PHP date format string
     *
     * @param string $locale Locale code (e.g., 'en-US', 'en-GB')
     * @return string PHP date format string
     */
    private static function localeToPhpDateFormat(string $locale): string
    {
        // Map common locale codes to PHP date formats
        $formats = [
            'en-US' => 'Y-m-d H:i:s',  // 2026-02-06 14:30:45
            'en-GB' => 'd/m/Y H:i:s',  // 06/02/2026 14:30:45
            'de-DE' => 'd.m.Y H:i:s',  // 06.02.2026 14:30:45
            'fr-FR' => 'd/m/Y H:i:s',  // 06/02/2026 14:30:45
            'ja-JP' => 'Y/m/d H:i:s',  // 2026/02/06 14:30:45
        ];

        return $formats[$locale] ?? 'Y-m-d H:i:s';
    }

    /**
     * Format date using user's timezone and date format preferences
     *
     * Converts UTC date to user's timezone and formats according to their preference.
     * User timezone and format are stored in state during login.
     *
     * @param string $utcDate Date string in UTC (from database)
     * @param array $state Terminal state containing user_timezone and user_date_format
     * @param bool $includeTimezone Whether to append timezone abbreviation (default: true)
     * @return string Formatted date string, or original date if formatting fails
     */
    public static function formatUserDate(string $utcDate, array $state, bool $includeTimezone = true): string
    {
        if (empty($utcDate)) {
            return '';
        }

        $userTimezone = $state['user_timezone'] ?? 'UTC';
        $localeCode = $state['user_date_format'] ?? 'en-US';

        // Convert locale code to PHP date format
        $dateFormat = self::localeToPhpDateFormat($localeCode);

        try {
            $dt = new \DateTime($utcDate, new \DateTimeZone('UTC'));
            $dt->setTimezone(new \DateTimeZone($userTimezone));
            $formatted = $dt->format($dateFormat);

            if ($includeTimezone) {
                $formatted .= ' ' . $dt->format('T');
            }

            return $formatted;
        } catch (\Exception $e) {
            // Return original date if formatting fails
            return $utcDate;
        }
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

    /**
     * Format a message list line with proper column width calculations
     *
     * Calculates column widths based on terminal width to ensure the entire line
     * fits without wrapping or truncation.
     *
     * @param int $num Message number
     * @param string $from From name
     * @param string $subject Subject line
     * @param string $date Formatted date string
     * @param int $cols Terminal width
     * @return string Formatted line that fits within terminal width
     */
    public static function formatMessageListLine(int $num, string $from, string $subject, string $date, int $cols): string
    {
        // Reserve space for number and separators
        $numWidth = strlen(" {$num}) ");

        // Date column has fixed width based on formatted date
        $dateWidth = strlen($date);

        // Calculate remaining space for from and subject
        $remaining = $cols - $numWidth - $dateWidth - 2; // 2 for padding

        // Allocate proportionally: from gets ~30%, subject gets ~70% of remaining space
        $fromWidth = max(10, (int)($remaining * 0.3));
        $subjectWidth = max(10, $remaining - $fromWidth - 1); // 1 for space between columns

        // Truncate columns to fit
        $fromTrunc = substr($from, 0, $fromWidth);
        $subjectTrunc = substr($subject, 0, $subjectWidth);
        $dateTrunc = substr($date, 0, $dateWidth);

        // Build the line
        $line = sprintf(' %d) %-' . $fromWidth . 's %-' . $subjectWidth . 's %s',
            $num,
            $fromTrunc,
            $subjectTrunc,
            $dateTrunc
        );

        // Ensure final line doesn't exceed terminal width
        return substr($line, 0, $cols - 1);
    }

    public static function showScreenIfExists(string $screenFile, TelnetServer &$server, $conn)
    {
        $screenFile = __DIR__ . '/../screens/'.$screenFile;

        if (is_file($screenFile)) {
            $content = @file_get_contents($screenFile);
            if ($content !== false && $content !== '') {
                $content = str_replace("\r\n", "\n", $content);
                $content = str_replace("\n", "\r\n", $content);
                $server->safeWrite($conn, $content . "\r\n");
                return true;
            }
        }
        return false;
    }
}
