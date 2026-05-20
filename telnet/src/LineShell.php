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

    private function renderListPage($conn, array &$state, string $title, array $items, int $page, int $perPage, ?int $selectedIndex = null, ?callable $preambleFn = null): void
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
            TelnetUtils::writeLine($conn, sprintf('%s%2d) %s', $prefix, $displayIndex, $itemData['label']));
            if ($itemData['detail'] !== '') {
                foreach (TelnetUtils::wrapTextLines($itemData['detail'], $wrapWidth) as $line) {
                    TelnetUtils::writeLine($conn, '    ' . $line);
                }
            }
        }

        TelnetUtils::writeLine($conn, '');
    }

    private function promptCommand($conn, array &$state, string $prompt): ?string
    {
        $choice = $this->server->prompt($conn, $state, TelnetUtils::colorize($prompt, TelnetUtils::ANSI_CYAN), true);
        return $choice === null ? null : trim($choice);
    }

    private function buildChoicePrompt(array $choices, string $default): string
    {
        $parts = [];
        foreach ($choices as $key => $label) {
            $parts[] = strtoupper((string)$key) . ') ' . $label;
        }
        return implode('  ', $parts) . ' [' . strtoupper($default) . ']: ';
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
        $perPage = max(1, min(max(5, (int)($state['rows'] ?? 24) - 8), 25));
        $page = min(
            max(1, ((int)($options['selected_index'] ?? 0) / $perPage) + 1),
            max(1, (int)ceil(count($items) / $perPage))
        );

        while (true) {
            $this->renderListPage($conn, $state, $title, $items, $page, $perPage, null, $preambleFn);
            TelnetUtils::writeLine($conn, 'Commands: number = select, N = next page, P = previous page, Q = back');
            $choice = $this->promptCommand($conn, $state, $prompt);
            if ($choice === null) {
                return null;
            }

            if ($choice === '') {
                continue;
            }

            if (strtolower($choice) === 'q') {
                return null;
            }
            if (strcasecmp($choice, 'n') === 0) {
                $page = min(max(1, (int)ceil(count($items) / $perPage)), $page + 1);
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
        $offset = max(0, $initialOffset);

        while (true) {
            if ($rebuildFn !== null) {
                $rebuilt = (array)$rebuildFn($state);
                $headerLines = $rebuilt['headerLines'] ?? $headerLines;
                $wrappedLines = $rebuilt['wrappedLines'] ?? $wrappedLines;
                $statusLine = $rebuilt['statusLine'] ?? $statusLine;
            }

            $currentRows = max(12, (int)($state['rows'] ?? $rows));
            $headerCount = count($headerLines);
            $bodyHeight = max(4, $currentRows - $headerCount - 8);
            $maxOffset = max(0, count($wrappedLines) - $bodyHeight);
            $offset = min($offset, $maxOffset);

            $this->clearAndTitle($conn, $this->server->t('ui.terminalserver.message.viewer_title', 'Message', [], $state['locale'] ?? 'en'));
            foreach ($headerLines as $line) {
                TelnetUtils::writeWrapped($conn, $this->stripAnsi((string)$line), $this->wrapWidth($state));
            }
            TelnetUtils::writeLine($conn, '');
            foreach (array_slice($wrappedLines, $offset, $bodyHeight) as $line) {
                TelnetUtils::writeWrapped($conn, $this->stripAnsi((string)$line), $this->wrapWidth($state));
            }
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, sprintf('Showing lines %d-%d of %d', $offset + 1, min(count($wrappedLines), $offset + $bodyHeight), count($wrappedLines)));

            $commands = ['Q = back', 'U = up', 'D = down', 'P = page up', 'N = page down', 'L = prev', 'R = reply/next'];
            if (!empty($kludgeLines)) {
                $commands[] = 'H = headers';
            }
            if ($allowDownloadAction) {
                $commands[] = 'Z = download';
            }
            if (!empty($imageRefs) && $imageFn !== null) {
                $commands[] = count($imageRefs) === 1 ? 'I = image' : 'I/# = image';
            }
            foreach (array_keys($extraKeys) as $key) {
                if (strlen((string)$key) === 1) {
                    $commands[] = strtoupper((string)$key) . ' = action';
                }
            }
            TelnetUtils::writeLine($conn, implode('  ', $commands));

            $choice = $this->promptCommand($conn, $state, 'Command: ');
            if ($choice === null || $choice === '' || strcasecmp($choice, 'q') === 0) {
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
                        $imageChoice = $this->promptCommand($conn, $state, 'Image #: ');
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

            $lowerChoice = strtolower($choice);
            if (isset($extraKeys[$lowerChoice])) {
                return ['action' => $extraKeys[$lowerChoice], 'offset' => $offset];
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
        $toggleKey = strtolower((string)($options['toggleKey'] ?? ' '));

        while (true) {
            if ($rebuildFn !== null) {
                $rebuilt = (array)$rebuildFn($state);
                $rows = $rebuilt['rows'] ?? $rows;
                $title = $rebuilt['title'] ?? $title;
            }

            $this->renderListPage($conn, $state, $this->stripAnsi($title), $rows, $page, count($rows), $selectedIndex);
            $commands = ['number = select', 'P = prev page', 'N = next page', 'Q = quit'];
            if (!empty($options['multiSelect'])) {
                $commands[] = 'M# = toggle mark';
            }
            foreach (array_keys($extraKeys) as $key) {
                if (strlen((string)$key) === 1) {
                    $commands[] = strtoupper((string)$key) . ' = action';
                }
            }
            TelnetUtils::writeLine($conn, implode('  ', $commands));
            $choice = $this->promptCommand($conn, $state, 'Command: ');
            if ($choice === null) {
                return ['action' => 'disconnect', 'index' => $selectedIndex, 'selectedIndex' => $selectedIndex];
            }
            if ($choice === '' || strcasecmp($choice, 'q') === 0) {
                return ['action' => 'quit', 'index' => $selectedIndex, 'selectedIndex' => $selectedIndex];
            }
            if (strcasecmp($choice, 'p') === 0) {
                return ['action' => 'prev', 'index' => 0, 'selectedIndex' => 0];
            }
            if (strcasecmp($choice, 'n') === 0) {
                return ['action' => 'next', 'index' => 0, 'selectedIndex' => 0];
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

            if (isset($extraKeys[$lowerChoice])) {
                return ['action' => $extraKeys[$lowerChoice], 'index' => $selectedIndex, 'selectedIndex' => $selectedIndex];
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

            $choice = $this->promptCommand($conn, $state, $this->buildChoicePrompt($choices, $default));
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

        while (true) {
            $contentHeight = max(4, (int)($state['rows'] ?? 24) - 8);
            $pages = array_chunk($lines ?: [''], $contentHeight);
            $pageCount = max(1, count($pages));
            $pageIndex = min($pageIndex, $pageCount - 1);

            $this->clearAndTitle($conn, $pageCount > 1 ? sprintf('%s (%d/%d)', $title, $pageIndex + 1, $pageCount) : $title);
            foreach ($pages[$pageIndex] ?? [''] as $line) {
                TelnetUtils::writeWrapped($conn, $this->stripAnsi((string)$line), $this->wrapWidth($state));
            }
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, $continuePrompt);

            $extraStopHints = [];
            foreach ($stopKeys as $stopKey) {
                if (preg_match('/^CHAR:(.)$/', (string)$stopKey, $matches)) {
                    $extraStopHints[] = strtoupper($matches[1]);
                }
            }
            $commandPrompt = 'Enter = next';
            if ($pageCount > 1) {
                $commandPrompt .= '  P = previous';
            }
            if ($extraStopHints !== []) {
                $commandPrompt .= '  ' . implode('/', $extraStopHints) . ' = stop';
            }
            $commandPrompt .= '  Q = quit: ';

            $choice = $this->promptCommand($conn, $state, $commandPrompt);
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
