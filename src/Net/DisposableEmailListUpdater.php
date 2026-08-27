<?php

namespace BinktermPHP\Net;

use BinktermPHP\Config;

/**
 * Downloads a disposable / throwaway email provider domain list and refreshes
 * the disposable_email_domains cache table used by the registration screening
 * feature's disposable_email signal.
 *
 * The default source is the community-maintained `disposable-email-domains`
 * project (https://github.com/disposable-email-domains/disposable-email-domains),
 * whose `disposable_email_blocklist.conf` is a plain newline-delimited list of
 * lowercase apex domains. Override with the DISPOSABLE_EMAIL_LIST_URL env var.
 *
 * Shared by scripts/update_disposable_email_list.php and the binkp scheduler's
 * gated refresh.
 *
 * @see docs/proposals/NewUserScreeningIPAddress.md
 */
class DisposableEmailListUpdater extends CachedListUpdater
{
    public const LIST_NAME = 'disposable_email_domains';

    public const DEFAULT_URL = 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/master/disposable_email_blocklist.conf';

    public function listName(): string
    {
        return self::LIST_NAME;
    }

    protected function valueColumn(): string
    {
        return 'domain';
    }

    protected function entryNoun(): string
    {
        return 'disposable domains';
    }

    protected function sourceUrl(): string
    {
        return Config::env('DISPOSABLE_EMAIL_LIST_URL', self::DEFAULT_URL);
    }

    protected function parseLine(string $line): ?string
    {
        $domain = strtolower(trim($line, " \t.\r\n"));
        if ($domain === '' || strlen($domain) > 255) {
            return null;
        }
        // Accept only plausible registrable domains (letters/digits/hyphens,
        // at least one dot, TLD of 2+ letters). Guards against a source file
        // that starts returning HTML or junk.
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9](-*[a-z0-9])*\.)+[a-z]{2,}$/', $domain)) {
            return null;
        }
        return $domain;
    }
}
