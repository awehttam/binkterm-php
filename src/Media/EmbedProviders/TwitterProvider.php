<?php

namespace BinktermPHP\Media\EmbedProviders;

use BinktermPHP\Media\OEmbedProvider;

class TwitterProvider extends OEmbedProvider
{
    public function matches(string $url): bool
    {
        return (bool) preg_match('/(?:twitter|x)\.com\//i', $url);
    }

    protected function getOEmbedEndpoint(string $url): string
    {
        return 'https://publish.twitter.com/oembed?url=' . urlencode($url);
    }

    public function getName(): string { return 'twitter'; }
}
