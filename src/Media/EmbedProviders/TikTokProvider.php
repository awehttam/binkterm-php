<?php

namespace BinktermPHP\Media\EmbedProviders;

use BinktermPHP\Media\OEmbedProvider;

class TikTokProvider extends OEmbedProvider
{
    public function matches(string $url): bool
    {
        return (bool) preg_match('/tiktok\.com\/(?:@[^\/]+\/video\/|t\/[a-zA-Z0-9]+)|(?:vm|vt)\.tiktok\.com\/[a-zA-Z0-9]+/i', $url);
    }

    protected function getOEmbedEndpoint(string $url): string
    {
        return 'https://www.tiktok.com/oembed?url=' . urlencode($url);
    }

    public function getName(): string { return 'tiktok'; }
}
