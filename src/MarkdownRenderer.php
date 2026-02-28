<?php

namespace BinktermPHP;

/**
 * Minimal Markdown-to-HTML renderer covering the subset used in UPGRADING docs:
 * headings, bold, inline code, fenced code blocks, horizontal rules,
 * unordered lists, GFM-style tables, and paragraphs.
 */
class MarkdownRenderer
{
    /**
     * Convert a Markdown string to an HTML string.
     *
     * @param string $markdown Raw Markdown content
     * @return string HTML output
     */
    public static function toHtml(string $markdown): string
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

            // --- Unordered list ---
            if (preg_match('/^[-*]\s+(.+)$/', $line, $m)) {
                $items = [];
                while ($i < $total && preg_match('/^[-*]\s+(.+)$/', $lines[$i], $lm)) {
                    $items[] = '<li>' . self::inlineHtml($lm[1]) . '</li>';
                    $i++;
                }
                $output[] = '<ul>' . implode('', $items) . '</ul>';
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
                !preg_match('/^(#{1,6}\s|```|---+\s*$|[-*]\s|\|)/', $lines[$i])
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

        // Inline code  (`...`)
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);

        // Bold (**...** or __...__)
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $text);

        // Italic (*...* or _..._) — single delimiters only
        $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/_([^_]+)_/', '<em>$1</em>', $text);

        // Markdown links [label](url) — absolute (https?://) or root-relative (/)
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(((?:https?:\/\/|\/)[^\)]+)\)/',
            function ($m) {
                $label = $m[1]; // already htmlspecialchars-encoded
                $url   = $m[2];
                $isExternal = str_starts_with($url, 'http');
                $extra = $isExternal ? ' target="_blank" rel="noopener"' : '';
                return '<a href="' . $url . '"' . $extra . '>' . $label . '</a>';
            },
            $text
        );

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
}
