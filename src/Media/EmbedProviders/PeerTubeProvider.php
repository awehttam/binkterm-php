<?php

namespace BinktermPHP\Media\EmbedProviders;

use BinktermPHP\Media\EmbedProviderInterface;

class PeerTubeProvider implements EmbedProviderInterface
{
    private const UUID_PATTERN = '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i';

    public function matches(string $url): bool
    {
        return $this->buildEmbedUrl($url) !== '';
    }

    public function getEmbedHtml(string $url): string
    {
        $embedUrl = $this->buildEmbedUrl($url);
        if ($embedUrl === '') {
            return '';
        }

        $escapedUrl = htmlspecialchars($embedUrl, ENT_QUOTES);
        return '<iframe class="bink-media-iframe" src="' . $escapedUrl . '"'
            . ' sandbox="allow-scripts allow-same-origin allow-presentation"'
            . ' allowfullscreen loading="lazy"></iframe>';
    }

    private function buildEmbedUrl(string $url): string
    {
        $parsed = parse_url($url);
        if (!is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host']) || empty($parsed['path'])) {
            return '';
        }

        if (!in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            return '';
        }

        $path = trim($parsed['path'], '/');
        if (!preg_match('#^videos/(?:watch|embed)/([^/]+)$#i', $path, $m)) {
            return '';
        }

        $uuid = $m[1];
        if (!preg_match(self::UUID_PATTERN, $uuid)) {
            return '';
        }

        return 'https://' . $parsed['host'] . '/videos/embed/' . $uuid;
    }

    public function getName(): string { return 'peertube'; }
    public function getType(): string { return 'platform'; }
}
