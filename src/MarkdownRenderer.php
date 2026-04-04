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
     * @param bool $allowHtml When true, raw HTML blocks and inline tags are passed through
     *                        unescaped. Must only be enabled for trusted content (e.g. README.md).
     * @return string HTML output
     */
    public static function toHtml(string $markdown, int $blockquoteDepth = 0, bool $allowHtml = false): string
    {
        // Normalise line endings
        $text = str_replace(["\r\n", "\r"], "\n", $markdown);

        // Normalise non-breaking space (U+00A0, UTF-8: 0xC2 0xA0) to regular space.
        // Copy-paste from browsers and some editors substitutes NBSP for ASCII space,
        // which breaks list detection because PHP's \s does not match NBSP without /u.
        $text = str_replace("\xc2\xa0", ' ', $text);

        $lines  = explode("\n", $text);
        $output = [];
        $i      = 0;
        $total  = count($lines);

        while ($i < $total) {
            $line = $lines[$i];

            // --- Raw HTML block (only when allowHtml is explicitly enabled) ---
            if ($allowHtml && preg_match('/^<[a-zA-Z\/!]/', $line)) {
                $htmlBlock = [];
                while ($i < $total && trim($lines[$i]) !== '') {
                    $htmlBlock[] = $lines[$i];
                    $i++;
                }
                $output[] = implode("\n", $htmlBlock);
                continue;
            }

            // --- Fenced code block ---
            if (preg_match('/^```(.*)$/', $line, $m)) {
                if (!self::hasClosingFenceAhead($lines, $i + 1, $total)) {
                    $output[] = '<p>' . self::inlineHtml($line, $allowHtml) . '</p>';
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
                $content  = self::inlineHtml($m[2], $allowHtml);
                $slug     = self::slugify($m[2]);
                $output[] = "<h{$level} id=\"{$slug}\">{$content}</h{$level}>";
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
                    implode('', array_map(fn($h) => '<th>' . self::inlineHtml($h, $allowHtml) . '</th>', $headers)) .
                    '</tr></thead>';
                $tbody    = '<tbody>';
                while ($i < $total && preg_match('/^\|.+\|/', $lines[$i])) {
                    $cells  = self::parseTableRow($lines[$i]);
                    $tbody .= '<tr>' .
                        implode('', array_map(fn($c) => '<td>' . self::inlineHtml($c, $allowHtml) . '</td>', $cells)) .
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
                $output[] = '<p>' . self::inlineHtml($line, $allowHtml) . '</p>';
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
                    $inner = self::toHtml(implode("\n", $quoteLines), $blockquoteDepth + 1, $allowHtml);
                }

                $output[] = '<blockquote class="border-start border-3 ps-3 text-muted">' . $inner . '</blockquote>';
                continue;
            }

            // --- Ordered list ---
            if (preg_match('/^(\s*)\d+[.)]\s+(.*)$/', $line, $m)) {
                $output[] = self::parseOrderedList($lines, $i, $total, strlen($m[1]), $allowHtml);
                continue;
            }

            // --- Unordered list (supports nested indented sub-lists) ---
            if (preg_match('/^(\s*)[-*]\s+(.*)$/', $line, $m)) {
                $output[] = self::parseUnorderedList($lines, $i, $total, strlen($m[1]), $allowHtml);
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
                !preg_match('/^(#{1,6}\s|```|---+\s*$|[-*]\s|\d+[.)]\s|\||>)/', $lines[$i])
            ) {
                $para[] = $lines[$i];
                $i++;
            }
            if ($para) {
                // Join lines first so inline links that span a soft wrap are
                // processed as a single string rather than split across calls.
                $output[] = '<p>' . self::inlineHtml(implode(' ', $para), $allowHtml) . '</p>';
                continue;
            }

            // Fallback: consume any otherwise-unhandled line so malformed
            // markdown cannot trap the parser in a non-advancing loop.
            $output[] = '<p>' . self::inlineHtml($line, $allowHtml) . '</p>';
            $i++;
        }

        return implode("\n", $output);
    }

    /**
     * Convert a heading string to a GitHub-style anchor slug.
     *
     * @param string $text Raw heading text (before HTML encoding)
     * @return string Slugified anchor id
     */
    private static function slugify(string $text): string
    {
        // Strip inline markdown (backticks, asterisks, etc.)
        $text = preg_replace('/[`*_~\[\]()]/', '', $text);
        $text = strtolower($text);
        $text = preg_replace('/[^\w\s-]/', '', $text);
        $text = preg_replace('/[\s]+/', '-', trim($text));
        return $text;
    }

    /**
     * Apply inline Markdown transformations: bold, inline code, links.
     *
     * @param string $text Raw inline Markdown
     * @param bool $allowHtml When true, inline HTML tags are passed through unescaped
     * @return string HTML string
     */
    private static function inlineHtml(string $text, bool $allowHtml = false): string
    {
        // When HTML is allowed, extract and protect inline tags before escaping so
        // they survive htmlspecialchars() and are restored verbatim at the end.
        $htmlTags = [];
        if ($allowHtml) {
            $text = preg_replace_callback(
                '/<(?:[a-zA-Z][a-zA-Z0-9]*(?:\s[^>]*)?\/?>|\/[a-zA-Z][a-zA-Z0-9]*>|!--.*?-->)/',
                function (array $m) use (&$htmlTags): string {
                    $token = '%%HTAG' . count($htmlTags) . '%%';
                    $htmlTags[$token] = $m[0];
                    return $token;
                },
                $text
            );
        }

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

        // Protect markdown links and images before emphasis parsing so underscores
        // in URLs are not interpreted as italics.
        // Image syntax ![alt](url) is matched by the optional leading `!`.
        $links = [];
        $text = preg_replace_callback(
            '/(!?)\[([^\]]+)\]\(((?:https?:\/\/|\/|#)[^\)]+)\)/',
            function ($m) use (&$links, &$codeSpans) {
                $isImage = $m[1] === '!';
                // Restore any inline-code tokens that appear inside the label
                // (e.g. [The `foo` Table](#anchor)) so they render as <code> not %%CODE0%%.
                $label = !empty($codeSpans) ? strtr($m[2], $codeSpans) : $m[2];
                $url   = $m[3];
                $token = '%%LINK' . count($links) . '%%';

                if ($isImage) {
                    // Render as a click-to-load placeholder. The user's browser will
                    // not auto-fetch the remote image; clicking reveals it inline.
                    $links[$token] = '<span class="md-image-placeholder" data-src="' . $url . '" data-alt="' . $label . '">'
                        . '<a href="' . $url . '" class="md-image-load" target="_blank" rel="noopener noreferrer">'
                        . '<i class="fas fa-image"></i> ' . $label
                        . '</a></span>';
                } else {
                    $isExternal = str_starts_with($url, 'http');
                    $extra = $isExternal ? ' target="_blank" rel="noopener"' : '';
                    $links[$token] = '<a href="' . $url . '"' . $extra . '>' . $label . '</a>';
                }

                return $token;
            },
            $text
        );

        // Protect bare URLs (not already inside code spans or markdown link tokens).
        // After htmlspecialchars, & is encoded as &amp; so we match &amp; as a unit
        // to allow query strings to link correctly.
        $bareUrls = [];
        $text = preg_replace_callback(
            '/\b(https?:\/\/(?:[^\s<>"\'&]|&amp;)+)/',
            function ($m) use (&$bareUrls) {
                $url = $m[1];
                // Strip trailing punctuation that is likely not part of the URL
                $trailing = '';
                if (preg_match('/([.,;:!?)]+)$/', $url, $tm)) {
                    $trailing = $tm[1];
                    $url = substr($url, 0, -strlen($trailing));
                }
                $token = '%%BURL' . count($bareUrls) . '%%';
                $bareUrls[$token] = '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>';
                return $token . $trailing;
            },
            $text
        );

        // Bold (**...** or __...__)
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $text);

        // Strikethrough (~~...~~)
        $text = preg_replace('/~~(.+?)~~/', '<del>$1</del>', $text);

        // Italic (*...* or _..._) — single delimiters only.
        // For underscore, require non-word character (or start/end) on both
        // flanking sides so that SNAKE_CASE_WORDS are never italicised.
        $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/(?<!\w)_([^_]+)_(?!\w)/', '<em>$1</em>', $text);

        if (!empty($codeSpans)) {
            $text = strtr($text, $codeSpans);
        }
        if (!empty($bareUrls)) {
            $text = strtr($text, $bareUrls);
        }
        if (!empty($links)) {
            $text = strtr($text, $links);
        }
        if (!empty($htmlTags)) {
            $text = strtr($text, $htmlTags);
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
     * Parse an ordered list block at the given indentation level.
     *
     * @param string[] $lines
     * @param int      $i Current line index (advanced by reference)
     * @param int      $total Total line count
     * @param int      $baseIndent Indentation level for this list
     * @param bool     $allowHtml Passed through to inlineHtml()
     * @return string
     */
    private static function parseOrderedList(array $lines, int &$i, int $total, int $baseIndent, bool $allowHtml = false): string
    {
        $items = [];

        while ($i < $total) {
            if (!preg_match('/^(\s*)\d+[.)]\s+(.*)$/', $lines[$i], $itemMatch)) {
                break;
            }

            $itemIndent = strlen($itemMatch[1]);
            if ($itemIndent < $baseIndent) {
                break;
            }
            if ($itemIndent > $baseIndent) {
                break;
            }

            $itemText = [$itemMatch[2]];
            $i++;

            // Collect continuation lines
            while ($i < $total) {
                $current = $lines[$i];
                if (trim($current) === '') {
                    break;
                }
                if (preg_match('/^(\s*)\d+[.)]\s/', $current, $nextMatch)) {
                    if (strlen($nextMatch[1]) === $baseIndent) {
                        break;
                    }
                }
                if (preg_match('/^(\s*)[-*]\s/', $current) || preg_match('/^#{1,6}\s/', $current)) {
                    break;
                }
                if (preg_match('/^\s+(.+)$/', $current, $cont)) {
                    $itemText[] = trim($cont[1]);
                    $i++;
                    continue;
                }
                break;
            }

            $items[] = '<li>' . self::inlineHtml(implode(' ', $itemText), $allowHtml) . '</li>';
        }

        return '<ol>' . implode('', $items) . '</ol>';
    }

    /**
     * Parse an unordered list block at the given indentation level.
     *
     * @param string[] $lines
     * @param int      $i Current line index (advanced by reference)
     * @param int      $total Total line count
     * @param int      $baseIndent Indentation level for this list
     * @param bool     $allowHtml Passed through to inlineHtml()
     * @return string
     */
    private static function parseUnorderedList(array $lines, int &$i, int $total, int $baseIndent, bool $allowHtml = false): string
    {
        $items = [];

        while ($i < $total) {
            if (!preg_match('/^(\s*)[-*]\s+(.*)$/', $lines[$i], $itemMatch)) {
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

                if (preg_match('/^(\s*)[-*]\s+(.*)$/', $current, $nestedMatch)) {
                    $nestedIndent = strlen($nestedMatch[1]);
                    if ($nestedIndent > $baseIndent) {
                        $itemInner .= self::parseUnorderedList($lines, $i, $total, $nestedIndent, $allowHtml);
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

            $li = '<li>' . self::inlineHtml(implode(' ', $itemText), $allowHtml) . $itemInner . '</li>';
            $items[] = $li;
        }

        return '<ul>' . implode('', $items) . '</ul>';
    }
}

