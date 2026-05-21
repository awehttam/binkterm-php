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

    private function clearAndTitle($conn, string $title): void
    {
        TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($title, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
        TelnetUtils::writeLine($conn, '');
    }

    private function wrapWidth(array $state, int $padding = 2, int $minimum = 20): int
    {
        return max($minimum, (int)($state['cols'] ?? 80) - $padding);
    }

    private function stripAnsi(string $text): string
    {
        return (string)preg_replace('/\033\[[0-9;?]*[ -\/]*[@-~]/', '', $text);
    }

    private function normalizeListItem($item): array
    {
        if (is_array($item)) {
            return [
                'label' => (string)($item['label'] ?? $item['title'] ?? $item['name'] ?? $item['text'] ?? ''),
                'detail' => trim((string)($item['detail'] ?? $item['description'] ?? $item['desc'] ?? '')),
            ];
        }

        return [
            'label' => $this->stripAnsi((string)$item),
            'detail' => '',
        ];
    }

    private function renderListPage($conn, array &$state, string $title, array $items, int $page, int $perPage, ?int $selectedIndex = null, ?callable $preambleFn = null, bool $showNumbers = true): void
    {
        if ($preambleFn !== null && $preambleFn() !== false) {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($title, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
            TelnetUtils::writeLine($conn, '');
        } else {
            $this->clearAndTitle($conn, $title);
        }
        $totalItems = count($items);
        $totalPages = max(1, (int)ceil($totalItems / max(1, $perPage)));
        $start = ($page - 1) * $perPage;
        $slice = array_slice($items, $start, $perPage);
        $wrapWidth = $this->wrapWidth($state, 6);

        TelnetUtils::writeLine($conn, sprintf('Page %d/%d', $page, $totalPages));
        TelnetUtils::writeLine($conn, '');

        foreach ($slice as $offset => $item) {
            $displayIndex = $offset + 1;
            $itemData = $this->normalizeListItem($item);
            $prefix = $selectedIndex !== null && ($start + $offset) === $selectedIndex ? '>' : ' ';
            $rowText = $showNumbers
                ? sprintf('%s%2d) %s', $prefix, $displayIndex, $itemData['label'])
                : sprintf('%s%s', $prefix, $itemData['label']);
            TelnetUtils::writeLine($conn, $this->fitPlainLine($rowText, $this->wrapWidth($state, 1, 10)));
            if ($itemData['detail'] !== '') {
                foreach (TelnetUtils::wrapTextLines($itemData['detail'], $wrapWidth) as $line) {
                    TelnetUtils::writeLine($conn, '    ' . $line);
                }
            }
        }

        TelnetUtils::writeLine($conn, '');
    }

    private function renderCurrentPageRows($conn, array &$state, string $title, array $rows, int $page, int $totalPages, ?int $selectedIndex = null, array $markedRows = []): void
    {
        $this->clearAndTitle($conn, $title);
        TelnetUtils::writeLine($conn, sprintf('Page %d/%d', $page, max(1, $totalPages)));
        TelnetUtils::writeLine($conn, '');

        foreach ($rows as $offset => $row) {
            $isMarked = isset($markedRows[$offset]);
            $mark = $isMarked ? '*' : ' ';
            $rowText = sprintf('%s%2d) %s', $mark, $offset + 1, $this->stripAnsi((string)$row));
            TelnetUtils::writeLine($conn, $this->fitPlainLine($rowText, $this->wrapWidth($state, 1, 10)));
        }

        TelnetUtils::writeLine($conn, '');
    }

    private function buildChoicePrompt(array $choices, string $default): string
    {
        $parts = [];
        foreach ($choices as $key => $label) {
            $parts[] = strtoupper((string)$key) . ') ' . $label;
        }
        return implode('  ', $parts) . ' [' . strtoupper($default) . ']: ';
    }

    private function buildPlainStatusLine(array $segments): string
    {
        $parts = [];
        foreach ($segments as $segment) {
            $text = $this->stripAnsi((string)($segment['text'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return trim(implode('', $parts));
    }

    private function fitPlainLine(string $text, int $width): string
    {
        return mb_strimwidth($text, 0, max(1, $width), '', 'UTF-8');
    }

    private function simplifyHeaderLinesForLineMode(array $headerLines): array
    {
        $result = [];

        foreach ($headerLines as $line) {
            $plain = $this->stripAnsi((string)$line);
            $plain = trim($plain);
            if ($plain === '') {
                continue;
            }

            // Drop framed box borders/dividers from TUI header widgets.
            if (preg_match('/^[\+\-\|=#\s]+$/', $plain)) {
                continue;
            }
            if (preg_match('/^[\x{2500}-\x{257F}\x{2580}-\x{259F}\s]+$/u', $plain)) {
                continue;
            }

            // Remove any remaining leading/trailing frame characters.
            $plain = preg_replace('/^[\s\|\+\#\x{2500}-\x{257F}\x{2580}-\x{259F}]+/u', '', $plain) ?? $plain;
            $plain = preg_replace('/[\s\|\+\#\x{2500}-\x{257F}\x{2580}-\x{259F}]+$/u', '', $plain) ?? $plain;
            $plain = trim($plain);
            if ($plain === '') {
                continue;
            }

            if (str_contains($plain, ':')) {
                $result[] = $plain;
            }
        }

        return $result;
    }

    private function flushImmediateLineTerminators($conn, array &$state): void
    {
        while (true) {
            $hasImmediateInput = !empty($state['pushback'] ?? '');
            if (!$hasImmediateInput && is_resource($conn)) {
                $read = [$conn];
                $write = $except = null;
                $hasImmediateInput = @stream_select($read, $write, $except, 0, 0) > 0;
            }

            if (!$hasImmediateInput) {
                return;
            }

            $char = $this->server->readRawChar($conn, $state);
            if ($char === null) {
                return;
            }

            if ($char === "\x00" || $char === "\r" || $char === "\n") {
                continue;
            }

            $state['pushback'] = $char . ($state['pushback'] ?? '');
            return;
        }
    }

    private function readPromptLine($conn, array &$state, string $prompt, bool $echo = true, ?callable $redrawFn = null): ?string
    {
        $buffer = '';
        $lastRows = (int)($state['rows'] ?? 24);
        $lastCols = (int)($state['cols'] ?? 80);

        $renderInput = function () use ($conn, &$state, $prompt, $echo, &$buffer, $redrawFn): void {
            if ($redrawFn !== null) {
                $redrawFn($state);
            }
            if ($prompt !== '') {
                TelnetUtils::safeWrite($conn, TelnetUtils::colorize($prompt, TelnetUtils::ANSI_CYAN));
            }
            TelnetUtils::safeWrite($conn, $echo ? $buffer : str_repeat('*', strlen($buffer)));
        };

        $renderInput();

        while (true) {
            $char = $this->server->readRawChar($conn, $state);
            if ($char === null) {
                return null;
            }

            $newRows = (int)($state['rows'] ?? $lastRows);
            $newCols = (int)($state['cols'] ?? $lastCols);
            if ($newRows !== $lastRows || $newCols !== $lastCols) {
                $lastRows = $newRows;
                $lastCols = $newCols;
                $renderInput();
                continue;
            }

            if ($char === "\x00") {
                continue;
            }

            $byte = ord($char[0]);
            if ($byte === 3) {
                TelnetUtils::safeWrite($conn, "^C\r\n");
                return null;
            }
            if ($byte === 10 || $byte === 13) {
                TelnetUtils::safeWrite($conn, "\r\n");
                return $buffer;
            }
            if ($byte === 8 || $byte === 127) {
                if ($buffer !== '') {
                    $buffer = substr($buffer, 0, -1);
                    if ($echo) {
                        TelnetUtils::safeWrite($conn, "\x08 \x08");
                    } else {
                        $renderInput();
                    }
                }
                continue;
            }
            if (strlen($char) > 1 || $byte === 27 || $byte < 32 || $byte > 126) {
                continue;
            }

            $buffer .= chr($byte);
            TelnetUtils::safeWrite($conn, $echo ? chr($byte) : '*');
        }
    }

    private function promptCommand($conn, array &$state, string $prompt, ?callable $redrawFn = null): ?string
    {
        $this->flushImmediateLineTerminators($conn, $state);
        $choice = $this->readPromptLine($conn, $state, $prompt, true, $redrawFn);
        return $choice === null ? null : trim($choice);
    }

    private function readCommandKey($conn, array &$state, string $prompt, ?callable $redrawFn = null): ?string
    {
        $this->flushImmediateLineTerminators($conn, $state);

        if ($redrawFn !== null) {
            $redrawFn($state);
        }
        if ($prompt !== '') {
            TelnetUtils::safeWrite($conn, TelnetUtils::colorize($prompt, TelnetUtils::ANSI_CYAN));
        }

        $lastRows = (int)($state['rows'] ?? 24);
        $lastCols = (int)($state['cols'] ?? 80);

        while (true) {
            $key = $this->server->readKeyWithIdleCheck($conn, $state);
            if ($key === null) {
                return null;
            }

            $newRows = (int)($state['rows'] ?? $lastRows);
            $newCols = (int)($state['cols'] ?? $lastCols);
            if ($newRows !== $lastRows || $newCols !== $lastCols) {
                $lastRows = $newRows;
                $lastCols = $newCols;
                if ($redrawFn !== null) {
                    $redrawFn($state);
                }
                if ($prompt !== '') {
                    TelnetUtils::safeWrite($conn, TelnetUtils::colorize($prompt, TelnetUtils::ANSI_CYAN));
                }
                continue;
            }

            if ($key === '' || $key === 'ENTER') {
                continue;
            }

            if (str_starts_with($key, 'CHAR:')) {
                return strtolower(substr($key, 5));
            }

            return strtoupper($key);
        }
    }

    private function renderDivider($conn, array &$state): void
    {
        $width = min(78, $this->wrapWidth($state, 0));
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(str_repeat('-', $width), TelnetUtils::ANSI_DIM));
    }

    private function renderWrappedBlock($conn, array &$state, array $lines, int $padding = 2): void
    {
        foreach ($lines as $line) {
            TelnetUtils::writeWrapped($conn, $this->stripAnsi((string)$line), $this->wrapWidth($state, $padding));
        }
    }

    private function renderFooterLines($conn, array $footerLines): void
    {
        foreach ($footerLines as $line) {
            if ($line === '') {
                TelnetUtils::writeLine($conn, '');
                continue;
            }
            TelnetUtils::writeLine($conn, (string)$line);
        }
    }

    private function normalizeCommandMap(array $commands): array
    {
        $normalized = [];
        foreach ($commands as $key => $action) {
            $normalized[strtolower((string)$key)] = $action;
        }
        return $normalized;
    }

    private function visibleListPageSize(array $state, int $reservedRows = 8, int $minimum = 5, int $maximum = 25): int
    {
        return max(1, min(max($minimum, (int)($state['rows'] ?? 24) - $reservedRows), $maximum));
    }

    private function renderSelectableListScreen($conn, array &$state, string $title, array $rows, int $page, int $totalPages, ?int $selectedIndex, array $commandLines, array $markedRows = []): void
    {
        $this->renderCurrentPageRows($conn, $state, $this->stripAnsi($title), $rows, $page, $totalPages, $selectedIndex, $markedRows);
        $this->renderFooterLines($conn, $commandLines);
    }

    private function renderScrollablePanelScreen($conn, array &$state, array $panel, array $commandLines): array
    {
        $cols = max(40, (int)($state['cols'] ?? 80));
        $rows = max(20, (int)($state['rows'] ?? 24));
        $bodyHeight = max(6, $rows - 8);
        $lines = array_map(static fn($line): string => (string)$line, $panel['lines'] ?? []);
        $maxOffset = max(0, count($lines) - $bodyHeight);
        $offset = min(max(0, (int)($panel['offset'] ?? 0)), $maxOffset);

        $this->clearAndTitle($conn, (string)($panel['title'] ?? ''));
        foreach (array_slice($lines, $offset, $bodyHeight) as $line) {
            TelnetUtils::writeWrapped($conn, $this->stripAnsi((string)$line), max(20, $cols - 2));
        }

        TelnetUtils::writeLine($conn, '');
        if ((string)($panel['status_line'] ?? '') !== '') {
            TelnetUtils::writeLine($conn, (string)$panel['status_line']);
        }
        $this->renderFooterLines($conn, $commandLines);

        return [$offset, $maxOffset, $bodyHeight];
    }

    private function renderMessageViewerScreen(
        $conn,
        array &$state,
        array $headerLines,
        array $wrappedLines,
        int $offset,
        int $bodyHeight,
        array $commandLines
    ): void {
        $simpleHeaderLines = $this->simplifyHeaderLinesForLineMode($headerLines);
        $this->clearAndTitle($conn, $this->server->t('ui.terminalserver.message.viewer_title', 'Message', [], $state['locale'] ?? 'en'));
        $this->renderWrappedBlock($conn, $state, $simpleHeaderLines);
        $this->renderDivider($conn, $state);
        foreach (array_slice($wrappedLines, $offset, $bodyHeight) as $line) {
            TelnetUtils::writeWrapped($conn, $this->stripAnsi((string)$line), $this->wrapWidth($state));
        }
        $this->renderDivider($conn, $state);
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            sprintf('Lines %d-%d of %d', $offset + 1, min(count($wrappedLines), $offset + $bodyHeight), count($wrappedLines)),
            TelnetUtils::ANSI_DIM
        ));
        $this->renderFooterLines($conn, $commandLines);
    }

    private function renderPagedBoxScreen(
        $conn,
        array &$state,
        string $title,
        array $pages,
        int $pageIndex,
        string $continuePrompt,
        array $commandLines
    ): void {
        $pageCount = max(1, count($pages));
        $this->clearAndTitle($conn, $pageCount > 1 ? sprintf('%s (%d/%d)', $title, $pageIndex + 1, $pageCount) : $title);
        foreach ($pages[$pageIndex] ?? [''] as $line) {
            TelnetUtils::writeWrapped($conn, $this->stripAnsi((string)$line), $this->wrapWidth($state));
        }
        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, $continuePrompt);
        $this->renderFooterLines($conn, $commandLines);
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
        $preambleFn = isset($options['preamble_fn']) && is_callable($options['preamble_fn']) ? $options['preamble_fn'] : null;
        $showNumbers = (bool)($options['show_numbers'] ?? true);
        $keyToIndex = [];
        if (is_array($options['key_to_index'] ?? null)) {
            foreach ($options['key_to_index'] as $key => $index) {
                $keyToIndex[strtolower((string)$key)] = (int)$index;
            }
        }
        $quitKeys = array_map(
            static fn($key): string => strtolower((string)$key),
            is_array($options['quit_keys'] ?? null) ? $options['quit_keys'] : ['q']
        );
        $initialPerPage = $this->visibleListPageSize($state);
        $initialSelectedIndex = max(0, (int)($options['selected_index'] ?? 0));
        $page = min(
            max(1, (int)floor($initialSelectedIndex / max(1, $initialPerPage)) + 1),
            max(1, (int)ceil(count($items) / max(1, $initialPerPage)))
        );
        $perPage = $initialPerPage;
        $totalPages = max(1, (int)ceil(count($items) / max(1, $perPage)));
        $commandHelp = [];

        $renderPage = function () use ($conn, &$state, $title, $items, $preambleFn, $showNumbers, &$perPage, &$totalPages, &$page, &$commandHelp): void {
            $perPage = $this->visibleListPageSize($state);
            $totalPages = max(1, (int)ceil(count($items) / $perPage));
            $page = min(max(1, $page), $totalPages);
            $this->renderListPage($conn, $state, $title, $items, $page, $perPage, null, $preambleFn, $showNumbers);
            $commandHelp = $showNumbers
                ? ['Commands: number = select, N = next page, P = previous page, Q = back']
                : ['Commands: configured key = select, N = next page, P = previous page, Q = back'];
            $this->renderFooterLines($conn, $commandHelp);
        };

        while (true) {
            $renderPage();

            if (!$showNumbers && $keyToIndex !== []) {
                $choice = $this->readCommandKey($conn, $state, $prompt, function () use (&$renderPage): void {
                    $renderPage();
                });
                if ($choice === null) {
                    return null;
                }
                if (in_array($choice, $quitKeys, true)) {
                    return null;
                }
                if (isset($keyToIndex[$choice]) && isset($items[$keyToIndex[$choice]])) {
                    return $keyToIndex[$choice];
                }
                if ($choice === 'n') {
                    $page = min($totalPages, $page + 1);
                } elseif ($choice === 'p') {
                    $page = max(1, $page - 1);
                }
                continue;
            }

            $choice = $this->promptCommand($conn, $state, $prompt, function () use (&$renderPage): void {
                $renderPage();
            });
            if ($choice === null) {
                return null;
            }

            if ($choice === '') {
                continue;
            }

            $lowerChoice = strtolower($choice);
            if (in_array($lowerChoice, $quitKeys, true)) {
                return null;
            }
            if (isset($keyToIndex[$lowerChoice]) && isset($items[$keyToIndex[$lowerChoice]])) {
                return $keyToIndex[$lowerChoice];
            }
            if (strcasecmp($choice, 'n') === 0) {
                $page = min($totalPages, $page + 1);
                continue;
            }
            if (strcasecmp($choice, 'p') === 0) {
                $page = max(1, $page - 1);
                continue;
            }

            $selectedIndex = (int)$choice - 1;
            $pageStart = ($page - 1) * $perPage;
            $pageEnd = min(count($items) - 1, $pageStart + $perPage - 1);
            if ($selectedIndex >= 0 && ($pageStart + $selectedIndex) <= $pageEnd) {
                return $pageStart + $selectedIndex;
            }
        }
    }

    public function promptText($conn, array &$state, string $title, string $prompt, array $options = []): ?string
    {
        $locale = $state['locale'] ?? 'en';
        $label = $prompt !== ''
            ? $prompt
            : $this->server->t('ui.terminalserver.server.input_prompt', 'Input: ', [], $locale);

        $render = function () use ($conn, $title): void {
            $this->clearAndTitle($conn, $title);
        };

        $this->flushImmediateLineTerminators($conn, $state);
        return $this->readPromptLine($conn, $state, $label, true, $render);
    }

    public function promptKey($conn, array &$state, string $title, string $prompt, array $allowedKeys, array $options = []): ?string
    {
        $allowed = array_map(static fn($key): string => strtolower((string)$key), $allowedKeys);
        $render = function () use ($conn, $title, $prompt, $allowedKeys): void {
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($title, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, $prompt);
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, 'Press one of: ' . implode(' / ', array_map('strtoupper', $allowedKeys)));
            TelnetUtils::writeLine($conn, '');
        };

        while (true) {
            $choice = $this->readCommandKey($conn, $state, '', function () use ($render): void {
                $render();
            });
            if ($choice === null) {
                return null;
            }

            $choice = strtolower(trim($choice));
            if (in_array($choice, $allowed, true)) {
                return $choice;
            }
        }
    }

    public function showText($conn, array &$state, string $title, array $lines, array $options = []): void
    {
        $locale = $state['locale'] ?? 'en';
        $render = function () use ($conn, &$state, $title, $lines, $options, $locale): void {
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
        };

        $render();
        $this->flushImmediateLineTerminators($conn, $state);
        $lastRows = (int)($state['rows'] ?? 24);
        $lastCols = (int)($state['cols'] ?? 80);
        while (true) {
            $key = $this->server->readKeyWithIdleCheck($conn, $state);
            $newRows = (int)($state['rows'] ?? $lastRows);
            $newCols = (int)($state['cols'] ?? $lastCols);
            if ($newRows !== $lastRows || $newCols !== $lastCols) {
                $lastRows = $newRows;
                $lastCols = $newCols;
                $render();
                continue;
            }
            if ($key === null || $key !== '') {
                break;
            }
        }
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
        $extraKeys = $this->normalizeCommandMap(is_array($options['extra_keys'] ?? null) ? $options['extra_keys'] : []);
        $redrawFn = isset($options['redraw_fn']) && is_callable($options['redraw_fn']) ? $options['redraw_fn'] : null;
        $statusSegments = is_array($options['status_segments'] ?? null) ? $options['status_segments'] : [];
        $data = [
            'title' => $title,
            'lines' => $lines,
            'status_line' => (string)($options['status_line'] ?? $this->buildPlainStatusLine($statusSegments)),
            'offset' => max(0, (int)($options['initial_offset'] ?? 0)),
        ];
        $commandLines = ['Command: U/D scroll, N/P page, Q back'];
        $offset = 0;
        $maxOffset = 0;
        $bodyHeight = 0;
        $renderPanel = function () use ($conn, &$state, &$data, $commandLines, $redrawFn, &$offset, &$maxOffset, &$bodyHeight): void {
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
            [$offset, $maxOffset, $bodyHeight] = $this->renderScrollablePanelScreen($conn, $state, $data, $commandLines);
        };

        while (true) {
            $renderPanel();
            $key = $this->promptCommand($conn, $state, 'Command > ', function () use (&$renderPanel): void {
                $renderPanel();
            });
            if ($key === null) {
                return 'quit';
            }

            $choice = strtolower(trim($key));
            if ($choice === '' || $choice === 'q' || $choice === 'b') {
                return 'quit';
            }
            if ($choice === 'up' || $choice === 'u') {
                $data['offset'] = max(0, $offset - 1);
                [$offset, $maxOffset, $bodyHeight] = $this->renderScrollablePanelScreen($conn, $state, $data, $commandLines);
                continue;
            }
            if ($choice === 'down' || $choice === 'd') {
                $data['offset'] = min($maxOffset, $offset + 1);
                [$offset, $maxOffset, $bodyHeight] = $this->renderScrollablePanelScreen($conn, $state, $data, $commandLines);
                continue;
            }
            if ($choice === 'pgup' || $choice === 'p') {
                $data['offset'] = max(0, $offset - max(1, $bodyHeight));
                [$offset, $maxOffset, $bodyHeight] = $this->renderScrollablePanelScreen($conn, $state, $data, $commandLines);
                continue;
            }
            if ($choice === 'pgdown' || $choice === 'n') {
                $data['offset'] = min($maxOffset, $offset + max(1, $bodyHeight));
                [$offset, $maxOffset, $bodyHeight] = $this->renderScrollablePanelScreen($conn, $state, $data, $commandLines);
                continue;
            }
            if ($choice === 'home') {
                $data['offset'] = 0;
                [$offset, $maxOffset, $bodyHeight] = $this->renderScrollablePanelScreen($conn, $state, $data, $commandLines);
                continue;
            }
            if ($choice === 'end') {
                $data['offset'] = $maxOffset;
                [$offset, $maxOffset, $bodyHeight] = $this->renderScrollablePanelScreen($conn, $state, $data, $commandLines);
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
        $offset = max(0, $initialOffset);
        $extraKeyMap = $this->normalizeCommandMap($extraKeys);
        $bodyHeight = 4;
        $maxOffset = 0;
        $commandLines = [];

        $renderViewer = function () use ($conn, &$state, &$headerLines, &$wrappedLines, &$statusLine, $rows, $rebuildFn, $allowDownloadAction, $kludgeLines, $imageRefs, $imageFn, $extraKeyMap, $helpItems, &$offset, &$bodyHeight, &$maxOffset, &$commandLines): void {
            if ($rebuildFn !== null) {
                $rebuilt = (array)$rebuildFn($state);
                $headerLines = $rebuilt['headerLines'] ?? $headerLines;
                $wrappedLines = $rebuilt['wrappedLines'] ?? $wrappedLines;
                $statusLine = $rebuilt['statusLine'] ?? $statusLine;
            }

            $currentRows = max(12, (int)($state['rows'] ?? $rows));
            $simpleHeaderLines = $this->simplifyHeaderLinesForLineMode($headerLines);
            $headerCount = count($simpleHeaderLines);
            $bodyHeight = max(4, $currentRows - $headerCount - 9);
            $maxOffset = max(0, count($wrappedLines) - $bodyHeight);
            $offset = min($offset, $maxOffset);

            $commandLines = [];
            if ($statusLine !== '') {
                $commandLines[] = $this->stripAnsi($statusLine);
            }
            $commands = ['Q = back', 'Enter/N = next page', 'P = prev page', 'U/D = scroll', 'L = prev', 'R = reply/next'];
            if (!empty($kludgeLines)) {
                $commands[] = 'H = headers';
            }
            if ($allowDownloadAction) {
                $commands[] = 'Z = download';
            }
            if (!empty($imageRefs) && $imageFn !== null) {
                $commands[] = count($imageRefs) === 1 ? 'I = image' : 'I/# = image';
            }
            foreach ($extraKeyMap as $key => $action) {
                if (strlen((string)$key) === 1) {
                    $label = ucwords(str_replace('_', ' ', (string)$action));
                    $commands[] = strtoupper((string)$key) . ' = ' . $label;
                }
            }
            if (!empty($helpItems)) {
                $commands[] = '? = help';
            }
            $commandLines[] = implode('  ', $commands);
            $this->renderMessageViewerScreen($conn, $state, $headerLines, $wrappedLines, $offset, $bodyHeight, $commandLines);
        };

        while (true) {
            $renderViewer();
            $choice = $this->promptCommand($conn, $state, 'Command: ', function () use (&$renderViewer): void {
                $renderViewer();
            });
            if ($choice === null) {
                return ['action' => 'quit', 'offset' => $offset];
            }

            $choice = trim($choice);
            if ($choice === '') {
                $offset = min($maxOffset, $offset + $bodyHeight);
                continue;
            }
            if (strcasecmp($choice, 'q') === 0) {
                return ['action' => 'quit', 'offset' => $offset];
            }
            if (strcasecmp($choice, 'u') === 0) {
                $offset = max(0, $offset - 1);
                continue;
            }
            if (strcasecmp($choice, 'd') === 0) {
                $offset = min($maxOffset, $offset + 1);
                continue;
            }
            if (strcasecmp($choice, 'p') === 0) {
                $offset = max(0, $offset - $bodyHeight);
                continue;
            }
            if (strcasecmp($choice, 'n') === 0) {
                $offset = min($maxOffset, $offset + $bodyHeight);
                continue;
            }
            if (strcasecmp($choice, 'l') === 0) {
                return ['action' => 'prev', 'offset' => $offset];
            }
            if (strcasecmp($choice, 'r') === 0) {
                return ['action' => 'reply', 'offset' => $offset];
            }
            if ($allowDownloadAction && strcasecmp($choice, 'z') === 0) {
                return ['action' => 'download', 'offset' => $offset];
            }
            if (!empty($kludgeLines) && strcasecmp($choice, 'h') === 0) {
                $this->showText($conn, $state, 'Headers', array_map(fn($line) => $this->stripAnsi((string)$line), $kludgeLines));
                continue;
            }
            if ($imageFn !== null && !empty($imageRefs)) {
                if (strcasecmp($choice, 'i') === 0) {
                    $index = 0;
                    if (count($imageRefs) > 1) {
                        $imageChoice = $this->promptCommand($conn, $state, 'Image #: ', function () use (&$renderViewer): void {
                            $renderViewer();
                        });
                        if ($imageChoice === null || $imageChoice === '' || !ctype_digit($imageChoice)) {
                            continue;
                        }
                        $index = (int)$imageChoice - 1;
                    }
                    if (isset($imageRefs[$index])) {
                        $imageFn($index);
                    }
                    continue;
                }
                if (ctype_digit($choice)) {
                    $index = (int)$choice - 1;
                    if (isset($imageRefs[$index])) {
                        $imageFn($index);
                        continue;
                    }
                }
            }

            if ($choice === '?' && !empty($helpItems)) {
                $lines = array_map(fn($item) => sprintf('%-16s %s', $item['key'] ?? '', $item['label'] ?? ''), $helpItems);
                $this->showText($conn, $state, 'Help', $lines);
                continue;
            }

            $lowerChoice = strtolower($choice);
            if (isset($extraKeyMap[$lowerChoice])) {
                return ['action' => $extraKeyMap[$lowerChoice], 'offset' => $offset];
            }
        }
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
        $rows = [];
        foreach ($messages as $idx => $msg) {
            $rows[] = TelnetUtils::formatMessageListEntry(
                $msg,
                $idx + 1,
                false,
                max(20, (int)($state['cols'] ?? 80) - (!empty($options['multiSelect']) ? 1 : 0)),
                $state
            );
        }

        if (!empty($options['selectedMessageIds']) && !isset($options['selectedRows'])) {
            $selectedRows = [];
            foreach ($messages as $idx => $msg) {
                if (in_array((int)($msg['id'] ?? 0), $options['selectedMessageIds'], true)) {
                    $selectedRows[] = $idx;
                }
            }
            $options['selectedRows'] = $selectedRows;
        }

        $result = $this->showSelectableList(
            $conn,
            $state,
            $title,
            $rows,
            $page,
            $totalPages,
            $selectedIndex,
            [],
            array_merge(['c' => 'compose'], $extraKeys),
            null,
            $options,
            $helpItems
        );

        if (($result['action'] ?? '') === 'select') {
            $result['action'] = 'read';
        }

        return $result;
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
        $selectedRows = array_fill_keys(array_map('intval', $options['selectedRows'] ?? []), true);
        $extraKeyMap = $this->normalizeCommandMap($extraKeys);
        $commandLines = [];

        $renderList = function () use ($conn, &$state, &$title, &$rows, $rebuildFn, $page, $totalPages, $selectedIndex, $options, $extraKeyMap, $helpItems, &$commandLines, &$selectedRows): void {
            if ($rebuildFn !== null) {
                $rebuilt = (array)$rebuildFn($state);
                $rows = $rebuilt['rows'] ?? $rows;
                $title = $rebuilt['title'] ?? $title;
            }

            $commands = ['number = select', 'P = prev page', 'N = next page', 'Q = quit'];
            if (!empty($options['multiSelect'])) {
                $commands[] = 'M# = toggle mark';
            }
            foreach ($extraKeyMap as $key => $action) {
                if (strlen((string)$key) === 1) {
                    $label = ucwords(str_replace('_', ' ', (string)$action));
                    $commands[] = strtoupper((string)$key) . ' = ' . $label;
                }
            }
            if (!empty($helpItems)) {
                $commands[] = '? = help';
            }
            $commandLines = [implode('  ', $commands)];
            $this->renderSelectableListScreen($conn, $state, $title, $rows, $page, $totalPages, $selectedIndex, $commandLines, $selectedRows);
        };

        while (true) {
            $renderList();
            $choice = $this->promptCommand($conn, $state, 'Command: ', function () use (&$renderList): void {
                $renderList();
            });
            if ($choice === null) {
                return ['action' => 'disconnect', 'index' => $selectedIndex, 'selectedIndex' => $selectedIndex];
            }

            $choice = trim($choice);
            if ($choice === '') {
                continue;
            }
            if (strcasecmp($choice, 'q') === 0) {
                return ['action' => 'quit', 'index' => $selectedIndex, 'selectedIndex' => $selectedIndex];
            }
            if (strcasecmp($choice, 'p') === 0) {
                return ['action' => 'prev', 'index' => 0, 'selectedIndex' => 0];
            }
            if (strcasecmp($choice, 'n') === 0) {
                return ['action' => 'next', 'index' => 0, 'selectedIndex' => 0];
            }

            if ($choice === '?' && !empty($helpItems)) {
                $lines = array_map(fn($item) => sprintf('%-16s %s', $item['key'] ?? '', $item['label'] ?? ''), $helpItems);
                $this->showText($conn, $state, 'Help', $lines);
                continue;
            }

            $lowerChoice = strtolower($choice);
            if (!empty($options['multiSelect']) && preg_match('/^m(\d+)$/i', $choice, $matches)) {
                $idx = (int)$matches[1] - 1;
                if (isset($rows[$idx])) {
                    if (isset($selectedRows[$idx])) {
                        unset($selectedRows[$idx]);
                    } else {
                        $selectedRows[$idx] = true;
                    }
                    $selectedIndex = $idx;
                    return ['action' => 'toggle_select', 'index' => $idx, 'selectedIndex' => $selectedIndex];
                }
            }

            if (isset($extraKeyMap[$lowerChoice])) {
                return ['action' => $extraKeyMap[$lowerChoice], 'index' => $selectedIndex, 'selectedIndex' => $selectedIndex];
            }

            if (ctype_digit($choice)) {
                $idx = (int)$choice - 1;
                if (isset($rows[$idx])) {
                    $selectedIndex = $idx;
                    return ['action' => 'select', 'index' => $idx, 'selectedIndex' => $selectedIndex];
                }
            }
        }
    }

    public function showAlert($conn, array &$state, string $title, string $message, string $style = 'info'): void
    {
        $color = $style === 'error' ? TelnetUtils::ANSI_RED : TelnetUtils::ANSI_GREEN;
        $this->showText($conn, $state, $title, [TelnetUtils::colorize($message, $color)]);
    }

    public function showConfirmDialog($conn, array &$state, string $title, string $message, array $choices = ['y' => 'Confirm', 'n' => 'Cancel'], string $default = 'n', array $options = []): string
    {
        while (true) {
            if (isset($options['redraw_fn']) && is_callable($options['redraw_fn'])) {
                ($options['redraw_fn'])($state);
            } else {
                $this->clearAndTitle($conn, $title);
            }
            if ($message !== '') {
                foreach (TelnetUtils::wrapTextLines($message, $this->wrapWidth($state)) as $line) {
                    TelnetUtils::writeLine($conn, $line);
                }
                TelnetUtils::writeLine($conn, '');
            }

            $choice = $this->promptCommand($conn, $state, $this->buildChoicePrompt($choices, $default), function () use ($conn, &$state, $title, $message, $choices, $default, $options): void {
                if (isset($options['redraw_fn']) && is_callable($options['redraw_fn'])) {
                    ($options['redraw_fn'])($state);
                } else {
                    $this->clearAndTitle($conn, $title);
                }
                if ($message !== '') {
                    foreach (TelnetUtils::wrapTextLines($message, $this->wrapWidth($state)) as $line) {
                        TelnetUtils::writeLine($conn, $line);
                    }
                    TelnetUtils::writeLine($conn, '');
                }
            });
            if ($choice === null || $choice === '') {
                return $default;
            }

            $choice = strtolower($choice);
            if (isset($choices[$choice])) {
                return $choice;
            }
        }
    }

    public function showWorkingOverlay($conn, array &$state, string $message, array $options = []): void
    {
        $this->clearAndTitle($conn, $this->server->t('ui.terminalserver.server.working', 'Working', [], $state['locale'] ?? 'en'));
        foreach (TelnetUtils::wrapTextLines($message, $this->wrapWidth($state)) as $line) {
            TelnetUtils::writeLine($conn, $line);
        }
        TelnetUtils::writeLine($conn, '');
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
        $selected = array_values(array_unique(array_map('intval', $selectedIndices)));
        sort($selected);
        $cursorIdx = 0;
        $page = 1;
        $totalPages = 1;
        $pageRows = [];
        $pageSelectedIndex = 0;
        $pageStart = 0;
        $commandLines = [];

        $renderCheckboxList = function () use ($conn, &$state, $titleFn, $items, $maxSelect, $hintSkip, $redrawFn, &$selected, &$cursorIdx, &$page, &$totalPages, &$pageRows, &$pageSelectedIndex, &$pageStart, &$commandLines): void {
            if ($redrawFn !== null) {
                $redrawFn($state);
            }
            $rows = [];
            foreach ($items as $idx => $item) {
                $mark = in_array($idx, $selected, true) ? '[x]' : '[ ]';
                $rows[] = sprintf('%s %s', $mark, $this->stripAnsi((string)$item));
            }

            $title = $titleFn(count($selected));
            $pageSize = $this->visibleListPageSize($state, 10, 4, 20);
            $cursorIdx = min(max(0, $cursorIdx), max(0, count($rows) - 1));
            $page = max(1, (int)floor($cursorIdx / max(1, $pageSize)) + 1);
            $totalPages = max(1, (int)ceil(max(1, count($rows)) / max(1, $pageSize)));
            $pageStart = ($page - 1) * $pageSize;
            $pageRows = array_slice($rows, $pageStart, $pageSize);
            $pageSelectedIndex = $cursorIdx - $pageStart;
            $commandLines = [
                sprintf('Selected: %d%s', count($selected), $maxSelect > 0 ? '/' . $maxSelect : ''),
                'Commands: number = toggle, N = next page, P = previous page, D = done, Q = ' . $hintSkip,
            ];
            $this->renderListPage($conn, $state, $title, $pageRows, 1, max(1, count($pageRows)), $pageSelectedIndex, null, true);
            $this->renderFooterLines($conn, $commandLines);
        };

        while (true) {
            $renderCheckboxList();
            $choice = $this->promptCommand($conn, $state, 'Command: ', function () use (&$renderCheckboxList): void {
                $renderCheckboxList();
            });
            if ($choice === null) {
                return null;
            }

            $choice = trim($choice);
            if ($choice === '') {
                continue;
            }
            if (strcasecmp($choice, 'q') === 0) {
                return ['action' => 'quit', 'selected' => []];
            }
            if (strcasecmp($choice, 'd') === 0) {
                return ['action' => 'confirm', 'selected' => $selected];
            }
            if (strcasecmp($choice, 'n') === 0) {
                if ($page < $totalPages) {
                    $cursorIdx = min(count($rows) - 1, $pageStart + $pageSize);
                }
                continue;
            }
            if (strcasecmp($choice, 'p') === 0) {
                if ($page > 1) {
                    $cursorIdx = max(0, $pageStart - $pageSize);
                }
                continue;
            }
            if (!ctype_digit($choice)) {
                continue;
            }

            $pageIndex = (int)$choice - 1;
            $absoluteIndex = $pageStart + $pageIndex;
            if (!isset($items[$absoluteIndex])) {
                continue;
            }

            $cursorIdx = $absoluteIndex;
            if (in_array($absoluteIndex, $selected, true)) {
                $selected = array_values(array_filter($selected, static fn(int $index): bool => $index !== $absoluteIndex));
                continue;
            }
            if ($maxSelect > 0 && count($selected) >= $maxSelect) {
                if ($atLimitMessage !== '') {
                    $this->showAlert($conn, $state, '', $atLimitMessage, 'error');
                }
                continue;
            }
            $selected[] = $absoluteIndex;
            $selected = array_values(array_unique($selected));
            sort($selected);
        }
    }

    public function showSelectableDialog($conn, array &$state, string $title, array $items, string $hintSelect = 'Select', string $hintBack = 'Back', int $selectedIndex = 0, ?callable $redrawFn = null, array $options = []): ?array
    {
        $index = $this->chooseFromList(
            $conn,
            $state,
            $title,
            $items,
            [
                'selected_index' => $selectedIndex,
                'prompt' => $hintSelect . ' # or Q to ' . strtolower($hintBack) . ': ',
            ]
        );

        if ($index === null) {
            return ['action' => 'quit', 'index' => $selectedIndex];
        }

        return ['action' => 'select', 'index' => $index];
    }

    public function showAddressPicker($conn, array &$state, string $apiBase, string $session): ?array
    {
        return TelnetUtils::runAddressPicker($conn, $state, $this->server, $apiBase, $session, $state['locale'] ?? 'en');
    }

    public function showPublicProfileViewer($conn, array &$state, array $profile, array $options = []): void
    {
        $locale = $state['locale'] ?? 'en';
        $notSpecified = $this->server->t('ui.terminalserver.profile.not_specified', 'Not specified', [], $locale);
        $bioText = trim((string)($profile['about_me'] ?? ''));
        if ($bioText === '') {
            $bioText = $this->server->t('ui.terminalserver.profile.empty_biography', 'No biography provided.', [], $locale);
        }

        $lines = [
            $this->server->t('ui.terminalserver.profile.username', 'Username', [], $locale) . ': ' . ((string)($profile['username'] ?? '') !== '' ? (string)$profile['username'] : $notSpecified),
            $this->server->t('ui.terminalserver.profile.real_name', 'Full Name', [], $locale) . ': ' . (trim((string)($profile['real_name'] ?? '')) !== '' ? (string)$profile['real_name'] : $notSpecified),
            $this->server->t('ui.terminalserver.profile.location', 'Location', [], $locale) . ': ' . (trim((string)($profile['location'] ?? '')) !== '' ? (string)$profile['location'] : $notSpecified),
            '',
            $this->server->t('ui.terminalserver.profile.biography', 'Biography', [], $locale) . ':',
            '',
        ];
        $lines = array_merge($lines, TelnetUtils::wrapTextLines($bioText, $this->wrapWidth($state)));

        $this->showScrollablePanel(
            $conn,
            $state,
            $this->server->t('ui.terminalserver.profile.title', 'User Profile', [], $locale),
            $lines,
            ['status_line' => 'U/D scroll  Q back']
        );
    }

    public function showPagedBox($conn, array &$state, string $title, array $lines, string $continuePrompt, int $verticalMargin = 2, array $stopKeys = [], array $options = []): ?string
    {
        $pageIndex = 0;
        $pages = [];
        $pageCount = 1;
        $commandLines = [];

        $renderPagedBox = function () use ($conn, &$state, $title, $lines, $continuePrompt, $stopKeys, &$pageIndex, &$pages, &$pageCount, &$commandLines): void {
            $contentHeight = max(4, (int)($state['rows'] ?? 24) - 8);
            $pages = array_chunk($lines ?: [''], $contentHeight);
            $pageCount = max(1, count($pages));
            $pageIndex = min($pageIndex, $pageCount - 1);

            $extraStopHints = [];
            foreach ($stopKeys as $stopKey) {
                if (preg_match('/^CHAR:(.)$/', (string)$stopKey, $matches)) {
                    $extraStopHints[] = strtoupper($matches[1]);
                }
            }
            $commandLines = ['Enter = next'];
            if ($pageCount > 1) {
                $commandLines[0] .= '  P = previous';
            }
            if ($extraStopHints !== []) {
                $commandLines[0] .= '  ' . implode('/', $extraStopHints) . ' = stop';
            }
            $commandLines[0] .= '  Q = quit';
            $this->renderPagedBoxScreen($conn, $state, $title, $pages, $pageIndex, $continuePrompt, $commandLines);
        };

        while (true) {
            $renderPagedBox();
            $choice = $this->promptCommand($conn, $state, 'Command: ', function () use (&$renderPagedBox): void {
                $renderPagedBox();
            });
            if ($choice === null || strcasecmp($choice, 'q') === 0) {
                return null;
            }
            foreach ($stopKeys as $stopKey) {
                if (preg_match('/^CHAR:(.)$/', (string)$stopKey, $matches) && strcasecmp($choice, $matches[1]) === 0) {
                    return $stopKey;
                }
            }
            if (strcasecmp($choice, 'p') === 0 && $pageCount > 1) {
                $pageIndex = max(0, $pageIndex - 1);
                continue;
            }
            if ($pageIndex >= $pageCount - 1) {
                return null;
            }
            $pageIndex++;
        }
    }
}
