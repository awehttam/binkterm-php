<?php

namespace BinktermPHP\Security;

/**
 * Policy for the anonymous tracker/MIDI media proxy (GET /api/media/raw).
 *
 * The proxy fetches a remote file for the in-browser retro audio player. Two
 * defects made it dangerous: the remote Content-Type was echoed inline from the
 * board's origin (an attacker's ".mid" URL could return HTML/JavaScript that runs
 * as the board), and the host check resolved the name separately from curl
 * (IPv4 only), so curl could connect to an unchecked address (an AAAA record, or
 * a rebinding answer). This class decides everything the route needs:
 *
 *  - which URLs are fetchable (http/https, ports 80/443 only, allowed extensions);
 *  - the exact addresses the fetch may use: every A and AAAA answer must be public,
 *    and curl is pinned to them (CURLOPT_RESOLVE), so the connection goes only to an
 *    address that was checked; TLS still verifies the original host name;
 *  - the response headers: never the remote Content-Type, always a download that
 *    no browser renders or sniffs, sandboxed.
 */
final class MediaProxyPolicy
{
    public const ALLOWED_EXTENSIONS = ['xm', 'it', 's3m', 'mod', 'stm', 'amf', '669', 'mptm', 'sid', 'mid', 'midi'];
    public const ALLOWED_PORTS = ['http' => 80, 'https' => 443];
    public const MAX_BYTES = 8 * 1024 * 1024;

    /** @var callable(string): array<int,string> host -> IPv4 and IPv6 addresses */
    private $resolver;

