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
     * Return the canonical default style profile used by terminal widgets.
     */
    public static function getDefaultStyleProfile(): array
    {
        return [
            'panel' => TerminalBoxRenderer::SCHEME_DEFAULT,
            'list' => [
                'title' => self::ANSI_CYAN . self::ANSI_BOLD,
                'selected_bg' => self::ANSI_BG_BLUE . self::ANSI_BOLD,
            ],
            'scrollable_panel' => [
                'border' => self::ANSI_BLUE . self::ANSI_BOLD,
                'divider' => self::ANSI_BLUE,
                'title_bar' => self::ANSI_BG_BLUE . self::ANSI_CYAN . self::ANSI_BOLD,
                'body' => "\033[40m\033[37m",
                'status_bar_bg' => "\033[40m",
            ],
            'dialog' => [
                'bg' => self::ANSI_BG_BLUE,
                'frame' => self::ANSI_BG_BLUE . "\033[1;37m",
                'body' => self::ANSI_BG_BLUE . "\033[37m",
                'hint' => self::ANSI_YELLOW,
                'choice_key' => self::ANSI_CYAN . self::ANSI_BOLD,
                'choice_label' => "\033[37m",
            ],
            'help_overlay' => [
                'bg' => self::ANSI_BG_BLUE,
                'frame' => self::ANSI_BG_BLUE . "\033[1;37m",
                'body' => self::ANSI_BG_BLUE . "\033[37m",
                'key' => self::ANSI_CYAN . self::ANSI_BOLD,
                'status_key' => self::ANSI_RED,
                'status_label' => self::ANSI_BLUE,
            ],
            'working_overlay' => [
                'bg' => self::ANSI_BG_BLUE,
                'frame' => self::ANSI_BG_BLUE . "\033[1;37m",
                'body' => self::ANSI_BG_BLUE . "\033[37m",
            ],
            'checkbox_dialog' => [
                'bg' => self::ANSI_BG_BLUE,
                'frame' => self::ANSI_BG_BLUE . "\033[1;37m",
                'body' => self::ANSI_BG_BLUE . "\033[37m",
                'hilite' => self::ANSI_BG_BLUE . "\033[1;33m",
                'dim' => self::ANSI_BG_BLUE . "\033[2;37m",
            ],
            'status_bar' => [
                'bg'    => self::ANSI_BG_WHITE,
                'text'  => self::ANSI_BLUE,
                'fill'  => self::ANSI_BLUE,
                'key'   => self::ANSI_RED,   // key binding name color
                'label' => self::ANSI_BLUE,  // key label text color
            ],
            'header_box' => [
                'bg' => self::ANSI_BG_BLUE,
                'frame' => self::ANSI_BG_BLUE . "\033[37m",
                'body' => self::ANSI_BG_BLUE . "\033[37m",
            ],
            'selectable_dialog' => [
                'bg' => self::ANSI_BG_BLUE,
                'frame' => self::ANSI_BG_BLUE . "\033[1;37m",
                'body' => self::ANSI_BG_BLUE . "\033[37m",
                'hilite' => self::ANSI_BG_BLUE . "\033[1;33m",
                'dim' => self::ANSI_BG_BLUE . "\033[2;37m",
            ],
            'image_prompt' => [
                'bg' => self::ANSI_BG_WHITE,
                'frame' => self::ANSI_BG_WHITE . self::ANSI_BLUE . self::ANSI_BOLD,
                'body' => self::ANSI_BG_WHITE . self::ANSI_BLUE,
            ],
            'profile_viewer' => [
                'bio_label' => self::ANSI_CYAN . self::ANSI_BOLD,
                'status_key' => self::ANSI_RED,
                'status_label' => self::ANSI_BLUE,
            ],
            'file_detail_panel' => [
                'border' => self::ANSI_BLUE . self::ANSI_BOLD,
                'divider' => self::ANSI_BLUE,
                'title_bar' => self::ANSI_BG_BLUE . self::ANSI_CYAN . self::ANSI_BOLD,
                'body' => "\033[40m\033[37m",
                'status_bar_bg' => "\033[40m",
            ],
            'alert' => [
                'info' => [
                    'bg' => self::ANSI_BG_BLUE,
                    'frame' => self::ANSI_BG_BLUE . "\033[1;37m",
                    'body' => self::ANSI_BG_BLUE . "\033[37m",
                ],
                'error' => [
                    'bg' => self::ANSI_BG_RED,
                    'frame' => self::ANSI_BG_RED . "\033[1;37m",
                    'body' => self::ANSI_BG_RED . "\033[37m",
                ],
            ],
        ];
    }

    /**
     * Return the active shell style profile for the current session state.
     */
    public static function getStyleProfile(array $state = []): array
    {
        $default = self::getDefaultStyleProfile();
        $active = $state['_shell_style_profile'] ?? null;
        if (!is_array($active) || $active === []) {
            return $default;
        }

        return array_replace_recursive($default, $active);
    }

    /**
     * Enable or disable ANSI color rendering globally for the active session.
     */
    public static function setAnsiColorEnabled(bool $enabled): void
    {
        self::$ansiColorEnabled = $enabled;
    }

    /**
     * Return the effective row count for selector-style screens.
     *
     * SyncTERM appears to keep its own local bottom status line even when the
     * negotiated height includes that row. Selector screens anchor their
     * status/input line to the last row, so reserve one row there for SyncTERM.
     */
    private static function getSelectorRows(array $state): int
    {
        $rows = (int)($state['rows'] ?? 24);
        $ttype = strtoupper((string)($state['terminal_type'] ?? ''));
        if ($ttype !== '' && str_contains($ttype, 'SYNCTERM')) {
            return max(1, $rows - 1);
        }
        return max(1, $rows);
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
        array $helpItems = [],
        array $options = []
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
                self::showHelpOverlay(
                    $conn,
                    $state,
                    $server,
                    $helpItems,
                    $allowDownloadAction,
                    !empty($imageRefs),
                    $lastRows,
                    null,
                    (array)($options['help_overlay'] ?? [])
                );
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

        $profile = self::getDefaultStyleProfile();
        $statusBar = $profile['status_bar'] ?? [];
        $statusLine = self::buildStatusBar([
            ['text' => 'U/D',      'color' => $statusBar['key']   ?? self::ANSI_RED],
            ['text' => ' Scroll  ', 'color' => $statusBar['label'] ?? self::ANSI_BLUE],
            ['text' => 'PgUp/PgDn', 'color' => $statusBar['key']  ?? self::ANSI_RED],
            ['text' => ' Page  ',  'color' => $statusBar['label'] ?? self::ANSI_BLUE],
            ['text' => 'Q',        'color' => $statusBar['key']   ?? self::ANSI_RED],
            ['text' => ' Close',   'color' => $statusBar['label'] ?? self::ANSI_BLUE],
        ], $width, $statusBar);

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
        int $rows,
        ?array $builtInItems = null,
        array $colorScheme = []
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

        $builtIn = $builtInItems ?? [
            ['key' => 'Up / Down',    'label' => $server->t('ui.terminalserver.message.help_scroll',    'Scroll one line',        [], $locale)],
            ['key' => 'PgUp / PgDn',  'label' => $server->t('ui.terminalserver.message.help_page',      'Scroll one page',        [], $locale)],
            ['key' => 'Left / Right', 'label' => $server->t('ui.terminalserver.message.help_prev_next', 'Previous / next message', [], $locale)],
            ['key' => 'R',            'label' => $server->t('ui.terminalserver.message.help_reply',      'Reply',                  [], $locale)],
            ['key' => 'H',            'label' => $server->t('ui.terminalserver.message.help_headers',    'View message headers',   [], $locale)],
        ];
        if ($builtInItems === null) {
            if ($hasAttachments) {
                $builtIn[] = ['key' => 'Z', 'label' => $server->t('ui.terminalserver.message.help_download', 'Download attachment (ZMODEM)', [], $locale)];
            }
            if ($hasImages) {
                $builtIn[] = ['key' => 'I', 'label' => $server->t('ui.terminalserver.message.help_images', 'View inline image(s)', [], $locale)];
            }
            $builtIn[] = ['key' => 'Q / Enter', 'label' => $server->t('ui.terminalserver.message.help_quit', 'Quit / close message', [], $locale)];
        }

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
        $profile = self::getDefaultStyleProfile();
        $scheme = array_merge($profile['help_overlay'] ?? [], $colorScheme);
        $bg    = (string)($scheme['bg'] ?? self::ANSI_BG_BLUE);
        $frame = (string)($scheme['frame'] ?? ($bg . "\033[1;37m"));  // borders
        $body  = (string)($scheme['body'] ?? ($bg . "\033[37m"));      // content
        $keyC  = (string)($scheme['key'] ?? ($bg . "\033[1;31m"));     // key names
        $statusKey = (string)($scheme['status_key'] ?? self::ANSI_RED);
        $statusLabel = (string)($scheme['status_label'] ?? self::ANSI_BLUE);

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
            $allItems, $keyWidth, $tl, $tr, $bl, $br, $hz, $titleLine, $titleLen,
            $statusKey, $statusLabel
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
                ['text' => 'Q',      'color' => $statusKey],
                ['text' => ' Close', 'color' => $statusLabel],
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
        array $extraStatusSegments = [],
        array $options = [],
        array $helpItems = []
    ): array {
        $cols = $state['cols'] ?? 80;
        $selectedIds = array_fill_keys(array_map('intval', $options['selectedMessageIds'] ?? []), true);
        $selectedRows = [];
        $markerWidth = !empty($options['multiSelect']) ? 1 : 0;
        $contentCols = max(20, $cols - $markerWidth);

        // Pre-format rows without selection highlight; runSelectableList handles highlighting.
        $rows = [];
        foreach ($messages as $idx => $msg) {
            if (!empty($selectedIds[(int)($msg['id'] ?? 0)])) {
                $selectedRows[] = $idx;
            }
            $rows[] = self::formatMessageListEntry($msg, $idx + 1, false, $contentCols, $state);
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
        $rebuildFn = static function(array &$s) use ($messages, $server, $encodedTitle, $markerWidth): array {
            $newCols = $s['cols'] ?? 80;
            $contentCols = max(20, $newCols - $markerWidth);
            $newRows = [];
            foreach ($messages as $idx => $msg) {
                $newRows[] = TelnetUtils::formatMessageListEntry($msg, $idx + 1, false, $contentCols, $s);
            }
            if (method_exists($server, 'encodeForTerminal')) {
                $newRows = array_map(
                    static fn(string $row): string => $server->encodeForTerminal($row),
                    $newRows
                );
            }
            return ['rows' => $newRows, 'title' => $encodedTitle];
        };

        $profile = self::getDefaultStyleProfile();
        $statusBarProfile = $profile['status_bar'] ?? [];
        $statusBar = [
            ['text' => 'U/D',        'color' => $statusBarProfile['key']   ?? self::ANSI_RED],
            ['text' => ' Move  ',    'color' => $statusBarProfile['label'] ?? self::ANSI_BLUE],
            ['text' => 'L/R',        'color' => $statusBarProfile['key']   ?? self::ANSI_RED],
            ['text' => ' Page  ',    'color' => $statusBarProfile['label'] ?? self::ANSI_BLUE],
            ['text' => 'C',          'color' => $statusBarProfile['key']   ?? self::ANSI_RED],
            ['text' => ' Compose  ', 'color' => $statusBarProfile['label'] ?? self::ANSI_BLUE],
            ['text' => 'Enter',      'color' => $statusBarProfile['key']   ?? self::ANSI_RED],
            ['text' => ' Read  ',    'color' => $statusBarProfile['label'] ?? self::ANSI_BLUE],
            ['text' => 'Q',          'color' => $statusBarProfile['key']   ?? self::ANSI_RED],
            ['text' => ' Quit',      'color' => $statusBarProfile['label'] ?? self::ANSI_BLUE],
        ];
        if (!empty($extraStatusSegments)) {
            // Ensure a two-space gap between the base "Q Quit" and the first extra segment.
            $statusBar[array_key_last($statusBar)]['text'] .= '  ';
            $statusBar = array_merge($statusBar, $extraStatusSegments);
        }

        $listOptions = $options;
        $listOptions['selectedRows'] = $selectedRows;
        $result = self::runSelectableList(
            $conn, $state, $server,
            $title, $rows, $page, $totalPages, $selectedIndex,
            $statusBar,
            array_merge(['c' => 'compose'], $extraKeys),
            $rebuildFn,
            $listOptions,
            $helpItems
        );

        // Map the generic 'select' action to the message-specific 'read' action.
        if ($result['action'] === 'select') {
            $result['action'] = 'read';
        }

        return $result;
    }

    /**
     * Render a message list screen without entering the interactive key loop.
     *
     * Useful for repainting the current background behind a modal dialog after a
     * terminal resize, while keeping the same message-list formatting and status
     * bar layout as runMessageList().
     *
     * @param resource $conn
     * @param array    $state
     * @param object   $server
     * @param string   $title
     * @param array    $messages
     * @param int      $selectedIndex
     * @param array    $extraStatusSegments
     * @return void
     */
    public static function renderMessageListScreen(
        $conn,
        array &$state,
        $server,
        string $title,
        array $messages,
        int $selectedIndex,
        array $extraStatusSegments = [],
        array $options = []
    ): void {
        $cols = $state['cols'] ?? 80;
        $selectedIds = array_fill_keys(array_map('intval', $options['selectedMessageIds'] ?? []), true);
        $selectedRows = [];
        $markerWidth = !empty($options['multiSelect']) ? 1 : 0;
        $contentCols = max(20, $cols - $markerWidth);
        $rows = [];
        foreach ($messages as $idx => $msg) {
            if (!empty($selectedIds[(int)($msg['id'] ?? 0)])) {
                $selectedRows[$idx] = true;
            }
            $rows[] = self::formatMessageListEntry($msg, $idx + 1, false, $contentCols, $state);
        }
        if (method_exists($server, 'encodeForTerminal')) {
            $rows = array_map(
                static fn(string $row): string => $server->encodeForTerminal($row),
                $rows
            );
            $title = $server->encodeForTerminal($title);
        }

        $profile = self::getDefaultStyleProfile();
        $statusBarProfile = $profile['status_bar'] ?? [];
        $statusBar = [
            ['text' => 'U/D',        'color' => $statusBarProfile['key']   ?? self::ANSI_RED],
            ['text' => ' Move  ',    'color' => $statusBarProfile['label'] ?? self::ANSI_BLUE],
            ['text' => 'L/R',        'color' => $statusBarProfile['key']   ?? self::ANSI_RED],
            ['text' => ' Page  ',    'color' => $statusBarProfile['label'] ?? self::ANSI_BLUE],
            ['text' => 'C',          'color' => $statusBarProfile['key']   ?? self::ANSI_RED],
            ['text' => ' Compose  ', 'color' => $statusBarProfile['label'] ?? self::ANSI_BLUE],
            ['text' => 'Enter',      'color' => $statusBarProfile['key']   ?? self::ANSI_RED],
            ['text' => ' Read  ',    'color' => $statusBarProfile['label'] ?? self::ANSI_BLUE],
            ['text' => 'Q',          'color' => $statusBarProfile['key']   ?? self::ANSI_RED],
            ['text' => ' Quit  ',    'color' => $statusBarProfile['label'] ?? self::ANSI_BLUE],
            ['text' => 'Ctrl-K',     'color' => $statusBarProfile['key']   ?? self::ANSI_RED],
            ['text' => ' Help',      'color' => $statusBarProfile['label'] ?? self::ANSI_BLUE],
        ];
        if (!empty($extraStatusSegments)) {
            $statusBar[array_key_last($statusBar)]['text'] .= '  ';
            $statusBar = array_merge($statusBar, $extraStatusSegments);
        }

        $termRows = self::getSelectorRows($state);
        $inputRow = max(1, $termRows);

        self::safeWrite($conn, "\033[2J\033[H");
        self::writeLine($conn, $title);

        $showMarker = !empty($options['multiSelect']);
        foreach ($rows as $idx => $row) {
            self::writeLine($conn, self::buildSelectableListDisplayLine($row, $idx === $selectedIndex, isset($selectedRows[$idx]), $cols, $showMarker));
        }

        $statusLine = self::buildStatusBar($statusBar, $cols);
        self::safeWrite($conn, "\033[{$inputRow};1H\033[K");
        self::safeWrite($conn, $statusLine . "\r");
        self::safeWrite($conn, "\033[{$inputRow};1H");
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
        if (empty($msg['is_read'])) {
            $line = self::colorize($line, self::ANSI_BOLD);
        }
        if ($selected) {
            $profile = self::getDefaultStyleProfile();
            $selectedBg = (string)($profile['list']['selected_bg'] ?? (self::ANSI_BG_BLUE . self::ANSI_BOLD));
            $line = self::colorize($line, $selectedBg);
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
     * Truncate a string to at most $maxVisible printable characters while preserving
     * ANSI SGR escape sequences so colors are not lost on narrow terminals.
     */
    private static function truncateAnsi(string $text, int $maxVisible): string
    {
        $result  = '';
        $visible = 0;
        $i       = 0;
        $len     = strlen($text);

        while ($i < $len && $visible < $maxVisible) {
            if ($text[$i] === "\033" && $i + 1 < $len && $text[$i + 1] === '[') {
                $end = strpos($text, 'm', $i + 2);
                if ($end !== false) {
                    $result .= substr($text, $i, $end - $i + 1);
                    $i = $end + 1;
                } else {
                    $result .= $text[$i++];
                }
            } else {
                $result .= $text[$i++];
                $visible++;
            }
        }

        return $result . self::ANSI_RESET;
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
    private static function renderSelectableListLine($conn, array $rows, int $idx, bool $selected, int $listStartRow, int $cols, bool $marked = false, bool $showMarker = false, string $selectedBg = self::ANSI_BG_BLUE . self::ANSI_BOLD): void
    {
        if (!isset($rows[$idx])) {
            return;
        }
        $line = self::buildSelectableListDisplayLine($rows[$idx], $selected, $marked, $cols, $showMarker, $selectedBg);
        $row = $listStartRow + $idx;
        self::safeWrite($conn, "\033[{$row};1H\033[K");
        self::safeWrite($conn, $line);
    }

    private static function buildSelectableListDisplayLine(string $row, bool $selected, bool $marked, int $cols, bool $showMarker = false, string $selectedBg = self::ANSI_BG_BLUE . self::ANSI_BOLD): string
    {
        if ($showMarker) {
            $display = $marked
                ? self::colorize('*', self::ANSI_GREEN . self::ANSI_BOLD) . $row
                : ' ' . $row;
        } else {
            $display = $row;
        }

        $plain   = self::stripAnsi($display);
        $maxCols = max(1, $cols - 1);

        if ($selected) {
            $text = substr($plain, 0, $maxCols);
            return self::colorize(str_pad($text, $maxCols), $selectedBg);
        }

        // Prevent line-wrap on stale or oversized rows when terminal is narrow
        if (strlen($plain) > $cols) {
            return self::truncateAnsi($display, $cols);
        }

        return $display;
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
        ?callable $rebuildFn = null,
        array $options = [],
        array $helpItems = []
    ): array {
        $colorScheme = is_array($options['color_scheme'] ?? null) ? $options['color_scheme'] : [];
        if (self::hasStructuredSelectableRows($rows)) {
            return self::runSelectableStructuredList(
                $conn,
                $state,
                $server,
                $title,
                $rows,
                $page,
                $totalPages,
                $selectedIndex,
                $statusBar,
                $extraKeys,
                $rebuildFn,
                $options,
                $helpItems,
                $colorScheme
            );
        }

        $cols         = $state['cols'] ?? 80;
        $termRows     = self::getSelectorRows($state);
        $rowCount     = count($rows);
        $listStartRow = 2;
        $inputRow     = max(1, $termRows);
        $maxDisplayRows = max(1, $inputRow - $listStartRow);
        $selectedRows = array_fill_keys(array_map('intval', $options['selectedRows'] ?? []), true);
        $toggleKey    = strtolower((string)($options['toggleKey'] ?? 'x'));
        $showMarker   = !empty($options['multiSelect']);

        $statusLine = '';

        // Render closure — always recomputes layout from current $state so it is safe
        // to call both from within the key loop and as $state['repaint_fn'] from overlays.
        // Mutable variables ($cols, $termRows, etc.) are captured by reference so that
        // the key loop sees up-to-date values after each render.
        $render = function() use (
            $conn, &$state,
            &$rows, &$title, &$statusLine, &$selectedIndex, &$selectedRows,
            &$cols, &$termRows, &$inputRow, &$maxDisplayRows,
            $showMarker, $listStartRow, $statusBar, $colorScheme
        ): void {
            $cols           = $state['cols'] ?? 80;
            $termRows       = self::getSelectorRows($state);
            $inputRow       = max(1, $termRows);
            $maxDisplayRows = max(1, $inputRow - $listStartRow);
            $statusLine     = self::buildStatusBar($statusBar, $cols);
            $titleColor     = (string)($colorScheme['title'] ?? self::ANSI_CYAN . self::ANSI_BOLD);
            $selectedBg     = (string)($colorScheme['selected_bg'] ?? (self::ANSI_BG_BLUE . self::ANSI_BOLD));

            self::safeWrite($conn, "\033[2J\033[H");
            self::writeLine($conn, self::colorize($title, $titleColor));
            foreach (array_slice($rows, 0, $maxDisplayRows) as $idx => $row) {
                self::writeLine($conn, self::buildSelectableListDisplayLine($row, $idx === $selectedIndex, isset($selectedRows[$idx]), $cols, $showMarker, $selectedBg));
            }
            self::safeWrite($conn, "\033[{$inputRow};1H\033[K");
            self::safeWrite($conn, $statusLine . "\r");
            self::safeWrite($conn, "\033[{$inputRow};1H");
        };

        // Register as the active repaint function so overlays shown by the caller
        // after this function returns can repaint the list on resize.  Intentionally
        // not restored on exit — the next surface to become active will overwrite it.
        $state['repaint_fn'] = $render;

        // --- Initial render ---
        $render();

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
            $newTermRows = self::getSelectorRows($state);
            if ($newCols !== $cols || $newTermRows !== $termRows) {
                if ($rebuildFn !== null) {
                    $rebuilt       = $rebuildFn($state);
                    $rows          = $rebuilt['rows'];
                    $title         = $rebuilt['title'];
                    $rowCount      = count($rows);
                    $selectedIndex = min($selectedIndex, max(0, $rowCount - 1));
                }
                $buffer = '';
                $render(); // recomputes $cols, $termRows, $inputRow, $maxDisplayRows, $statusLine
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

            if ($key === 'CTRL_K') {
                $overlayItems = $helpItems;
                $listLocale   = $state['locale'] ?? 'en';
                if (!empty($options['multiSelect'])) {
                    array_unshift($overlayItems, [
                        'key' => 'Space',
                        'label' => $server->t('ui.terminalserver.list.help_toggle_selection', 'Toggle selection', [], $listLocale),
                    ]);
                }
                self::showHelpOverlay(
                    $conn,
                    $state,
                    $server,
                    $overlayItems,
                    false,
                    false,
                    $termRows,
                    [
                        ['key' => 'Up / Down',   'label' => $server->t('ui.terminalserver.list.help_move_selection', 'Move selection', [], $listLocale)],
                        ['key' => 'Left / Right', 'label' => $server->t('ui.terminalserver.list.help_prev_page', 'Previous / next page', [], $listLocale)],
                        ['key' => '1-9',          'label' => $server->t('ui.terminalserver.list.help_jump_row', 'Jump to row', [], $listLocale)],
                        ['key' => 'Enter',        'label' => $server->t('ui.terminalserver.list.help_open_selected', 'Open selected item', [], $listLocale)],
                        ['key' => 'Q / Enter',    'label' => $server->t('ui.terminalserver.list.help_close_help', 'Close help', [], $listLocale)],
                    ],
                    (array)($colorScheme['help_overlay'] ?? [])
                );
                $newCols     = $state['cols'] ?? $cols;
                $newTermRows = self::getSelectorRows($state);
                if ($newCols !== $cols || $newTermRows !== $termRows) {
                    if ($rebuildFn !== null) {
                        $rebuilt   = $rebuildFn($state);
                        $rows      = $rebuilt['rows'];
                        $title     = $rebuilt['title'];
                        $rowCount  = count($rows);
                        $selectedIndex = min($selectedIndex, max(0, $rowCount - 1));
                    }
                }
                $buffer = '';
                $render(); // always redraws after help, recomputes layout if size changed
                continue;
            }

            if ($key === 'UP') {
                if ($selectedIndex > 0) {
                    $prev = $selectedIndex;
                    $selectedIndex--;
                    self::renderSelectableListLine($conn, $rows, $prev,          false, $listStartRow, $cols, isset($selectedRows[$prev]), $showMarker, (string)($colorScheme['selected_bg'] ?? (self::ANSI_BG_BLUE . self::ANSI_BOLD)));
                    self::renderSelectableListLine($conn, $rows, $selectedIndex, true,  $listStartRow, $cols, isset($selectedRows[$selectedIndex]), $showMarker, (string)($colorScheme['selected_bg'] ?? (self::ANSI_BG_BLUE . self::ANSI_BOLD)));
                }
                self::safeWrite($conn, "\033[{$inputRow};" . ($inputColStart + strlen($buffer)) . "H");
                continue;
            }

            if ($key === 'DOWN') {
                if ($selectedIndex < $rowCount - 1) {
                    $prev = $selectedIndex;
                    $selectedIndex++;
                    self::renderSelectableListLine($conn, $rows, $prev,          false, $listStartRow, $cols, isset($selectedRows[$prev]), $showMarker, (string)($colorScheme['selected_bg'] ?? (self::ANSI_BG_BLUE . self::ANSI_BOLD)));
                    self::renderSelectableListLine($conn, $rows, $selectedIndex, true,  $listStartRow, $cols, isset($selectedRows[$selectedIndex]), $showMarker, (string)($colorScheme['selected_bg'] ?? (self::ANSI_BG_BLUE . self::ANSI_BOLD)));
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
                if (!empty($options['multiSelect']) && $lower === $toggleKey) {
                    if (isset($selectedRows[$selectedIndex])) {
                        unset($selectedRows[$selectedIndex]);
                    } else {
                        $selectedRows[$selectedIndex] = true;
                    }
                    self::renderSelectableListLine($conn, $rows, $selectedIndex, true, $listStartRow, $cols, isset($selectedRows[$selectedIndex]), $showMarker, (string)($colorScheme['selected_bg'] ?? (self::ANSI_BG_BLUE . self::ANSI_BOLD)));
                    return [
                        'action' => 'toggle_select',
                        'index' => $selectedIndex,
                        'selectedIndex' => $selectedIndex,
                    ];
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
                            self::renderSelectableListLine($conn, $rows, $prev,          false, $listStartRow, $cols, isset($selectedRows[$prev]), $showMarker, (string)($colorScheme['selected_bg'] ?? (self::ANSI_BG_BLUE . self::ANSI_BOLD)));
                            self::renderSelectableListLine($conn, $rows, $selectedIndex, true,  $listStartRow, $cols, isset($selectedRows[$selectedIndex]), $showMarker, (string)($colorScheme['selected_bg'] ?? (self::ANSI_BG_BLUE . self::ANSI_BOLD)));
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
     * Detect whether a selectable-list row uses the wrapped-row structure.
     *
     * @param array $rows
     * @return bool
     */
    private static function hasStructuredSelectableRows(array $rows): bool
    {
        foreach ($rows as $row) {
            if (is_array($row)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalize a selectable row into a wrapped multi-line display block.
     *
     * @param mixed $row
     * @param int $width
     * @return array{label:string, lines:string[]}
     */
    private static function normalizeStructuredSelectableRow($row, int $width): array
    {
        if (!is_array($row)) {
            return [
                'label' => (string)$row,
                'lines' => [(string)$row],
            ];
        }

        $label = (string)($row['label'] ?? $row['title'] ?? $row['name'] ?? $row['text'] ?? '');
        $detail = (string)($row['detail'] ?? $row['description'] ?? $row['desc'] ?? '');
        $labelLines = self::wrapTextLines($label, max(20, $width - 6));
        if ($labelLines === []) {
            $labelLines = [''];
        }

        $lines = $labelLines;
        if ($detail !== '') {
            foreach (self::wrapTextLines($detail, max(20, $width - 4)) as $line) {
                $lines[] = '  ' . $line;
            }
        }

        return [
            'label' => $label,
            'lines' => $lines,
        ];
    }

    /**
     * Wrap-aware selectable-list renderer for structured rows.
     *
     * @param resource $conn
     * @param array $state
     * @param object $server
     * @param string $title
     * @param array $rows
     * @param int $page
     * @param int $totalPages
     * @param int $selectedIndex
     * @param array $statusBar
     * @param array $extraKeys
     * @param callable|null $rebuildFn
     * @param array $options
     * @param array $helpItems
     * @return array{action: string, index: int, selectedIndex: int}
     */
    private static function runSelectableStructuredList(
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
        ?callable $rebuildFn = null,
        array $options = [],
        array $helpItems = [],
        array $colorScheme = []
    ): array {
        $blocks = [];
        foreach ($rows as $row) {
            $blocks[] = self::normalizeStructuredSelectableRow($row, (int)($state['cols'] ?? 80));
        }

        if ($blocks === []) {
            return ['action' => 'quit', 'index' => 0, 'selectedIndex' => 0];
        }

        $sourceRows    = $rows;
        $selectedIndex = max(0, min($selectedIndex, count($blocks) - 1));
        $cols          = $state['cols'] ?? 80;
        $termRows      = self::getSelectorRows($state);
        $listStartRow  = 2;
        $inputRow      = max(1, $termRows);
        $maxDisplayRows = max(1, $inputRow - $listStartRow);
        $statusLine    = '';

        $rebuildBlocks = static function(array $sourceRows, int $width): array {
            $rebuilt = [];
            foreach ($sourceRows as $row) {
                $rebuilt[] = self::normalizeStructuredSelectableRow($row, $width);
            }
            return $rebuilt;
        };

        $computeOffset = static function(array $blocks, int $selectedIndex, int $bodyHeight): int {
            $offset = 0;
            $count  = count($blocks);
            while ($offset < $count) {
                $used = 0;
                $seen = false;
                for ($i = $offset; $i < $count; $i++) {
                    $height = max(1, count($blocks[$i]['lines'] ?? ['']));
                    if ($used + $height > $bodyHeight) {
                        break;
                    }
                    if ($i === $selectedIndex) {
                        $seen = true;
                    }
                    $used += $height;
                }
                if ($seen || $offset >= $selectedIndex) {
                    return $offset;
                }
                $offset++;
            }
            return max(0, min($selectedIndex, $count - 1));
        };

        $render = function() use (
            $conn, &$state, &$sourceRows, &$blocks, &$title, &$selectedIndex, &$cols, &$termRows, &$inputRow, &$maxDisplayRows, &$statusLine,
            $statusBar, $listStartRow, $computeOffset, $rebuildBlocks, $colorScheme
        ): void {
            $cols          = $state['cols'] ?? 80;
            $termRows      = self::getSelectorRows($state);
            $inputRow      = max(1, $termRows);
            $maxDisplayRows = max(1, $inputRow - $listStartRow);
            $blocks        = $rebuildBlocks($sourceRows, $cols);
            $selectedIndex = max(0, min($selectedIndex, count($blocks) - 1));
            $statusLine    = self::buildStatusBar($statusBar, $cols);
            $offset        = $computeOffset($blocks, $selectedIndex, $maxDisplayRows);

            self::safeWrite($conn, "\033[2J\033[H");
            self::writeLine($conn, $title);

            $rowNumber = $offset + 1;
            $screenRow = $listStartRow;
            $count     = count($blocks);
            for ($i = $offset; $i < $count && $screenRow < $inputRow; $i++) {
                $lines = $blocks[$i]['lines'] ?? [''];
                $isSelected = ($i === $selectedIndex);
                foreach ($lines as $lineIndex => $lineText) {
                    if ($screenRow >= $inputRow) {
                        break 2;
                    }
                    $prefix = $lineIndex === 0 ? sprintf('%2d) ', $rowNumber) : '    ';
                    $display = $prefix . $lineText;
                    $plain = self::stripAnsi($display);
                    $maxCols = max(1, $cols - 1);
                    if (strlen($plain) > $cols) {
                        $display = self::truncateAnsi($display, $cols);
                    }
                    self::safeWrite($conn, "\033[{$screenRow};1H\033[K");
                    if ($isSelected) {
                        self::safeWrite($conn, self::colorize(str_pad(self::stripAnsi($display), $maxCols), (string)($colorScheme['selected_bg'] ?? (self::ANSI_BG_BLUE . self::ANSI_BOLD))));
                    } else {
                        self::safeWrite($conn, $display);
                    }
                    $screenRow++;
                }
                $rowNumber++;
            }

            while ($screenRow < $inputRow) {
                self::safeWrite($conn, "\033[{$screenRow};1H\033[K");
                $screenRow++;
            }

            self::safeWrite($conn, "\033[{$inputRow};1H\033[K");
            self::safeWrite($conn, $statusLine . "\r");
            self::safeWrite($conn, "\033[{$inputRow};1H");
        };

        $state['repaint_fn'] = $render;
        $render();

        $lastRows = $state['rows'] ?? 24;
        $lastCols = $state['cols'] ?? 80;

        while (true) {
            $key = $server->readKeyWithIdleCheck($conn, $state);
            if ($key === null) {
                return ['action' => 'disconnect', 'index' => $selectedIndex, 'selectedIndex' => $selectedIndex];
            }

            $newRows = $state['rows'] ?? $lastRows;
            $newCols = $state['cols'] ?? $lastCols;
            if ($newRows !== $lastRows || $newCols !== $lastCols) {
                $lastRows = $newRows;
                $lastCols = $newCols;
                if ($rebuildFn !== null) {
                    $rebuilt = $rebuildFn($state);
                    if (isset($rebuilt['rows']) && is_array($rebuilt['rows'])) {
                        $sourceRows = $rebuilt['rows'];
                    }
                    if (isset($rebuilt['title']) && is_string($rebuilt['title'])) {
                        $title = $rebuilt['title'];
                    }
                }
                $render();
                continue;
            }

            if ($key === 'UP') {
                if ($selectedIndex > 0) {
                    $selectedIndex--;
                    $render();
                }
                continue;
            }

            if ($key === 'DOWN') {
                if ($selectedIndex < count($blocks) - 1) {
                    $selectedIndex++;
                    $render();
                }
                continue;
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

            if ($key === 'ENTER') {
                return ['action' => 'select', 'index' => $selectedIndex, 'selectedIndex' => $selectedIndex];
            }

            if (str_starts_with($key, 'CHAR:')) {
                $char  = substr($key, 5);
                $lower = strtolower($char);
                if ($lower === 'q') {
                    return ['action' => 'quit', 'index' => 0, 'selectedIndex' => $selectedIndex];
                }
                if ($lower === 'n' && $page < $totalPages) {
                    return ['action' => 'next', 'index' => 0, 'selectedIndex' => 0];
                }
                if ($lower === 'p' && $page > 1) {
                    return ['action' => 'prev', 'index' => 0, 'selectedIndex' => 0];
                }
                if ($char !== '' && ctype_digit($char)) {
                    $choice = (int)$char - 1;
                    if (isset($blocks[$choice])) {
                        return ['action' => 'select', 'index' => $choice, 'selectedIndex' => $choice];
                    }
                }
            }
        }
    }

    /**
     * Show a centered selectable list dialog overlay and wait for selection.
     *
     * @param resource $conn
     * @param array    &$state
     * @param object   $server
     * @param string   $title
     * @param string[] $items
     * @param string   $hintSelect
     * @param string   $hintBack
     * @param int      $selectedIndex
     * @param callable|null $redrawFn Invoked on resize before redrawing the dialog.
     * @return array{action:'select'|'quit',index:int}|null Null on disconnect.
     */
    public static function showSelectableDialog(
        $conn,
        array &$state,
        $server,
        string $title,
        array $items,
        string $hintSelect = 'Select',
        string $hintBack = 'Back',
        int $selectedIndex = 0,
        ?callable $redrawFn = null,
        array $colorScheme = []
    ): ?array {
        $charset = method_exists($server, 'getTerminalCharset') ? $server->getTerminalCharset() : 'ascii';
        if ($charset === 'utf8') {
            $tl = '┌'; $tr = '┐'; $bl = '└'; $br = '┘'; $hz = '─'; $vt = '│';
            $sepL = '├'; $sepR = '┤';
            $arrowUp = '▲'; $arrowDn = '▼';
        } elseif ($charset === 'cp437') {
            $tl = "\xda"; $tr = "\xbf"; $bl = "\xc0"; $br = "\xd9"; $hz = "\xc4"; $vt = "\xb3";
            $sepL = "\xc3"; $sepR = "\xb4";
            $arrowUp = "\x1e"; $arrowDn = "\x1f";
        } else {
            $tl = '+'; $tr = '+'; $bl = '+'; $br = '+'; $hz = '-'; $vt = '|';
            $sepL = '+'; $sepR = '+';
            $arrowUp = '^'; $arrowDn = 'v';
        }

        $ansi   = self::$ansiColorEnabled;
        $profile = self::getDefaultStyleProfile();
        $scheme = array_merge($profile['selectable_dialog'] ?? [], $colorScheme);
        $bg     = (string)($scheme['bg'] ?? self::ANSI_BG_BLUE);
        $rst    = self::ANSI_RESET;
        $frame  = (string)($scheme['frame'] ?? ($bg . "\033[1;37m"));
        $body   = (string)($scheme['body'] ?? ($bg . "\033[37m"));
        $hilite = (string)($scheme['hilite'] ?? ($bg . "\033[1;33m"));
        $dim    = (string)($scheme['dim'] ?? ($bg . "\033[2;37m"));

        $cursorIdx = min(max(0, $selectedIndex), max(0, count($items) - 1));
        $scrollOffset = 0;
        $itemCount = count($items);
        $hintStr = "Enter {$hintSelect}  Q {$hintBack}";
        $encodedHint = self::encodeHeaderTextForCharset($hintStr, $charset);
        $plainTitleText = self::stripAnsi($title);

        $renderDialog = function () use (
            &$cursorIdx, &$scrollOffset,
            $conn, &$state, $plainTitleText, $items, $itemCount,
            $tl, $tr, $bl, $br, $hz, $vt, $sepL, $sepR,
            $arrowUp, $arrowDn, $hintStr, $encodedHint, $charset,
            $ansi, $rst, $frame, $body, $hilite, $dim
        ): void {
            $rows = $state['rows'] ?? 24;
            $cols = $state['cols'] ?? 80;

            $plainItems = array_map(static fn(string $item): string => self::stripAnsi($item), $items);
            $longestItem = 0;
            foreach ($plainItems as $item) {
                $longestItem = max($longestItem, mb_strlen($item));
            }

            $innerWidth = max(
                28,
                min(
                    max(mb_strlen($plainTitleText) + 4, $longestItem + 4, mb_strlen($hintStr) + 4),
                    min($cols - 6, 72)
                )
            );
            $boxWidth = $innerWidth + 2;
            $maxListRows = max(3, min($itemCount, $rows - 8));
            $dialogHeight = 6 + $maxListRows;
            $startRow = max(1, (int)round(($rows - $dialogHeight) / 2));
            $startCol = max(1, (int)round(($cols - $boxWidth) / 2));

            if ($cursorIdx < $scrollOffset) {
                $scrollOffset = $cursorIdx;
            } elseif ($cursorIdx >= $scrollOffset + $maxListRows) {
                $scrollOffset = $cursorIdx - $maxListRows + 1;
            }

            $hasAbove = $scrollOffset > 0;
            $hasBelow = ($scrollOffset + $maxListRows) < $itemCount;

            $titleLen = mb_strlen(' ' . $plainTitleText . ' ');
            $totalHz = max(0, $innerWidth - $titleLen);
            $encodedTitle = self::encodeHeaderTextForCharset($plainTitleText, $charset);
            $topBorder = $tl . str_repeat($hz, (int)floor($totalHz / 2)) . ' ' . $encodedTitle . ' '
                . str_repeat($hz, (int)ceil($totalHz / 2)) . $tr;
            $midBorder = $sepL . str_repeat($hz, $innerWidth) . $sepR;
            $btmBorder = $bl . str_repeat($hz, $innerWidth) . $br;

            $hintLen = mb_strlen($hintStr);
            $hintLeftPad = max(0, (int)floor(($innerWidth - $hintLen) / 2));
            $hintRightPad = max(0, $innerWidth - $hintLen - $hintLeftPad);

            $buildScrollRow = static function (string $glyph) use ($innerWidth, $vt): string {
                return $vt . str_repeat(' ', $innerWidth - 2) . $glyph . ' ' . $vt;
            };
            $scrollUpRow = $buildScrollRow($arrowUp);
            $scrollDnRow = $buildScrollRow($arrowDn);
            $emptyRow = $vt . str_repeat(' ', $innerWidth) . $vt;

            $draw = static function(int $r, string $line) use ($conn, $startCol): void {
                self::safeWrite($conn, "\033[{$r};{$startCol}H{$line}");
            };

            self::safeWrite($conn, "\033[?25l");

            $r = $startRow;
            if ($ansi) {
                $draw($r++, $frame . $topBorder . $rst);
                $draw($r++, ($hasAbove ? $dim . $scrollUpRow : $body . $emptyRow) . $rst);
            } else {
                $draw($r++, $topBorder);
                $draw($r++, $hasAbove ? $scrollUpRow : $emptyRow);
            }

            $labelWidth = $innerWidth;
            for ($i = 0; $i < $maxListRows; $i++) {
                $itemIdx = $scrollOffset + $i;
                if ($itemIdx >= $itemCount) {
                    $line = $emptyRow;
                    if ($ansi) {
                        $draw($r++, $body . $line . $rst);
                    } else {
                        $draw($r++, $line);
                    }
                    continue;
                }

                $isCur = ($itemIdx === $cursorIdx);
                $plain = str_pad(mb_substr($plainItems[$itemIdx], 0, $labelWidth - 2), $labelWidth - 2);
                $encodedPlain = self::encodeHeaderTextForCharset(rtrim($plain), $charset);
                $rowLabel = str_pad($encodedPlain, $labelWidth - 2);
                $rowText = ($isCur ? '> ' : '  ') . $rowLabel;
                if ($ansi) {
                    $color = $isCur ? $hilite : $body;
                    $draw($r++, $color . $vt . $rowText . $body . $vt . $rst);
                } else {
                    $draw($r++, $vt . $rowText . $vt);
                }
            }

            if ($ansi) {
                $draw($r++, ($hasBelow ? $dim . $scrollDnRow : $body . $emptyRow) . $rst);
                $draw($r++, $frame . $midBorder . $rst);
                $draw($r++, $body . $vt . str_repeat(' ', $hintLeftPad) . "\033[3m" . $encodedHint . "\033[23m" . str_repeat(' ', $hintRightPad) . $body . $vt . $rst);
                $draw($r, $frame . $btmBorder . $rst);
            } else {
                $draw($r++, $hasBelow ? $scrollDnRow : $emptyRow);
                $draw($r++, $midBorder);
                $draw($r++, $vt . str_repeat(' ', $hintLeftPad) . $encodedHint . str_repeat(' ', $hintRightPad) . $vt);
                $draw($r, $btmBorder);
            }

            self::safeWrite($conn, "\033[?25h");
        };

        $renderDialog();

        $lastRows = $state['rows'] ?? 24;
        $lastCols = $state['cols'] ?? 80;

        while (true) {
            $key = $server->readKeyWithIdleCheck($conn, $state);

            $newRows = $state['rows'] ?? $lastRows;
            $newCols = $state['cols'] ?? $lastCols;
            if ($newRows !== $lastRows || $newCols !== $lastCols) {
                $lastRows = $newRows;
                $lastCols = $newCols;
                if ($redrawFn !== null) {
                    $redrawFn($state);
                }
                $renderDialog();
                continue;
            }

            if ($key === null) {
                return null;
            }

            if ($key === 'ENTER') {
                return ['action' => 'select', 'index' => $cursorIdx];
            }

            if ($key === 'UP') {
                if ($cursorIdx > 0) {
                    $cursorIdx--;
                    $renderDialog();
                }
                continue;
            }

            if ($key === 'DOWN') {
                if ($cursorIdx < $itemCount - 1) {
                    $cursorIdx++;
                    $renderDialog();
                }
                continue;
            }

            if (!str_starts_with($key, 'CHAR:')) {
                continue;
            }

            $char = substr($key, 5);
            if (strtolower($char) === 'q') {
                return ['action' => 'quit', 'index' => $cursorIdx];
            }
            if (ctype_digit($char)) {
                $choice = (int)$char;
                if ($choice > 0 && $choice <= $itemCount) {
                    $cursorIdx = $choice - 1;
                    $renderDialog();
                }
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
    private static function promptImageNumber($conn, array &$state, $server, int $total, int $rows, string $statusLine, array $colorScheme = []): ?int
    {
        $maxDigits = strlen((string)$total); // 1 digit for ≤9, 2 for ≤99
        $prompt    = ' View image [1-' . $total . ']: ';

        $renderPrompt = function (string $typed) use ($conn, $rows, $prompt, $colorScheme): void {
            $profile = self::getDefaultStyleProfile();
            $scheme = array_merge($profile['image_prompt'] ?? [], $colorScheme);
            $bg = (string)($scheme['bg'] ?? self::ANSI_BG_WHITE);
            $frame = (string)($scheme['frame'] ?? ($bg . self::ANSI_BLUE . self::ANSI_BOLD));
            $body = (string)($scheme['body'] ?? ($bg . self::ANSI_BLUE));
            if (self::$ansiColorEnabled) {
                $line = "\033[" . $rows . ";1H\033[K"
                    . $frame
                    . $prompt . self::ANSI_RESET
                    . $body
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
    public static function buildStatusBar(array $segments, int $width, array $colorScheme = []): string
    {
        if (!self::$ansiColorEnabled) {
            $plain = '';
            foreach ($segments as $segment) {
                $remaining = $width - strlen($plain);
                if ($remaining <= 0) {
                    break;
                }
                $text = $segment['text'] ?? '';
                $plain .= strlen($text) <= $remaining ? $text : substr($text, 0, $remaining);
            }
            if (strlen($plain) < $width) {
                $plain .= str_repeat(' ', $width - strlen($plain));
            }
            return $plain;
        }

        $profile = self::getDefaultStyleProfile();
        $scheme = array_merge($profile['status_bar'] ?? [], $colorScheme);
        $bg = (string)($scheme['bg'] ?? self::ANSI_BG_WHITE);
        $blue = (string)($scheme['fill'] ?? self::ANSI_BLUE);
        $textColor = (string)($scheme['text'] ?? self::ANSI_BLUE);
        $reset = self::ANSI_RESET;

        $used = 0;
        $line = '';
        foreach ($segments as $segment) {
            if ($used >= $width) {
                break;
            }
            $text      = $segment['text'] ?? '';
            $remaining = $width - $used;
            if (strlen($text) > $remaining) {
                $text = substr($text, 0, $remaining);
            }
            $color = $segment['color'] ?? $textColor;
            $line .= $bg . $color . $text;
            $used += strlen($text);
        }
        if ($used < $width) {
            $line .= $bg . $blue . str_repeat(' ', $width - $used);
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
    public static function buildMessageHeaderBox(int $width, array $fields, string $charset = 'ascii', array $colorScheme = []): array
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
        $profile = self::getDefaultStyleProfile();
        $scheme = array_merge($profile['header_box'] ?? [], $colorScheme);
        $bg    = (string)($scheme['bg'] ?? self::ANSI_BG_BLUE);
        $rst   = self::ANSI_RESET;
        $frame = (string)($scheme['frame'] ?? ($bg . "\033[37m")); // gray foreground on dark blue background
        $body  = (string)($scheme['body'] ?? ($bg . "\033[37m"));

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
     * Display a read-only public user profile viewer.
     *
     * @param resource $conn
     * @param array    &$state
     * @param object   $server
     * @param array    $profile
     * @return void
     */
    public static function showPublicProfileViewer($conn, array &$state, $server, array $profile, array $colorScheme = []): void
    {
        $locale = $state['locale'] ?? 'en';
        $profileStyles = array_merge(self::getDefaultStyleProfile()['profile_viewer'] ?? [], $colorScheme);
        $buildView = function(array $s) use ($server, $profile, $locale, $profileStyles): array {
            $cols = $s['cols'] ?? 80;
            $width = max(10, $cols - 2);
            $charset = method_exists($server, 'getTerminalCharset') ? $server->getTerminalCharset() : 'ascii';

            $notSpecified = $server->t('ui.terminalserver.profile.not_specified', 'Not specified', [], $locale);
            $bioLabel = $server->t('ui.terminalserver.profile.biography', 'Biography', [], $locale);
            $realName = trim((string)($profile['real_name'] ?? ''));
            $location = trim((string)($profile['location'] ?? ''));
            $bioText = trim((string)($profile['about_me'] ?? ''));
            if ($bioText === '') {
                $bioText = $server->t('ui.terminalserver.profile.empty_biography', 'No biography provided.', [], $locale);
            }

            $bodyWidth = max(20, $width - 4);
            $bioLabelColor = (string)($profileStyles['bio_label'] ?? (self::ANSI_CYAN . self::ANSI_BOLD));
            $bodyLines = array_merge(
                [self::colorize($bioLabel, $bioLabelColor), ''],
                TerminalMarkupRenderer::render('markdown', $bioText, $bodyWidth)
            );
            $bodyLines = array_map(fn(string $line): string => $server->encodeForTerminal($line), $bodyLines);

            $statusKey = (string)($profileStyles['status_key'] ?? self::ANSI_RED);
            $statusLabel = (string)($profileStyles['status_label'] ?? self::ANSI_BLUE);
            $segments = [
                ['text' => 'U/D',       'color' => $statusKey],
                ['text' => ' Scroll  ', 'color' => $statusLabel],
                ['text' => 'Q',         'color' => $statusKey],
                ['text' => ' ' . $server->t('ui.terminalserver.profile.status_back', 'Back', [], $locale), 'color' => $statusLabel],
            ];

            return [
                'headerLines' => self::buildMessageHeaderBox($width, [
                    ['label' => $server->t('ui.terminalserver.profile.username', 'Username', [], $locale) . ': ', 'value' => (string)($profile['username'] ?? $notSpecified), 'style' => 'bold'],
                    ['label' => $server->t('ui.terminalserver.profile.real_name', 'Full Name', [], $locale) . ': ', 'value' => $realName !== '' ? $realName : $notSpecified, 'style' => 'normal'],
                    ['label' => $server->t('ui.terminalserver.profile.location', 'Location', [], $locale) . ': ', 'value' => $location !== '' ? $location : $notSpecified, 'style' => 'dim'],
                ], $charset, $profileStyles),
                'wrappedLines' => $bodyLines,
                'statusLine' => self::buildStatusBar($segments, $width, $profileStyles),
            ];
        };

        $helpItems = [
            ['key' => 'PgUp / PgDn', 'label' => $server->t('ui.terminalserver.message.help_page', 'Scroll one page', [], $locale)],
        ];

        $view = $buildView($state);
        self::runMessageViewer(
            $conn,
            $state,
            $server,
            $view['headerLines'],
            $view['wrappedLines'],
            $view['statusLine'],
            $state['rows'] ?? 24,
            0,
            false,
            [],
            $buildView,
            [],
            null,
            [],
            $helpItems
        );
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
        string $default = 'n',
        ?callable $redrawFn = null,
        array $colorScheme = []
    ): string {
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
        $sep = '    ';
        $hintTokens = array_map(
            static fn(array $p): array => [
                'char' => $p['char'],
                'label' => $p['label'],
                'plain' => $p['char'] . ') ' . $p['label'],
            ],
            $hintParts
        );

        $ansi  = self::$ansiColorEnabled;
        $profile = self::getStyleProfile($state);
        $scheme = array_merge($profile['dialog'] ?? [], $colorScheme);
        $bg    = (string)($scheme['bg'] ?? self::ANSI_BG_BLUE);
        $rst   = self::ANSI_RESET;
        $frame = (string)($scheme['frame'] ?? ($bg . "\033[1;37m"));
        $body  = (string)($scheme['body'] ?? ($bg . "\033[37m"));
        $hint  = (string)($scheme['hint'] ?? self::ANSI_YELLOW);
        $choiceKey = (string)($scheme['choice_key'] ?? (self::ANSI_RED . self::ANSI_BOLD));
        $choiceLabel = (string)($scheme['choice_label'] ?? $body);
        $renderDialog = function () use (
            $conn,
            &$state,
            $title,
            $message,
            $hintTokens,
            $sep,
            $tl,
            $tr,
            $bl,
            $br,
            $hz,
            $vt,
            $ansi,
            $frame,
            $body,
            $hint,
            $choiceKey,
            $choiceLabel,
            $rst
        ): void {
            $rows = $state['rows'] ?? 24;
            $cols = $state['cols'] ?? 80;
            $maxInnerWidth = max(24, $cols - 6);
            $messageWidth  = max(10, $maxInnerWidth - 2);
            $messageLines  = self::wrapTextLines($message, $messageWidth);
            $messageMaxLen = 0;
            foreach ($messageLines as $line) {
                $messageMaxLen = max($messageMaxLen, mb_strlen($line));
            }

            $hintLines = [];
            $currentHintLine = [];
            $currentHintLen = 0;
            $hintWrapWidth = max(10, $maxInnerWidth - 2);
            foreach ($hintTokens as $token) {
                $tokenLen = mb_strlen($token['plain']);
                $projectedLen = $currentHintLine === [] ? $tokenLen : $currentHintLen + mb_strlen($sep) + $tokenLen;
                if ($currentHintLine !== [] && $projectedLen > $hintWrapWidth) {
                    $hintLines[] = $currentHintLine;
                    $currentHintLine = [$token];
                    $currentHintLen = $tokenLen;
                } else {
                    $currentHintLine[] = $token;
                    $currentHintLen = $projectedLen;
                }
            }
            if ($currentHintLine !== []) {
                $hintLines[] = $currentHintLine;
            }
            if ($hintLines === []) {
                $hintLines[] = [];
            }

            $buildPlainHintLine = static function(array $lineTokens) use ($sep): string {
                return implode($sep, array_map(static fn(array $token): string => $token['plain'], $lineTokens));
            };

            $hintMaxLen = 0;
            foreach ($hintLines as $lineTokens) {
                $hintMaxLen = max($hintMaxLen, mb_strlen($buildPlainHintLine($lineTokens)));
            }

            $innerWidth = max(
                24,
                min(
                    max($messageMaxLen + 2, mb_strlen($title) + 4, $hintMaxLen + 4),
                    $maxInnerWidth
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

            $msgRows = [];
            foreach ($messageLines as $messageLine) {
                $msgContent = str_pad(mb_substr($messageLine, 0, $innerWidth - 2), $innerWidth - 2);
                $msgRows[]  = $vt . ' ' . $msgContent . ' ' . $vt;
            }

            $dialogHeight = 1 + 1 + count($msgRows) + 1 + count($hintLines) + 1;
            $startRow = max(1, (int)round(($rows - $dialogHeight) / 2));
            $startCol = max(1, (int)round(($cols - $boxWidth) / 2));
            $draw = static function(int $r, string $line) use ($conn, $startCol): void {
                self::safeWrite($conn, "\033[{$r};{$startCol}H{$line}");
            };

            self::safeWrite($conn, "\033[?25l");

            $r = $startRow;
            if ($ansi) {
                $draw($r++, $frame . $topBorder . $rst);
                $draw($r++, $body . $emptyRow . $rst);
                foreach ($msgRows as $msgRow) {
                    $draw($r++, $body . $msgRow . $rst);
                }
                $draw($r++, $body . $emptyRow . $rst);

                foreach ($hintLines as $lineTokens) {
                    $plainHintLine = $buildPlainHintLine($lineTokens);
                    $hintLineLen   = mb_strlen($plainHintLine);
                    $leftPad       = max(0, (int)floor(($innerWidth - $hintLineLen) / 2));
                    $rightPad      = max(0, $innerWidth - $hintLineLen - $leftPad);

                    $hintContent = str_repeat(' ', $leftPad);
                    foreach ($lineTokens as $i => $token) {
                        if ($i > 0) {
                            $hintContent .= $body . $sep;
                        }
                        $hintContent .= $choiceKey . $token['char']
                            . $choiceLabel . ') ' . $token['label'];
                    }
                    $hintContent .= str_repeat(' ', $rightPad);
                    $draw($r++, $body . $vt . $hintContent . $body . $vt . $rst);
                }
                $draw($r, $frame . $btmBorder . $rst);
            } else {
                $draw($r++, $topBorder);
                $draw($r++, $emptyRow);
                foreach ($msgRows as $msgRow) {
                    $draw($r++, $msgRow);
                }
                $draw($r++, $emptyRow);
                foreach ($hintLines as $lineTokens) {
                    $plainHintLine = $buildPlainHintLine($lineTokens);
                    $hintLineLen   = mb_strlen($plainHintLine);
                    $leftPad       = max(0, (int)floor(($innerWidth - $hintLineLen) / 2));
                    $rightPad      = max(0, $innerWidth - $hintLineLen - $leftPad);
                    $draw($r++, $vt . str_repeat(' ', $leftPad) . $plainHintLine . str_repeat(' ', $rightPad) . $vt);
                }
                $draw($r, $btmBorder);
            }

            self::safeWrite($conn, "\033[?25h");
        };

        $renderDialog();

        // Read a keypress — only accept keys in $choices
        $lastRows = $state['rows'] ?? 24;
        $lastCols = $state['cols'] ?? 80;
        while (true) {
            $key = $server->readKeyWithIdleCheck($conn, $state);
            $newRows = $state['rows'] ?? $lastRows;
            $newCols = $state['cols'] ?? $lastCols;
            if ($newRows !== $lastRows || $newCols !== $lastCols) {
                $lastRows = $newRows;
                $lastCols = $newCols;
                $fn = $redrawFn ?? $state['repaint_fn'] ?? null;
                if ($fn !== null) {
                    $fn($state);
                }
                $renderDialog();
            }
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
     * Show a centered single-line text input dialog.
     *
     * Renders a boxed overlay with a title, an optional prompt line, and an editable
     * input field pre-filled with $prefill. The user can type, use Backspace/Delete,
     * and press Enter to confirm or Escape to cancel. The dialog redraws automatically
     * on terminal resize. If $redrawFn is supplied it is called before each redraw so
     * the caller can repaint the screen behind the dialog.
     *
     * @param resource $conn
     * @param callable|null $redrawFn  Called with ($state) before each resize redraw
     * @return string|null  The entered string, or null on Escape / disconnect
     */
    public static function showInputDialog(
        $conn,
        array &$state,
        $server,
        string $title,
        string $prompt,
        string $prefill = '',
        int $maxLength = 255,
        ?callable $redrawFn = null,
        array $colorScheme = [],
        array $options = []
    ): ?string {
        $charset = method_exists($server, 'getTerminalCharset') ? $server->getTerminalCharset() : 'ascii';

        if ($charset === 'utf8') {
            $tl = '┌'; $tr = '┐'; $bl = '└'; $br = '┘'; $hz = '─'; $vt = '│';
        } elseif ($charset === 'cp437') {
            $tl = "\xda"; $tr = "\xbf"; $bl = "\xc0"; $br = "\xd9"; $hz = "\xc4"; $vt = "\xb3";
        } else {
            $tl = '+'; $tr = '+'; $bl = '+'; $br = '+'; $hz = '-'; $vt = '|';
        }

        $ansi  = self::$ansiColorEnabled;
        $profile = self::getDefaultStyleProfile();
        $scheme = array_merge($profile['dialog'] ?? [], $colorScheme);
        $bg    = (string)($scheme['bg'] ?? self::ANSI_BG_BLUE);
        $rst   = self::ANSI_RESET;
        $frame = (string)($scheme['frame'] ?? ($bg . "\033[1;37m"));
        $body  = (string)($scheme['body'] ?? ($bg . "\033[37m"));
        $hint  = (string)($scheme['hint'] ?? self::ANSI_YELLOW);

        $value = $prefill;
        $inlinePrompt = !empty($options['inline_prompt']);

        // Returns [startRow, startCol, innerWidth, inputRow] for the current terminal size.
        $footerHint = (string)($options['footer_hint'] ?? '');

        $layout = static function() use (&$state, $title, $prompt, $footerHint, $inlinePrompt): array {
            $rows       = $state['rows'] ?? 24;
            $cols       = $state['cols'] ?? 80;
            $innerWidth = max(30, min($cols - 6, 60));
            $hasPrompt  = $prompt !== '';
            // inline_prompt combines the prompt label and input on one row, so box is 7 not 8
            $boxHeight  = ($hasPrompt && !$inlinePrompt) ? 8 : 7;
            $startRow   = max(1, (int)round(($rows - $boxHeight) / 2));
            $startCol   = max(1, (int)round(($cols - ($innerWidth + 2)) / 2));
            return [$startRow, $startCol, $innerWidth, $hasPrompt];
        };

        $render = function() use (
            $conn, &$state, &$value,
            $title, $prompt,
            $tl, $tr, $bl, $br, $hz, $vt,
            $ansi, $frame, $body, $hint, $rst,
            $layout,
            $inlinePrompt,
            $footerHint
        ): array {
            [$startRow, $startCol, $innerWidth, $hasPrompt] = $layout();

            $titleText  = mb_substr($title, 0, $innerWidth - 2);
            $titlePad   = (int)floor(($innerWidth - mb_strlen($titleText)) / 2);
            $titleLine  = str_repeat(' ', $titlePad) . $titleText
                        . str_repeat(' ', max(0, $innerWidth - mb_strlen($titleText) - $titlePad));

            $emptyRow  = $vt . str_repeat(' ', $innerWidth) . $vt;
            $topBorder = $tl . str_repeat($hz, $innerWidth) . $tr;
            $btmBorder = $bl . str_repeat($hz, $innerWidth) . $br;

            $promptText = $hasPrompt ? mb_substr($prompt, 0, max(0, $innerWidth - 4)) : '';
            $promptPlain = self::stripAnsi($promptText);
            $promptWidth = $inlinePrompt && $hasPrompt
                ? max(0, min(mb_strlen($promptPlain), $innerWidth - 12))
                : 0;

            // Input field: value left-aligned, truncated if longer than the field
            $fieldWidth  = $inlinePrompt && $hasPrompt
                ? max(10, $innerWidth - 3 - $promptWidth)
                : $innerWidth - 2;
            $displayVal  = mb_substr($value, max(0, mb_strlen($value) - $fieldWidth));
            $inputContent = $displayVal . str_repeat(' ', max(0, $fieldWidth - mb_strlen($displayVal)));
            $cursorOffset = mb_strlen($displayVal);

            $draw = static function(int $r, string $line) use ($conn, $startCol): void {
                self::safeWrite($conn, "\033[{$r};{$startCol}H{$line}");
            };

            self::safeWrite($conn, "\033[?25l");
            $r = $startRow;
            if ($ansi) {
                $draw($r++, $frame . $topBorder . $rst);
                $draw($r++, $body . $vt . $titleLine . $vt . $rst);
                $draw($r++, $body . $emptyRow . $rst);
                if ($inlinePrompt && $hasPrompt) {
                    // Replace any ANSI_RESET in the colored prompt with $body so the dialog blue
                    // background is restored rather than falling back to the terminal default (black).
                    $promptContent = str_replace(self::ANSI_RESET, $body, $promptText)
                        . str_repeat(' ', max(0, $promptWidth - mb_strlen($promptPlain)));
                    $inputRow = $r;
                    $draw($r++, $body . $vt . ' ' . $promptContent . $body . ' ' . "\033[1;37;44m" . $inputContent . $body . ' ' . $vt . $rst);
                } else {
                    if ($hasPrompt) {
                        // Replace ANSI_RESET with dialog body style so colored labels keep blue background;
                        // use stripAnsi length for correct padding regardless of escape-sequence byte count.
                        $promptContent = str_replace(self::ANSI_RESET, $body, $prompt);
                        $promptContent .= str_repeat(' ', max(0, $fieldWidth - mb_strlen(self::stripAnsi($promptContent))));
                        $draw($r++, $body . $vt . ' ' . $promptContent . ' ' . $vt . $rst);
                    }
                    // Input row: highlight the field with a different background
                    $inputRow = $r;
                    $draw($r++, $body . $vt . ' ' . $inputContent . ' ' . $vt . $rst);
                }
                $draw($r++, $body . $emptyRow . $rst);
                $hintText    = 'Enter=OK  Esc=Cancel' . ($footerHint !== '' ? '  ' . $footerHint : '');
                $hintWidth   = $innerWidth - 2;
                $hintContent = str_pad(mb_substr($hintText, 0, $hintWidth), $hintWidth);
                $draw($r++, $body . $vt . ' ' . $hint . $hintContent . $body . ' ' . $vt . $rst);
                $draw($r,   $frame . $btmBorder . $rst);
            } else {
                $draw($r++, $topBorder);
                $draw($r++, $vt . $titleLine . $vt);
                $draw($r++, $emptyRow);
                if ($inlinePrompt && $hasPrompt) {
                    $promptContent = $promptText . str_repeat(' ', max(0, $promptWidth - mb_strlen($promptPlain)));
                    $inputRow = $r;
                    $draw($r++, $vt . ' ' . $promptContent . ' ' . $inputContent . ' ' . $vt);
                } else {
                    if ($hasPrompt) {
                        $promptContent = mb_substr($prompt, 0, $fieldWidth);
                        $promptContent = $promptContent . str_repeat(' ', max(0, $fieldWidth - mb_strlen($promptContent)));
                        $draw($r++, $vt . ' ' . $promptContent . ' ' . $vt);
                    }
                    $inputRow = $r;
                    $draw($r++, $vt . ' ' . $inputContent . ' ' . $vt);
                }
                $draw($r++, $emptyRow);
                $hintText    = 'Enter=OK  Esc=Cancel' . ($footerHint !== '' ? '  ' . $footerHint : '');
                $hintWidth   = $innerWidth - 2;
                $hintContent = str_pad(mb_substr($hintText, 0, $hintWidth), $hintWidth);
                $draw($r++, $vt . ' ' . $hint . $hintContent . $rst . ' ' . $vt);
                $draw($r,   $btmBorder);
            }

            // Place cursor at end of input field and show it
            $cursorCol = $startCol + 1 + (($inlinePrompt && $hasPrompt) ? ($promptWidth + 1) : 0) + $cursorOffset + 1; // box left + vt + space + chars
            self::safeWrite($conn, "\033[{$inputRow};{$cursorCol}H\033[?25h");

            return [$inputRow, $startCol, $innerWidth];
        };

        $render();

        $lastRows = $state['rows'] ?? 24;
        $lastCols = $state['cols'] ?? 80;

        while (true) {
            $key = $server->readKeyWithIdleCheck($conn, $state);

            $newRows = $state['rows'] ?? $lastRows;
            $newCols = $state['cols'] ?? $lastCols;
            if ($newRows !== $lastRows || $newCols !== $lastCols) {
                $lastRows = $newRows;
                $lastCols = $newCols;
                $fn = $redrawFn ?? $state['repaint_fn'] ?? null;
                if ($fn !== null) {
                    $fn($state);
                }
                $render();
                continue;
            }

            if ($key === null) {
                return null;
            }

            if ($key === 'ENTER') {
                self::safeWrite($conn, "\033[?25l");
                return $value;
            }

            // ESC (bare) returns '' — treat as cancel, along with Ctrl+C
            if ($key === '' || $key === 'CTRL_C') {
                self::safeWrite($conn, "\033[?25l");
                return null;
            }

            if ($key === 'BACKSPACE' || $key === 'DELETE') {
                if ($value !== '') {
                    $value = mb_substr($value, 0, mb_strlen($value) - 1);
                    $render();
                }
                continue;
            }

            if (str_starts_with($key, 'CHAR:')) {
                $char = substr($key, 5);
                if (mb_strlen($value) < $maxLength && $char !== '') {
                    $value .= $char;
                    $render();
                }
                continue;
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
        string $message,
        array $colorScheme = []
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

        $dialogHeight = 3; // top + message + bottom
        $startRow = max(1, (int)round(($rows - $dialogHeight) / 2));
        $startCol = max(1, (int)round(($cols - $boxWidth)    / 2));

        $ansi  = self::$ansiColorEnabled;
        $bg    = (string)($colorScheme['bg'] ?? self::ANSI_BG_BLUE);
        $rst   = self::ANSI_RESET;
        $frame = (string)($colorScheme['frame'] ?? ($bg . "\033[1;37m"));
        $body  = (string)($colorScheme['body'] ?? ($bg . "\033[37m"));

        $draw = static function(int $r, string $line) use ($conn, $startCol): void {
            self::safeWrite($conn, "\033[{$r};{$startCol}H{$line}");
        };

        self::safeWrite($conn, "\033[?25l");

        $r = $startRow;
        if ($ansi) {
            $draw($r++, $frame . $topBorder . $rst);
            $draw($r++, $body  . $msgRow    . $rst);
            $draw($r,   $frame . $btmBorder . $rst);
        } else {
            $draw($r++, $topBorder);
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
        string $style = 'info',
        array $colorScheme = []
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

        $hint  = 'Press Enter to continue';
        $ansi  = self::$ansiColorEnabled;
        $profile = self::getDefaultStyleProfile();
        $scheme = array_merge(($profile['alert'][$style] ?? []), $colorScheme);
        $bg    = (string)($scheme['bg'] ?? ($style === 'error' ? self::ANSI_BG_RED : self::ANSI_BG_BLUE));
        $rst   = self::ANSI_RESET;
        $frame = (string)($scheme['frame'] ?? ($bg . "\033[1;37m"));
        $body  = (string)($scheme['body'] ?? ($bg . "\033[37m"));

        $renderDialog = function() use ($conn, &$state, $title, $message, $hint, $tl, $tr, $bl, $br, $hz, $vt, $ansi, $frame, $body, $rst): void {
            $rows = $state['rows'] ?? 24;
            $cols = $state['cols'] ?? 80;

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

            $msgContent   = str_pad(mb_substr($message, 0, $innerWidth - 2), $innerWidth - 2);
            $msgRow       = $vt . ' ' . $msgContent . ' ' . $vt;
            $hintLen      = mb_strlen($hint);
            $hintLeftPad  = max(0, (int)floor(($innerWidth - $hintLen) / 2));
            $hintRightPad = max(0, $innerWidth - $hintLen - $hintLeftPad);

            $dialogHeight = 6; // top + empty + message + empty + hint + bottom
            $startRow = max(1, (int)round(($rows - $dialogHeight) / 2));
            $startCol = max(1, (int)round(($cols - $boxWidth)    / 2));

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
        };

        $renderDialog();

        $lastRows = $state['rows'] ?? 24;
        $lastCols = $state['cols'] ?? 80;

        while (true) {
            $key = $server->readKeyWithIdleCheck($conn, $state);

            $newRows = $state['rows'] ?? $lastRows;
            $newCols = $state['cols'] ?? $lastCols;
            if ($newRows !== $lastRows || $newCols !== $lastCols) {
                $lastRows = $newRows;
                $lastCols = $newCols;
                $fn = $state['repaint_fn'] ?? null;
                if ($fn !== null) {
                    $fn($state);
                }
                $renderDialog();
                continue;
            }

            if ($key === null || $key === 'ENTER') {
                return;
            }
        }
    }

    /**
     * Shows a centered dialog overlay with a scrollable checkbox list.
     *
     * Items are displayed with [ ] / [*] prefix based on selection state. The cursor
     * (>) highlights the current row. Space toggles; Enter confirms; Q skips.
     * Scroll indicators appear when the list overflows the dialog height.
     * On terminal resize the old dialog region is erased before redrawing.
     *
     * @param resource  $conn            Socket connection
     * @param array     &$state          Terminal state
     * @param object    $server          Server instance
     * @param callable  $titleFn         fn(int $selectedCount): string — title text, called on each redraw
     * @param string[]  $items           Plain-text item labels (one per row)
     * @param int[]     $selectedIndices Initially selected item indices (0-based)
     * @param int       $maxSelect       Max selections allowed (0 = unlimited)
     * @param string    $atLimitMessage  Message shown when trying to exceed $maxSelect
     * @param string    $hintConfirm     Enter key label in hint area
     * @param string    $hintSkip        Q key label in hint area
     * @param callable|null $redrawFn    Optional callback invoked on terminal resize before dialog redraw
     * @return array|null ['action'=>'confirm'|'quit', 'selected'=>int[]], or null on disconnect
     */
    public static function showCheckboxListDialog(
        $conn,
        array &$state,
        $server,
        callable $titleFn,
        array $items,
        array $selectedIndices = [],
        int $maxSelect = 0,
        string $atLimitMessage = '',
        string $hintConfirm = 'Done',
        string $hintSkip = 'Skip',
        ?callable $redrawFn = null,
        array $colorScheme = []
    ): ?array {
        $charset = method_exists($server, 'getTerminalCharset') ? $server->getTerminalCharset() : 'ascii';
        if ($charset === 'utf8') {
            $tl = '┌'; $tr = '┐'; $bl = '└'; $br = '┘'; $hz = '─'; $vt = '│';
            $sepL = '├'; $sepR = '┤';
            $arrowUp = '▲'; $arrowDn = '▼';
        } elseif ($charset === 'cp437') {
            $tl = "\xda"; $tr = "\xbf"; $bl = "\xc0"; $br = "\xd9"; $hz = "\xc4"; $vt = "\xb3";
            $sepL = "\xc3"; $sepR = "\xb4";
            $arrowUp = "\x1e"; $arrowDn = "\x1f";
        } else {
            $tl = '+'; $tr = '+'; $bl = '+'; $br = '+'; $hz = '-'; $vt = '|';
            $sepL = '+'; $sepR = '+';
            $arrowUp = '^'; $arrowDn = 'v';
        }

        $ansi   = self::$ansiColorEnabled;
        $profile = self::getDefaultStyleProfile();
        $scheme = array_merge($profile['checkbox_dialog'] ?? [], $colorScheme);
        $bg     = (string)($scheme['bg'] ?? self::ANSI_BG_BLUE);
        $rst    = self::ANSI_RESET;
        $frame  = (string)($scheme['frame'] ?? ($bg . "\033[1;37m"));
        $body   = (string)($scheme['body'] ?? ($bg . "\033[37m"));
        $hilite = (string)($scheme['hilite'] ?? ($bg . "\033[1;33m")); // highlighted row
        $dim    = (string)($scheme['dim'] ?? ($bg . "\033[2;37m"));     // scroll indicators

        $cursorIdx    = 0;
        $scrollOffset = 0;
        $selected     = array_values($selectedIndices);
        $itemCount    = count($items);
        $hintStr      = "Space Toggle  Enter {$hintConfirm}  Q {$hintSkip}";

        $renderDialog = function () use (
            &$cursorIdx, &$scrollOffset, &$selected,
            $conn, &$state, $titleFn, $items, $itemCount,
            $tl, $tr, $bl, $br, $hz, $vt, $sepL, $sepR,
            $arrowUp, $arrowDn, $hintStr,
            $ansi, $bg, $rst, $frame, $body, $hilite, $dim
        ): void {
            $rows = $state['rows'] ?? 24;
            $cols = $state['cols'] ?? 80;

            $innerWidth   = max(30, min(64, $cols - 6));
            $boxWidth     = $innerWidth + 2;
            $maxListRows  = max(3, min($itemCount, $rows - 9));
            // rows: top + scrollUp + list + scrollDn + separator + hint + bottom
            $dialogHeight = 7 + $maxListRows;
            $startRow     = max(1, (int)round(($rows - $dialogHeight) / 2));
            $startCol     = max(1, (int)round(($cols - $boxWidth)    / 2));

            // keep cursor in view
            if ($cursorIdx < $scrollOffset) {
                $scrollOffset = $cursorIdx;
            } elseif ($cursorIdx >= $scrollOffset + $maxListRows) {
                $scrollOffset = $cursorIdx - $maxListRows + 1;
            }

            $hasAbove = $scrollOffset > 0;
            $hasBelow = ($scrollOffset + $maxListRows) < $itemCount;

            $rawTitle   = $titleFn(count($selected));
            $plainTitle = ' ' . self::stripAnsi($rawTitle) . ' ';
            $titleLen   = mb_strlen($plainTitle);
            $totalHz    = max(0, $innerWidth - $titleLen);
            $topBorder  = $tl . str_repeat($hz, (int)floor($totalHz / 2)) . $plainTitle
                . str_repeat($hz, (int)ceil($totalHz / 2)) . $tr;
            $midBorder  = $sepL . str_repeat($hz, $innerWidth) . $sepR;
            $btmBorder  = $bl . str_repeat($hz, $innerWidth) . $br;

            $hintLen      = mb_strlen($hintStr);
            $hintLeftPad  = max(0, (int)floor(($innerWidth - $hintLen) / 2));
            $hintRightPad = max(0, $innerWidth - $hintLen - $hintLeftPad);

            // Scroll indicator rows: spaces with arrow glyph flush-right
            $buildScrollRow = static function (string $glyph) use ($innerWidth, $vt): string {
                return $vt . str_repeat(' ', $innerWidth - 2) . $glyph . ' ' . $vt;
            };
            $scrollUpRow = $buildScrollRow($arrowUp);
            $scrollDnRow = $buildScrollRow($arrowDn);
            $emptyRow    = $vt . str_repeat(' ', $innerWidth) . $vt;

            $draw = static function(int $r, string $line) use ($conn, $startCol): void {
                self::safeWrite($conn, "\033[{$r};{$startCol}H{$line}");
            };

            self::safeWrite($conn, "\033[?25l");

            $r = $startRow;
            if ($ansi) {
                $draw($r++, $frame . $topBorder . $rst);
                // scroll-up indicator row
                if ($hasAbove) {
                    $draw($r++, $dim . $scrollUpRow . $rst);
                } else {
                    $draw($r++, $body . $emptyRow . $rst);
                }
            } else {
                $draw($r++, $topBorder);
                $draw($r++, $hasAbove ? $scrollUpRow : $emptyRow);
            }

            $labelWidth = $innerWidth - 6; // "> [*] " = 6 chars; row fills innerWidth exactly
            for ($i = 0; $i < $maxListRows; $i++) {
                $itemIdx = $scrollOffset + $i;
                if ($itemIdx >= $itemCount) {
                    if ($ansi) {
                        $draw($r++, $body . $emptyRow . $rst);
                    } else {
                        $draw($r++, $emptyRow);
                    }
                    continue;
                }
                $isSel   = in_array($itemIdx, $selected, true);
                $isCur   = ($itemIdx === $cursorIdx);
                $check   = $isSel ? '[*]' : '[ ]';
                $cursor  = $isCur ? '>' : ' ';
                $label   = str_pad(mb_substr((string)($items[$itemIdx] ?? ''), 0, $labelWidth), $labelWidth);
                $rowText = "{$cursor} {$check} {$label}";
                if ($ansi) {
                    $color = $isCur ? $hilite : $body;
                    $draw($r++, $color . $vt . $rowText . $body . $vt . $rst);
                } else {
                    $draw($r++, $vt . $rowText . $vt);
                }
            }

            if ($ansi) {
                // scroll-down indicator row
                if ($hasBelow) {
                    $draw($r++, $dim . $scrollDnRow . $rst);
                } else {
                    $draw($r++, $body . $emptyRow . $rst);
                }
                $draw($r++, $frame . $midBorder . $rst);
                $draw($r++, $body . $vt . str_repeat(' ', $hintLeftPad) . "\033[3m" . $hintStr . "\033[23m" . str_repeat(' ', $hintRightPad) . $body . $vt . $rst);
                $draw($r,   $frame . $btmBorder . $rst);
            } else {
                $draw($r++, $hasBelow ? $scrollDnRow : $emptyRow);
                $draw($r++, $midBorder);
                $draw($r++, $vt . str_repeat(' ', $hintLeftPad) . $hintStr . str_repeat(' ', $hintRightPad) . $vt);
                $draw($r,   $btmBorder);
            }

            self::safeWrite($conn, "\033[?25h");
        };

        $renderDialog();

        $lastRows = $state['rows'] ?? 24;
        $lastCols = $state['cols'] ?? 80;

        while (true) {
            $key = $server->readKeyWithIdleCheck($conn, $state);

            $newRows = $state['rows'] ?? $lastRows;
            $newCols = $state['cols'] ?? $lastCols;
            if ($newRows !== $lastRows || $newCols !== $lastCols) {
                $lastRows = $newRows;
                $lastCols = $newCols;
                if ($redrawFn !== null) {
                    $redrawFn($state);
                }
                $renderDialog();
                continue;
            }

            if ($key === null) {
                return null;
            }

            if ($key === 'ENTER') {
                return ['action' => 'confirm', 'selected' => $selected];
            }

            if ($key === 'UP' || $key === 'CHAR:k') {
                if ($cursorIdx > 0) {
                    $cursorIdx--;
                    $renderDialog();
                }
                continue;
            }

            if ($key === 'DOWN' || $key === 'CHAR:j') {
                if ($cursorIdx < $itemCount - 1) {
                    $cursorIdx++;
                    $renderDialog();
                }
                continue;
            }

            if (!str_starts_with($key, 'CHAR:')) {
                continue;
            }

            $char = substr($key, 5);

            if (strtolower($char) === 'q') {
                return ['action' => 'quit', 'selected' => []];
            }

            if ($char === ' ') {
                if (in_array($cursorIdx, $selected, true)) {
                    $selected = array_values(array_filter($selected, fn(int $i): bool => $i !== $cursorIdx));
                    $renderDialog();
                } elseif ($maxSelect === 0 || count($selected) < $maxSelect) {
                    $selected[] = $cursorIdx;
                    sort($selected);
                    $renderDialog();
                } elseif ($atLimitMessage !== '') {
                    self::showAlertDialog($conn, $state, $server, '', $atLimitMessage, 'error');
                    $renderDialog();
                }
                continue;
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

            // Fetch address book autocomplete results, which also include local-user matches.
            $abResp    = self::apiRequest($apiBase, 'GET', '/api/address-book/search/' . rawurlencode($query), null, $session);
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
                $profile = self::getDefaultStyleProfile();
                $titleLine = self::colorize(
                    $server->t('ui.terminalserver.compose.address_book_title', 'Address Book Search', [], $locale)
                    . ' — "' . $query . '"',
                    $profile['list']['title'] ?? (self::ANSI_CYAN . self::ANSI_BOLD)
                );
                return ['rows' => $rows, 'title' => $titleLine];
            };

            $built = $formatRows($state);
            $statusBarProfile = self::getDefaultStyleProfile()['status_bar'] ?? [];
            $statusBar = [
                ['text' => 'Up/Dn', 'color' => $statusBarProfile['text'] ?? self::ANSI_RED],
                ['text' => ' Select  ', 'color' => $statusBarProfile['fill'] ?? self::ANSI_BLUE],
                ['text' => 'Enter',    'color' => $statusBarProfile['text'] ?? self::ANSI_RED],
                ['text' => ' Confirm  ', 'color' => $statusBarProfile['fill'] ?? self::ANSI_BLUE],
                ['text' => 'Q',        'color' => $statusBarProfile['text'] ?? self::ANSI_RED],
                ['text' => ' Cancel',  'color' => $statusBarProfile['fill'] ?? self::ANSI_BLUE],
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
