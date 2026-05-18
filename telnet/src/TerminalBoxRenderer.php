<?php

namespace BinktermPHP\TelnetServer;

/**
 * Renders paged boxed screens for the terminal session.
 */
class TerminalBoxRenderer
{
    public const SCHEME_DEFAULT = [
        'border' => TelnetUtils::ANSI_BLUE . TelnetUtils::ANSI_BOLD,
        'divider' => TelnetUtils::ANSI_BLUE,
        'title_bar' => TelnetUtils::ANSI_BG_BLUE . TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD,
    ];

    public const SCHEME_BULLETINS = [
        'border' => TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD,
        'divider' => TelnetUtils::ANSI_CYAN,
        'title_bar' => TelnetUtils::ANSI_BG_WHITE . TelnetUtils::ANSI_BLUE . TelnetUtils::ANSI_BOLD,
    ];

    public const SCHEME_SHOUTBOX = [
        'border' => TelnetUtils::ANSI_MAGENTA . TelnetUtils::ANSI_BOLD,
        'divider' => TelnetUtils::ANSI_MAGENTA,
        'title_bar' => TelnetUtils::ANSI_BG_RED . TelnetUtils::ANSI_YELLOW . TelnetUtils::ANSI_BOLD,
    ];

    private BbsSession $server;

    public function __construct(BbsSession $server)
    {
        $this->server = $server;
    }

