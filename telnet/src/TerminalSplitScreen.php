<?php

namespace BinktermPHP\TelnetServer;

/**
 * Reusable full-screen split layout renderer for terminal UIs.
 *
 * This component renders one or more rows of boxed panes that can be split
 * horizontally across the screen. Each pane has a title and content lines.
 * Row heights can be fixed or auto-distributed, and pane widths are allocated
 * from relative weights.
 *
 * It is intentionally render-only: callers own input handling and state.
 */
class TerminalSplitScreen
{
    private const MIN_PANE_HEIGHT = 5;
    private const MIN_PANE_WIDTH = 12;

    private BbsSession $server;
    private string $title;
    private string $titleColor = TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD;
    private int $horizontalMargin = 1;
    private int $verticalMargin = 1;
    private array $lastLayout = [];

    /**
     * @var array<int, array{height: int|null, panes: array<int, array<string, mixed>>}>
     */
    private array $rows = [];

    public function __construct(BbsSession $server, string $title = '')
    {
        $this->server = $server;
        $this->title = $title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function setMargins(int $horizontal, int $vertical): static
    {
        $this->horizontalMargin = max(0, $horizontal);
        $this->verticalMargin = max(0, $vertical);
        return $this;
    }

    /**
     * Add a horizontal split row.
     *
     * Each pane accepts:
     * - title: string
     * - lines: string[]
     * - weight: int
     * - min_width: int
     * - scroll: 'top'|'bottom'
     * - border_color: ANSI color
     * - title_color: ANSI color
     *
     * @param array<int, array<string, mixed>> $panes
     */
    public function addRow(array $panes, ?int $height = null): static
    {
        $normalized = [];
        foreach ($panes as $pane) {
            $normalized[] = [
                'title' => (string)($pane['title'] ?? ''),
                'lines' => array_values(array_map('strval', $pane['lines'] ?? [])),
                'weight' => max(1, (int)($pane['weight'] ?? 1)),
                'min_width' => max(self::MIN_PANE_WIDTH, (int)($pane['min_width'] ?? 18)),
                'scroll' => (($pane['scroll'] ?? 'top') === 'bottom') ? 'bottom' : 'top',
                'border_color' => (string)($pane['border_color'] ?? (TelnetUtils::ANSI_BLUE)),
                'title_color' => (string)($pane['title_color'] ?? (TelnetUtils::ANSI_BG_BLUE . TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD)),
            ];
        }

        if ($normalized !== []) {
            $this->rows[] = [
                'height' => $height !== null ? max(self::MIN_PANE_HEIGHT, $height) : null,
                'panes' => $normalized,
            ];
        }

        return $this;
    }

    public function clearRows(): static
    {
        $this->rows = [];
        return $this;
    }

    /**
     * Return pane coordinates from the most recent render.
     *
     * Shape:
     * [
     *   'rows' => [
     *     ['y' => int, 'height' => int, 'panes' => [
     *         ['x' => int, 'y' => int, 'width' => int, 'height' => int, 'pane' => [...]],
     *     ]],
     *   ],
     * ]
     */
    public function getLastLayout(): array
    {
        return $this->lastLayout;
    }

    /**
     * Clear the terminal and render the configured split layout.
     *
     * @param resource $conn
     * @param array $state
     */
    public function render($conn, array $state): void
    {
        $cols = max(40, (int)($state['cols'] ?? 80));
        $rows = max(12, (int)($state['rows'] ?? 24));

        $left = 1 + $this->horizontalMargin;
        $top = 1 + $this->verticalMargin;
        $usableWidth = max(20, $cols - ($this->horizontalMargin * 2));
        $usableHeight = max(6, $rows - ($this->verticalMargin * 2));

        $this->server->safeWrite($conn, "\033[2J\033[H");

        $titleRows = ($this->title !== '') ? 2 : 0;
        if ($this->title !== '') {
            $titleText = $this->fitPlainText($this->server->encodeForTerminal($this->title), $usableWidth);
            $titleLine = $this->padPlainText($titleText, $usableWidth, STR_PAD_BOTH);
            $this->writeAt($conn, $top, $left, $this->server->colorizeForTerminal($titleLine, $this->titleColor));
        }

        $contentTop = $top + $titleRows;
        $contentHeight = max(self::MIN_PANE_HEIGHT, $usableHeight - $titleRows);
        $rowLayouts = $this->buildRowLayouts($contentHeight);
        $this->lastLayout = ['rows' => []];

        foreach ($rowLayouts as $rowIndex => $rowLayout) {
            $rowY = $contentTop + $rowLayout['y'];
            $paneLayouts = $this->buildPaneLayouts($usableWidth, $rowLayout['panes']);
            $layoutRow = [
                'y' => $rowY,
                'height' => $rowLayout['height'],
                'panes' => [],
            ];

            foreach ($paneLayouts as $paneLayout) {
                $paneX = $left + $paneLayout['x'];
                $this->renderPane(
                    $conn,
                    $paneX,
                    $rowY,
                    $paneLayout['width'],
                    $rowLayout['height'],
                    $paneLayout['pane']
                );
                $layoutRow['panes'][] = [
                    'x' => $paneX,
                    'y' => $rowY,
                    'width' => $paneLayout['width'],
                    'height' => $rowLayout['height'],
                    'pane' => $paneLayout['pane'],
                ];
            }
            $this->lastLayout['rows'][$rowIndex] = $layoutRow;
        }

        $finalRow = min($rows, $contentTop + $contentHeight);
        $this->server->safeWrite($conn, "\033[" . $finalRow . ";1H");
    }

    /**
     * @return array<int, array{y: int, height: int, panes: array<int, array<string, mixed>>}>
     */
    private function buildRowLayouts(int $contentHeight): array
    {
        if ($this->rows === []) {
            return [[
                'y' => 0,
                'height' => max(self::MIN_PANE_HEIGHT, $contentHeight),
                'panes' => [[
                    'title' => '',
                    'lines' => [],
                    'weight' => 1,
                    'min_width' => 18,
                    'scroll' => 'top',
                    'border_color' => TelnetUtils::ANSI_BLUE,
                    'title_color' => TelnetUtils::ANSI_BG_BLUE . TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD,
                ]],
            ]];
        }

        $rowGapCount = max(0, count($this->rows) - 1);
        $availableHeight = max(self::MIN_PANE_HEIGHT, $contentHeight - $rowGapCount);

        $fixedHeight = 0;
        $autoRows = 0;
        foreach ($this->rows as $row) {
            if ($row['height'] === null) {
                $autoRows++;
            } else {
                $fixedHeight += (int)$row['height'];
            }
        }

        $remaining = $availableHeight - $fixedHeight;
        $autoHeight = $autoRows > 0 ? max(self::MIN_PANE_HEIGHT, (int)floor($remaining / $autoRows)) : 0;
        if ($remaining < ($autoRows * self::MIN_PANE_HEIGHT)) {
            $autoHeight = self::MIN_PANE_HEIGHT;
        }

        $layouts = [];
        $cursorY = 0;
        foreach ($this->rows as $index => $row) {
            $height = $row['height'] !== null ? (int)$row['height'] : $autoHeight;
            $remainingRows = count($this->rows) - $index - 1;
            $maxAllowed = $contentHeight - $cursorY - $remainingRows;
            $height = max(self::MIN_PANE_HEIGHT, min($height, max(self::MIN_PANE_HEIGHT, $maxAllowed)));

            $layouts[] = [
                'y' => $cursorY,
                'height' => $height,
                'panes' => $row['panes'],
            ];
            $cursorY += $height + 1;
            if ($cursorY >= $contentHeight) {
                break;
            }
        }

        return $layouts;
    }

    /**
     * @param array<int, array<string, mixed>> $panes
     * @return array<int, array{x: int, width: int, pane: array<string, mixed>}>
     */
    private function buildPaneLayouts(int $availableWidth, array $panes): array
    {
        $paneCount = count($panes);
        if ($paneCount === 0) {
            return [];
        }

        $gapCount = max(0, $paneCount - 1);
        $contentWidth = max(self::MIN_PANE_WIDTH, $availableWidth - $gapCount);
        $totalWeight = 0;
        $minWidthSum = 0;
        foreach ($panes as $pane) {
            $totalWeight += (int)$pane['weight'];
            $minWidthSum += (int)$pane['min_width'];
        }

        $widths = [];
        if ($minWidthSum > $contentWidth) {
            $baseWidth = max(self::MIN_PANE_WIDTH, (int)floor($contentWidth / $paneCount));
            $used = 0;
            for ($i = 0; $i < $paneCount; $i++) {
                $width = ($i === $paneCount - 1) ? ($contentWidth - $used) : $baseWidth;
                $widths[] = max(self::MIN_PANE_WIDTH, $width);
                $used += $width;
            }
        } else {
            $remaining = $contentWidth - $minWidthSum;
            $used = 0;
            foreach ($panes as $index => $pane) {
                $width = (int)$pane['min_width'];
                if ($remaining > 0 && $totalWeight > 0) {
                    $extra = ($index === $paneCount - 1)
                        ? ($contentWidth - $used - $width)
                        : (int)floor(($remaining * (int)$pane['weight']) / $totalWeight);
                    $width += max(0, $extra);
                }
                $widths[] = $width;
                $used += $width;
            }

            $diff = $contentWidth - array_sum($widths);
            if ($diff !== 0) {
                $widths[$paneCount - 1] += $diff;
            }
        }

        $layouts = [];
        $cursorX = 0;
        foreach ($panes as $index => $pane) {
            $layouts[] = [
                'x' => $cursorX,
                'width' => $widths[$index],
                'pane' => $pane,
            ];
            $cursorX += $widths[$index] + 1;
        }

        return $layouts;
    }

    /**
     * @param resource $conn
     * @param array<string, mixed> $pane
     */
    private function renderPane($conn, int $x, int $y, int $width, int $height, array $pane): void
    {
        $height = max(self::MIN_PANE_HEIGHT, $height);
        $width = max(self::MIN_PANE_WIDTH, $width);
        $contentWidth = max(4, $width - 4);
        $contentHeight = max(1, $height - 4);

        $chars = $this->server->getTerminalLineDrawingChars();
        $topBorder = $this->server->encodeForTerminal($chars['tl'] . str_repeat($chars['h_bold'], $width - 2) . $chars['tr']);
        $divider = $this->server->encodeForTerminal($chars['l_tee'] . str_repeat($chars['h'], $width - 2) . $chars['r_tee']);
        $bottomBorder = $this->server->encodeForTerminal($chars['bl'] . str_repeat($chars['h_bold'], $width - 2) . $chars['br']);

        $borderColor = (string)$pane['border_color'];
        $titleColor = (string)$pane['title_color'];

        $title = $this->fitPlainText($this->server->encodeForTerminal((string)$pane['title']), $contentWidth);
        $titleInner = $this->padPlainText($title, $contentWidth, STR_PAD_BOTH);
        $titleLine = $this->server->colorizeForTerminal($this->server->encodeForTerminal($chars['v']), $borderColor)
            . $this->server->colorizeForTerminal(' ' . $titleInner . ' ', $titleColor)
            . $this->server->colorizeForTerminal($this->server->encodeForTerminal($chars['v']), $borderColor);

        $this->writeAt($conn, $y, $x, $this->server->colorizeForTerminal($topBorder, $borderColor . TelnetUtils::ANSI_BOLD));
        $this->writeAt($conn, $y + 1, $x, $titleLine);
        $this->writeAt($conn, $y + 2, $x, $this->server->colorizeForTerminal($divider, $borderColor));

        $contentLines = $this->prepareContentLines(
            $pane['lines'],
            $contentWidth,
            $contentHeight,
            (string)$pane['scroll']
        );

        for ($i = 0; $i < $contentHeight; $i++) {
            $line = $contentLines[$i] ?? '';
            $this->writeAt($conn, $y + 3 + $i, $x, $this->renderContentLine($line, $contentWidth, $chars, $borderColor));
        }

        $this->writeAt($conn, $y + $height - 1, $x, $this->server->colorizeForTerminal($bottomBorder, $borderColor . TelnetUtils::ANSI_BOLD));
    }

    /**
     * @param string[] $lines
     * @return string[]
     */
    private function prepareContentLines(array $lines, int $contentWidth, int $contentHeight, string $scrollMode): array
    {
        $prepared = [];
        foreach ($lines as $line) {
            $chunks = $this->wrapLine($line, $contentWidth);
            foreach ($chunks as $chunk) {
                $prepared[] = $chunk;
            }
        }

        if ($scrollMode === 'bottom' && count($prepared) > $contentHeight) {
            $prepared = array_slice($prepared, -$contentHeight);
        } else {
            $prepared = array_slice($prepared, 0, $contentHeight);
        }

        return $prepared;
    }

    /**
     * @return string[]
     */
    private function wrapLine(string $line, int $width): array
    {
        if ($line === '') {
            return [''];
        }

        if ($this->containsAnsi($line)) {
            return $this->wrapAnsiLine($line, $width);
        }

        $wrapped = wordwrap($this->server->encodeForTerminal($line), max(1, $width), "\n", true);
        return explode("\n", $wrapped);
    }

    /**
     * @return string[]
     */
    private function wrapAnsiLine(string $line, int $width): array
    {
        $segments = [];
        $current = '';
        $visible = 0;
        $activeAnsi = '';
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            if ($line[$i] === "\033" && preg_match('/\G\033\[[0-9;]*m/', $line, $match, 0, $i)) {
                $seq = $match[0];
                $current .= $seq;
                $i += strlen($seq) - 1;
                $activeAnsi = ($seq === "\033[0m") ? '' : $activeAnsi . $seq;
                if ($seq === "\033[0m") {
                    $activeAnsi = '';
                }
                continue;
            }

            if ($visible >= $width) {
                $segments[] = $current . "\033[0m";
                $current = $activeAnsi;
                $visible = 0;
            }

            $current .= $line[$i];
            $visible++;
        }

        if ($current !== '' || $segments === []) {
            $segments[] = $current . ($this->containsAnsi($current) ? "\033[0m" : '');
        }

        return $segments;
    }

