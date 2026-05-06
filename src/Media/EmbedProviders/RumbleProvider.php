<?php

namespace BinktermPHP\Media\EmbedProviders;

use BinktermPHP\Media\EmbedProviderInterface;

class RumbleProvider implements EmbedProviderInterface
{
    private const PATTERN = '/rumble\.com\/(v[a-z0-9]+)-/i';

    public function matches(string $url): bool
    {
        return (bool) preg_match(self::PATTERN, $url);
    }

    public function getEmbedHtml(string $url): string
    {
        preg_match(self::PATTERN, $url, $m);
        $id = htmlspecialchars($m[1], ENT_QUOTES);
        return '<iframe class="bink-media-iframe" src="https://rumble.com/embed/' . $id . '/"'
            . ' sandbox="allow-scripts allow-same-origin allow-presentation"'
            . ' allowfullscreen loading="lazy"></iframe>';
    }

    public function getName(): string { return 'rumble'; }
    public function getType(): string { return 'platform'; }
}
