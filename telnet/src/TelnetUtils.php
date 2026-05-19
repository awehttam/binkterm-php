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
    /** Global session color toggle (set by BbsSession terminal settings). */
    private static bool $ansiColorEnabled = true;

    /**
     * Enable or disable ANSI color rendering globally for the active session.
     */
    public static function setAnsiColorEnabled(bool $enabled): void
    {
        self::$ansiColorEnabled = $enabled;
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
    public static function apiRequest(string $base, string $method, string $path, ?array $payload, ?string $session, int $maxRetries = 3, ?string $csrfToken = null): array
    {
        $url = rtrim($base, '/') . $path;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $attempt++;

            $ch = curl_init($url);
            $headers = [
                'Accept: application/json',
                'Cache-Control: no-cache, no-store, must-revalidate',
                'Pragma: no-cache',
                'Expires: 0',
            ];
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if ($payload !== null) {
                    $json    = json_encode($payload);
                    $headers[] = 'Content-Type: application/json';
                    $headers[] = 'Content-Length: ' . strlen($json);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
                }
                if ($csrfToken !== null) {
                    $headers[] = 'X-CSRF-Token: ' . $csrfToken;
                }
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

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
        $total  = strlen($data);
        $offset = 0;
        while ($offset < $total) {
            $written = @fwrite($conn, substr($data, $offset));
            if ($written === false || $written === 0) {
                break;  // connection closed or unrecoverable error
            }
            $offset += $written;
        }
        @fflush($conn);
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
     * Repaint only the body region of the message viewer without clearing the screen.
     *
     * Uses absolute cursor positioning to overwrite each body row in place.
     * The header and status bar are left untouched, eliminating flicker on scroll.
     * Only call this when ANSI cursor control is available.
     *
     * @param resource $conn
     * @param int      $headerCount Number of header lines (determines body start row)
     * @param array    $bodyLines   Visible body lines for the current scroll position
     * @param int      $rows        Terminal row count
     */
    private static function renderBodyOnly($conn, int $headerCount, array $bodyLines, int $rows): void
    {
        $bodyHeight   = max(1, $rows - $headerCount - 1);
        $bodyStartRow = $headerCount + 1; // 1-based screen row where body begins

        self::safeWrite($conn, "\033[?25l");

        for ($i = 0; $i < $bodyHeight; $i++) {
            $row  = $bodyStartRow + $i;
            $line = $bodyLines[$i] ?? '';
            // Position cursor at start of row, erase to EOL, write new content
            self::safeWrite($conn, "\033[{$row};1H\033[K" . $line);
        }

        self::safeWrite($conn, "\033[H");
    }

    /**
     * Run the shared message viewer loop for a single already-fetched message.
     *
     * Handles rendering and all scroll keys (Up/Down/PgUp/PgDn/Home/End)
     * internally. Returns when the user presses a key that requires the caller
     * to take action (navigate, reply, or quit).
     *
     * When $rebuildFn is provided the viewer supports live terminal resize:
     * after each keypress it compares the current $state['cols']/$state['rows']
     * to the values used for the last render. On change it calls $rebuildFn($state)
     * which must return:
     *   ['headerLines' => array, 'wrappedLines' => array, 'statusLine' => string]
     * The viewer then recalculates layout and does a full redraw.
     *
     * @param resource      $conn
     * @param array         &$state              Session state (passed by reference for idle tracking)
     * @param object        $server              BbsSession instance (provides readKeyWithIdleCheck)
     * @param array         $headerLines         Pre-built header lines (border, From, Subj, etc.)
     * @param array         $wrappedLines        Pre-wrapped/rendered body lines
     * @param string        $statusLine          Pre-built status bar string
     * @param int           $rows                Terminal row count (initial value; live value read from $state)
     * @param int           $initialOffset       Starting scroll offset (default 0)
     * @param bool          $allowDownloadAction Whether to return 'download' on Z key
     * @param array         $kludgeLines         Raw kludge lines for the H viewer
     * @param callable|null $rebuildFn           Optional resize callback: fn(array $state): array
     * @param array         $imageRefs           Image refs from TerminalMarkupRenderer::extractImageRefs()
     * @param callable|null $imageFn             Called with (int $zeroBasedIndex) to show an image
     * @return array{action: string, offset: int}
     *   action: 'quit' | 'prev' | 'next' | 'reply' | 'download'
     *   offset: scroll position at time of exit (unused for quit/reply)
     */
    public static function runMessageViewer(
        $conn,
        array &$state,
        $server,
        array $headerLines,
        array $wrappedLines,
        string $statusLine,
        int $rows,
        int $initialOffset = 0,
        bool $allowDownloadAction = false,
        array $kludgeLines = [],
        ?callable $rebuildFn = null,
        array $imageRefs = [],
        ?callable $imageFn = null,
        array $extraKeys = [],
        array $helpItems = []
    ): array {
        $headerCount = count($headerLines);
        $lastRows    = $state['rows'] ?? $rows;
        $lastCols    = $state['cols'] ?? 80;
        $bodyHeight  = max(1, $lastRows - $headerCount - 1);
        $maxOffset   = max(0, count($wrappedLines) - $bodyHeight);
        $offset      = min($initialOffset, $maxOffset);
        $fullRedraw  = true; // first render always does a full draw

        while (true) {
            $visibleLines = array_slice($wrappedLines, $offset, $bodyHeight);

            if ($fullRedraw || !self::$ansiColorEnabled) {
                self::renderFullScreen($conn, $headerLines, $visibleLines, $statusLine, $lastRows);
                $fullRedraw = false;
            } else {
                // Scroll only changed the body — repaint body rows in-place
                self::renderBodyOnly($conn, $headerCount, $visibleLines, $lastRows);
            }

            $key = $server->readKeyWithIdleCheck($conn, $state);

            // Check for terminal resize (NAWS update may have changed state mid-read)
            $newRows = $state['rows'] ?? $lastRows;
            $newCols = $state['cols'] ?? $lastCols;
            if (($newRows !== $lastRows || $newCols !== $lastCols) && $rebuildFn !== null) {
                $rebuilt      = $rebuildFn($state);
                $headerLines  = $rebuilt['headerLines'];
                $wrappedLines = $rebuilt['wrappedLines'];
                $statusLine   = $rebuilt['statusLine'];
                $headerCount  = count($headerLines);
                $bodyHeight   = max(1, $newRows - $headerCount - 1);
                $maxOffset    = max(0, count($wrappedLines) - $bodyHeight);
                $offset       = min($offset, $maxOffset);
                $lastRows     = $newRows;
                $lastCols     = $newCols;
                $fullRedraw   = true;
            }

            if ($key === null || $key === 'ENTER') {
                self::setCursorVisible($conn, true);
                return ['action' => 'quit', 'offset' => $offset];
            }
            if ($key === 'CHAR:q' || $key === 'CHAR:Q') {
                self::setCursorVisible($conn, true);
                return ['action' => 'quit', 'offset' => $offset];
            }
            if ($key === 'UP')     { if ($offset > 0) $offset--;                               continue; }
            if ($key === 'DOWN')   { if ($offset < $maxOffset) $offset++;                      continue; }
            if ($key === 'HOME')   { $offset = 0;                                              continue; }
            if ($key === 'END')    { $offset = $maxOffset;                                     continue; }
            if ($key === 'PGUP')   { $offset = max(0, $offset - $bodyHeight);                  continue; }
            if ($key === 'PGDOWN') { $offset = min($maxOffset, $offset + $bodyHeight);         continue; }

            // Returning from the kludge viewer requires a full redraw
            if ($key === 'CHAR:h' || $key === 'CHAR:H') {
                self::runKludgeViewer($conn, $state, $server, $kludgeLines, $lastRows);
                // Overlay may have received NAWS resize events — apply them now so the
                // viewer redraws at the correct size rather than waiting for the next key.
                $newRows = $state['rows'] ?? $lastRows;
                $newCols = $state['cols'] ?? $lastCols;
                if (($newRows !== $lastRows || $newCols !== $lastCols) && $rebuildFn !== null) {
                    $rebuilt      = $rebuildFn($state);
                    $headerLines  = $rebuilt['headerLines'];
                    $wrappedLines = $rebuilt['wrappedLines'];
                    $statusLine   = $rebuilt['statusLine'];
                    $headerCount  = count($headerLines);
                    $bodyHeight   = max(1, $newRows - $headerCount - 1);
                    $maxOffset    = max(0, count($wrappedLines) - $bodyHeight);
                    $offset       = min($offset, $maxOffset);
                    $lastRows     = $newRows;
                    $lastCols     = $newCols;
                }
                $fullRedraw = true;
                continue;
            }

            // Help overlay — shows all available key bindings
            if ($key === 'CTRL_K') {
                self::showHelpOverlay($conn, $state, $server, $helpItems, $allowDownloadAction, !empty($imageRefs), $lastRows);
                // Same resize-on-return handling as the kludge viewer above.
                $newRows = $state['rows'] ?? $lastRows;
                $newCols = $state['cols'] ?? $lastCols;
                if (($newRows !== $lastRows || $newCols !== $lastCols) && $rebuildFn !== null) {
                    $rebuilt      = $rebuildFn($state);
                    $headerLines  = $rebuilt['headerLines'];
                    $wrappedLines = $rebuilt['wrappedLines'];
                    $statusLine   = $rebuilt['statusLine'];
                    $headerCount  = count($headerLines);
                    $bodyHeight   = max(1, $newRows - $headerCount - 1);
                    $maxOffset    = max(0, count($wrappedLines) - $bodyHeight);
                    $offset       = min($offset, $maxOffset);
                    $lastRows     = $newRows;
                    $lastCols     = $newCols;
                }
                $fullRedraw = true;
                continue;
            }

            // Image viewer — I shows image 1 (or prompts for number when multiple exist);
            // digit keys 1-9 jump directly to that numbered image
            if ($imageFn !== null && !empty($imageRefs)) {
                $imgIdx = null;
                if ($key === 'CHAR:i' || $key === 'CHAR:I') {
                    if (count($imageRefs) === 1) {
                        $imgIdx = 0;
                    } else {
                        $imgIdx = self::promptImageNumber(
                            $conn, $state, $server, count($imageRefs), $lastRows, $statusLine
                        );
                        $fullRedraw = true;
                    }
                } elseif (preg_match('/^CHAR:([1-9])$/', $key, $km)) {
                    $candidate = (int)$km[1] - 1;
                    if ($candidate < count($imageRefs)) {
                        $imgIdx = $candidate;
                    }
                }
                if ($imgIdx !== null) {
                    ($imageFn)($imgIdx);
                    $fullRedraw = true;
                    continue;
                }
                if ($key === 'CHAR:i' || $key === 'CHAR:I') {
                    // Prompt was shown but cancelled — fullRedraw already set above
                    continue;
                }
            }

            // Navigation / action keys — return to caller
            if ($key === 'LEFT')                              return ['action' => 'prev',  'offset' => $offset];
            if ($key === 'RIGHT')                             return ['action' => 'next',  'offset' => $offset];
            if ($key === 'CHAR:r' || $key === 'CHAR:R')      return ['action' => 'reply', 'offset' => $offset];
            if ($allowDownloadAction && ($key === 'CHAR:z' || $key === 'CHAR:Z')) {
                return ['action' => 'download', 'offset' => $offset];
            }

            // Caller-supplied extra key bindings (char keys and named keys like 'DELETE')
            if (!empty($extraKeys)) {
                $lookup = str_starts_with($key, 'CHAR:') ? strtolower(substr($key, 5)) : $key;
                if (isset($extraKeys[$lookup])) {
                    self::setCursorVisible($conn, true);
                    return ['action' => $extraKeys[$lookup], 'offset' => $offset];
                }
            }
        }
    }

    /**
     * Display a scrollable view of message kludge lines (message headers).
     * Invoked when the user presses H in the message viewer.
     *
     * @param resource $conn
     * @param array    $state        Session state (cols, rows, locale, etc.)
     * @param object   $server       BbsSession instance
     * @param array    $kludgeLines  Pre-formatted kludge lines from extractKludgeLines()
     * @param int      $rows         Terminal row count
     */
    private static function runKludgeViewer($conn, array &$state, $server, array $kludgeLines, int $rows): void
    {
        $cols  = $state['cols'] ?? 80;
        $width = max(10, $cols - 2);

        $title       = $server->t('ui.terminalserver.message.headers_title', '=== Message Headers ===', [], $state['locale'] ?? 'en');
        $headerLines = [self::colorize(substr($title, 0, $width), self::ANSI_CYAN . self::ANSI_BOLD)];

        if (empty($kludgeLines)) {
            $noHeaders = $server->t('ui.terminalserver.message.no_headers', '(No message headers)', [], $state['locale'] ?? 'en');
            $kludgeLines = [self::colorize($noHeaders, self::ANSI_DIM)];
        }

        $bodyHeight = max(1, $rows - count($headerLines) - 1);
        $maxOffset  = max(0, count($kludgeLines) - $bodyHeight);
        $offset     = 0;

        $statusLine = self::buildStatusBar([
            ['text' => 'U/D',      'color' => self::ANSI_RED],
            ['text' => ' Scroll  ', 'color' => self::ANSI_BLUE],
            ['text' => 'PgUp/PgDn', 'color' => self::ANSI_RED],
            ['text' => ' Page  ',  'color' => self::ANSI_BLUE],
            ['text' => 'Q',        'color' => self::ANSI_RED],
            ['text' => ' Close',   'color' => self::ANSI_BLUE],
        ], $width);

        while (true) {
            $visibleLines = array_slice($kludgeLines, $offset, $bodyHeight);
            self::renderFullScreen($conn, $headerLines, $visibleLines, $statusLine, $rows);

            $key = $server->readKeyWithIdleCheck($conn, $state);

            if ($key === null || $key === 'ENTER' || $key === 'CHAR:q' || $key === 'CHAR:Q' || $key === 'CHAR:h' || $key === 'CHAR:H') {
                break;
            }
            if ($key === 'UP')     { if ($offset > 0) $offset--;                          }
            if ($key === 'DOWN')   { if ($offset < $maxOffset) $offset++;                  }
            if ($key === 'HOME')   { $offset = 0;                                          }
            if ($key === 'END')    { $offset = $maxOffset;                                 }
            if ($key === 'PGUP')   { $offset = max(0, $offset - $bodyHeight);              }
            if ($key === 'PGDOWN') { $offset = min($maxOffset, $offset + $bodyHeight);     }
        }
    }

    /**
     * Display a framed full-screen overlay listing all key bindings for the message viewer.
     *
     * Renders a dark-blue panel with charset-aware box-drawing borders, the title
     * embedded in the top rule, and two-column key/label rows. Shows built-in viewer
     * keys plus any caller-supplied extras from $helpItems. Dismissed by Q, Enter, or Ctrl-K.
     *
     * @param resource $conn
     * @param array    $state
     * @param object   $server
     * @param array    $helpItems        Caller-supplied keys: [['key'=>string,'label'=>string], ...]
     * @param bool     $hasAttachments   Whether the Z (download attachment) key is active
     * @param bool     $hasImages        Whether the I (image viewer) key is active
     * @param int      $rows
     */
    private static function showHelpOverlay(
        $conn,
        array &$state,
        $server,
        array $helpItems,
        bool $hasAttachments,
        bool $hasImages,
        int $rows
    ): void {
        $locale  = $state['locale'] ?? 'en';
        $cols    = $state['cols'] ?? 80;
        $charset = method_exists($server, 'getTerminalCharset') ? $server->getTerminalCharset() : 'ascii';
        $ansi    = self::$ansiColorEnabled;

        if ($charset === 'utf8') {
            $tl = '┌'; $tr = '┐'; $bl = '└'; $br = '┘'; $hz = '─'; $vt = '│';
        } elseif ($charset === 'cp437') {
            $tl = "\xda"; $tr = "\xbf"; $bl = "\xc0"; $br = "\xd9"; $hz = "\xc4"; $vt = "\xb3";
        } else {
            $tl = '+'; $tr = '+'; $bl = '+'; $br = '+'; $hz = '-'; $vt = '|';
        }

        $builtIn = [
            ['key' => 'Up / Down',    'label' => $server->t('ui.terminalserver.message.help_scroll',    'Scroll one line',        [], $locale)],
            ['key' => 'PgUp / PgDn',  'label' => $server->t('ui.terminalserver.message.help_page',      'Scroll one page',        [], $locale)],
            ['key' => 'Left / Right', 'label' => $server->t('ui.terminalserver.message.help_prev_next', 'Previous / next message', [], $locale)],
            ['key' => 'R',            'label' => $server->t('ui.terminalserver.message.help_reply',      'Reply',                  [], $locale)],
            ['key' => 'H',            'label' => $server->t('ui.terminalserver.message.help_headers',    'View message headers',   [], $locale)],
        ];
        if ($hasAttachments) {
            $builtIn[] = ['key' => 'Z', 'label' => $server->t('ui.terminalserver.message.help_download', 'Download attachment (ZMODEM)', [], $locale)];
        }
        if ($hasImages) {
            $builtIn[] = ['key' => 'I', 'label' => $server->t('ui.terminalserver.message.help_images', 'View inline image(s)', [], $locale)];
        }
        $builtIn[] = ['key' => 'Q / Enter', 'label' => $server->t('ui.terminalserver.message.help_quit', 'Quit / close message', [], $locale)];

        $allItems = array_merge($builtIn, $helpItems);

        // Key column: wide enough for the longest key name (content-based, never changes)
        $keyWidth = 0;
        foreach ($allItems as $item) {
            $keyWidth = max($keyWidth, mb_strlen($item['key']));
        }

        // Title line is constant (locale doesn't change mid-session)
        $title     = $server->t('ui.terminalserver.message.help_title', 'Key Bindings', [], $locale);
        $titleLine = ' ' . $title . ' ';
        $titleLen  = mb_strlen($titleLine);

        $rst   = self::ANSI_RESET;
        $bg    = self::ANSI_BG_BLUE;
        $frame = $bg . "\033[1;37m";  // bold white on dark blue  — borders
        $body  = $bg . "\033[37m";    // normal white on dark blue — content
        $keyC  = $bg . "\033[1;31m";  // bold red on dark blue     — key names

        // Layout variables — shared by reference between $rebuildLayout and $render
        // so a single $rebuildLayout() call is enough to update what $render() draws.
        $innerWidth = 0;
        $labelWidth = 0;
        $bodyHeight = 0;
        $btmRow     = 0;
        $topBorder  = '';
        $btmBorder  = '';
        $statusLine = '';
        $maxOffset  = 0;
        $offset     = 0;

        $rebuildLayout = function() use (
            &$rows, &$cols,
            &$innerWidth, &$labelWidth, &$bodyHeight, &$btmRow,
            &$topBorder, &$btmBorder, &$statusLine,
            &$maxOffset, &$offset,
            $allItems, $keyWidth, $tl, $tr, $bl, $br, $hz, $titleLine, $titleLen
        ): void {
            $innerWidth = max(10, $cols - 2);
            $labelWidth = max(1, $innerWidth - $keyWidth - 3);
            $bodyHeight = max(1, $rows - 3);
            $btmRow     = $rows - 1;

            $totalHz   = max(0, $innerWidth - $titleLen);
            $topBorder = $tl . str_repeat($hz, (int)floor($totalHz / 2)) . $titleLine
                            . str_repeat($hz, (int)ceil($totalHz / 2)) . $tr;
            $btmBorder = $bl . str_repeat($hz, $innerWidth) . $br;
            $statusLine = self::buildStatusBar([
                ['text' => 'Q',      'color' => self::ANSI_RED],
                ['text' => ' Close', 'color' => self::ANSI_BLUE],
            ], $cols);

            $maxOffset = max(0, count($allItems) - $bodyHeight);
            $offset    = min($offset, $maxOffset);
        };

        $render = static function() use (
            $conn, &$rows, &$btmRow, &$innerWidth, &$labelWidth, &$bodyHeight,
            $allItems, $keyWidth, &$offset, &$topBorder, &$btmBorder, &$statusLine,
            $vt, $frame, $body, $keyC, $rst, $ansi
        ): void {
            self::safeWrite($conn, "\033[2J\033[?25l");

            if ($ansi) {
                self::safeWrite($conn, "\033[1;1H" . $frame . $topBorder . $rst);
                for ($i = 0; $i < $bodyHeight; $i++) {
                    $r    = 2 + $i;
                    $item = $allItems[$offset + $i] ?? null;
                    self::safeWrite($conn, "\033[{$r};1H" . $body . $vt);
                    if ($item !== null) {
                        $k   = str_pad(mb_substr($item['key'],   0, $keyWidth), $keyWidth);
                        $lbl = mb_substr($item['label'], 0, $labelWidth);
                        $pad = str_repeat(' ', max(0, $labelWidth - mb_strlen($lbl)));
                        self::safeWrite($conn, ' ' . $keyC . $k . $body . '  ' . $lbl . $pad . $vt . $rst);
                    } else {
                        self::safeWrite($conn, str_repeat(' ', $innerWidth) . $vt . $rst);
                    }
                }
                self::safeWrite($conn, "\033[{$btmRow};1H" . $frame . $btmBorder . $rst);
            } else {
                self::safeWrite($conn, "\033[1;1H" . $topBorder);
                for ($i = 0; $i < $bodyHeight; $i++) {
                    $r    = 2 + $i;
                    $item = $allItems[$offset + $i] ?? null;
                    self::safeWrite($conn, "\033[{$r};1H" . $vt);
                    if ($item !== null) {
                        $k   = str_pad(mb_substr($item['key'],   0, $keyWidth), $keyWidth);
                        $lbl = mb_substr($item['label'], 0, $labelWidth);
                        $pad = str_repeat(' ', max(0, $labelWidth - mb_strlen($lbl)));
                        self::safeWrite($conn, ' ' . $k . '  ' . $lbl . $pad . $vt);
                    } else {
                        self::safeWrite($conn, str_repeat(' ', $innerWidth) . $vt);
                    }
                }
                self::safeWrite($conn, "\033[{$btmRow};1H" . $btmBorder);
            }

            self::safeWrite($conn, "\033[{$rows};1H" . $statusLine . "\033[?25h");
        };

        $rebuildLayout();
        $render();

        $lastRows = $rows;
        $lastCols = $cols;

        while (true) {
            $key = $server->readKeyWithIdleCheck($conn, $state);

            // Respond to terminal resize (NAWS may update $state during readKeyWithIdleCheck)
            $newRows = $state['rows'] ?? $lastRows;
            $newCols = $state['cols'] ?? $lastCols;
            if ($newRows !== $lastRows || $newCols !== $lastCols) {
                $rows     = $newRows;
                $cols     = $newCols;
                $lastRows = $newRows;
                $lastCols = $newCols;
                $rebuildLayout();
                $render();
            }

            if ($key === null || $key === 'ENTER' || $key === 'CHAR:q' || $key === 'CHAR:Q' || $key === 'CTRL_K') {
                break;
            }
            $changed = false;
            if ($key === 'UP'      && $offset > 0)          { $offset--; $changed = true; }
            if ($key === 'DOWN'    && $offset < $maxOffset)  { $offset++; $changed = true; }
            if ($key === 'HOME'    && $offset > 0)           { $offset = 0; $changed = true; }
            if ($key === 'END'     && $offset < $maxOffset)  { $offset = $maxOffset; $changed = true; }
            if ($key === 'PGUP')   { $new = max(0, $offset - $bodyHeight);    if ($new !== $offset) { $offset = $new; $changed = true; } }
            if ($key === 'PGDOWN') { $new = min($maxOffset, $offset + $bodyHeight); if ($new !== $offset) { $offset = $new; $changed = true; } }
            if ($changed) {
                $render();
            }
        }
    }

    /**
     * Display a single inline image as Sixel graphics on a full-screen viewer.
     *
     * Clears the screen, fetches the image (downloading to a temp file), converts
     * it with img2sixel, writes the Sixel data, then waits for any keypress before
     * returning. The caller should set fullRedraw = true after this returns.
     *
     * @param resource $conn       Terminal socket
     * @param array    $state      Session state (cols, rows, locale, etc.)
     * @param object   $server     BbsSession instance
     * @param array    $imageRef   Entry from TerminalMarkupRenderer::extractImageRefs()
     *                             {index:int, alt:string, url:string}
     * @param int      $totalImages Total image count in this message (for "N of M" display)
     * @param string   $apiBase    API base URL for resolving relative paths
     */
    public static function showSixelImageViewer(
        $conn,
        array &$state,
        $server,
        array $imageRef,
        int $totalImages,
        string $apiBase
    ): void {
        $cols   = $state['cols'] ?? 80;
        $width  = max(10, $cols - 2);
        $locale = $state['locale'] ?? 'en';

        self::safeWrite($conn, "\033[2J\033[H");

        $num   = (int)($imageRef['index'] ?? 1);
        $alt   = (string)($imageRef['alt'] ?? '');
        $url   = (string)($imageRef['url'] ?? '');
        $label = $alt !== '' ? $alt : $url;

        // Header
        self::writeLine($conn, self::colorize(
            'Image ' . $num . ' of ' . $totalImages . ': ' . $label,
            self::ANSI_CYAN . self::ANSI_BOLD
        ));
        self::writeLine($conn, self::colorize($url, self::ANSI_DIM));
        self::writeLine($conn, '');

        $renderer = new SixelImageRenderer($apiBase);

        if (!$renderer->isAvailable()) {
            self::writeLine($conn, self::colorize(
                'img2sixel is not installed on this server.',
                self::ANSI_RED
            ));
        } else {
            self::writeLine($conn, self::colorize('Fetching image...', self::ANSI_YELLOW));

            // Calculate pixel dimensions from terminal size.
            // Cap height to avoid overwhelming terminals (e.g. SyncTERM) that freeze
            // when the sixel stream is too large.  One character cell is approximately
            // pixelsPerCol wide by pixelsPerRow tall; the screen height in pixels gives
            // a natural upper bound for the image.
            $pixelsPerCol = (int)\BinktermPHP\Config::env('SIXEL_PIXELS_PER_COL', '9');
            $pixelsPerRow = (int)\BinktermPHP\Config::env('SIXEL_PIXELS_PER_ROW', '16');
            $rows         = $state['rows'] ?? 24;
            $maxWidth     = min(1600, $cols * $pixelsPerCol);
            $maxHeight    = min(1200, $rows * $pixelsPerRow);

            $fetchError = '';
            $sixelData  = $renderer->fetchAndConvert($url, $maxWidth, $fetchError, $maxHeight);

            if ($sixelData === null) {
                // Overwrite the "Fetching..." line
                self::safeWrite($conn, "\033[A\033[2K");
                self::writeLine($conn, self::colorize('Error: ' . $fetchError, self::ANSI_RED));
            } else {
                // Overwrite the "Fetching..." line then render the image
                self::safeWrite($conn, "\033[A\033[2K");
                $renderer->writeSixel($conn, $sixelData);
            }
        }

        // Belt-and-suspenders: send a second ST plus a DECSTR soft terminal reset
        // (ESC [ ! p) to force any terminal still stuck in DCS/sixel mode back to
        // normal mode before we emit plain text.  ST alone is sometimes not enough
        // for SyncTERM when the sixel stream was large or ended mid-parse.
        self::safeWrite($conn, "\033\\");
        self::safeWrite($conn, "\033[!p");

        // Drain any automatic escape sequences the terminal may have sent in response
        // to the DCS block (e.g. cursor-position reports, DA responses).  These arrive
        // within a few milliseconds; 150 ms is generous.  We discard up to 4 KB.
        $read = [$conn]; $w = $e = null;
        if (@stream_select($read, $w, $e, 0, 150000) > 0) {
            @fread($conn, 4096);
        }

        // Print the prompt at the current cursor position — directly below wherever the
        // image ended up after rendering.  Do NOT use absolute row positioning here:
        // sixel images scroll the terminal when they extend below the viewport, which
        // pushes any pre-positioned text off-screen before the user can read it.
        // writeSixel() already emitted \r\n after the ST, so cursor is at column 1.
        self::safeWrite($conn, "\r\n");
        self::safeWrite($conn, self::colorize(
            $server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
            self::ANSI_YELLOW
        ));

        // Loop until a recognised keypress is received. readKeyWithIdleCheck returns ''
        // for bytes that readTelnetKeyWithTimeout does not recognise (control codes,
        // spurious telnet negotiation bytes the terminal sends in response to the sixel
        // image, etc.).  Ignoring those keeps us waiting for real input.
        while (true) {
            $key = $server->readKeyWithIdleCheck($conn, $state);
            if ($key === null) { break; }   // idle disconnect
            if ($key !== '') { break; }     // recognised keypress — exit viewer
        }
    }

    /**
     * Render and run the interactive message list browser for one page.
     *
     * Renders the full screen (title, message lines, status bar) then handles
     * the key input loop. UP/DOWN are handled internally with single-line
     * re-renders. Returns when an action requiring the caller's attention occurs.
     *
     * @param resource $conn
     * @param array    $state         Session state (passed by reference for idle tracking)
     * @param object   $server        BbsSession instance (provides readKeyWithIdleCheck)
     * @param string   $title         Coloured header line already formatted by caller
     * @param array    $messages      Page of message summary arrays
     * @param int      $page          Current page number (1-based)
     * @param int      $totalPages    Total page count
     * @param int      $selectedIndex Currently highlighted row index
     * @return array{action: string, index: int, selectedIndex: int}
     *   action:        'quit' | 'disconnect' | 'read' | 'compose' | 'prev' | 'next'
     *   index:         message index to open (only meaningful for 'read')
     *   selectedIndex: updated highlight position
     */
    public static function runMessageList(
        $conn,
        array &$state,
        $server,
        string $title,
        array $messages,
        int $page,
        int $totalPages,
        int $selectedIndex,
        array $extraKeys = [],
        array $extraStatusSegments = []
    ): array {
        $cols = $state['cols'] ?? 80;

        // Pre-format rows without selection highlight; runSelectableList handles highlighting.
        $rows = [];
        foreach ($messages as $idx => $msg) {
            $rows[] = self::formatMessageListEntry($msg, $idx + 1, false, $cols, $state);
        }
        if (method_exists($server, 'encodeForTerminal')) {
            $rows = array_map(
                static fn(string $row): string => $server->encodeForTerminal($row),
                $rows
            );
            $title = $server->encodeForTerminal($title);
        }

        // Rebuild rows at the new terminal width on resize.
        $encodedTitle = $title;
        $rebuildFn = static function(array &$s) use ($messages, $server, $encodedTitle): array {
            $newCols = $s['cols'] ?? 80;
            $newRows = [];
            foreach ($messages as $idx => $msg) {
                $newRows[] = TelnetUtils::formatMessageListEntry($msg, $idx + 1, false, $newCols, $s);
            }
            if (method_exists($server, 'encodeForTerminal')) {
                $newRows = array_map(
                    static fn(string $row): string => $server->encodeForTerminal($row),
                    $newRows
                );
            }
            return ['rows' => $newRows, 'title' => $encodedTitle];
        };

        $statusBar = [
            ['text' => 'U/D',        'color' => self::ANSI_RED],
            ['text' => ' Move  ',    'color' => self::ANSI_BLUE],
            ['text' => 'L/R',        'color' => self::ANSI_RED],
            ['text' => ' Page  ',    'color' => self::ANSI_BLUE],
            ['text' => 'C',          'color' => self::ANSI_RED],
            ['text' => ' Compose  ', 'color' => self::ANSI_BLUE],
            ['text' => 'Enter',      'color' => self::ANSI_RED],
            ['text' => ' Read  ',    'color' => self::ANSI_BLUE],
            ['text' => 'Q',          'color' => self::ANSI_RED],
            ['text' => ' Quit',      'color' => self::ANSI_BLUE],
        ];
        if (!empty($extraStatusSegments)) {
            $statusBar = array_merge($statusBar, $extraStatusSegments);
        }

        $result = self::runSelectableList(
            $conn, $state, $server,
            $title, $rows, $page, $totalPages, $selectedIndex,
            $statusBar,
            array_merge(['c' => 'compose'], $extraKeys),
            $rebuildFn
        );

        // Map the generic 'select' action to the message-specific 'read' action.
        if ($result['action'] === 'select') {
            $result['action'] = 'read';
        }

        return $result;
    }

    /**
     * Format a single message list entry line with optional highlight.
     *
     * @param array  $msg       Message summary array
     * @param int    $num       Display number (1-based)
     * @param bool   $selected  Whether to apply selection highlight
     * @param int    $cols      Terminal column width
     * @param array  $state     Session state for date formatting
     * @return string Formatted and optionally coloured line
     */
    public static function formatMessageListEntry(array $msg, int $num, bool $selected, int $cols, array &$state): string
    {
        $from      = $msg['from_name'] ?? 'Unknown';
        $subject   = $msg['subject'] ?? '(no subject)';
        $dateShort = self::formatUserDate($msg['date_written'] ?? '', $state, false);
        $line      = self::formatMessageListLine($num, $from, $subject, $dateShort, $cols);
        if ($selected) {
            $line = self::colorize($line, self::ANSI_BG_BLUE . self::ANSI_BOLD);
        }
        return $line;
    }

    /**
     * Re-render a single message list row in-place without redrawing the screen.
     *
     * @param resource $conn
     * @param array    $messages     Full messages array for the current page
     * @param int      $idx          Zero-based index of the row to update
     * @param bool     $selected     Whether to apply selection highlight
     * @param int      $listStartRow Screen row where the list begins (1-based)
     * @param int      $cols         Terminal column width
     * @param array    $state        Session state for date formatting
     */
    public static function renderMessageListLine($conn, array $messages, int $idx, bool $selected, int $listStartRow, int $cols, array &$state): void
    {
        if (!isset($messages[$idx])) {
            return;
        }
        $line = self::formatMessageListEntry($messages[$idx], $idx + 1, $selected, $cols, $state);
        $row  = $listStartRow + $idx;
        self::safeWrite($conn, "\033[{$row};1H");
        self::safeWrite($conn, str_pad($line, max(1, $cols - 1)));
    }

    /**
     * Strip ANSI SGR (Select Graphic Rendition) escape sequences from a string,
     * returning plain text suitable for visual-width calculations or highlight rendering.
     *
     * Only SGR sequences (\033[...m) are stripped. Other ANSI control codes such as
     * cursor movement or erase sequences are not affected; callers should avoid
     * including those in pre-formatted rows passed to runSelectableList().
     *
     * @param string $text
     * @return string
     */
    private static function stripAnsi(string $text): string
    {
        return (string)preg_replace('/\033\[[0-9;]*m/', '', $text);
    }

    /**
     * Re-render a single selectable-list row in-place without redrawing the screen.
     *
     * When selected, ANSI sequences are stripped from the row before applying the
     * full-row blue highlight so that inner color resets cannot break the background.
     *
     * @param resource $conn
     * @param array    $rows         Pre-formatted row strings (no selection highlight)
     * @param int      $idx          Zero-based index of the row to update
     * @param bool     $selected     Whether to apply selection highlight
     * @param int      $listStartRow Screen row where the list begins (1-based)
     * @param int      $cols         Terminal column width
     */
    private static function renderSelectableListLine($conn, array $rows, int $idx, bool $selected, int $listStartRow, int $cols): void
    {
        if (!isset($rows[$idx])) {
            return;
        }
        $plain = self::stripAnsi($rows[$idx]);
        if ($selected) {
            $line = self::colorize(str_pad($plain, max(1, $cols - 1)), self::ANSI_BG_BLUE . self::ANSI_BOLD);
        } else {
            $line = $rows[$idx];
        }
        $row = $listStartRow + $idx;
        self::safeWrite($conn, "\033[{$row};1H\033[K");
        self::safeWrite($conn, $line);
    }

    /**
     * Render and run an interactive selectable list for one page.
     *
     * Renders the full screen (title, pre-formatted rows, status bar) then handles
     * the key input loop. UP/DOWN are handled in-place with single-line re-renders.
     * Returns when an action requiring the caller's attention occurs.
     *
     * Rows should be passed **without** a selection highlight applied; the highlight
     * is applied internally by this method. Inline ANSI colour sequences in rows are
     * automatically stripped before the selection highlight is drawn so the full-row
     * blue background is always rendered cleanly.
     *
     * @param resource $conn
     * @param array    $state         Session state (passed by reference for idle tracking)
     * @param object   $server        BbsSession instance (provides readKeyWithIdleCheck)
     * @param string   $title         Coloured header line already formatted by caller
     * @param array    $rows          Pre-formatted display strings for each list item
     *                                (current page only, no selection highlight)
     * @param int      $page          Current page number (1-based)
     * @param int      $totalPages    Total page count
     * @param int      $selectedIndex Currently highlighted row index (0-based)
     * @param array         $statusBar     Status bar segments: [['text' => string, 'color' => string], ...]
     * @param array         $extraKeys     Optional extra single-char key bindings (lowercase): ['c' => 'compose', ...]
     *                                     Built-in keys (q, n, p, and digits) always take precedence;
     *                                     attempting to bind those keys here is silently ignored.
     * @param callable|null $rebuildFn     Optional resize callback: fn(array &$state): array{rows: string[], title: string}
     *                                     When provided, called on terminal resize to reformat rows and title at the
     *                                     new dimensions before triggering a full repaint.
     * @return array{action: string, index: int, selectedIndex: int}
     *   action:        'quit' | 'disconnect' | 'select' | 'prev' | 'next' | (value from $extraKeys)
     *   index:         item index (meaningful for 'select')
     *   selectedIndex: updated highlight position
     */
    public static function runSelectableList(
        $conn,
        array &$state,
        $server,
        string $title,
        array $rows,
        int $page,
        int $totalPages,
        int $selectedIndex,
        array $statusBar,
        array $extraKeys = [],
        ?callable $rebuildFn = null
    ): array {
        $cols         = $state['cols'] ?? 80;
        $termRows     = $state['rows'] ?? 24;
        $rowCount     = count($rows);
        $listStartRow = 2;
        $inputRow     = max(1, $termRows - 1);

        // --- Render full screen ---
        self::safeWrite($conn, "\033[2J\033[H");
        self::writeLine($conn, $title);

        foreach ($rows as $idx => $row) {
            $plain = self::stripAnsi($row);
            if ($idx === $selectedIndex) {
                self::writeLine($conn, self::colorize(str_pad($plain, max(1, $cols - 1)), self::ANSI_BG_BLUE . self::ANSI_BOLD));
            } else {
                self::writeLine($conn, $row);
            }
        }

        $statusLine = self::buildStatusBar($statusBar, $cols);
        self::safeWrite($conn, "\033[{$inputRow};1H\033[K");
        self::safeWrite($conn, $statusLine . "\r");
        self::safeWrite($conn, "\033[{$inputRow};1H");

        // --- Key loop ---
        $buffer        = '';
        $inputColStart = 1;

        while (true) {
            $key = $server->readKeyWithIdleCheck($conn, $state);

            if ($key === null) {
                return ['action' => 'disconnect', 'index' => $selectedIndex, 'selectedIndex' => $selectedIndex];
            }

            // Detect terminal resize (NAWS update may have changed state mid-read)
            $newCols     = $state['cols'] ?? $cols;
            $newTermRows = $state['rows'] ?? $termRows;
            if ($newCols !== $cols || $newTermRows !== $termRows) {
                $cols      = $newCols;
                $termRows  = $newTermRows;
                $inputRow  = max(1, $termRows - 1);
                if ($rebuildFn !== null) {
                    $rebuilt       = $rebuildFn($state);
                    $rows          = $rebuilt['rows'];
                    $title         = $rebuilt['title'];
                    $rowCount      = count($rows);
                    $selectedIndex = min($selectedIndex, max(0, $rowCount - 1));
                }
                $buffer     = '';
                $statusLine = self::buildStatusBar($statusBar, $cols);
                self::safeWrite($conn, "\033[2J\033[H");
                self::writeLine($conn, $title);
                foreach ($rows as $idx => $row) {
                    $plain = self::stripAnsi($row);
                    if ($idx === $selectedIndex) {
                        self::writeLine($conn, self::colorize(str_pad($plain, max(1, $cols - 1)), self::ANSI_BG_BLUE . self::ANSI_BOLD));
                    } else {
                        self::writeLine($conn, $row);
                    }
                }
                self::safeWrite($conn, "\033[{$inputRow};1H\033[K");
                self::safeWrite($conn, $statusLine . "\r");
                self::safeWrite($conn, "\033[{$inputRow};1H");
            }

            if ($key === 'LEFT') {
                if ($page > 1) {
                    return ['action' => 'prev', 'index' => 0, 'selectedIndex' => 0];
                }
                continue;
            }

            if ($key === 'RIGHT') {
                if ($page < $totalPages) {
                    return ['action' => 'next', 'index' => 0, 'selectedIndex' => 0];
                }
                continue;
            }

            if ($key === 'UP') {
                if ($selectedIndex > 0) {
                    $prev = $selectedIndex;
                    $selectedIndex--;
                    self::renderSelectableListLine($conn, $rows, $prev,          false, $listStartRow, $cols);
                    self::renderSelectableListLine($conn, $rows, $selectedIndex, true,  $listStartRow, $cols);
                }
                self::safeWrite($conn, "\033[{$inputRow};" . ($inputColStart + strlen($buffer)) . "H");
                continue;
            }

            if ($key === 'DOWN') {
                if ($selectedIndex < $rowCount - 1) {
                    $prev = $selectedIndex;
                    $selectedIndex++;
                    self::renderSelectableListLine($conn, $rows, $prev,          false, $listStartRow, $cols);
                    self::renderSelectableListLine($conn, $rows, $selectedIndex, true,  $listStartRow, $cols);
                }
                self::safeWrite($conn, "\033[{$inputRow};" . ($inputColStart + strlen($buffer)) . "H");
                continue;
            }

            if ($key === 'BACKSPACE') {
                if ($buffer !== '') {
                    $buffer = substr($buffer, 0, -1);
                    self::safeWrite($conn, "\x08 \x08");
                }
                continue;
            }

            if ($key === 'ENTER') {
                $input  = strtolower(trim($buffer));
                $buffer = '';
                if ($input === '') {
                    if (isset($rows[$selectedIndex])) {
                        return ['action' => 'select', 'index' => $selectedIndex, 'selectedIndex' => $selectedIndex];
                    }
                    continue;
                }
                if ($input === 'q') { return ['action' => 'quit', 'index' => 0, 'selectedIndex' => $selectedIndex]; }
                if ($input === 'n') {
                    if ($page < $totalPages) { return ['action' => 'next', 'index' => 0, 'selectedIndex' => 0]; }
                    continue;
                }
                if ($input === 'p') {
                    if ($page > 1) { return ['action' => 'prev', 'index' => 0, 'selectedIndex' => 0]; }
                    continue;
                }
                if (isset($extraKeys[$input])) {
                    return ['action' => $extraKeys[$input], 'index' => $selectedIndex, 'selectedIndex' => $selectedIndex];
                }
                $choice = (int)$input;
                if ($choice > 0 && $choice <= $rowCount) {
                    return ['action' => 'select', 'index' => $choice - 1, 'selectedIndex' => $choice - 1];
                }
                continue;
            }

            if (str_starts_with($key, 'CHAR:')) {
                $char  = substr($key, 5);
                $lower = strtolower($char);
                if ($lower === 'q') { return ['action' => 'quit', 'index' => 0, 'selectedIndex' => $selectedIndex]; }
                if ($lower === 'n') {
                    if ($page < $totalPages) { return ['action' => 'next', 'index' => 0, 'selectedIndex' => 0]; }
                    continue;
                }
                if ($lower === 'p') {
                    if ($page > 1) { return ['action' => 'prev', 'index' => 0, 'selectedIndex' => 0]; }
                    continue;
                }
                if (isset($extraKeys[$lower])) {
                    return ['action' => $extraKeys[$lower], 'index' => $selectedIndex, 'selectedIndex' => $selectedIndex];
                }
                if (ctype_digit($char)) {
                    $buffer .= $char;
                    self::safeWrite($conn, $char);
                    $num = (int)$buffer;
                    if ($num > 0 && $num <= $rowCount) {
                        $prev          = $selectedIndex;
                        $selectedIndex = $num - 1;
                        if ($prev !== $selectedIndex) {
                            self::renderSelectableListLine($conn, $rows, $prev,          false, $listStartRow, $cols);
                            self::renderSelectableListLine($conn, $rows, $selectedIndex, true,  $listStartRow, $cols);
                        }
                        self::safeWrite($conn, "\033[{$inputRow};" . ($inputColStart + strlen($buffer)) . "H");
                    }
                    continue;
                }
                $buffer .= $char;
                self::safeWrite($conn, $char);
            }
        }
    }

    /**
     * Show an inline image-number prompt on the status bar and read a number (1–99).
     *
     * Overwrites the status line with "View image [1-N]: ", echoes each digit as the
     * user types (up to 2 digits), and confirms on Enter or a second digit. Restores
     * the original status line on ESC, Q, or an out-of-range entry. Returns the
     * 0-based image index chosen by the user, or null if cancelled.
     *
     * @param  resource $conn
     * @param  array    &$state
     * @param  object   $server
     * @param  int      $total      Total number of images in this message
     * @param  int      $rows       Current terminal row count
     * @param  string   $statusLine The existing status line (restored on cancel/return)
     * @return int|null             0-based image index, or null if cancelled
     */
    private static function promptImageNumber($conn, array &$state, $server, int $total, int $rows, string $statusLine): ?int
    {
        $maxDigits = strlen((string)$total); // 1 digit for ≤9, 2 for ≤99
        $prompt    = ' View image [1-' . $total . ']: ';

        $renderPrompt = function (string $typed) use ($conn, $rows, $prompt): void {
            if (self::$ansiColorEnabled) {
                $line = "\033[" . $rows . ";1H\033[K"
                    . self::ANSI_BG_WHITE . self::ANSI_BLUE . self::ANSI_BOLD
                    . $prompt . self::ANSI_RESET
                    . self::ANSI_BG_WHITE . self::ANSI_BLUE
                    . $typed
                    . self::ANSI_RESET;
            } else {
                $line = "\033[" . $rows . ";1H\033[K" . $prompt . $typed;
            }
            self::safeWrite($conn, $line);
        };

        $restore = function () use ($conn, $rows, $statusLine): void {
            self::setCursorVisible($conn, false);
            self::safeWrite($conn, "\033[" . $rows . ";1H\033[K" . $statusLine . "\r");
            self::safeWrite($conn, "\033[H");
        };

        $renderPrompt('');
        self::setCursorVisible($conn, true);

        $digits = '';
        while (true) {
            $key = $server->readKeyWithIdleCheck($conn, $state);

            if ($key === null || $key === 'ESC' || $key === 'CHAR:q' || $key === 'CHAR:Q') {
                $restore();
                return null;
            }

            if ($key === 'BACKSPACE' || $key === 'CHAR:\x7f') {
                if ($digits !== '') {
                    $digits = substr($digits, 0, -1);
                    $renderPrompt($digits);
                }
                continue;
            }

            if ($key === 'ENTER') {
                break;
            }

            if (preg_match('/^CHAR:([0-9])$/', $key, $m)) {
                // Don't allow leading zero
                if ($digits === '' && $m[1] === '0') {
                    continue;
                }
                $digits .= $m[1];
                $renderPrompt($digits);

                // Auto-confirm when max digits reached
                if (strlen($digits) >= $maxDigits) {
                    break;
                }
                continue;
            }
        }

        $restore();

        if ($digits === '') {
            return null;
        }

        $num = (int)$digits;
        if ($num >= 1 && $num <= $total) {
            return $num - 1;
        }

        return null;
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
        if (!self::$ansiColorEnabled) {
            $plain = '';
            foreach ($segments as $segment) {
                $plain .= $segment['text'] ?? '';
            }
            if (strlen($plain) < $width) {
                $plain .= str_repeat(' ', $width - strlen($plain));
            }
            return $plain;
        }

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
     * Build a framed message header box using charset-appropriate box-drawing characters.
     *
     * In ANSI mode renders a dark-blue panel with gray border characters.
     * In plain mode renders a simple ASCII/CP437 box without color sequences.
     *
     * @param int    $width   Total visual width of the box including border chars.
     *                        Should match the viewer's $width (typically $cols - 2).
     * @param array  $fields  Array of field descriptors:
     *                        ['label' => string, 'value' => string, 'style' => 'normal'|'dim'|'bold']
     * @param string $charset 'utf8', 'cp437', or 'ascii'
     * @return array Lines suitable for passing as $headerLines to runMessageViewer()
     */
    public static function buildMessageHeaderBox(int $width, array $fields, string $charset = 'ascii'): array
    {
        // Charset-specific box-drawing characters
        if ($charset === 'utf8') {
            $tl = '┌'; $tr = '┐'; $bl = '└'; $br = '┘'; $hz = '─'; $vt = '│';
        } elseif ($charset === 'cp437') {
            $tl = "\xda"; $tr = "\xbf"; $bl = "\xc0"; $br = "\xd9"; $hz = "\xc4"; $vt = "\xb3";
        } else {
            $tl = '+'; $tr = '+'; $bl = '+'; $br = '+'; $hz = '-'; $vt = '|';
        }

        // Inner content width: box width minus two corner/vertical chars and two space pads
        $innerWidth = max(0, $width - 4);
        $hFill      = str_repeat($hz, max(0, $width - 2));

        if (!self::$ansiColorEnabled) {
            $lines   = [$tl . $hFill . $tr];
            foreach ($fields as $field) {
                $text    = self::encodeHeaderTextForCharset(
                    ($field['label'] ?? '') . ($field['value'] ?? ''),
                    $charset
                );
                $text    = str_pad(substr($text, 0, $innerWidth), $innerWidth);
                $lines[] = $vt . ' ' . $text . ' ' . $vt;
            }
            $lines[] = $bl . $hFill . $br;
            return $lines;
        }

        // ANSI mode: dark blue background, gray frame characters
        $bg    = self::ANSI_BG_BLUE;
        $rst   = self::ANSI_RESET;
        $frame = $bg . "\033[37m"; // gray foreground on dark blue background

        $lines   = [$frame . $tl . $hFill . $tr . $rst];

        foreach ($fields as $field) {
            $text = self::encodeHeaderTextForCharset(
                ($field['label'] ?? '') . ($field['value'] ?? ''),
                $charset
            );
            $text = str_pad(substr($text, 0, $innerWidth), $innerWidth);

            $contentAnsi = match ($field['style'] ?? 'normal') {
                'bold'  => $bg . "\033[1;37m",  // bold white on dark blue
                'dim'   => $bg . "\033[2;37m",  // dim gray on dark blue
                default => $bg . "\033[37m",     // gray on dark blue
            };

            $lines[] = $frame . $vt . $contentAnsi . ' ' . $text . ' ' . $frame . $vt . $rst;
        }

        $lines[] = $frame . $bl . $hFill . $br . $rst;
        return $lines;
    }

    /**
     * Display a centered confirmation dialog overlay and wait for a keypress.
     *
     * Draws the dialog on top of existing screen content without clearing it.
     * The caller should trigger a full redraw (e.g. set $fullRedraw = true) after
     * this returns if the underlying screen needs to be restored.
     *
     * @param array  $choices Map of lowercase char => label, e.g. ['y' => 'Confirm', 'n' => 'Cancel']
     * @param string $default Char to return on disconnect or bare Enter; must be a key in $choices.
     * @return string The chosen lowercase char.
     */
    public static function showConfirmDialog(
        $conn,
        array &$state,
        $server,
        string $title,
        string $message,
        array $choices = ['y' => 'Confirm', 'n' => 'Cancel'],
        string $default = 'n'
    ): string {
        $rows    = $state['rows'] ?? 24;
        $cols    = $state['cols'] ?? 80;
        $charset = method_exists($server, 'getTerminalCharset') ? $server->getTerminalCharset() : 'ascii';

        // Box-drawing characters
        if ($charset === 'utf8') {
            $tl = '┌'; $tr = '┐'; $bl = '└'; $br = '┘'; $hz = '─'; $vt = '│';
        } elseif ($charset === 'cp437') {
            $tl = "\xda"; $tr = "\xbf"; $bl = "\xc0"; $br = "\xd9"; $hz = "\xc4"; $vt = "\xb3";
        } else {
            $tl = '+'; $tr = '+'; $bl = '+'; $br = '+'; $hz = '-'; $vt = '|';
        }

        // Build plain-text key hint string for width calculation
        $hintParts = [];
        foreach ($choices as $char => $label) {
            $hintParts[] = ['char' => strtoupper((string)$char), 'label' => (string)$label];
        }
        $sep       = '    ';
        $plainHint = implode($sep, array_map(fn($p) => $p['char'] . ') ' . $p['label'], $hintParts));
        $hintsLen  = mb_strlen($plainHint);

        // Inner width: fits all content, capped to terminal width
        $innerWidth = max(
            24,
            min(
                max(mb_strlen($message) + 2, mb_strlen($title) + 4, $hintsLen + 4),
                min($cols - 6, 58)
            )
        );
        $boxWidth = $innerWidth + 2;

        // Top border with centered title embedded in the rule
        $titleLine = ' ' . $title . ' ';
        $titleLen  = mb_strlen($titleLine);
        $totalHz   = max(0, $innerWidth - $titleLen);
        $topBorder = $tl . str_repeat($hz, (int)floor($totalHz / 2)) . $titleLine
                        . str_repeat($hz, (int)ceil($totalHz / 2)) . $tr;
        $btmBorder = $bl . str_repeat($hz, $innerWidth) . $br;
        $emptyRow  = $vt . str_repeat(' ', $innerWidth) . $vt;

        // Message row
        $msgContent = str_pad(mb_substr($message, 0, $innerWidth - 2), $innerWidth - 2);
        $msgRow     = $vt . ' ' . $msgContent . ' ' . $vt;

        // Hint row padding
        $leftPad  = max(0, (int)floor(($innerWidth - $hintsLen) / 2));
        $rightPad = max(0, $innerWidth - $hintsLen - $leftPad);

        // Center dialog on screen
        $dialogHeight = 6; // top + empty + message + empty + hints + bottom
        $startRow = max(1, (int)round(($rows - $dialogHeight) / 2));
        $startCol = max(1, (int)round(($cols - $boxWidth)    / 2));

        $ansi  = self::$ansiColorEnabled;
        $bg    = self::ANSI_BG_BLUE;
        $rst   = self::ANSI_RESET;
        $frame = $bg . "\033[1;37m"; // bold white on dark blue
        $body  = $bg . "\033[37m";   // normal white on dark blue

        $draw = static function(int $r, string $line) use ($conn, $startCol): void {
            self::safeWrite($conn, "\033[{$r};{$startCol}H{$line}");
        };

        self::safeWrite($conn, "\033[?25l"); // hide cursor while drawing

        $r = $startRow;
        if ($ansi) {
            $draw($r++, $frame . $topBorder . $rst);
            $draw($r++, $body  . $emptyRow  . $rst);
            $draw($r++, $body  . $msgRow    . $rst);
            $draw($r++, $body  . $emptyRow  . $rst);

            $hintContent = str_repeat(' ', $leftPad);
            foreach ($hintParts as $i => $part) {
                if ($i > 0) {
                    $hintContent .= $body . $sep;
                }
                $hintContent .= self::ANSI_RED . self::ANSI_BOLD . $part['char']
                              . $body . ') ' . $part['label'];
            }
            $hintContent .= str_repeat(' ', $rightPad);
            $draw($r++, $body . $vt . $hintContent . $body . $vt . $rst);
            $draw($r,   $frame . $btmBorder . $rst);
        } else {
            $draw($r++, $topBorder);
            $draw($r++, $emptyRow);
            $draw($r++, $msgRow);
            $draw($r++, $emptyRow);
            $draw($r++, $vt . str_repeat(' ', $leftPad) . $plainHint . str_repeat(' ', $rightPad) . $vt);
            $draw($r,   $btmBorder);
        }

        self::safeWrite($conn, "\033[?25h"); // restore cursor

        // Read a keypress — only accept keys in $choices
        while (true) {
            $key = $server->readKeyWithIdleCheck($conn, $state);
            if ($key === null || $key === 'ENTER') {
                return $default;
            }
            if (str_starts_with($key, 'CHAR:')) {
                $char = strtolower(substr($key, 5));
                if (isset($choices[$char])) {
                    return $char;
                }
            }
        }
    }

    /**
     * Draw a centered "please wait" overlay and return immediately (no key read).
     * Intended to be drawn before a blocking operation; a subsequent showAlertDialog
     * call will naturally overdraw it.
     */
    public static function showWorkingOverlay(
        $conn,
        array &$state,
        $server,
        string $message
    ): void {
        $rows    = $state['rows'] ?? 24;
        $cols    = $state['cols'] ?? 80;
        $charset = method_exists($server, 'getTerminalCharset') ? $server->getTerminalCharset() : 'ascii';

        if ($charset === 'utf8') {
            $tl = '┌'; $tr = '┐'; $bl = '└'; $br = '┘'; $hz = '─'; $vt = '│';
        } elseif ($charset === 'cp437') {
            $tl = "\xda"; $tr = "\xbf"; $bl = "\xc0"; $br = "\xd9"; $hz = "\xc4"; $vt = "\xb3";
        } else {
            $tl = '+'; $tr = '+'; $bl = '+'; $br = '+'; $hz = '-'; $vt = '|';
        }

        $innerWidth = max(24, min(mb_strlen($message) + 4, min($cols - 6, 58)));
        $boxWidth   = $innerWidth + 2;

        $msgContent = str_pad(mb_substr($message, 0, $innerWidth - 2), $innerWidth - 2);
        $msgRow     = $vt . ' ' . $msgContent . ' ' . $vt;
        $emptyRow   = $vt . str_repeat(' ', $innerWidth) . $vt;
        $topBorder  = $tl . str_repeat($hz, $innerWidth) . $tr;
        $btmBorder  = $bl . str_repeat($hz, $innerWidth) . $br;

        $dialogHeight = 4; // top + empty + message + bottom
        $startRow = max(1, (int)round(($rows - $dialogHeight) / 2));
        $startCol = max(1, (int)round(($cols - $boxWidth)    / 2));

        $ansi  = self::$ansiColorEnabled;
        $bg    = self::ANSI_BG_BLUE;
        $rst   = self::ANSI_RESET;
        $frame = $bg . "\033[1;37m";
        $body  = $bg . "\033[37m";

        $draw = static function(int $r, string $line) use ($conn, $startCol): void {
            self::safeWrite($conn, "\033[{$r};{$startCol}H{$line}");
        };

        self::safeWrite($conn, "\033[?25l");

        $r = $startRow;
        if ($ansi) {
            $draw($r++, $frame . $topBorder . $rst);
            $draw($r++, $body  . $emptyRow  . $rst);
            $draw($r++, $body  . $msgRow    . $rst);
            $draw($r,   $frame . $btmBorder . $rst);
        } else {
            $draw($r++, $topBorder);
            $draw($r++, $emptyRow);
            $draw($r++, $msgRow);
            $draw($r,   $btmBorder);
        }
    }

    /**
     * Show a centered alert dialog dismissed with Enter.
     *
     * @param string $style 'info' (blue background) or 'error' (red background)
     */
    public static function showAlertDialog(
        $conn,
        array &$state,
        $server,
        string $title,
        string $message,
        string $style = 'info'
    ): void {
        $rows    = $state['rows'] ?? 24;
        $cols    = $state['cols'] ?? 80;
        $charset = method_exists($server, 'getTerminalCharset') ? $server->getTerminalCharset() : 'ascii';

        if ($charset === 'utf8') {
            $tl = '┌'; $tr = '┐'; $bl = '└'; $br = '┘'; $hz = '─'; $vt = '│';
        } elseif ($charset === 'cp437') {
            $tl = "\xda"; $tr = "\xbf"; $bl = "\xc0"; $br = "\xd9"; $hz = "\xc4"; $vt = "\xb3";
        } else {
            $tl = '+'; $tr = '+'; $bl = '+'; $br = '+'; $hz = '-'; $vt = '|';
        }

        $hint = 'Press Enter to continue';

        $innerWidth = max(
            24,
            min(
                max(mb_strlen($message) + 2, mb_strlen($title) + 4, mb_strlen($hint) + 4),
                min($cols - 6, 58)
            )
        );
        $boxWidth = $innerWidth + 2;

        $titleLine = ' ' . $title . ' ';
        $titleLen  = mb_strlen($titleLine);
        $totalHz   = max(0, $innerWidth - $titleLen);
        $topBorder = $tl . str_repeat($hz, (int)floor($totalHz / 2)) . $titleLine
                        . str_repeat($hz, (int)ceil($totalHz / 2)) . $tr;
        $btmBorder = $bl . str_repeat($hz, $innerWidth) . $br;
        $emptyRow  = $vt . str_repeat(' ', $innerWidth) . $vt;

        $msgContent  = str_pad(mb_substr($message, 0, $innerWidth - 2), $innerWidth - 2);
        $msgRow      = $vt . ' ' . $msgContent . ' ' . $vt;
        $hintLen     = mb_strlen($hint);
        $hintLeftPad = max(0, (int)floor(($innerWidth - $hintLen) / 2));
        $hintRightPad = max(0, $innerWidth - $hintLen - $hintLeftPad);

        $dialogHeight = 6; // top + empty + message + empty + hint + bottom
        $startRow = max(1, (int)round(($rows - $dialogHeight) / 2));
        $startCol = max(1, (int)round(($cols - $boxWidth)    / 2));

        $ansi  = self::$ansiColorEnabled;
        $bg    = $style === 'error' ? self::ANSI_BG_RED : self::ANSI_BG_BLUE;
        $rst   = self::ANSI_RESET;
        $frame = $bg . "\033[1;37m";
        $body  = $bg . "\033[37m";

        $draw = static function(int $r, string $line) use ($conn, $startCol): void {
            self::safeWrite($conn, "\033[{$r};{$startCol}H{$line}");
        };

        self::safeWrite($conn, "\033[?25l");

        $r = $startRow;
        if ($ansi) {
            $draw($r++, $frame . $topBorder . $rst);
            $draw($r++, $body  . $emptyRow  . $rst);
            $draw($r++, $body  . $msgRow    . $rst);
            $draw($r++, $body  . $emptyRow  . $rst);
            $draw($r++, $body . $vt . str_repeat(' ', $hintLeftPad) . "\033[3m" . $hint . "\033[23m" . str_repeat(' ', $hintRightPad) . $body . $vt . $rst);
            $draw($r,   $frame . $btmBorder . $rst);
        } else {
            $draw($r++, $topBorder);
            $draw($r++, $emptyRow);
            $draw($r++, $msgRow);
            $draw($r++, $emptyRow);
            $draw($r++, $vt . str_repeat(' ', $hintLeftPad) . $hint . str_repeat(' ', $hintRightPad) . $vt);
            $draw($r,   $btmBorder);
        }

        self::safeWrite($conn, "\033[?25h");

        while (true) {
            $key = $server->readKeyWithIdleCheck($conn, $state);
            if ($key === null || $key === 'ENTER') {
                return;
            }
        }
    }

    /**
     * Encode UTF-8 header field text to match the box charset.
     *
     * CP437 boxes are already emitted as raw OEM bytes, so only the text fields
     * are converted here. ANSI escape sequences are added around the converted
     * text later and are plain ASCII, so they do not need conversion.
     */
    private static function encodeHeaderTextForCharset(string $text, string $charset): string
    {
        if ($charset === 'utf8') {
            return $text;
        }

        if ($charset === 'cp437') {
            if (!preg_match('/[^\x20-\x7E\r\n\t]/', $text)) {
                return $text;
            }
            if (function_exists('iconv')) {
                $converted = @iconv('UTF-8', 'CP437//TRANSLIT//IGNORE', $text);
                if (is_string($converted) && $converted !== '') {
                    return $converted;
                }
            }
        } elseif (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($ascii) && $ascii !== '') {
                return $ascii;
            }
        }

        return preg_replace('/[^\x20-\x7E\r\n\t]/', '', $text) ?? $text;
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
        if (!self::$ansiColorEnabled) {
            return $text;
        }
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
        // Map common locale codes to PHP date formats (without seconds)
        $formats = [
            'en-US' => 'Y-m-d H:i',  // 2026-02-06 14:30
            'en-GB' => 'd/m/Y H:i',  // 06/02/2026 14:30
            'de-DE' => 'd.m.Y H:i',  // 06.02.2026 14:30
            'fr-FR' => 'd/m/Y H:i',  // 06/02/2026 14:30
            'ja-JP' => 'Y/m/d H:i',  // 2026/02/06 14:30
        ];

        return $formats[$locale] ?? 'Y-m-d H:i';
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

    /**
     * Resolve a screen file or screen family to one concrete file path.
     *
     * Accepts either a single filename like "login.ans" or an ordered list of
     * aliases such as ["welcome.ans", "login.ans"]. Each filename is expanded to a
     * simple glob family (`login*.ans`, `mainmenu*.ans`, etc.). The first alias
     * with one or more matches wins, and one file from that family is selected
     * at random.
     *
     * @param string|array<int, string> $screenFile
     * @return string|null
     */
    private static function resolveScreenVariant(string|array $screenFile): ?string
    {
        $screenDir = __DIR__ . '/../screens';
        $families = is_array($screenFile) ? $screenFile : [$screenFile];

        foreach ($families as $family) {
            $family = trim($family);
            if ($family === '') {
                continue;
            }

            $safeName = basename($family);
            $extension = pathinfo($safeName, PATHINFO_EXTENSION);
            $baseName = pathinfo($safeName, PATHINFO_FILENAME);

            if ($extension === '' || $baseName === '') {
                continue;
            }

            $matches = glob($screenDir . '/' . $baseName . '*.' . $extension, GLOB_NOSORT) ?: [];
            $matches = array_values(array_filter(array_unique($matches), 'is_file'));

            if ($matches === []) {
                continue;
            }

            return $matches[random_int(0, count($matches) - 1)];
        }

        return null;
    }

    /**
     * Interactive address book / nodelist picker.
     *
     * Prompts for a search term, queries both the address book and nodelist APIs,
     * then presents the merged results through runSelectableList() so the user can
     * navigate with Up/Down/Enter or type a row number.
     *
     * Returns an array with 'name' and 'address' on selection, or null on cancel.
     *
     * @param resource $conn
     * @param string   $apiBase
     * @param string   $session
     * @param string   $locale
     * @return array{name:string,address:string}|null
     */
    public static function runAddressPicker($conn, array &$state, $server, string $apiBase, string $session, string $locale): ?array
    {
        while (true) {
            self::safeWrite($conn, "\033[2J\033[H");
            $titleText = $server->t('ui.terminalserver.compose.address_book_title', 'Address Book Search', [], $locale);
            self::writeLine($conn, self::colorize($titleText, self::ANSI_CYAN . self::ANSI_BOLD));
            self::writeLine($conn, '');

            $searchPrompt = self::colorize(
                $server->t('ui.terminalserver.compose.address_book_search', 'Search name or address (Enter to cancel): ', [], $locale),
                self::ANSI_CYAN
            );
            $query = $server->prompt($conn, $state, $searchPrompt, true);
            if ($query === null || trim($query) === '') {
                return null;
            }
            $query = trim($query);

            // Fetch address book entries (no trailing slash, matches route; + encoding valid in query strings)
            $abResp    = self::apiRequest($apiBase, 'GET', '/api/address-book?search=' . urlencode($query), null, $session);
            $abEntries = $abResp['data']['entries'] ?? [];

            // Fetch nodelist entries
            $nlResp = self::apiRequest($apiBase, 'GET', '/api/nodelist/search?q=' . urlencode($query), null, $session);
            $nlNodes = $nlResp['data']['nodes'] ?? [];

            // Build unified result list; address book takes priority, deduplicate by address
            $results = [];
            $seenAddresses = [];

            $abTag = $server->t('ui.terminalserver.compose.address_book_source_ab', 'AB', [], $locale);
            foreach ($abEntries as $entry) {
                $addr = trim((string)($entry['node_address'] ?? ''));
                $name = trim((string)($entry['name'] ?? ''));
                if ($name === '' && $addr === '') {
                    continue;
                }
                $key = strtolower($addr);
                if ($addr !== '' && isset($seenAddresses[$key])) {
                    continue;
                }
                if ($addr !== '') {
                    $seenAddresses[$key] = true;
                }
                $results[] = ['name' => $name, 'address' => $addr, 'tag' => $abTag];
            }

            $nlTag = $server->t('ui.terminalserver.compose.address_book_source_nl', 'NL', [], $locale);
            foreach ($nlNodes as $node) {
                $addr       = trim((string)($node['address']     ?? ''));
                $sysopName  = trim((string)($node['sysop_name']  ?? ''));
                $systemName = trim((string)($node['system_name'] ?? ''));
                if ($addr === '') {
                    continue;
                }
                $key = strtolower($addr);
                if (isset($seenAddresses[$key])) {
                    continue;
                }
                $seenAddresses[$key] = true;
                $results[] = [
                    'name'    => $sysopName !== '' ? $sysopName : $systemName,
                    'address' => $addr,
                    'tag'     => $nlTag,
                ];
            }

            if (empty($results)) {
                self::safeWrite($conn, "\033[2J\033[H");
                self::writeLine($conn, self::colorize(
                    $server->t('ui.terminalserver.compose.address_book_no_results', 'No matches found.', [], $locale),
                    self::ANSI_YELLOW
                ));
                self::writeLine($conn, '');
                self::writeLine($conn, self::colorize(
                    $server->t('ui.terminalserver.server.press_any_key', 'Press any key to search again, or Ctrl+C to cancel...', [], $locale),
                    self::ANSI_DIM
                ));
                $key = $server->readKeyWithIdleCheck($conn, $state);
                if ($key === null) {
                    return null;
                }
                continue;
            }

            // Row formatter — closure so runSelectableList can call it on resize.
            // Visible layout per row: "NNN) " (5) + name + "  " + addr (18) + "  " + "[XX]" (4) = cols-1
            $addrWidth = 18;
            $formatRows = static function(array $s) use ($results, $server, $query, $locale, $addrWidth): array {
                $cols      = $s['cols'] ?? 80;
                $nameWidth = max(10, $cols - 5 - 2 - $addrWidth - 2 - 4 - 1);
                $rows      = [];
                foreach ($results as $idx => $r) {
                    $num  = sprintf('%3d', $idx + 1);
                    $name = mb_substr(str_pad($r['name'], $nameWidth), 0, $nameWidth);
                    $addr = mb_substr(str_pad($r['address'], $addrWidth), 0, $addrWidth);
                    $tag  = '[' . $r['tag'] . ']';
                    $rows[] = self::colorize($num . ') ', self::ANSI_YELLOW)
                        . $name . '  '
                        . self::colorize($addr, self::ANSI_CYAN)
                        . '  ' . self::colorize($tag, self::ANSI_DIM);
                }
                $titleLine = self::colorize(
                    $server->t('ui.terminalserver.compose.address_book_title', 'Address Book Search', [], $locale)
                    . ' — "' . $query . '"',
                    self::ANSI_CYAN . self::ANSI_BOLD
                );
                return ['rows' => $rows, 'title' => $titleLine];
            };

            $built = $formatRows($state);
            $statusBar = [
                ['text' => 'Up/Dn', 'color' => self::ANSI_RED],
                ['text' => ' Select  ', 'color' => self::ANSI_BLUE],
                ['text' => 'Enter',    'color' => self::ANSI_RED],
                ['text' => ' Confirm  ', 'color' => self::ANSI_BLUE],
                ['text' => 'Q',        'color' => self::ANSI_RED],
                ['text' => ' Cancel',  'color' => self::ANSI_BLUE],
            ];

            $result = self::runSelectableList(
                $conn, $state, $server,
                $built['title'], $built['rows'],
                1, 1, 0, $statusBar,
                [],
                $formatRows
            );

            if ($result['action'] === 'select') {
                $chosen = $results[$result['index']] ?? null;
                if ($chosen !== null) {
                    return ['name' => $chosen['name'], 'address' => $chosen['address']];
                }
            }

            if ($result['action'] === 'disconnect') {
                return null;
            }
            // quit / any other action — loop back to search prompt
        }
    }

    /**
     * Show a random ANSI screen variant if one exists.
     *
     * @param string|array<int, string> $screenFile
     * @return bool
     */
    public static function showScreenIfExists(string|array $screenFile, BbsSession &$server, $conn): bool
    {
        $screenFile = self::resolveScreenVariant($screenFile);

        if ($screenFile !== null && is_file($screenFile)) {
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

    /**
     * Send a sixel graphics file raw to the client if it exists.
     * Sixel data is binary and must not have line endings normalized.
     *
     * @param string|array<int, string> $screenFile
     * @return bool True if the file existed and was sent.
     */
    public static function showSixelScreenIfExists(string|array $screenFile, BbsSession &$server, $conn): bool
    {
        $screenFile = self::resolveScreenVariant($screenFile);

        if ($screenFile !== null && is_file($screenFile)) {
            $content = @file_get_contents($screenFile);
            if ($content !== false && $content !== '') {
                $server->safeWrite($conn, $content);
                return true;
            }
        }
        return false;
    }
}
