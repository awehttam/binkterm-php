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
 * Parses a raw NNTP article (the block a client sends after `POST`, or a `HEAD` /
 * `ARTICLE` response) into a header map plus body.
 *
 * Shared between the server's `POST` path and the future NNTP client transport;
 * keep it free of any server/session state.
 */
final class NntpArticleParser
{
    private function __construct()
    {
    }

    /**
     * @return array{headers:array<string,string>,raw_headers:array<int,array{name:string,value:string}>,body:string}
     *   headers: last-value-wins map keyed by lowercased field name;
     *   raw_headers: every header line in order (unfolded);
     *   body: message body with \n line endings, no trailing newline, un-dot-stuffed.
     */
    public static function parse(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        // Split on the first blank line.
        $split = preg_split('/\n\n/', $raw, 2);
        $headerBlock = $split[0] ?? '';
        $body = $split[1] ?? '';

        $rawHeaders = [];
        $headers = [];
        foreach (self::unfold($headerBlock) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($name === '') {
                continue;
            }
            $rawHeaders[] = ['name' => $name, 'value' => $value];
            $headers[strtolower($name)] = $value;
        }

        return [
            'headers' => $headers,
            'raw_headers' => $rawHeaders,
            'body' => self::undotStuff(rtrim($body, "\n")),
        ];
    }

    /**
     * Join RFC 5322 folded header continuation lines (leading SP/TAB) onto the
     * preceding line.
     *
     * @return string[]
     */
    private static function unfold(string $headerBlock): array
    {
        $out = [];
        foreach (explode("\n", $headerBlock) as $line) {
            if ($line === '') {
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $out !== []) {
                $out[count($out) - 1] .= ' ' . ltrim($line);
            } else {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * Reverse NNTP dot-stuffing: a line that began "." on the wire arrives as "..".
     */
    private static function undotStuff(string $body): string
    {
        return preg_replace('/^\.\./m', '.', $body) ?? $body;
    }

    /**
     * Split a comma- or whitespace-separated header value (Newsgroups, References)
     * into trimmed non-empty tokens.
     *
     * @return string[]
     */
    public static function tokenList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $parts = preg_split('/[\s,]+/', trim($value)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn ($s) => $s !== ''));
    }

    /**
     * The last message-id in a References / In-Reply-To chain (the immediate
     * parent), or null.
     */
    public static function lastReference(array $headers): ?string
    {
        $refs = self::tokenList($headers['references'] ?? null);
        if ($refs !== []) {
            return end($refs);
        }

        $inReplyTo = self::tokenList($headers['in-reply-to'] ?? null);
        foreach (array_reverse($inReplyTo) as $token) {
            if (str_starts_with($token, '<')) {
                return $token;
            }
        }

        return null;
    }

    /**
     * True if the article is a control message (cancel/supersede/newgroup/…),
     * which the FTN gateway drops silently.
     */
    public static function isControl(array $headers): bool
    {
        return isset($headers['control'])
            || (isset($headers['supersedes']) && trim($headers['supersedes']) !== '');
    }
}
