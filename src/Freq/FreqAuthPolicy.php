<?php

namespace BinktermPHP\Freq;

use BinktermPHP\Config;

/**
 * Resolves whether an outbound FREQ session (scripts/freq_getfile.php,
 * scripts/freq_pickup.php) should connect anonymously or use a matching
 * configured uplink's real session password/CRAM-MD5. Centralized here so
 * both scripts, and anything shelling out to them (e.g. the web/terminal
 * File Requests UI via AdminDaemonClient::freqRequest()), agree on the same
 * default and override rules.
 */
class FreqAuthPolicy
{
    /**
     * @param array<string,string|true> $opts Parsed CLI options, expected to
     *                                         hold "authenticated" and/or
     *                                         "anonymous" flags when present.
     * @return bool True if the session should be forced anonymous.
     */
    public static function forceAnonymous(array $opts): bool
    {
        if (isset($opts['anonymous'])) {
            return true;
        }
        if (isset($opts['authenticated'])) {
            return false;
        }

        return strtolower(trim(Config::env('FREQ_AUTHENTICATE_UPLINKS', 'false'))) !== 'true';
    }
}
