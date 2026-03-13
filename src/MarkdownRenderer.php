<?php

namespace BinktermPHP;

/**
 * Minimal Markdown-to-HTML renderer covering the subset used in UPGRADING docs:
 * headings, bold, inline code, fenced code blocks, horizontal rules,
 * block quotes, unordered lists, GFM-style tables, and paragraphs.
 */
class MarkdownRenderer
{
    private const MAX_BLOCKQUOTE_DEPTH = 8;

    /**
     * Convert a Markdown string to an HTML string.
     *
     * @param string $markdown Raw Markdown content
     * @param int $blockquoteDepth Current recursive blockquote depth
     * @return string HTML output
     */
    public static function toHtml(string $markdown, int $blockquoteDepth = 0): string
    {
        // Normalise line endings
        $text = str_replace(["\r\n", "\r"], "\n", $markdown);

        $lines  = explode("\n", $text);
        $output = [];
        $i      = 0;
        $total  = count($lines);

        while ($i < $total) {
            $line = $lines[$i];

            // --- Fenced code block ---
            if (preg_match('/^```(.*)$/', $line, $m)) {
                if (!self::hasClosingFenceAhead($lines, $i + 1, $total)) {
                    $output[] = '<p>' . self::inlineHtml($line) . '</p>';
                    $i++;
                    continue;
                }

                $lang  = trim($m[1]);
                $code  = [];
                $i++;
                while ($i < $total && !preg_match('/^```$/', $lines[$i])) {
                    $code[] = htmlspecialchars($lines[$i], ENT_QUOTES, 'UTF-8');
                    $i++;
                }
                $langAttr = $lang ? ' class="language-' . htmlspecialchars($lang) . '"' : '';
                $output[] = '<pre><code' . $langAttr . '>' . implode("\n", $code) . '</code></pre>';
                $i++;
                continue;
            }

            // --- Horizontal rule ---
            if (preg_match('/^---+\s*$/', $line)) {
                $output[] = '<hr>';
                $i++;
                continue;
            }

            // --- ATX Headings ---
            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
                $level    = strlen($m[1]);
                $content  = self::inlineHtml($m[2]);
                $output[] = "<h{$level}>{$content}</h{$level}>";
                $i++;
                continue;
            }

            // --- GFM table (header row followed by separator) ---
            if (
                preg_match('/^\|.+\|/', $line) &&
                isset($lines[$i + 1]) &&
                preg_match('/^\|[\s\-:|]+\|/', $lines[$i + 1])
            ) {
                $headers  = self::parseTableRow($line);
                $i += 2; // skip separator
                $thead    = '<thead><tr>' .
                    implode('', array_map(fn($h) => '<th>' . self::inlineHtml($h) . '</th>', $headers)) .
                    '</tr></thead>';
                $tbody    = '<tbody>';
                while ($i < $total && preg_match('/^\|.+\|/', $lines[$i])) {
                    $cells  = self::parseTableRow($lines[$i]);
                    $tbody .= '<tr>' .
                        implode('', array_map(fn($c) => '<td>' . self::inlineHtml($c) . '</td>', $cells)) .
                        '</tr>';
                    $i++;
                }
                $tbody   .= '</tbody>';
                $output[] = '<table class="table table-bordered table-sm">' . $thead . $tbody . '</table>';
                continue;
            }

            // --- Pipe-delimited text that is not a valid table ---
            // Treat it as plain text so malformed tables cannot stall the parser.
            if (preg_match('/^\|.+\|/', $line)) {
                $output[] = '<p>' . self::inlineHtml($line) . '</p>';
                $i++;
                continue;
            }

            // --- Block quote ---
            if (preg_match('/^>\s?(.*)$/', $line, $m)) {
                $quoteLines = [$m[1]];
                $i++;
                while ($i < $total && preg_match('/^>\s?(.*)$/', $lines[$i], $qm)) {
                    $quoteLines[] = $qm[1];
                    $i++;
                }

                if ($blockquoteDepth >= self::MAX_BLOCKQUOTE_DEPTH) {
                    $escapedLines = array_map(
                        static fn(string $quoteLine): string => htmlspecialchars($quoteLine, ENT_QUOTES, 'UTF-8'),
                        $quoteLines
                    );
                    $inner = '<p>' . implode('<br>', $escapedLines) . '</p>';
                } else {
                    $inner = self::toHtml(implode("\n", $quoteLines), $blockquoteDepth + 1);
                }

                $output[] = '<blockquote class="border-start border-3 ps-3 text-muted">' . $inner . '</blockquote>';
                continue;
            }

            // --- Unordered list (supports nested indented sub-lists) ---
            if (preg_match('/^(\s*)[-*]\s+(.+)$/', $line, $m)) {
                $output[] = self::parseUnorderedList($lines, $i, $total, strlen($m[1]));
                continue;
            }

            // --- Blank line ---
            if (trim($line) === '') {
                $i++;
                continue;
            }

            // --- Paragraph: collect consecutive non-blank, non-special lines ---
            $para = [];
            while (
                $i < $total &&
                trim($lines[$i]) !== '' &&
                !preg_match('/^(#{1,6}\s|```|---+\s*$|[-*]\s|\||>)/', $lines[$i])
            ) {
                $para[] = $lines[$i];
                $i++;
            }
            if ($para) {
                // Join lines first so inline links that span a soft wrap are
                // processed as a single string rather than split across calls.
                $output[] = '<p>' . self::inlineHtml(implode(' ', $para)) . '</p>';
            }
        }

