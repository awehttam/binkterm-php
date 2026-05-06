<?php

namespace BinktermPHP\Media\EmbedProviders;

use BinktermPHP\Media\EmbedProviderInterface;

class YouTubeProvider implements EmbedProviderInterface
{
    private const PATTERN = '/(?:youtube\.com\/watch\?(?:[^&]*&)*v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/';

    public function matches(string $url): bool
    {
        return (bool) preg_match(self::PATTERN, $url);
    }

    public function getEmbedHtml(string $url): string
    {
        preg_match(self::PATTERN, $url, $m);
        $id = htmlspecialchars($m[1], ENT_QUOTES);
        return '<iframe class="bink-media-iframe" src="https://www.youtube.com/embed/' . $id . '?rel=0"'
            . ' sandbox="allow-scripts allow-same-origin allow-presentation"'
            . ' allowfullscreen loading="lazy"></iframe>';
    }

    public function getName(): string { return 'youtube'; }
    public function getType(): string { return 'platform'; }
}
