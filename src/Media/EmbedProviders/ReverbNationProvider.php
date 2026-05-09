<?php

namespace BinktermPHP\Media\EmbedProviders;

use BinktermPHP\Media\OEmbedProvider;

class ReverbNationProvider extends OEmbedProvider
{
    public function matches(string $url): bool
    {
        return (bool) preg_match('/reverbnation\.com\//i', $url);
    }

    protected function getOEmbedEndpoint(string $url): string
    {
        return 'https://www.reverbnation.com/oembed?format=json&url=' . urlencode($url);
    }

    public function getName(): string { return 'reverbnation'; }
}
