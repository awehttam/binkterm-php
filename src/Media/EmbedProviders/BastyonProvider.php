<?php

namespace BinktermPHP\Media\EmbedProviders;

use BinktermPHP\Media\EmbedProviderInterface;

/**
 * Bastyon video post embeds.
 * Bastyon video posts use the URL format:
 *   https://bastyon.com/index?v={64-char-hex-txid}&video=1
 */
class BastyonProvider implements EmbedProviderInterface
{
    /** @var string[] */
    private array $rpcNodes = [
        '1.pocketnet.app',
        '2.pocketnet.app',
        '3.pocketnet.app',
    ];

    public function matches(string $url): bool
    {
        $parsed = parse_url($url);
        if (empty($parsed['host']) || $parsed['host'] !== 'bastyon.com') return false;
        if (empty($parsed['path']) || !str_contains($parsed['path'], '/index')) return false;
        parse_str($parsed['query'] ?? '', $params);
        return ($params['video'] ?? '') === '1'
            && isset($params['v'])
            && preg_match('/^[a-f0-9]{64}$/i', $params['v']);
    }

    public function getEmbedHtml(string $url): string
    {
        $parsed = parse_url($url);
        if (!is_array($parsed)) {
            return '';
        }

        parse_str($parsed['query'] ?? '', $params);

        $txid = $params['v'] ?? '';
        if (!is_string($txid) || !preg_match('/^[a-f0-9]{64}$/i', $txid)) {
            return '';
        }

        $embedUrl = $this->resolvePeerTubeEmbedUrl($txid);
        if ($embedUrl === '') {
            return '';
        }

        $escapedUrl = htmlspecialchars($embedUrl, ENT_QUOTES);
        return '<iframe class="bink-media-iframe" src="' . $escapedUrl . '"'
            . ' sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"'
            . ' allowfullscreen loading="lazy"></iframe>';
    }

    /**
     * Resolve a Bastyon blockchain transaction ID to an embeddable PeerTube URL.
     */
    private function resolvePeerTubeEmbedUrl(string $txid): string
    {
        foreach ($this->rpcNodes as $node) {
            $videoUrl = $this->fetchVideoUrl($node, $txid);
            if ($videoUrl === '') {
                continue;
            }

            $embedUrl = $this->buildPeerTubeEmbedUrl($videoUrl);
            if ($embedUrl !== '') {
                return $embedUrl;
            }
        }

        return '';
    }

    /**
     * Fetch the on-chain video URL from a PocketNet RPC node.
     */
    private function fetchVideoUrl(string $node, string $txid): string
    {
        $payload = json_encode([
            'method' => 'getrawtransactionwithmessagebyid',
            'params' => [[$txid]],
            'id' => 1,
        ]);

        if ($payload === false) {
            return '';
        }

        $data = $this->requestRpc('https://' . $node . ':8899/rpc/public/', $payload);
        $videoUrl = $this->extractVideoUrl($data);
        if ($videoUrl !== '') {
            return $videoUrl;
        }

        $directNode = $data['node'] ?? '';
        if (!is_string($directNode) || !preg_match('/^[a-z0-9.:-]+$/i', $directNode)) {
            return '';
        }

        return $this->extractVideoUrl($this->requestRpc('http://' . $directNode . '/', $payload));
    }

    /**
     * Send a PocketNet RPC request and decode the JSON response.
     *
     * @return array<string, mixed>
     */
    private function requestRpc(string $endpoint, string $payload): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $payload,
                'timeout' => 6,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($endpoint, false, $context);
        if (!is_string($response) || $response === '') {
            return [];
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Extract and decode the PeerTube URL from supported PocketNet response shapes.
     *
     * @param array<string, mixed> $data
     */
    private function extractVideoUrl(array $data): string
    {
        if ($data === []) {
            return '';
        }

        $post = null;
        if (($data['result'] ?? '') === 'success') {
            $post = $data['data'][0] ?? null;
        } elseif (is_array($data['result'] ?? null)) {
            $post = $data['result'][0] ?? null;
        }

        if (!is_array($post)) {
            return '';
        }

        $videoUrl = $post['u'] ?? '';
        if (!is_string($videoUrl) || $videoUrl === '') {
            return '';
        }

        return rawurldecode($videoUrl);
    }

    /**
     * Convert peertube://host/uuid URLs into HTTPS PeerTube embed URLs.
     */
    private function buildPeerTubeEmbedUrl(string $videoUrl): string
    {
        $parsed = parse_url($videoUrl);
        if (($parsed['scheme'] ?? '') !== 'peertube' || empty($parsed['host']) || empty($parsed['path'])) {
            return '';
        }

        $uuid = trim($parsed['path'], '/');
        if (!preg_match('/^[a-f0-9-]{32,36}$/i', $uuid)) {
            return '';
        }

        return 'https://' . $parsed['host'] . '/videos/embed/' . $uuid;
    }

    public function getName(): string { return 'bastyon'; }
    public function getType(): string { return 'platform'; }
}
