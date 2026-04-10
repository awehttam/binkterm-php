<?php

namespace BinktermPHP\TelnetServer;

/**
 * Renders markup-formatted message bodies (LSC-001) for ANSI terminal display.
 *
 * Supported formats:
 *   markdown   - Subset of CommonMark/Markdown
 *   stylecodes - Synchronet StyleCodes (MARKUP: StyleCodes 1.0)
 *
 * Returns an array of strings suitable for terminal display, with ANSI escape
 * sequences for formatting. Each string represents one screen line. Lines are
 * wrapped to $width before ANSI formatting is applied, so escape sequences do
 * not interfere with wordwrap() width calculations.
 *
 * Quoted lines (starting with "> ") are treated as plain text per the LSC-001
 * recommendation that quoted content degrade gracefully.
 */
class TerminalMarkupRenderer
{
    // ANSI codes used for markup rendering
    private const R    = "\033[0m";    // reset (used at line ends only)
    private const BOLD = "\033[1m";
    private const BOLD_OFF = "\033[22m"; // bold off (avoids mid-line global reset)
    private const DIM  = "\033[2m";
    private const ITAL = "\033[4m";    // italic fallback: underline
    private const UL   = "\033[4m";    // underline
    private const UL_OFF = "\033[24m"; // underline off (avoids mid-line global reset)
    private const REV  = "\033[7m";
    private const REV_OFF = "\033[27m"; // reverse off
    private const CYN  = "\033[36m";
    private const YEL  = "\033[33m";
    private const GRN  = "\033[32m";
    private const MAG  = "\033[35m";

    /**
     * Render a markup-formatted message body for terminal display.
     *
     * @param string $format   Markup format identifier (e.g. 'markdown', 'stylecodes')
     * @param string $text     Raw message body (may contain kludge lines)
     * @param int    $width    Terminal column width for line wrapping
     * @return string[]        Array of ANSI-formatted lines
     */
    public static function render(string $format, string $text, int $width): array
    {
        $clean = self::stripKludgeLines($text);

        return match (strtolower($format)) {
            'markdown'   => self::renderMarkdown($clean, $width),
            'stylecodes' => self::renderStyleCodes($clean, $width),
            default      => TelnetUtils::wrapTextLines($clean, $width),
        };
    }

    /**
     * Extract and format kludge lines from a raw message body for display.
     *
     * Returns each kludge line with the SOH byte stripped and the kludge name
     * colorized in yellow for easy reading.
     *
     * @param string $text Raw message body (may contain kludge lines)
     * @return string[]    Array of ANSI-formatted kludge lines, one per kludge
     */
    public static function extractKludgeLines(string $text): array
    {
        $lines  = preg_split('/\r\n|\r|\n/', $text);
        $result = [];
        foreach ($lines as $line) {
            if (strlen($line) === 0 || ord($line[0]) !== 0x01) {
                continue;
            }
            $content = substr($line, 1); // strip SOH
            // Colorize the kludge keyword (before first ':' or first space)
            if (preg_match('/^([A-Za-z0-9_@]+)(:.*)$/s', $content, $m)) {
                $result[] = self::YEL . $m[1] . self::R . $m[2];
            } elseif (preg_match('/^([A-Za-z0-9_@]+)(\s.+)$/s', $content, $m)) {
                $result[] = self::YEL . $m[1] . self::R . $m[2];
            } else {
                $result[] = $content;
            }
        }
        return $result;
    }

