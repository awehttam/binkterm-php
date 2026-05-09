<?php

namespace BinktermPHP\Media\EmbedProviders;

use BinktermPHP\Media\EmbedProviderInterface;

class RumbleProvider implements EmbedProviderInterface
{
    private const PATTERN = '/rumble\.com\/(?:embed\/)?(v[a-z0-9]+)/i';

    public function matches(string $url): bool
    {
        return (bool) preg_match(self::PATTERN, $url);
    }

    public function getEmbedHtml(string $url): string
    {
        $embedUrl = $this->resolveEmbedUrl($url);
        if ($embedUrl === '') {
            return '';
        }

        $escapedUrl = htmlspecialchars($embedUrl, ENT_QUOTES);
        return '<iframe class="bink-media-iframe" src="' . $escapedUrl . '"'
            . ' sandbox="allow-scripts allow-same-origin allow-presentation"'
            . ' allowfullscreen loading="lazy"></iframe>';
    }

    /**
     * Resolve Rumble's public video URL to its internal embed URL.
     */
    private function resolveEmbedUrl(string $url): string
    {
        if (preg_match('/rumble\.com\/embed\/(v[a-z0-9]+)\//i', $url, $m)) {
            return 'https://rumble.com/embed/' . $m[1] . '/';
        }

        $endpoint = 'https://rumble.com/api/Media/oembed.json?url=' . rawurlencode($url);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\nUser-Agent: Mozilla/5.0\r\n",
                'timeout' => 6,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($endpoint, false, $context);
        if (!is_string($response) || $response === '') {
            return '';
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return '';
        }

        $html = $data['html'] ?? '';
        if (!is_string($html) || !preg_match('/src="(https:\/\/rumble\.com\/embed\/v[a-z0-9]+\/)"/i', $html, $m)) {
            return '';
        }

        return $m[1];
    }

    public function getName(): string { return 'rumble'; }
    public function getType(): string { return 'platform'; }
}
