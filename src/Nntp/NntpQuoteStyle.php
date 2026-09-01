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
 * (NNTP POST -> echomail/netmail) uses {@see toFtnAgainstParent()}, which falls
 * back to {@see toFtn()}. Both directions are line oriented, leave fenced code
 * blocks untouched, and are deliberately conservative: only an unmistakable
 * leading prefix is rewritten, everything else passes through verbatim.
 *
 * Inbound has two paths: {@see toFtnAgainstParent()} regenerates the quote from
 * the stored parent message (preserving per-author attribution for the whole
 * ancestry the parent carries) and falls back to {@see toFtn()}, which flatly
 * attributes every level to the immediate parent, when the parent cannot be
 * matched. See docs/proposals/NNTPServer.md - "Quote-style conversion".
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
     * Inbound quote conversion that reconstructs from the canonical parent rather
     * than blindly re-attributing every level to one author.
     *
     * A newsreader reply quotes the parent article by prefixing each of its lines
     * with `>`. That parent is a message we already store, complete with correct
     * per-author FSC-0032 attribution for its own ancestry. So when the article's
     * single quoted block is recognisably a quote of $parentBody, we discard the
     * newsreader's flat `>` quoting entirely and regenerate an FSC-0032 quote from
     * $parentBody — bumping the parent's own ` XX> ` lines one level deeper and
     * attributing only the parent's unquoted lines to $quotedAuthor. This keeps
     * multi-author attribution intact all the way down and stops round-trip
     * flattening from compounding.
     *
     * Falls back to {@see toFtn()} (flat re-attribution) when: $quotedAuthor
     * yields no initials, $parentBody is empty, the article has no quoted text,
     * the article has *more than one* quoted block (interleaved inline replies —
     * never disturb those), or the quoted block does not look like a quote of
     * $parentBody (a trimmed/edited quote, or a quote of some other message).
     */
    public static function toFtnAgainstParent(string $body, string $quotedAuthor, string $parentBody): string
    {
        $initials = self::initials($quotedAuthor);
        if ($initials === '' || trim($parentBody) === '') {
            return self::toFtn($body, $quotedAuthor);
        }

        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = explode("\n", $body);

        $blocks = self::quotedBlocks($lines);
        if (count($blocks) !== 1) {
            return self::toFtn($body, $quotedAuthor);
        }
        [$start, $end] = $blocks[0];

        // What the newsreader quoted, one '>' level peeled off each line.
        $stripped = [];
        for ($i = $start; $i <= $end; $i++) {
            if (preg_match(self::RFC_PREFIX, $lines[$i], $m)) {
                $stripped[] = substr($lines[$i], strlen($m[0]));
            } else {
                $stripped[] = '';
            }
        }

        // The parent as the newsreader would have received it over NNTP.
        if (!self::looksLikeQuoteOf($stripped, self::toRfc($parentBody))) {
            return self::toFtn($body, $quotedAuthor);
        }

        $rebuilt = explode("\n", self::requoteParent($parentBody, $initials));
        $out = array_merge(
            array_slice($lines, 0, $start),
            $rebuilt,
            array_slice($lines, $end + 1)
        );

        return implode("\n", $out);
    }

    /**
     * Maximal runs of consecutive quoted-or-blank lines that contain at least one
     * quoted line, with leading/trailing blank lines excluded from the span.
     *
     * @param string[] $lines
     * @return array<int,array{0:int,1:int}>  [startIndex, endIndex] inclusive
     */
    private static function quotedBlocks(array $lines): array
    {
        $isQuoted = static fn (string $l): bool => (bool)preg_match(self::RFC_PREFIX, $l);
        $isBlank = static fn (string $l): bool => trim($l) === '';

        $blocks = [];
        $n = count($lines);
        $i = 0;
        while ($i < $n) {
            if (!$isQuoted($lines[$i])) {
                $i++;
                continue;
            }
            $start = $i;
            $end = $i;
            $j = $i;
            while ($j < $n && ($isQuoted($lines[$j]) || $isBlank($lines[$j]))) {
                if ($isQuoted($lines[$j])) {
                    $end = $j;
                }
                $j++;
            }
            $blocks[] = [$start, $end];
            $i = $j;
        }

        return $blocks;
    }

    /**
     * Heuristic: is $stripped (a newsreader's quoted block with one '>' level
     * removed) a quote of $reference (the parent rendered Internet-style)?
     *
     * Passes on either a line-level match (>=60% of non-blank stripped lines
     * appear verbatim, whitespace-normalised, in the parent) or a word-level
     * match (>=75% of stripped words appear in the parent) — the latter tolerates
     * a client that re-wrapped the quoted text.
     *
     * @param string[] $stripped
     */
    private static function looksLikeQuoteOf(array $stripped, string $reference): bool
    {
        $normLine = static fn (string $s): string => strtolower(trim((string)preg_replace('/\s+/', ' ', $s)));

        $refLines = [];
        foreach (explode("\n", $reference) as $r) {
            $r = $normLine($r);
            if ($r !== '') {
                $refLines[$r] = true;
            }
        }
        if ($refLines === []) {
            return false;
        }

        $lineTotal = 0;
        $lineHit = 0;
        foreach ($stripped as $s) {
            $s = $normLine($s);
            if ($s === '') {
                continue;
            }
            $lineTotal++;
            if (isset($refLines[$s])) {
                $lineHit++;
            }
        }
        if ($lineTotal >= 2 && $lineHit / $lineTotal >= 0.6) {
            return true;
        }

        $words = static function (string $t): array {
            preg_match_all('/[\p{L}\p{N}]+/u', strtolower($t), $mm);
            return $mm[0];
        };
        $refWords = array_fill_keys($words($reference), true);
        $strippedWords = $words(implode(' ', $stripped));
        if (count($refWords) < 5 || count($strippedWords) < 5) {
            return false;
        }
        $hit = 0;
        foreach ($strippedWords as $w) {
            if (isset($refWords[$w])) {
                $hit++;
            }
        }

        return $hit / count($strippedWords) >= 0.75;
    }

    /**
     * FSC-0032 quote of $parentBody one nesting level deep: the parent's own
     * ` XX> ` lines gain another '>', its unquoted lines gain a ` $initials> `
     * prefix, blank lines pass through. Mirrors quoteMessageText() in
     * src/functions.php (kept self-contained so the class needs no bootstrap).
     */
    private static function requoteParent(string $parentBody, string $initials): string
    {
        $parentBody = str_replace(["\r\n", "\r"], "\n", $parentBody);
        $out = [];

        foreach (explode("\n", $parentBody) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $out[] = '';
                continue;
            }

            if (preg_match('/^\s*[A-Za-z]{0,2}>/', $trimmed)) {
                $bumped = preg_replace('/^(\s*[A-Za-z]{0,2})(>+)/', '$1$2>', ' ' . $trimmed);
                if (preg_match('/^(\s*[A-Za-z]{0,2}>+\s*)(.*)$/', (string)$bumped, $m)) {
                    foreach (self::wrapQuoted($m[1], $m[2]) as $w) {
                        $out[] = $w;
                    }
                } else {
                    $out[] = (string)$bumped;
                }
                continue;
            }

            foreach (self::wrapQuoted(' ' . $initials . '> ', $trimmed) as $w) {
                $out[] = $w;
            }
        }

        return implode("\n", $out);
    }

    /**
     * Word-wrap one quoted line to $maxWidth total, repeating $prefix on every
     * segment. Mirrors wrapQuotedLine() in src/functions.php.
     *
     * @return string[]
     */
    private static function wrapQuoted(string $prefix, string $content, int $maxWidth = 75): array
    {
        $available = $maxWidth - strlen($prefix);
        if ($available <= 0 || strlen($content) <= $available) {
            return [$prefix . $content];
        }
        $wrapped = wordwrap($content, $available, "\n", true);

        return array_map(static fn (string $l): string => $prefix . $l, explode("\n", $wrapped));
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