    /**
     * @param callable|null $resolver host -> list of IP addresses (both families). Tests inject one.
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? [self::class, 'resolveAll'];
    }

    /**
     * Decide a URL. Returns the pin (host, port, addresses) to fetch it with, or null if it must not be fetched.
     *
     * @return array{host:string,port:int,scheme:string,ips:array<int,string>}|null
     */
    public function pinFor(string $url): ?array
    {
        if ($url === '' || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!isset(self::ALLOWED_PORTS[$scheme]) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $port = isset($parts['port']) ? (int)$parts['port'] : self::ALLOWED_PORTS[$scheme];
        if ($port !== self::ALLOWED_PORTS[$scheme]) {
            return null;
        }
        $path = strtolower((string)($parts['path'] ?? ''));
        $ext = ltrim((string)(strrchr($path, '.') ?: ''), '.');
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return null;
        }
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || $host === 'localhost.localdomain') {
            return null;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_values(array_unique(($this->resolver)($host)));
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return null;
            }
        }
        return ['host' => $host, 'port' => $port, 'scheme' => $scheme, 'ips' => $ips];
    }

    /** A routable, public unicast address (not private, reserved, loopback, link-local, CGNAT, NAT64 or mapped). */
    public static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $bin = inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 4) {
            // 100.64.0.0/10 (shared/CGNAT) is neither private nor reserved to PHP.
            return !((ord($bin[0]) === 100) && ((ord($bin[1]) & 0xC0) === 64));
        }
        // IPv6: refuse anything embedding IPv4 (::ffff:0:0/96, 64:ff9b::/96 NAT64, ::/96, 2002::/16 6to4, 2001::/32 Teredo), multicast and 2001:db8::/32.
        if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff" || substr($bin, 0, 12) === str_repeat("\0", 12)) {
            return false;
        }
        if (substr($bin, 0, 4) === "\x00\x64\xff\x9b" || substr($bin, 0, 4) === "\x20\x01\x0d\xb8") {
            return false;
        }
        // Multicast (ff00::/8), 6to4 (2002::/16) and Teredo (2001::/32) carry or relay to embedded IPv4 or group addresses.
        if ($bin[0] === "\xff" || substr($bin, 0, 2) === "\x20\x02" || substr($bin, 0, 4) === "\x20\x01\x00\x00") {
            return false;
        }
        return true;
    }

    /** Every A and AAAA answer for $host (no search domains: a trailing dot is added). @return array<int,string> */
    public static function resolveAll(string $host): array
    {
        $ips = [];
        $fqdn = rtrim($host, '.') . '.';
        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $key) {
            $records = @dns_get_record($fqdn, $type);
            foreach (is_array($records) ? $records : [] as $r) {
                if (isset($r[$key]) && filter_var($r[$key], FILTER_VALIDATE_IP)) {
                    $ips[] = (string)$r[$key];
                }
            }
        }
        return $ips;
    }

    /**
     * CURLOPT_RESOLVE entries pinning the fetch to the checked addresses. An IP-literal host needs none (curl connects
     * to the literal itself, which was checked). @return array<int,string>
     */
    public static function curlResolve(array $pin): array
    {
        if (filter_var($pin['host'], FILTER_VALIDATE_IP)) {
            return [];
        }
        $addrs = array_map(static fn (string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $pin['ips']);
        return [$pin['host'] . ':' . $pin['port'] . ':' . implode(',', $addrs)];
    }

    /** Whether $ip (as curl reports it) is one of the pin's checked addresses, compared in binary form. */
    public static function isPinnedAddress(array $pin, string $ip): bool
    {
        $got = @inet_pton(trim($ip, '[]'));
        if ($got === false) {
            return false;
        }
        foreach ($pin['ips'] as $allowed) {
            if (@inet_pton($allowed) === $got) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve a redirect target against the URL that answered (RFC 3986 section 5.2). Returns an absolute URL or ''.
     */
    public static function resolveReference(string $base, string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '' || preg_match('/[\x00-\x20]/', $ref)) {
            return '';
        }
        $b = parse_url($base);
        if (!is_array($b) || !isset($b['scheme'], $b['host'])) {
            return '';
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $ref)) {
            return $ref; // absolute
        }
        $authority = $b['host'];
        if (str_contains($authority, ':') && $authority[0] !== '[') {
            $authority = '[' . $authority . ']'; // parse_url() keeps IPv6 brackets; add them only if absent
        }
        if (isset($b['port'])) {
            $authority .= ':' . $b['port'];
        }
        if (str_starts_with($ref, '//')) {
            return $b['scheme'] . ':' . $ref; // network-path reference
        }
        $frag = strpos($ref, '#');
        if ($frag !== false) {
            $ref = substr($ref, 0, $frag);
        }
        $query = '';
        $q = strpos($ref, '?');
        if ($q !== false) {
            $query = substr($ref, $q);
            $ref = substr($ref, 0, $q);
        }
        $basePath = $b['path'] ?? '/';
        if ($ref === '') {
            $path = $basePath;
            if ($query === '' && isset($b['query'])) {
                $query = '?' . $b['query'];
            }
        } elseif ($ref[0] === '/') {
            $path = $ref;
        } else {
            $dir = substr($basePath, 0, (int)strrpos($basePath, '/') + 1);
            $path = ($dir === '' ? '/' : $dir) . $ref;
        }
        // Remove dot segments.
        $out = [];
        $segs = explode('/', $path);
        if (in_array(end($segs), ['.', '..'], true)) {
            $segs[] = ''; // a final dot segment leaves a directory: trailing slash
        }
        foreach ($segs as $seg) {
            if ($seg === '..') {
                if (count($out) > 1) {
                    array_pop($out);
                }
            } elseif ($seg !== '.') {
                $out[] = $seg;
            }
        }
        $path = implode('/', $out);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        return $b['scheme'] . '://' . $authority . $path . $query;
    }

    /**
     * The response headers. The remote Content-Type is never used: the body is an opaque download no browser renders,
     * sniffs or runs (the player reads it with fetch()).
     *
     * @return array<int,string>
     */
    public static function responseHeaders(string $filename, int $length): array
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'audio';
        return [
            'Content-Type: application/octet-stream',
            'Content-Length: ' . $length,
            'Content-Disposition: attachment; filename="' . $safe . '"',
            'X-Content-Type-Options: nosniff',
            "Content-Security-Policy: sandbox; default-src 'none'",
            'Cross-Origin-Resource-Policy: same-origin',
            'Cache-Control: public, max-age=86400',
        ];
    }
}
