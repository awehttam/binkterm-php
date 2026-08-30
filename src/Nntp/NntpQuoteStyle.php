<?php

/*
 * Copyright Matthew Asham and BinktermPHP Contributors
 *
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the
 * following conditions are met:
 *
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 *
 */

namespace BinktermPHP\Nntp;

/**
 * Bidirectional conversion between the Internet mail/news quote style (`> text`,
 * stacked `>>` for depth) and the FTN / FSC-0032 style (` XX> text`, where `XX`
 * is the initials of the quoted author).
 *
 * Outbound (echomail/netmail -> NNTP article) uses {@see toRfc()}; inbound
 * (NNTP POST -> echomail/netmail) uses {@see toFtn()}. Both are line oriented,
 * leave fenced code blocks untouched, and are deliberately conservative: only an
 * unmistakable leading prefix is rewritten, everything else passes through
 * verbatim.
 *
 * The transform is lossy. FTN records only one quoted author per line, so a deep
 * quote chain that crosses the gateway repeatedly loses attribution detail below
 * the immediate parent. See docs/proposals/NNTPServer.md - "Quote-style
 * conversion".
 *
 * The initials rule mirrors generateInitials() in src/functions.php so an
 * NNTP-originated reply quotes the same way a web or terminal reply does.
 */
final class NntpQuoteStyle
{
    /**
     * One or more FSC-0032 quote segments at the start of a line: 1-10 name
     * characters, one or more '>', an optional space, repeated for stacked
     * quotes (" AB> CD> "). A leading letter is required so a bare ">" / ">>"
     * (already Internet style) and a '>'-led ASCII-art line are not matched.
     * Up to two leading spaces of indentation are tolerated.
     */
    private const FTN_PREFIX = '/^ {0,2}((?:[A-Za-z][A-Za-z0-9]{0,9}>+ ?)+)/';

    /** A bare, possibly stacked, Internet quote prefix at the start of a line. */
    private const RFC_PREFIX = '/^ {0,2}(>+) ?/';

    private function __construct()
    {
    }

    /**
     * FSC-0032 ` XX> ` quoting -> Internet `> ` quoting. Quote depth (the total
     * count of '>' in the prefix) is preserved as that many stacked '>'.
     */
    public static function toRfc(string $body): string
    {
        return self::mapLines($body, static function (string $line): string {
            if (!preg_match(self::FTN_PREFIX, $line, $m)) {
                return $line;
            }
            $depth = substr_count($m[1], '>');
            $rest = substr($line, strlen($m[0]));

            return str_repeat('>', $depth) . ($rest === '' ? '' : ' ' . $rest);
        });
    }

    /**
     * Internet `> ` quoting -> FSC-0032 ` XX> ` quoting, attributing every level
     * to $quotedAuthor (FTN has no per-level attribution). A line with no leading
     * '>' run, one that already carries an FSC-0032 prefix, or any line when
     * $quotedAuthor yields no usable initials, is left unchanged.
     */
    public static function toFtn(string $body, string $quotedAuthor): string
    {
        $initials = self::initials($quotedAuthor);
        if ($initials === '') {
            return $body;
        }

        return self::mapLines($body, static function (string $line) use ($initials): string {
            if (preg_match(self::FTN_PREFIX, $line)) {
                return $line;
            }
            if (!preg_match(self::RFC_PREFIX, $line, $m)) {
                return $line;
            }
            $depth = strlen($m[1]);
            $rest = substr($line, strlen($m[0]));

            return ' ' . $initials . str_repeat('>', $depth) . ($rest === '' ? '' : ' ' . $rest);
        });
    }

    /**
     * FSC-0032 initials for a display name, matching generateInitials() in
     * src/functions.php: two letters for a single-token name, first-plus-last
     * initial otherwise. Empty string when the name has no usable letters/digits.
     */
    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return '';
        }

        if (count($parts) === 1) {
            $one = preg_replace('/[^A-Za-z0-9]/', '', $parts[0]) ?? '';
            return strtoupper(substr($one, 0, 2));
        }

        $first = preg_replace('/[^A-Za-z0-9]/', '', $parts[0]) ?? '';
        $last = preg_replace('/[^A-Za-z0-9]/', '', (string)end($parts)) ?? '';

        return strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
    }

    /**
     * Apply $fn to every line that is not inside a fenced code block and does not
     * contain an ESC byte (ANSI control sequence -> almost certainly art).
     *
     * @param callable(string):string $fn
     */
    private static function mapLines(string $body, callable $fn): string
    {
        $lines = explode("\n", $body);
        $inFence = false;

        foreach ($lines as $i => $line) {
            if (preg_match('/^ {0,3}(```|~~~)/', $line)) {
                $inFence = !$inFence;
                continue;
            }
            if ($inFence || strpos($line, "\x1b") !== false) {
                continue;
            }
            $lines[$i] = $fn($line);
        }

        return implode("\n", $lines);
    }
}