    /**
     * Display ANSI-aware content inside a centered framed box.
     *
     * @param resource $conn
     * @param array $state
     * @param string $title
     * @param string[] $lines
     * @param string[] $stopKeys Optional key values that should stop paging early.
     * @return string|null Key that stopped paging, or null.
     */
    public function showPagedBox($conn, array &$state, string $title, array $lines, string $continuePrompt, int $verticalMargin = 2, array $stopKeys = [], array $colorScheme = self::SCHEME_DEFAULT): ?string
    {
        $layout = $this->buildLayout($state, $verticalMargin, 2);
        $pages = array_chunk($lines ?: [''], $layout['contentHeight']);
        $pageCount = count($pages);

        foreach ($pages as $pageIndex => $pageLines) {
            $pageLabel = $pageCount > 1 ? sprintf(' (%d/%d)', $pageIndex + 1, $pageCount) : '';
            $this->renderBox($conn, $state, $title . $pageLabel, $pageLines, $verticalMargin, $colorScheme, 2);
            $this->writeLine($conn, $this->server->colorizeForTerminal($continuePrompt, TelnetUtils::ANSI_YELLOW));
            $key = $this->server->readKeyWithIdleCheck($conn, $state);
            if ($key === null) {
                return null;
            }
            if (!empty($stopKeys) && in_array($key, $stopKeys, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Display one centered framed box without paging or waiting for input.
     *
     * @param resource $conn
     * @param array $state
     * @param string[] $lines
     */
    public function renderBox($conn, array &$state, string $title, array $lines, int $verticalMargin = 2, array $colorScheme = self::SCHEME_DEFAULT, int $footerLines = 0): void
    {
        $layout = $this->buildLayout($state, $verticalMargin, $footerLines);
        $chars = $this->server->getTerminalLineDrawingChars();
        $colors = $this->mergeColorScheme($colorScheme);
        $shadowChar = $chars['shadow_char'] ?? '';
        $hasShadow = $shadowChar !== '';

        $topBorder = $this->server->encodeForTerminal($chars['tl'] . str_repeat($chars['h_bold'], $layout['boxWidth'] - 2) . $chars['tr']);
        $divider = $this->server->encodeForTerminal($chars['l_tee'] . str_repeat($chars['h'], $layout['boxWidth'] - 2) . $chars['r_tee']);
        $bottomBorder = $this->server->encodeForTerminal($chars['bl'] . str_repeat($chars['h_bold'], $layout['boxWidth'] - 2) . $chars['br']);
        $titleText = $this->fitPlainText($title, $layout['contentWidth']);
        $titleInner = $this->padPlainText($titleText, $layout['contentWidth'], STR_PAD_BOTH);

        // Shadow: a single shadow glyph appended after vertical border lines,
        // plus an extra row of shadow glyphs below the bottom border.
        $shadowGlyph = $hasShadow ? $this->server->colorizeForTerminal(
            $this->server->encodeForTerminal($shadowChar),
            TelnetUtils::ANSI_DIM
        ) : '';
        $shadowRow = $hasShadow ? ' ' . $this->server->colorizeForTerminal(
            $this->server->encodeForTerminal(str_repeat($shadowChar, $layout['boxWidth'])),
            TelnetUtils::ANSI_DIM
        ) : '';

        $this->server->safeWrite($conn, "\033[2J\033[H");
        if ($layout['topPad'] !== '') {
            $this->server->safeWrite($conn, $layout['topPad']);
        }

        // Top border — no shadow on the top edge
        $this->writeLine($conn, $layout['leftPad'] . $this->server->colorizeForTerminal($topBorder, $colors['border']));

        // Title row
        $titleLine = $this->server->colorizeForTerminal($this->server->encodeForTerminal($chars['v']), $colors['border'])
            . $this->server->colorizeForTerminal(' ' . $titleInner . ' ', $colors['title_bar'])
            . $this->server->colorizeForTerminal($this->server->encodeForTerminal($chars['v']), $colors['border']);
        $this->writeLine($conn, $layout['leftPad'] . $titleLine . $shadowGlyph);

        // Divider row
        $this->writeLine($conn, $layout['leftPad'] . $this->server->colorizeForTerminal($divider, $colors['divider']) . $shadowGlyph);

        $visibleLines = 0;
        foreach ($lines as $line) {
            $this->writeLine($conn, $layout['leftPad'] . $this->renderContentLine($line, $layout['contentWidth'], $chars, $colors) . $shadowGlyph);
            $visibleLines++;
        }
        while ($visibleLines < $layout['contentHeight']) {
            $this->writeLine($conn, $layout['leftPad'] . $this->renderContentLine('', $layout['contentWidth'], $chars, $colors) . $shadowGlyph);
            $visibleLines++;
        }

        // Bottom border + optional shadow row beneath it
        $this->writeLine($conn, $layout['leftPad'] . $this->server->colorizeForTerminal($bottomBorder, $colors['border']) . $shadowGlyph);
        if ($hasShadow) {
            $this->writeLine($conn, $layout['leftPad'] . $shadowRow);
        }
        $this->writeLine($conn, '');
    }

    /**
     * @param array $state
     * @return array{boxWidth:int,contentWidth:int,contentHeight:int,leftPad:string,topPad:string}
     */
    private function buildLayout(array $state, int $verticalMargin, int $footerLines = 0): array
    {
        $cols = max(40, (int)($state['cols'] ?? 80));
        $rows = max(12, (int)($state['rows'] ?? 24));
        $boxWidth = max(38, min($cols - 4, 96));
        $contentWidth = max(20, $boxWidth - 4);
        $reservedFooter = max(0, $footerLines);
        $boxHeight = max(8, $rows - max(2, $verticalMargin) - $reservedFooter);
        $contentHeight = max(3, $boxHeight - 4);
        $leftPad = str_repeat(' ', max(0, (int)floor(($cols - $boxWidth) / 2)));
        $topPadCount = max(0, (int)floor(($rows - $boxHeight - $reservedFooter - 1) / 2));

        return [
            'boxWidth' => $boxWidth,
            'contentWidth' => $contentWidth,
            'contentHeight' => $contentHeight,
            'leftPad' => $leftPad,
            'topPad' => str_repeat("\r\n", $topPadCount),
        ];
    }

    private function renderContentLine(string $line, int $contentWidth, array $chars, array $colorScheme): string
    {
        $line = $this->server->encodeForTerminal($line);
        $visibleWidth = $this->ansiLength($line);
        if ($visibleWidth > $contentWidth) {
            $line = $this->truncateAnsiLine($line, $contentWidth);
            $visibleWidth = $this->ansiLength($line);
        }
        $padding = str_repeat(' ', max(0, $contentWidth - $visibleWidth));

        return $this->server->colorizeForTerminal($this->server->encodeForTerminal($chars['v']), $colorScheme['divider'])
            . ' '
            . $line
            . $padding
            . ' '
            . $this->server->colorizeForTerminal($this->server->encodeForTerminal($chars['v']), $colorScheme['divider']);
    }

    private function mergeColorScheme(array $colorScheme): array
    {
        return array_merge(self::SCHEME_DEFAULT, $colorScheme);
    }

    private function fitPlainText(string $text, int $width): string
    {
        return $this->truncatePlainText($this->server->encodeForTerminal($text), $width);
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

    private function writeLine($conn, string $text = ''): void
    {
        $this->server->safeWrite($conn, $text . "\r\n");
    }
}