    private function renderContentLine(string $line, int $contentWidth, array $chars, string $borderColor): string
    {
        $line = $this->server->encodeForTerminal($line);
        $visibleWidth = $this->ansiLength($line);
        if ($visibleWidth > $contentWidth) {
            $line = $this->truncateAnsiLine($line, $contentWidth);
            $visibleWidth = $this->ansiLength($line);
        }
        $padding = str_repeat(' ', max(0, $contentWidth - $visibleWidth));

        return $this->server->colorizeForTerminal($this->server->encodeForTerminal($chars['v']), $borderColor)
            . ' '
            . $line
            . $padding
            . ' '
            . $this->server->colorizeForTerminal($this->server->encodeForTerminal($chars['v']), $borderColor);
    }

    /**
     * @param resource $conn
     */
    private function writeAt($conn, int $row, int $col, string $text): void
    {
        $this->server->safeWrite($conn, "\033[{$row};{$col}H" . $text);
    }

    private function fitPlainText(string $text, int $width): string
    {
        return $this->truncatePlainText($text, $width);
    }

    private function padPlainText(string $text, int $width, int $padType): string
    {
        $textLength = $this->visibleTextWidth($text);
        if ($textLength >= $width) {
            return $text;
        }

        $pad = $width - $textLength;
        if ($padType === STR_PAD_BOTH) {
            $left = (int)floor($pad / 2);
            $right = $pad - $left;
            return str_repeat(' ', $left) . $text . str_repeat(' ', $right);
        }
        if ($padType === STR_PAD_LEFT) {
            return str_repeat(' ', $pad) . $text;
        }
        return $text . str_repeat(' ', $pad);
    }

