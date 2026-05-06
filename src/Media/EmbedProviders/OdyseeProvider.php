<?php

namespace BinktermPHP\Media\EmbedProviders;

use BinktermPHP\Media\EmbedProviderInterface;

class OdyseeProvider implements EmbedProviderInterface
{
    private const PATTERN = '/odysee\.com\/((@[^\/\?#]+)\/([^\/\?#]+))/';

    public function matches(string $url): bool
    {
        return (bool) preg_match(self::PATTERN, $url);
    }

    public function getEmbedHtml(string $url): string
    {
        preg_match(self::PATTERN, $url, $m);
        $path = htmlspecialchars($m[1], ENT_QUOTES);
        return '<iframe class="bink-media-iframe" src="https://odysee.com/$/embed/' . $path . '"'
            . ' sandbox="allow-scripts allow-same-origin allow-presentation"'
            . ' allowfullscreen loading="lazy"></iframe>';
    }

    public function getName(): string { return 'odysee'; }
    public function getType(): string { return 'platform'; }
}
