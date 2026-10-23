<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
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


namespace BinktermPHP\AutoFeed;

/**
 * HTTP(S) GET for Auto Feed sources with normal TLS verification.
 *
 * The peer certificate must chain to the system trust store and match the host name; any TLS or
 * transport failure throws, so nothing is posted and no feed state advances. There is no insecure
 * fallback.
 *
 * Only http:// and https:// targets are fetched, and redirects are followed here rather than by the
 * stream wrapper so every hop is checked: a redirect must stay on http(s), and once a request is on
 * https it may not move to plain http. file://, php:// and every other scheme fail closed.
 * Schemes are case-insensitive: the URL handed to PHP always carries the lower-case scheme that the
 * policy checked, so `HTTPS://` can never reach a non-TLS transport.
 */
final class FeedFetch
{
    public const TIMEOUT_SECONDS = 30;
    public const MAX_REDIRECTS = 5;

    /**
     * @return string Response body
     * @throws \RuntimeException on any fetch failure (the message never includes URL credentials or query)
     */
    public static function get(string $url, string $userAgent): string
    {
        $target = self::normalize($url);
        if ($target === null) {
            throw new \RuntimeException('Failed to fetch feed: only http:// and https:// feed URLs are allowed');
        }

        [$scheme, $current] = $target;
        for ($hops = 0; ; $hops++) {
            [$status, $location, $body] = self::request($current, $userAgent);
            if ($status < 300 || $status >= 400) {
                return $body;
            }

            if ($location === null) {
                throw new \RuntimeException('Failed to fetch feed ' . self::redact($current) . ": HTTP $status redirect without a Location");
            }
            if ($hops >= self::MAX_REDIRECTS) {
                throw new \RuntimeException('Failed to fetch feed ' . self::redact($url) . ': too many redirects');
            }

            $resolved = self::resolve($current, $location);
            $next = $resolved === null ? null : self::normalize($resolved);
            if ($next === null) {
                throw new \RuntimeException('Failed to fetch feed ' . self::redact($current) . ': redirect to a non-http(s) location refused');
            }
            if ($scheme === 'https' && $next[0] !== 'https') {
                throw new \RuntimeException('Failed to fetch feed ' . self::redact($current) . ': redirect from https to plain http refused');
            }
            [$scheme, $current] = $next;
        }
    }

    /** scheme://host[:port]/path, without user info, query or fragment. */
    public static function redact(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '(invalid URL)';
        }
        $out = ($parts['scheme'] ?? 'http') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');

        return isset($parts['query']) ? $out . '?…' : $out;
    }

    /**
     * One request without automatic redirects.
     *
     * @return array{0: int, 1: ?string, 2: string} status, Location header (if any), body
     */
    private static function request(string $url, string $userAgent): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => self::TIMEOUT_SECONDS,
                'user_agent' => $userAgent,
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
            ],
        ]);

        $warnings = [];
        $http_response_header = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            $body = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }

        if ($body === false) {
            throw new \RuntimeException('Failed to fetch feed ' . self::redact($url) . ': ' . self::reason($warnings, $url));
        }

        $status = 0;
        $location = null;
        foreach ($http_response_header as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int)$m[1];
            } elseif (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            }
        }

        return [$status, $location, $body];
    }

    /**
     * [scheme, URL] for an http(s) URL with a host, the URL rewritten to the lower-case scheme it was
     * judged by (the transport PHP picks must be the one the policy saw); otherwise null.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function normalize(string $url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $colon = strpos($url, ':');
        if (!in_array($scheme, ['http', 'https'], true) || $colon === false || strtolower(substr($url, 0, $colon)) !== $scheme) {
            return null;
        }

        return [$scheme, $scheme . substr($url, $colon)];
    }

    /** Absolute target of a Location header relative to the request URL (user info is never carried over). */
    private static function resolve(string $base, string $location): ?string
    {
        if ($location === '') {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $location)) {
            return $location;
        }
        $b = parse_url($base);
        if (!is_array($b) || empty($b['host'])) {
            return null;
        }
        $scheme = strtolower((string)$b['scheme']);
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        $origin = $scheme . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if ($location[0] === '/') {
            return $origin . $location;
        }
        $path = (string)($b['path'] ?? '/');
        $dir = substr($path, 0, (int)strrpos($path, '/') + 1);

        return $origin . ($dir !== '' ? $dir : '/') . $location;
    }

    /** @param string[] $warnings PHP warnings raised by the failed fetch */
    private static function reason(array $warnings, string $url): string
    {
        $all = implode(' | ', $warnings);
        if (preg_match('/did not match expected CN|Peer certificate CN|subject alternative name|hostname mismatch/i', $all)) {
            return 'TLS host name verification failed';
        }
        if (preg_match('/certificate verify failed|self[- ]signed|unable to get local issuer|certificate has expired/i', $all)) {
            return 'TLS certificate verification failed';
        }
        $last = (string)end($warnings);
        $last = str_replace($url, self::redact($url), $last);
        $last = preg_replace('/^file_get_contents\([^)]*\):\s*/', '', $last) ?? $last;

        return $last !== '' ? mb_substr(trim($last), 0, 200, 'UTF-8') : 'unknown error';
    }
}