        return implode("\n", $output);
    }

    /**
     * Apply inline Markdown transformations: bold, inline code, links.
     *
     * @param string $text Raw inline Markdown
     * @return string HTML string
     */
    private static function inlineHtml(string $text): string
    {
        // Escape HTML first (except we handle < > carefully)
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Protect inline code spans from later emphasis/link parsing.
        $codeSpans = [];
        $text = preg_replace_callback(
            '/`([^`]+)`/',
            function ($matches) use (&$codeSpans) {
                $token = '%%CODE' . count($codeSpans) . '%%';
                $codeSpans[$token] = '<code>' . $matches[1] . '</code>';
                return $token;
            },
            $text
        );

        // Protect markdown links before emphasis parsing so underscores in URLs
        // are not interpreted as italics.
        $links = [];
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(((?:https?:\/\/|\/)[^\)]+)\)/',
            function ($m) use (&$links) {
                $label = $m[1]; // already htmlspecialchars-encoded
                $url   = $m[2];
                $isExternal = str_starts_with($url, 'http');
                $extra = $isExternal ? ' target="_blank" rel="noopener"' : '';
                $token = '%%LINK' . count($links) . '%%';
                $links[$token] = '<a href="' . $url . '"' . $extra . '>' . $label . '</a>';
                return $token;
            },
            $text
        );

        // Bold (**...** or __...__)
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $text);

        // Strikethrough (~~...~~)
        $text = preg_replace('/~~(.+?)~~/', '<del>$1</del>', $text);

        // Italic (*...* or _..._) — single delimiters only
        $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/_([^_]+)_/', '<em>$1</em>', $text);

        if (!empty($codeSpans)) {
            $text = strtr($text, $codeSpans);
        }
        if (!empty($links)) {
            $text = strtr($text, $links);
        }

        return $text;
    }

    /**
     * Split a GFM table row into trimmed cell strings.
     *
     * @param string $row Raw table row (e.g. "| foo | bar |")
     * @return string[]
     */
    private static function parseTableRow(string $row): array
    {
        $row   = trim($row, " \t|");
        $cells = explode('|', $row);
        return array_map('trim', $cells);
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

    /**
     * Parse an unordered list block at the given indentation level.
     *
     * @param string[] $lines
     * @param int      $i Current line index (advanced by reference)
     * @param int      $total Total line count
     * @param int      $baseIndent Indentation level for this list
     * @return string
     */
    private static function parseUnorderedList(array $lines, int &$i, int $total, int $baseIndent): string
    {
        $items = [];

        while ($i < $total) {
            if (!preg_match('/^(\s*)[-*]\s+(.+)$/', $lines[$i], $itemMatch)) {
                break;
            }

            $itemIndent = strlen($itemMatch[1]);
            if ($itemIndent < $baseIndent) {
                break;
            }
            if ($itemIndent > $baseIndent) {
                // Deeper indentation belongs to a nested list under the previous item.
                break;
            }

            $itemText  = [$itemMatch[2]];
            $itemInner = '';
            $i++;

            while ($i < $total) {
                $current = $lines[$i];
                if (trim($current) === '') {
                    break;
                }

                if (preg_match('/^(\s*)[-*]\s+(.+)$/', $current, $nestedMatch)) {
                    $nestedIndent = strlen($nestedMatch[1]);
                    if ($nestedIndent > $baseIndent) {
                        $itemInner .= self::parseUnorderedList($lines, $i, $total, $nestedIndent);
                        continue;
                    }
                    if ($nestedIndent === $baseIndent) {
                        break;
                    }
                    break;
                }

                if (preg_match('/^\s+(.+)$/', $current, $continuation)) {
                    $itemText[] = trim($continuation[1]);
                    $i++;
                    continue;
                }

                break;
            }

            $li = '<li>' . self::inlineHtml(implode(' ', $itemText)) . $itemInner . '</li>';
            $items[] = $li;
        }

        return '<ul>' . implode('', $items) . '</ul>';
    }
}

