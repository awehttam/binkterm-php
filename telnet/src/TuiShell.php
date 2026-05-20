<?php

namespace BinktermPHP\TelnetServer;

/**
 * Full-screen widget-backed terminal shell implementation.
 */
class TuiShell implements TerminalShellInterface
{
    private BbsSession $server;
    private array $styleProfile;

    public function __construct(BbsSession $server)
    {
        $this->server = $server;
        $this->styleProfile = $this->buildStyleProfile();
    }

    /**
     * Return the canonical style profile for shell-backed widgets.
     *
     * This keeps the palette in one place so future configuration can swap the
     * look without changing each widget method.
     */
    private function buildStyleProfile(): array
    {
        return TelnetUtils::getDefaultStyleProfile();
    }

    public function chooseFromList($conn, array &$state, string $title, array $items, array $options = []): ?int
    {
        if ($items === []) {
            $this->showAlert(
                $conn,
                $state,
                $title,
                (string)($options['empty_message'] ?? 'Nothing to show.'),
                'info'
            );
            return null;
        }

        $sb = $this->styleProfile['status_bar'] ?? [];
        $statusBar = $options['status_bar'] ?? [
            ['text' => 'U/D',       'color' => $sb['key']   ?? TelnetUtils::ANSI_RED],
            ['text' => ' Move  ',   'color' => $sb['label'] ?? TelnetUtils::ANSI_BLUE],
            ['text' => 'Enter',     'color' => $sb['key']   ?? TelnetUtils::ANSI_RED],
            ['text' => ' Select  ', 'color' => $sb['label'] ?? TelnetUtils::ANSI_BLUE],
            ['text' => 'Q',         'color' => $sb['key']   ?? TelnetUtils::ANSI_RED],
            ['text' => ' Back',     'color' => $sb['label'] ?? TelnetUtils::ANSI_BLUE],
        ];

        $selectedIndex = max(0, (int)($options['selected_index'] ?? 0));

        while (true) {
            $result = TelnetUtils::runSelectableList(
                $conn,
                $state,
                $this->server,
                TelnetUtils::colorize($title, $this->styleProfile['list']['title']),
                $items,
                1,
                1,
                $selectedIndex,
                $statusBar,
                [],
                null,
                [
                    'color_scheme' => $this->styleProfile['panel'],
                    'help_overlay' => $this->styleProfile['help_overlay'],
                ]
            );

            $selectedIndex = $result['selectedIndex'] ?? $selectedIndex;
            if (($result['action'] ?? '') === 'select') {
                return (int)($result['index'] ?? 0);
            }
            if (($result['action'] ?? '') === 'quit' || ($result['action'] ?? '') === 'disconnect') {
                return null;
            }
        }
    }

    public function promptText($conn, array &$state, string $title, string $prompt, array $options = []): ?string
    {
        return TelnetUtils::showInputDialog(
            $conn,
            $state,
            $this->server,
            $title,
            $prompt,
            (string)($options['prefill'] ?? ''),
            max(1, (int)($options['max_length'] ?? 255)),
            isset($options['redraw_fn']) && is_callable($options['redraw_fn']) ? $options['redraw_fn'] : null,
            $this->styleProfile['dialog'],
            [
                'inline_prompt' => (bool)($options['inline_prompt'] ?? false),
                'footer_hint'   => (string)($options['footer_hint'] ?? ''),
            ]
        );
    }

    public function promptKey($conn, array &$state, string $title, string $prompt, array $allowedKeys, array $options = []): ?string
    {
        $redrawFn = isset($options['redraw_fn']) && is_callable($options['redraw_fn'])
            ? $options['redraw_fn']
            : null;
        $labels = is_array($options['labels'] ?? null) ? $options['labels'] : [];
        $choices = [];
        foreach ($allowedKeys as $key) {
            $choices[$key] = (string)($labels[$key] ?? strtoupper($key));
        }

        $default = strtolower((string)($options['default'] ?? ($allowedKeys[0] ?? 'q')));
        $choice = TelnetUtils::showConfirmDialog($conn, $state, $this->server, $title, $prompt, $choices, $default, $redrawFn, $this->styleProfile['dialog']);
        return strtolower($choice);
    }

