<?php

namespace BinktermPHP;

/**
 * StyleCodes renderer (MARKUP: StyleCodes 1.0 / Synchronet Message Markup).
 *
 * Supported codes:
 *   *bold*        → <strong>
 *   /italics/     → <em>
 *   _underlined_  → <u>
 *   #inverse#     → <span class="sc-inverse">
 *
 * Line breaks in the source are preserved. Codes must open and close on the
 * same line (delimiters are non-greedy and may not span newlines).
 */
class StyleCodesRenderer
{
    /**
     * Convert a StyleCodes-formatted string to HTML.
     *
     * @param string $text Raw message body (kludge lines already stripped)
     * @return string HTML output
     */
    public static function toHtml(string $text): string
    {
        // Normalise line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $lines  = explode("\n", $text);
        $output = [];

        foreach ($lines as $line) {
            $output[] = self::renderLine($line);
        }

        return '<div class="sc-body">' . implode('<br>', $output) . '</div>';
    }

    /**
     * Apply StyleCodes inline transformations to a single line.
     *
     * @param string $line Raw line of text
     * @return string HTML string
     */
    private static function renderLine(string $line): string
    {
        $line = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');

        // *bold*
        $line = preg_replace('/\*([^*\r\n]+)\*/', '<strong>$1</strong>', $line);

        // /italics/ — exclude slashes adjacent to colons to avoid mangling URLs
        $line = preg_replace('/(?<![:\w])\/([^\/\r\n]+)\/(?![\w])/', '<em>$1</em>', $line);

        // _underlined_
        $line = preg_replace('/_([^_\r\n]+)_/', '<u>$1</u>', $line);

        // #inverse#
        $line = preg_replace('/#([^#\r\n]+)#/', '<span class="sc-inverse">$1</span>', $line);

        return $line;
    }
}
