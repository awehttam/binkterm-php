<?php

namespace BinktermPHP\Freq;

use BinktermPHP\Config;

/**
 * Resolves whether the outbound File Requests feature (web UI, terminal
 * server UI, and their backing /api/freq/requests endpoints) is available to
 * a given user, per the FREQ_ENABLE_INTERFACE setting. Centralized here so
 * routes/api-routes.php, routes/web-routes.php, src/Template.php, and
 * telnet/src/BbsSession.php all agree on the same tri-state logic.
 */
class FreqWebAccess
{
    /**
     * FREQ_ENABLE_INTERFACE accepts:
     *   - "false" (default): feature disabled for everyone
     *   - "true": feature available to any logged-in user
     *   - "sysop": feature available to admins only
     */
    public static function isEnabledFor(bool $isAdmin): bool
    {
        $setting = strtolower(trim(Config::env('FREQ_ENABLE_INTERFACE', 'false')));

        if ($setting === 'sysop') {
            return $isAdmin;
        }

        return $setting === 'true';
    }
}
