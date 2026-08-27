<?php

namespace BinktermPHP\Net;

use BinktermPHP\Config;

/**
 * Downloads the Tor Project's bulk exit list and refreshes the tor_exit_nodes
 * cache table used by the registration screening feature.
 *
 * Shared by scripts/update_tor_exit_list.php (manual/cron use) and the binkp
 * scheduler's 6-hour gated refresh, so the fetch+store logic lives in one place.
 *
 * @see docs/proposals/NewUserScreeningIPAddress.md
 */
class TorExitListUpdater extends CachedListUpdater
{
    public const LIST_NAME = 'tor_exit_nodes';

    public function listName(): string
    {
        return self::LIST_NAME;
    }

    protected function valueColumn(): string
    {
        return 'ip_address';
    }

    protected function valuePlaceholder(): string
    {
        return '?::inet';
    }

    protected function entryNoun(): string
    {
        return 'exit node IPs';
    }

    protected function sourceUrl(): string
    {
        return Config::env('TOR_EXIT_LIST_URL', 'https://check.torproject.org/torbulkexitlist');
    }

    protected function parseLine(string $line): ?string
    {
        return filter_var($line, FILTER_VALIDATE_IP) !== false ? $line : null;
    }
}
