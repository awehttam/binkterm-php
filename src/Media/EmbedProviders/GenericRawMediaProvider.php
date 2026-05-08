<?php

namespace BinktermPHP\Media\EmbedProviders;

use BinktermPHP\Media\EmbedProviderInterface;

class GenericRawMediaProvider implements EmbedProviderInterface
{
    private const VIDEO_EXTS = ['mp4', 'webm', 'ogv', 'mov'];
    private const AUDIO_EXTS = ['mp3', 'flac', 'ogg', 'opus', 'wav', 'm4a', 'aac'];
    private const IMAGE_EXTS = ['png', 'webp', 'gif', 'jpg', 'jpeg', 'svg'];

    public function matches(string $url): bool
    {
        return $this->detectMediaType($url) !== null;
    }

    public function getEmbedHtml(string $url): string
    {
        $type = $this->detectMediaType($url);
        $safe = htmlspecialchars($url, ENT_QUOTES);

        switch ($type) {
            case 'video':
                return '<video controls preload="metadata" class="bink-media-video">'
                    . '<source src="' . $safe . '"></video>';
            case 'audio':
                return '<audio controls preload="metadata" class="bink-media-audio">'
                    . '<source src="' . $safe . '"></audio>';
            case 'image':
                // SVG loaded as <img> prevents script execution — do not use <object> or inline SVG
                return '<img src="' . $safe . '" referrerpolicy="no-referrer" loading="lazy"'
                    . ' class="bink-media-image" alt="">';
            default:
                return '';
        }
    }

    public function getName(): string { return 'raw_media'; }
    public function getType(): string { return 'raw_media'; }

    private function detectMediaType(string $url): ?string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        if ($host === 'cdn.bsky.app' && strpos($path, '/img/') === 0) {
            return 'image';
        }

        $dotExt = strrchr($path, '.');
        $ext = $dotExt === false ? '' : ltrim($dotExt, '.');
        if ($ext === '' && preg_match('/@([a-z0-9]+)$/', $path, $matches)) {
            $ext = $matches[1];
        }

        if (in_array($ext, self::VIDEO_EXTS, true)) return 'video';
        if (in_array($ext, self::AUDIO_EXTS, true)) return 'audio';
        if (in_array($ext, self::IMAGE_EXTS, true)) return 'image';
        return null;
    }
}
