<?php

namespace BinktermPHP\Freq;

/**
 * Shared parsing helpers for outbound FREQ target addresses, which may be
 * given either as an FTN address (zone:net/node) or as a plain internet
 * hostname/IP (optionally "host:port") for nodes with no nodelist/binkp_zone
 * entry. Used by both scripts/freq_getfile.php and the outbound FREQ web API
 * (routes/api-routes.php) so the two accept the same address syntax.
 */
class FreqAddress
{
    /**
     * Check whether $value looks like an FTN address (zone:net/node,
     * optionally with @domain and/or a .point suffix), as opposed to an
     * internet hostname.
     */
    public static function isFtnAddress(string $value): bool
    {
        $stripped = str_contains($value, '@') ? explode('@', $value, 2)[0] : $value;
        return (bool)preg_match('/^\d+:\d+\/\d+(\.\d+)?$/', $stripped);
    }

    /**
     * Split a "host" or "host:port" string into its parts. Understands
     * bracketed IPv6 literals (e.g. "[::1]:24554"). Returns a null port when
     * none is given.
     *
     * @return array{0:string,1:int|null}
     */
    public static function splitHostPort(string $value): array
    {
        $parts = parse_url('binkp://' . $value);
        if ($parts === false || empty($parts['host'])) {
            return [$value, null];
        }
        return [trim($parts['host'], '[]'), isset($parts['port']) ? (int)$parts['port'] : null];
    }
}
