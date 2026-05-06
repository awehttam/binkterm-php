<?php

namespace BinktermPHP\Media\EmbedProviders;

use BinktermPHP\Media\OEmbedProvider;

class SoundCloudProvider extends OEmbedProvider
{
    public function matches(string $url): bool
    {
        return (bool) preg_match('/soundcloud\.com\//i', $url);
    }

    protected function getOEmbedEndpoint(string $url): string
    {
        return 'https://soundcloud.com/oembed?format=json&url=' . urlencode($url);
    }

    public function getName(): string { return 'soundcloud'; }
}