    /**
     * Strip SOH-prefixed kludge lines from message text, preserving blank lines.
     *
     * @param string $text Raw message body
     * @return string Cleaned message body
     */
    private static function stripKludgeLines(string $text): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $out   = [];
        $firstLine = true;
        foreach ($lines as $line) {
            if ($firstLine) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
                $firstLine = false;
            }
            if (strlen($line) > 0 && ord($line[0]) === 0x01) {
                continue;
            }
            $out[] = $line;
        }
        // Remove leading blank lines left by stripped kludges
        while (!empty($out) && trim($out[0]) === '') {
            array_shift($out);
        }
        return implode("\n", $out);
    }

    // -------------------------------------------------------------------------
    // Markdown renderer
    // -------------------------------------------------------------------------

    /**
     * Render Markdown body to ANSI terminal lines.
     *
     * @param string $text  Kludge-stripped message body
     * @param int    $width Terminal column width
     * @return string[]
     */
    private static function renderMarkdown(string $text, int $width): array
    {
        $lines  = preg_split('/\r?\n/', $text);
        $output = [];
        $i      = 0;
        $total  = count($lines);

        while ($i < $total) {
            $line = $lines[$i];

            // Fenced code block
            if (preg_match('/^```/', $line)) {
                if (!self::hasClosingFenceAhead($lines, $i + 1, $total)) {
                    $output[] = self::inlineMarkdown($line);
                    $i++;
                    continue;
                }

                $i++;
                while ($i < $total && !preg_match('/^```$/', $lines[$i])) {
                    $codeLine = $lines[$i];
                    // Clip code lines to width rather than word-wrap
                    if (mb_strlen($codeLine) > $width) {
                        $codeLine = mb_substr($codeLine, 0, $width);
                    }
                    $output[] = self::YEL . '  ' . $codeLine . self::R;
                    $i++;
                }
                $i++; // closing ```
                $output[] = '';
                continue;
            }

            // Horizontal rule
            if (preg_match('/^---+\s*$/', $line)) {
                $output[] = self::DIM . str_repeat('-', $width) . self::R;
                $i++;
                continue;
            }

            // ATX Heading
            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
                $level   = strlen($m[1]);
                $content = $m[2];
                if ($level === 1) {
                    $formatted = self::BOLD . self::CYN . strtoupper($content) . self::R;
                } elseif ($level === 2) {
                    $formatted = self::BOLD . $content . self::R;
                } else {
                    $formatted = self::CYN . $content . self::R;
                }
                $output[] = '';
                $output[] = $formatted;
                if ($level <= 2) {
                    $underlineChar = $level === 1 ? '=' : '-';
                    $output[] = self::DIM . str_repeat($underlineChar, min($width, self::textLength($content))) . self::R;
                }
                $output[] = '';
                $i++;
                continue;
            }

            // Block quote (FidoNet-style "> " quoted text — plain DIM, no inline markup)
            if (
                preg_match('/^\|.+\|/', $line) &&
                isset($lines[$i + 1]) &&
                preg_match('/^\|[\s\-:|]+\|/', $lines[$i + 1])
            ) {
                $headers = self::parseTableRow($line);
                $rows = [];
                $i += 2;

                while ($i < $total && preg_match('/^\|.+\|/', $lines[$i])) {
                    $rows[] = self::parseTableRow($lines[$i]);
                    $i++;
                }

                foreach (self::renderMarkdownTable($headers, $rows, $width) as $tableLine) {
                    $output[] = $tableLine;
                }
                $output[] = '';
                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $line, $m)) {
                $quoteLines = [$m[1]];
                $i++;
                while ($i < $total && preg_match('/^>\s?(.*)$/', $lines[$i], $qm)) {
                    $quoteLines[] = $qm[1];
                    $i++;
                }
                foreach ($quoteLines as $ql) {
                    $wrapped = self::wrapRaw($ql, max(4, $width - 2));
                    foreach ($wrapped as $wl) {
                        $output[] = self::GRN . '| ' . self::R . self::DIM . $wl . self::R;
                    }
                }
                $output[] = '';
                continue;
            }

            // Unordered list
            if (preg_match('/^[-*]\s+(.+)$/', $line, $m)) {
                while ($i < $total && preg_match('/^[-*]\s+(.+)$/', $lines[$i], $lm)) {
                    $itemText    = self::expandMarkdownLinks($lm[1]);
                    $continuation = $width - 2;
                    $wrapped      = self::wrapRaw($itemText, max(4, $continuation));
                    $first        = true;
                    foreach ($wrapped as $wl) {
                        $prefix   = $first ? self::BOLD . '* ' . self::R : '  ';
                        $output[] = $prefix . self::inlineMarkdown($wl);
                        $first    = false;
                    }
                    $i++;
                }
                $output[] = '';
                continue;
            }

            // Blank line
            if (trim($line) === '') {
                $output[] = '';
                $i++;
                continue;
            }

            // Paragraph: collect consecutive non-special lines
            $para = [];
            while (
                $i < $total &&
                trim($lines[$i]) !== '' &&
                !preg_match('/^(#{1,6}\s|```|---+\s*$|[-*]\s|\>|^\|.+\|)/', $lines[$i])
            ) {
                $para[] = $lines[$i];
                $i++;
            }
            if ($para) {
                $joined  = self::expandMarkdownLinks(implode(' ', $para));
                $wrapped = self::wrapRaw($joined, $width);
                foreach ($wrapped as $wl) {
                    $output[] = self::inlineMarkdown($wl);
                }
                $output[] = '';
            }
        }

        // Collapse consecutive blank lines into one
        $collapsed = [];
        $prevBlank = false;
        foreach ($output as $line) {
            $isBlank = trim(self::stripAnsi($line)) === '';
            if ($isBlank && $prevBlank) {
                continue;
            }
            $collapsed[] = $line;
            $prevBlank   = $isBlank;
        }

        // Trim trailing blank lines
        while (!empty($collapsed) && trim(self::stripAnsi($collapsed[count($collapsed) - 1])) === '') {
            array_pop($collapsed);
        }

        return $collapsed ?: [''];
    }

    /**
     * Apply inline Markdown ANSI formatting to a single already-wrapped line.
     *
     * @param string $text Raw text (no kludges, already wrapped to width)
     * @return string ANSI-formatted line
     */
    private static function inlineMarkdown(string $text): string
    {
        // Protect inline code spans first
        $codeMap = [];
        $text = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codeMap) {
            $token           = "\x00CODE" . count($codeMap) . "\x00";
            $codeMap[$token] = self::YEL . $m[1] . self::R;
            return $token;
        }, $text);

        // Bold (**text** or __text__)
        $text = preg_replace('/\*\*(.+?)\*\*/', self::BOLD . '$1' . self::BOLD_OFF, $text);
        $text = preg_replace('/__(.+?)__/',     self::BOLD . '$1' . self::BOLD_OFF, $text);

        // Strikethrough (~~text~~ → -text-)
        $text = preg_replace('/~~(.+?)~~/', '-$1-', $text);

        // Italic (*text* or _text_)
        $text = preg_replace('/\*([^*]+)\*/',   self::ITAL . '$1' . self::UL_OFF, $text);
        $text = preg_replace('/_([^_]+)_/',     self::ITAL . '$1' . self::UL_OFF, $text);

        // Links — show label and URL
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\)]+)\)/',
            fn($m) => self::UL . $m[1] . self::UL_OFF . ' (' . $m[2] . ')',
            $text
        );

        // Restore code spans
        if (!empty($codeMap)) {
            $text = strtr($text, $codeMap);
        }

        return $text;
    }

    /**
     * Expand markdown links before wrapping so long links do not get split
     * before inline markdown conversion runs.
     */
    private static function expandMarkdownLinks(string $text): string
    {
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\)]+)\)/',
            static fn($m) => $m[1] . ' (' . $m[2] . ')',
            $text
        );

        $text = preg_replace('/<((?:https?:\/\/)[^>]+)>/', '$1', $text);

        return $text;
    }

    /**
     * Render a markdown table as an ASCII table sized to the terminal width.
     *
     * @param string[] $headers
     * @param string[][] $rows
     * @param int $width
     * @return string[]
     */
    private static function renderMarkdownTable(array $headers, array $rows, int $width): array
    {
        if (empty($headers)) {
            return [];
        }

        $columnCount = count($headers);
        $headers = self::normalizeTableRow($headers, $columnCount);
        $rows = array_map(
            static fn(array $row) => self::normalizeTableRow($row, $columnCount),
            $rows
        );

        $widths = array_fill(0, $columnCount, 3);
        foreach (array_merge([$headers], $rows) as $row) {
            foreach ($row as $index => $cell) {
                $widths[$index] = max($widths[$index], self::textLength(trim((string) $cell)));
            }
        }

        $borderOverhead = ($columnCount * 3) + 1;
        $availableCellWidth = max($columnCount * 3, max(10, $width) - $borderOverhead);
        while (array_sum($widths) > $availableCellWidth) {
            $largestIndex = array_keys($widths, max($widths), true)[0];
            if ($widths[$largestIndex] <= 3) {
                break;
            }
            $widths[$largestIndex]--;
        }

        $output = [];
        $border = self::DIM . self::buildTableBorder($widths) . self::R;
        $output[] = $border;
        foreach (self::renderTableLogicalRow($headers, $widths, true) as $line) {
            $output[] = $line;
        }
        $output[] = $border;
        foreach ($rows as $row) {
            foreach (self::renderTableLogicalRow($row, $widths, false) as $line) {
                $output[] = $line;
            }
        }
        $output[] = $border;

        return $output;
    }

    // -------------------------------------------------------------------------
    // StyleCodes renderer
    // -------------------------------------------------------------------------

    /**
     * Render StyleCodes body to ANSI terminal lines.
     *
     * @param string $text  Kludge-stripped message body
     * @param int    $width Terminal column width
     * @return string[]
     */
    private static function renderStyleCodes(string $text, int $width): array
    {
        $lines  = preg_split('/\r?\n/', $text);
        $output = [];

        foreach ($lines as $line) {
            // FidoNet-style quoted lines — treat as plain text
            if (preg_match('/^>/', $line)) {
                $wrapped = self::wrapRaw($line, $width);
                foreach ($wrapped as $wl) {
                    $output[] = self::DIM . $wl . self::R;
                }
                continue;
            }

            $wrapped = self::wrapRaw($line, $width);
            foreach ($wrapped as $wl) {
                $output[] = self::inlineStyleCodes($wl);
            }
        }

        return $output ?: [''];
    }

    /**
     * Apply StyleCodes inline ANSI formatting to a single already-wrapped line.
     *
     * @param string $line Raw line
     * @return string ANSI-formatted line
     */
    private static function inlineStyleCodes(string $line): string
    {
        // *bold*
        $line = preg_replace('/\*([^*\r\n]+)\*/', self::BOLD . '$1' . self::BOLD_OFF, $line);

        // /italics/ — exclude slashes adjacent to colons (URLs)
        $line = preg_replace('/(?<![:\w])\/([^\/\r\n]+)\/(?![\w])/', self::ITAL . '$1' . self::UL_OFF, $line);

        // _underlined_
        $line = preg_replace('/_([^_\r\n]+)_/', self::UL . '$1' . self::UL_OFF, $line);

        // #inverse#
        $line = preg_replace('/#([^#\r\n]+)#/', self::REV . '$1' . self::REV_OFF, $line);

        return $line;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Word-wrap a plain-text string to $width and return the resulting lines.
     * ANSI codes must NOT be present in $text when calling this.
     *
     * @param string $text  Plain text to wrap
     * @param int    $width Maximum visible width
     * @return string[]
     */
    private static function wrapRaw(string $text, int $width): array
    {
        if ($text === '') {
            return [''];
        }
        $wrapped = wordwrap($text, max(1, $width), "\n", true);
        return explode("\n", $wrapped);
    }

    /**
     * Strip ANSI escape sequences from a string (for length measurement).
     *
     * @param string $text Text possibly containing ANSI codes
     * @return string Plain text
     */
    private static function stripAnsi(string $text): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $text);
    }

    /**
     * Split a markdown table row into trimmed cell strings.
     *
     * @param string $row
     * @return string[]
     */
    private static function parseTableRow(string $row): array
    {
        $row = trim($row, " \t|");
        $cells = explode('|', $row);
        return array_map('trim', $cells);
    }

    /**
     * Normalize a parsed table row to the required column count.
     *
     * @param string[] $row
     * @param int $columnCount
     * @return string[]
     */
    private static function normalizeTableRow(array $row, int $columnCount): array
    {
        $row = array_slice(array_values($row), 0, $columnCount);
        while (count($row) < $columnCount) {
            $row[] = '';
        }
        return $row;
    }

    /**
     * Render a logical table row into one or more physical terminal lines.
     *
     * @param string[] $row
     * @param int[] $widths
     * @param bool $header
     * @return string[]
     */
    private static function renderTableLogicalRow(array $row, array $widths, bool $header): array
    {
        $wrappedCells = [];
        $lineCount = 1;

        foreach ($widths as $index => $colWidth) {
            $cellLines = self::wrapRaw((string) ($row[$index] ?? ''), max(1, $colWidth));
            $wrappedCells[$index] = $cellLines;
            $lineCount = max($lineCount, count($cellLines));
        }

        $lines = [];
        for ($lineIndex = 0; $lineIndex < $lineCount; $lineIndex++) {
            $parts = ['|'];
            foreach ($widths as $index => $colWidth) {
                $rawCell = $wrappedCells[$index][$lineIndex] ?? '';
                $formatted = self::inlineMarkdown($rawCell);
                if ($header) {
                    $formatted = self::BOLD . $formatted . self::BOLD_OFF;
                }
                $parts[] = ' ' . self::padAnsiRight($formatted, $colWidth) . ' |';
            }
            $lines[] = implode('', $parts);
        }

        return $lines;
    }

    /**
     * Build a +----+ table border line.
     *
     * @param int[] $widths
     * @return string
     */
    private static function buildTableBorder(array $widths): string
    {
        $parts = ['+'];
        foreach ($widths as $width) {
            $parts[] = str_repeat('-', $width + 2) . '+';
        }
        return implode('', $parts);
    }

    /**
     * Pad an ANSI-formatted string to a target visible width.
     *
     * @param string $text
     * @param int $width
     * @return string
     */
    private static function padAnsiRight(string $text, int $width): string
    {
        $visible = self::textLength(self::stripAnsi($text));
        if ($visible >= $width) {
            return $text;
        }
        return $text . str_repeat(' ', $width - $visible);
    }

    /**
     * Return visible text length for terminal layout.
     *
     * @param string $text
     * @return int
     */
    private static function textLength(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }

    /**
     * Check whether a fenced code block has a closing fence later in this parse chunk.
     *
     * @param string[] $lines
     * @param int $start
     * @param int $total
     * @return bool
     */
    private static function hasClosingFenceAhead(array $lines, int $start, int $total): bool
    {
        for ($j = $start; $j < $total; $j++) {
            if (preg_match('/^```$/', $lines[$j])) {
                return true;
            }
        }

        return false;
    }
}