    public function showText($conn, array &$state, string $title, array $lines, array $options = []): void
    {
        $box = new TerminalBoxRenderer($this->server);
        $box->showPagedBox(
            $conn,
            $state,
            $title,
            $lines === [] ? [''] : $lines,
            (string)($options['continue_prompt'] ?? $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $state['locale'] ?? 'en')),
            max(0, (int)($options['vertical_margin'] ?? 2)),
            [],
            $options['color_scheme'] ?? $this->styleProfile['panel']
        );
    }

    public function renderPanel($conn, array &$state, string $title, array $lines, array $options = []): void
    {
        $box = new TerminalBoxRenderer($this->server);
        $box->renderBox(
            $conn,
            $state,
            $title,
            $lines === [] ? [''] : $lines,
            max(0, (int)($options['vertical_margin'] ?? 2)),
            $options['color_scheme'] ?? $this->styleProfile['panel'],
            max(0, (int)($options['footer_lines'] ?? 0))
        );
    }

    public function showScrollablePanel($conn, array &$state, string $title, array $lines, array $options = []): ?string
    {
        $redrawFn = isset($options['redraw_fn']) && is_callable($options['redraw_fn']) ? $options['redraw_fn'] : null;
        $extraKeys = is_array($options['extra_keys'] ?? null) ? $options['extra_keys'] : [];
        $initialOffset = max(0, (int)($options['initial_offset'] ?? 0));
        $scheme = is_array($options['color_scheme'] ?? null) ? $options['color_scheme'] : $this->styleProfile['scrollable_panel'];
        $statusSegments = is_array($options['status_segments'] ?? null) ? $options['status_segments'] : null;

        $data = [
            'title' => $title,
            'lines' => $lines,
            'status_line' => (string)($options['status_line'] ?? ''),
        ];

        $getChars = static function(BbsSession $server): array {
            if (method_exists($server, 'getTerminalLineDrawingChars')) {
                return $server->getTerminalLineDrawingChars();
            }
            return ['tl' => '+', 'tr' => '+', 'bl' => '+', 'br' => '+', 'h' => '-', 'v' => '|'];
        };

        $stripAnsi = static function(string $text): string {
            return (string)preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $text);
        };

        $padAnsi = static function(string $text, int $width) use ($stripAnsi): string {
            $plain = $stripAnsi($text);
            $plainWidth = mb_strlen($plain);
            if ($plainWidth >= $width) {
                return $text;
            }
            return $text . str_repeat(' ', $width - $plainWidth);
        };

        $render = function() use (&$data, $conn, &$state, $stripAnsi, $padAnsi, $scheme, $statusSegments): array {
            $rows = max(20, (int)($state['rows'] ?? 24));
            $cols = max(48, (int)($state['cols'] ?? 80));
            $boxWidth = max(46, min($cols - 6, 78));
            $contentWidth = max(24, $boxWidth - 4);
            $bodyHeight = max(8, min($rows - 8, 14));
            $panelHeight = $bodyHeight + 5;

            $chars = $this->server->getTerminalLineDrawingChars();
            $tl = $chars['tl'] ?? '+';
            $tr = $chars['tr'] ?? '+';
            $bl = $chars['bl'] ?? '+';
            $br = $chars['br'] ?? '+';
            $hz = $chars['h_bold'] ?? ($chars['h'] ?? '-');
            $vt = $chars['v'] ?? '|';
            $lTee = $chars['l_tee'] ?? '+';
            $rTee = $chars['r_tee'] ?? '+';

            $topBorder = $tl . str_repeat($hz, $boxWidth - 2) . $tr;
            $bottomBorder = $bl . str_repeat($hz, $boxWidth - 2) . $br;
            $frameColor = (string)($scheme['border'] ?? (TelnetUtils::ANSI_BLUE . TelnetUtils::ANSI_BOLD));
            $dividerColor = (string)($scheme['divider'] ?? TelnetUtils::ANSI_BLUE);
            $titleBarColor = (string)($scheme['title_bar'] ?? (TelnetUtils::ANSI_BG_BLUE . TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
            $bodyColor = (string)($scheme['body'] ?? (TelnetUtils::ANSI_BG_BLUE . "\033[37m"));
            $statusBarBg = (string)($scheme['status_bar_bg'] ?? TelnetUtils::ANSI_BG_WHITE);
            $statusBarFill = (string)($scheme['status_bar_fill'] ?? '');

            $bodyLines = array_map(static fn($line): string => (string)$line, $data['lines'] ?? []);
            $maxOffset = max(0, count($bodyLines) - $bodyHeight);
            $offset = min(max(0, (int)($data['offset'] ?? 0)), $maxOffset);
            $visibleLines = array_slice($bodyLines, $offset, $bodyHeight);

            $startRow = max(1, (int)round(($rows - $panelHeight) / 2));
            $startCol = max(1, (int)round(($cols - $boxWidth) / 2));
            $statusLine = '';
            if ($statusSegments !== null) {
                $used = 0;
                foreach ($statusSegments as $segment) {
                    if ($used >= $contentWidth) {
                        break;
                    }
                    $text = (string)($segment['text'] ?? '');
                    if ($text === '') {
                        continue;
                    }
                    $remaining = $contentWidth - $used;
                    $chunk = mb_strimwidth($text, 0, $remaining, '', 'UTF-8');
                    if ($chunk === '') {
                        continue;
                    }
                    $segmentColor = (string)($segment['color'] ?? '');
                    $statusLine .= $statusBarBg . ($segmentColor !== '' ? $segmentColor : '') . $chunk;
                    $used += mb_strwidth($chunk, 'UTF-8');
                }
                if ($used < $contentWidth) {
                    $statusLine .= $statusBarBg . str_repeat(' ', $contentWidth - $used);
                }
                $statusLine .= TelnetUtils::ANSI_RESET;
            } else {
                $statusLine = $padAnsi(str_replace(TelnetUtils::ANSI_RESET, $bodyColor, (string)$data['status_line']), $contentWidth);
            }

            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::safeWrite($conn, "\033[?25l");
            TelnetUtils::safeWrite($conn, "\033[" . $startRow . ';' . $startCol . 'H' . $frameColor . $topBorder . TelnetUtils::ANSI_RESET);

            $titleText = mb_substr($this->server->encodeForTerminal((string)$data['title']), 0, $contentWidth);
            $titleText = $titleText . str_repeat(' ', max(0, $contentWidth - mb_strlen($titleText)));
            $titleLineOut = $this->server->colorizeForTerminal($vt, $frameColor)
                . $this->server->colorizeForTerminal(' ' . $titleText . ' ', $titleBarColor)
                . $this->server->colorizeForTerminal($vt, $frameColor);
            TelnetUtils::safeWrite($conn, "\033[" . ($startRow + 1) . ';' . $startCol . 'H' . $titleLineOut . TelnetUtils::ANSI_RESET);

            $divider = $lTee . str_repeat($hz, $boxWidth - 2) . $rTee;
            TelnetUtils::safeWrite($conn, "\033[" . ($startRow + 2) . ';' . $startCol . 'H' . $this->server->colorizeForTerminal($this->server->encodeForTerminal($divider), $dividerColor) . TelnetUtils::ANSI_RESET);

            for ($i = 0; $i < $bodyHeight; $i++) {
                $line = $visibleLines[$i] ?? '';
                $line = str_replace(TelnetUtils::ANSI_RESET, $bodyColor, $line);
                $line = $padAnsi($line, $contentWidth);
                TelnetUtils::safeWrite(
                    $conn,
                    "\033[" . ($startRow + $i + 3) . ";{$startCol}H" . $frameColor . $vt . $bodyColor . ' ' . $line . ' ' . $frameColor . $vt . TelnetUtils::ANSI_RESET
                );
            }

            TelnetUtils::safeWrite(
                $conn,
                "\033[" . ($startRow + $bodyHeight + 3) . ';' . $startCol . 'H' . $frameColor . $vt . $bodyColor . ' ' . $statusLine . ' ' . $frameColor . $vt . TelnetUtils::ANSI_RESET
            );
            TelnetUtils::safeWrite(
                $conn,
                "\033[" . ($startRow + $bodyHeight + 4) . ';' . $startCol . 'H' . $frameColor . $bottomBorder . TelnetUtils::ANSI_RESET
            );
            TelnetUtils::safeWrite($conn, "\033[?25h");

            return [$offset, $maxOffset, $bodyHeight];
        };

        [$offset, $maxOffset, $bodyHeight] = $render();

        $lastRows = $state['rows'] ?? 24;
        $lastCols = $state['cols'] ?? 80;
        $extraKeys = array_change_key_case($extraKeys, CASE_LOWER);

        while (true) {
            $key = $this->server->readKeyWithIdleCheck($conn, $state);
            if ($key === null) {
                return 'quit';
            }

            $newRows = $state['rows'] ?? $lastRows;
            $newCols = $state['cols'] ?? $lastCols;
            if ($newRows !== $lastRows || $newCols !== $lastCols) {
                $lastRows = $newRows;
                $lastCols = $newCols;
                if ($redrawFn !== null) {
                    $rebuilt = (array)$redrawFn($state);
                    if (isset($rebuilt['title'])) {
                        $data['title'] = (string)$rebuilt['title'];
                    }
                    if (isset($rebuilt['lines']) && is_array($rebuilt['lines'])) {
                        $data['lines'] = $rebuilt['lines'];
                    }
                    if (array_key_exists('status_line', $rebuilt)) {
                        $data['status_line'] = (string)$rebuilt['status_line'];
                    }
                    if (array_key_exists('offset', $rebuilt)) {
                        $data['offset'] = (int)$rebuilt['offset'];
                    }
                }
                [$offset, $maxOffset, $bodyHeight] = $render();
                continue;
            }

            if ($key === 'UP') {
                $offset = max(0, $offset - 1);
                $data['offset'] = $offset;
                $render();
                continue;
            }
            if ($key === 'DOWN') {
                $offset = min($maxOffset, $offset + 1);
                $data['offset'] = $offset;
                $render();
                continue;
            }
            if ($key === 'PGUP') {
                $offset = max(0, $offset - max(1, $bodyHeight));
                $data['offset'] = $offset;
                $render();
                continue;
            }
            if ($key === 'PGDOWN') {
                $offset = min($maxOffset, $offset + max(1, $bodyHeight));
                $data['offset'] = $offset;
                $render();
                continue;
            }
            if ($key === 'HOME') {
                $offset = 0;
                $data['offset'] = $offset;
                $render();
                continue;
            }
            if ($key === 'END') {
                $offset = $maxOffset;
                $data['offset'] = $offset;
                $render();
                continue;
            }

            if ($key === 'ENTER' || $key === 'ESC' || $key === 'CHAR:q' || $key === 'CHAR:Q' || $key === 'CHAR:b' || $key === 'CHAR:B') {
                return 'quit';
            }

            if (str_starts_with($key, 'CHAR:')) {
                $char = strtolower(substr($key, 5));
                if (isset($extraKeys[$char])) {
                    return (string)$extraKeys[$char];
                }
            }
        }
    }

    public function showMessageViewer(
        $conn,
        array &$state,
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
        return TelnetUtils::runMessageViewer(
            $conn,
            $state,
            $this->server,
            $headerLines,
            $wrappedLines,
            $statusLine,
            $rows,
            $initialOffset,
            $allowDownloadAction,
            $kludgeLines,
            $rebuildFn,
            $imageRefs,
            $imageFn,
            $extraKeys,
            $helpItems,
            array_replace(
                ['help_overlay' => $this->styleProfile['help_overlay']],
                $options
            )
        );
    }

    public function showMessageList(
        $conn,
        array &$state,
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
        $options = array_replace(
            ['help_overlay' => $this->styleProfile['help_overlay']],
            $options
        );
        return TelnetUtils::runMessageList(
            $conn,
            $state,
            $this->server,
            $title,
            $messages,
            $page,
            $totalPages,
            $selectedIndex,
            $extraKeys,
            $extraStatusSegments,
            $options,
            $helpItems
        );
    }

    public function showSelectableList(
        $conn,
        array &$state,
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
        $options = array_replace(
            ['help_overlay' => $this->styleProfile['help_overlay']],
            $options
        );
        return TelnetUtils::runSelectableList(
            $conn,
            $state,
            $this->server,
            $title,
            $rows,
            $page,
            $totalPages,
            $selectedIndex,
            $statusBar,
            $extraKeys,
            $rebuildFn,
            $options,
            $helpItems
        );
    }

    public function showAlert($conn, array &$state, string $title, string $message, string $style = 'info'): void
    {
        TelnetUtils::showAlertDialog($conn, $state, $this->server, $title, $message, $style, $this->styleProfile['alert'][$style] ?? $this->styleProfile['dialog']);
    }

    public function showConfirmDialog($conn, array &$state, string $title, string $message, array $choices = ['y' => 'Confirm', 'n' => 'Cancel'], string $default = 'n', array $options = []): string
    {
        return TelnetUtils::showConfirmDialog(
            $conn,
            $state,
            $this->server,
            $title,
            $message,
            $choices,
            $default,
            isset($options['redraw_fn']) && is_callable($options['redraw_fn']) ? $options['redraw_fn'] : null,
            $this->styleProfile['dialog']
        );
    }

    public function showWorkingOverlay($conn, array &$state, string $message, array $options = []): void
    {
        TelnetUtils::showWorkingOverlay(
            $conn,
            $state,
            $this->server,
            $message,
            $this->styleProfile['working_overlay']
        );
    }

    public function showCheckboxListDialog(
        $conn,
        array &$state,
        callable $titleFn,
        array $items,
        array $selectedIndices = [],
        int $maxSelect = 0,
        string $atLimitMessage = '',
        string $hintConfirm = 'Done',
        string $hintSkip = 'Skip',
        ?callable $redrawFn = null,
        array $options = []
    ): ?array {
        return TelnetUtils::showCheckboxListDialog(
            $conn,
            $state,
            $this->server,
            $titleFn,
            $items,
            $selectedIndices,
            $maxSelect,
            $atLimitMessage,
            $hintConfirm,
            $hintSkip,
            $redrawFn,
            $this->styleProfile['checkbox_dialog']
        );
    }

    public function showSelectableDialog($conn, array &$state, string $title, array $items, string $hintSelect = 'Select', string $hintBack = 'Back', int $selectedIndex = 0, ?callable $redrawFn = null, array $options = []): ?array
    {
        return TelnetUtils::showSelectableDialog(
            $conn,
            $state,
            $this->server,
            $title,
            $items,
            $hintSelect,
            $hintBack,
            $selectedIndex,
            $redrawFn,
            $this->styleProfile['selectable_dialog']
        );
    }

    public function showAddressPicker($conn, array &$state, string $apiBase, string $session): ?array
    {
        return TelnetUtils::runAddressPicker($conn, $state, $this->server, $apiBase, $session, $state['locale'] ?? 'en');
    }

    public function showPublicProfileViewer($conn, array &$state, array $profile, array $options = []): void
    {
        TelnetUtils::showPublicProfileViewer(
            $conn,
            $state,
            $this->server,
            $profile,
            $this->styleProfile['profile_viewer']
        );
    }

    public function showPagedBox($conn, array &$state, string $title, array $lines, string $continuePrompt, int $verticalMargin = 2, array $stopKeys = [], array $options = []): ?string
    {
        $box = new TerminalBoxRenderer($this->server);
        return $box->showPagedBox(
            $conn,
            $state,
            $title,
            $lines,
            $continuePrompt,
            $verticalMargin,
            $stopKeys,
            $options['color_scheme'] ?? $this->styleProfile['panel']
        );
    }
}