    private function truncatePlainText(string $text, int $width): string
    {
        if ($this->visibleTextWidth($text) <= $width) {
            return $text;
        }
        return $this->truncateVisibleText($text, $width);
    }

    private function containsAnsi(string $text): bool
    {
        return str_contains($text, "\033[");
    }

    private function ansiLength(string $text): int
    {
        return $this->visibleTextWidth($this->stripAnsi($text));
    }

    private function stripAnsi(string $text): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $text) ?? $text;
    }

    private function truncateAnsiLine(string $line, int $width): string
    {
        $result = '';
        $visible = 0;
        if (!preg_match_all('/\033\[[0-9;]*m|./us', $line, $matches)) {
            return '';
        }

        foreach ($matches[0] as $token) {
            if (str_starts_with($token, "\033[")) {
                $result .= $token;
                continue;
            }

            $charWidth = max(1, mb_strwidth($token, 'UTF-8'));
            if ($visible + $charWidth > $width) {
                break;
            }
            $result .= $token;
            $visible += $charWidth;
        }

        return $result . "\033[0m";
    }

    private function visibleTextWidth(string $text): int
    {
        return mb_strwidth($text, 'UTF-8');
    }

    private function truncateVisibleText(string $text, int $width): string
    {
        if ($width <= 0 || $text === '') {
            return '';
        }

        $result = '';
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return mb_substr($text, 0, $width, 'UTF-8');
        }

        $visible = 0;
        foreach ($chars as $char) {
            $charWidth = max(1, mb_strwidth($char, 'UTF-8'));
            if ($visible + $charWidth > $width) {
                break;
            }
            $result .= $char;
            $visible += $charWidth;
        }

        return $result;
    }
}
