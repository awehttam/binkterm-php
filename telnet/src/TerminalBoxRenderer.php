<?php

namespace BinktermPHP\TelnetServer;

/**
 * Renders paged boxed screens for the terminal session.
 */
class TerminalBoxRenderer
{
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
     */
    public function showPagedBox($conn, array &$state, string $title, array $lines, string $continuePrompt, int $verticalMargin = 2): void
    {
        $cols = max(40, (int)($state['cols'] ?? 80));
        $rows = max(12, (int)($state['rows'] ?? 24));
        $boxWidth = max(38, min($cols - 4, 96));
        $contentWidth = max(20, $boxWidth - 4);
        $boxHeight = max(8, $rows - max(2, $verticalMargin));
        $contentHeight = max(3, $boxHeight - 4);
        $leftPad = str_repeat(' ', max(0, (int)floor(($cols - $boxWidth) / 2)));
        $topPadCount = max(0, (int)floor(($rows - $boxHeight - 1) / 2));
        $topPad = str_repeat("\r\n", $topPadCount);

        $pages = array_chunk($lines ?: [''], $contentHeight);
        $pageCount = count($pages);

        $chars = $this->server->getTerminalLineDrawingChars();
        $topBorder = $this->server->encodeForTerminal($chars['tl'] . str_repeat($chars['h_bold'], $boxWidth - 2) . $chars['tr']);
        $divider = $this->server->encodeForTerminal($chars['l_tee'] . str_repeat($chars['h'], $boxWidth - 2) . $chars['r_tee']);
        $bottomBorder = $this->server->encodeForTerminal($chars['bl'] . str_repeat($chars['h_bold'], $boxWidth - 2) . $chars['br']);

        foreach ($pages as $pageIndex => $pageLines) {
            $this->server->safeWrite($conn, "\033[2J\033[H");
            if ($topPad !== '') {
                $this->server->safeWrite($conn, $topPad);
            }

            $this->writeLine($conn, $leftPad . $this->server->colorizeForTerminal($topBorder, TelnetUtils::ANSI_BLUE . TelnetUtils::ANSI_BOLD));

            $pageLabel = $pageCount > 1 ? sprintf(' (%d/%d)', $pageIndex + 1, $pageCount) : '';
            $titleText = $this->fitPlainText($title . $pageLabel, $contentWidth);
            $titleInner = $this->padPlainText($titleText, $contentWidth, STR_PAD_BOTH);
            $titleLine = $this->server->colorizeForTerminal($this->server->encodeForTerminal($chars['v']), TelnetUtils::ANSI_BLUE . TelnetUtils::ANSI_BOLD)
                . $this->server->colorizeForTerminal(' ' . $titleInner . ' ', TelnetUtils::ANSI_BG_BLUE . TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD)
                . $this->server->colorizeForTerminal($this->server->encodeForTerminal($chars['v']), TelnetUtils::ANSI_BLUE . TelnetUtils::ANSI_BOLD);
            $this->writeLine($conn, $leftPad . $titleLine);
            $this->writeLine($conn, $leftPad . $this->server->colorizeForTerminal($divider, TelnetUtils::ANSI_BLUE));

            $visibleLines = 0;
            foreach ($pageLines as $line) {
                $this->writeLine($conn, $leftPad . $this->renderContentLine($line, $contentWidth, $chars));
                $visibleLines++;
            }
            while ($visibleLines < $contentHeight) {
                $this->writeLine($conn, $leftPad . $this->renderContentLine('', $contentWidth, $chars));
                $visibleLines++;
            }

            $this->writeLine($conn, $leftPad . $this->server->colorizeForTerminal($bottomBorder, TelnetUtils::ANSI_BLUE . TelnetUtils::ANSI_BOLD));
            $this->writeLine($conn, '');
            $this->writeLine($conn, $this->server->colorizeForTerminal($continuePrompt, TelnetUtils::ANSI_YELLOW));
            $this->server->readKeyWithIdleCheck($conn, $state);
        }
    }

    private function renderContentLine(string $line, int $contentWidth, array $chars): string
    {
        $line = $this->server->encodeForTerminal($line);
        $visibleWidth = $this->ansiLength($line);
        if ($visibleWidth > $contentWidth) {
            $line = $this->truncateAnsiLine($line, $contentWidth);
            $visibleWidth = $this->ansiLength($line);
        }
        $padding = str_repeat(' ', max(0, $contentWidth - $visibleWidth));

        return $this->server->colorizeForTerminal($this->server->encodeForTerminal($chars['v']), TelnetUtils::ANSI_BLUE)
            . ' '
            . $line
            . $padding
            . ' '
            . $this->server->colorizeForTerminal($this->server->encodeForTerminal($chars['v']), TelnetUtils::ANSI_BLUE);
    }

    private function fitPlainText(string $text, int $width): string
    {
        return $this->truncatePlainText($this->server->encodeForTerminal($text), $width);
    }

    private function padPlainText(string $text, int $width, int $padType): string
    {
        $textLength = strlen($text);
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
        if (strlen($text) <= $width) {
            return $text;
        }
        return substr($text, 0, $width);
    }

    private function ansiLength(string $text): int
    {
        return strlen($this->stripAnsi($text));
    }

    private function stripAnsi(string $text): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $text) ?? $text;
    }

    private function truncateAnsiLine(string $line, int $width): string
    {
        $result = '';
        $visible = 0;
        $length = strlen($line);

        for ($i = 0; $i < $length && $visible < $width; $i++) {
            if ($line[$i] === "\033" && preg_match('/\G\033\[[0-9;]*m/', $line, $match, 0, $i)) {
                $result .= $match[0];
                $i += strlen($match[0]) - 1;
                continue;
            }

            $result .= $line[$i];
            $visible++;
        }

        return $result . "\033[0m";
    }

    private function writeLine($conn, string $text = ''): void
    {
        $this->server->safeWrite($conn, $text . "\r\n");
    }
}
