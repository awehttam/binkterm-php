<?php

namespace BinktermPHP\TelnetServer;

/**
 * Plain prompt-driven shell implementation.
 */
class LineShell implements TerminalShellInterface
{
    private BbsSession $server;

    public function __construct(BbsSession $server)
    {
        $this->server = $server;
    }

    public function chooseFromList($conn, array &$state, string $title, array $items, array $options = []): ?int
    {
        if ($items === []) {
            $this->showText(
                $conn,
                $state,
                $title,
                [(string)($options['empty_message'] ?? 'Nothing to show.')]
            );
            return null;
        }

        $locale = $state['locale'] ?? 'en';
        $prompt = (string)($options['prompt'] ?? $this->server->t('ui.terminalserver.server.select_prompt', 'Enter selection # or Q to return: ', [], $locale));

        while (true) {
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($title, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
            TelnetUtils::writeLine($conn, '');
            foreach ($items as $index => $item) {
                if (is_array($item)) {
                    $label = (string)($item['label'] ?? $item['title'] ?? $item['name'] ?? $item['text'] ?? '');
                    $detail = trim((string)($item['detail'] ?? $item['description'] ?? $item['desc'] ?? ''));
                    TelnetUtils::writeLine($conn, sprintf(' %2d) %s', $index + 1, $label));
                    if ($detail !== '') {
                        foreach (TelnetUtils::wrapTextLines($detail, max(20, (int)($state['cols'] ?? 80) - 6)) as $line) {
                            TelnetUtils::writeLine($conn, '    ' . $line);
                        }
                    }
                    continue;
                }
                TelnetUtils::writeLine($conn, sprintf(' %2d) %s', $index + 1, $item));
            }
            TelnetUtils::writeLine($conn, '');

            $choice = $this->server->prompt($conn, $state, TelnetUtils::colorize($prompt, TelnetUtils::ANSI_CYAN), true);
            if ($choice === null) {
                return null;
            }

            $choice = trim($choice);
            if ($choice === '' || strtolower($choice) === 'q') {
                return null;
            }

            $selectedIndex = (int)$choice - 1;
            if (isset($items[$selectedIndex])) {
                return $selectedIndex;
            }
        }
    }

    public function promptText($conn, array &$state, string $title, string $prompt, array $options = []): ?string
    {
        $locale = $state['locale'] ?? 'en';
        TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($title, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
        TelnetUtils::writeLine($conn, '');

        $label = $prompt !== ''
            ? $prompt
            : $this->server->t('ui.terminalserver.server.input_prompt', 'Input: ', [], $locale);

        return $this->server->prompt(
            $conn,
            $state,
            TelnetUtils::colorize($label, TelnetUtils::ANSI_CYAN),
            true
        );
    }

    public function promptKey($conn, array &$state, string $title, string $prompt, array $allowedKeys, array $options = []): ?string
    {
        while (true) {
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($title, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, $prompt);
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, 'Press one of: ' . implode(' / ', array_map('strtoupper', $allowedKeys)));
            TelnetUtils::writeLine($conn, '');

            $choice = $this->server->readRawChar($conn, $state);
            if ($choice === null) {
                return null;
            }

            $choice = strtolower(trim($choice));
            if ($choice === '') {
                continue;
            }

            if (in_array($choice, $allowedKeys, true)) {
                return $choice;
            }
        }
    }

    public function showText($conn, array &$state, string $title, array $lines, array $options = []): void
    {
        $locale = $state['locale'] ?? 'en';
        TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($title, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
        TelnetUtils::writeLine($conn, '');
        foreach ($lines as $line) {
            TelnetUtils::writeWrapped($conn, $line, max(40, (int)($state['cols'] ?? 80) - 2));
        }
        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine(
            $conn,
            TelnetUtils::colorize(
                (string)($options['continue_prompt'] ?? $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale)),
                TelnetUtils::ANSI_YELLOW
            )
        );
        $this->server->readKeyWithIdleCheck($conn, $state);
    }

    public function renderPanel($conn, array &$state, string $title, array $lines, array $options = []): void
    {
        $locale = $state['locale'] ?? 'en';
        TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($title, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
        TelnetUtils::writeLine($conn, '');
        foreach ($lines as $line) {
            TelnetUtils::writeWrapped($conn, $line, max(40, (int)($state['cols'] ?? 80) - 2));
        }
        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine(
            $conn,
            TelnetUtils::colorize(
                (string)($options['continue_prompt'] ?? $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale)),
                TelnetUtils::ANSI_YELLOW
            )
        );
    }

    public function showScrollablePanel($conn, array &$state, string $title, array $lines, array $options = []): ?string
    {
        $extraKeys = is_array($options['extra_keys'] ?? null) ? array_change_key_case($options['extra_keys'], CASE_LOWER) : [];
        $redrawFn = isset($options['redraw_fn']) && is_callable($options['redraw_fn']) ? $options['redraw_fn'] : null;
        $data = [
            'title' => $title,
            'lines' => $lines,
            'status_line' => (string)($options['status_line'] ?? ''),
            'offset' => max(0, (int)($options['initial_offset'] ?? 0)),
        ];

        $render = function() use (&$data, $conn, &$state): array {
            $cols = max(40, (int)($state['cols'] ?? 80));
            $rows = max(20, (int)($state['rows'] ?? 24));
            $bodyHeight = max(6, $rows - 6);
            $lines = array_map(static fn($line): string => (string)$line, $data['lines'] ?? []);
            $maxOffset = max(0, count($lines) - $bodyHeight);
            $offset = min(max(0, (int)($data['offset'] ?? 0)), $maxOffset);

            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize((string)$data['title'], TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
            TelnetUtils::writeLine($conn, '');

            foreach (array_slice($lines, $offset, $bodyHeight) as $line) {
                TelnetUtils::writeWrapped($conn, $line, max(20, $cols - 2));
            }

            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, (string)$data['status_line']);

            return [$offset, $maxOffset, $bodyHeight];
        };

        [$offset, $maxOffset, $bodyHeight] = $render();
        $lastRows = $state['rows'] ?? 24;
        $lastCols = $state['cols'] ?? 80;

        while (true) {
            $key = $this->server->readRawChar($conn, $state);
            if ($key === null) {
                return 'quit';
            }

            $newRows = $state['rows'] ?? $lastRows;
            $newCols = $state['cols'] ?? $lastCols;
            if (($newRows !== $lastRows || $newCols !== $lastCols) && $redrawFn !== null) {
                $lastRows = $newRows;
                $lastCols = $newCols;
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
                [$offset, $maxOffset, $bodyHeight] = $render();
                continue;
            }

            $choice = strtolower(trim($key));
            if ($choice === '' || $choice === 'q' || $choice === 'b') {
                return 'quit';
            }
            if ($choice === 'up' || $choice === 'u') {
                $data['offset'] = max(0, $offset - 1);
                [$offset, $maxOffset, $bodyHeight] = $render();
                continue;
            }
            if ($choice === 'down' || $choice === 'd') {
                $data['offset'] = min($maxOffset, $offset + 1);
                [$offset, $maxOffset, $bodyHeight] = $render();
                continue;
            }
            if ($choice === 'pgup') {
                $data['offset'] = max(0, $offset - max(1, $bodyHeight));
                [$offset, $maxOffset, $bodyHeight] = $render();
                continue;
            }
            if ($choice === 'pgdown') {
                $data['offset'] = min($maxOffset, $offset + max(1, $bodyHeight));
                [$offset, $maxOffset, $bodyHeight] = $render();
                continue;
            }
            if ($choice === 'home') {
                $data['offset'] = 0;
                [$offset, $maxOffset, $bodyHeight] = $render();
                continue;
            }
            if ($choice === 'end') {
                $data['offset'] = $maxOffset;
                [$offset, $maxOffset, $bodyHeight] = $render();
                continue;
            }

            if (isset($extraKeys[$choice])) {
                return (string)$extraKeys[$choice];
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
            $options
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
        $color = $style === 'error' ? TelnetUtils::ANSI_RED : TelnetUtils::ANSI_GREEN;
        $this->showText($conn, $state, $title, [TelnetUtils::colorize($message, $color)]);
    }

    public function showConfirmDialog($conn, array &$state, string $title, string $message, array $choices = ['y' => 'Confirm', 'n' => 'Cancel'], string $default = 'n', array $options = []): string
    {
        return TelnetUtils::showConfirmDialog($conn, $state, $this->server, $title, $message, $choices, $default, isset($options['redraw_fn']) && is_callable($options['redraw_fn']) ? $options['redraw_fn'] : null);
    }

    public function showWorkingOverlay($conn, array &$state, string $message, array $options = []): void
    {
        TelnetUtils::showWorkingOverlay($conn, $state, $this->server, $message);
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
        return TelnetUtils::showCheckboxListDialog($conn, $state, $this->server, $titleFn, $items, $selectedIndices, $maxSelect, $atLimitMessage, $hintConfirm, $hintSkip, $redrawFn);
    }

    public function showSelectableDialog($conn, array &$state, string $title, array $items, string $hintSelect = 'Select', string $hintBack = 'Back', int $selectedIndex = 0, ?callable $redrawFn = null, array $options = []): ?array
    {
        return TelnetUtils::showSelectableDialog($conn, $state, $this->server, $title, $items, $hintSelect, $hintBack, $selectedIndex, $redrawFn);
    }

    public function showAddressPicker($conn, array &$state, string $apiBase, string $session): ?array
    {
        return TelnetUtils::runAddressPicker($conn, $state, $this->server, $apiBase, $session, $state['locale'] ?? 'en');
    }

    public function showPublicProfileViewer($conn, array &$state, array $profile, array $options = []): void
    {
        TelnetUtils::showPublicProfileViewer($conn, $state, $this->server, $profile);
    }

    public function showPagedBox($conn, array &$state, string $title, array $lines, string $continuePrompt, int $verticalMargin = 2, array $stopKeys = [], array $options = []): ?string
    {
        $box = new TerminalBoxRenderer($this->server);
        return $box->showPagedBox($conn, $state, $title, $lines, $continuePrompt, $verticalMargin, $stopKeys, $options['color_scheme'] ?? TerminalBoxRenderer::SCHEME_DEFAULT);
    }
}
