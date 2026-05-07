<?php

namespace BinktermPHP\Media\EmbedProviders;

use BinktermPHP\Media\EmbedProviderInterface;

class RetroAudioProvider implements EmbedProviderInterface
{
    private const EXTS = ['xm', 'it', 's3m', 'mod', 'stm', 'amf', '669', 'mptm', 'sid', 'mid', 'midi'];

    public function matches(string $url): bool
    {
        return $this->getExtension($url) !== '';
    }

    public function getEmbedHtml(string $url): string
    {
        if ($this->getExtension($url) === '') {
            return '';
        }

        $playableUrl = '/api/media/raw?url=' . rawurlencode($url);
        $safeUrl = htmlspecialchars($playableUrl, ENT_QUOTES);
        $label = htmlspecialchars(rawurldecode(basename(parse_url($url, PHP_URL_PATH) ?? 'Audio file')), ENT_QUOTES);

        return '<div class="bink-retro-audio" data-retro-audio-url="' . $safeUrl . '"'
            . ' data-retro-audio-label="' . $label . '"></div>'
            . '<script>import("/js/retro-audio-player.js").then(function(m){'
            . 'm.renderRetroAudioPlayer(document.currentScript.previousElementSibling);'
            . '});</script>';
    }

    public function getName(): string { return 'raw_media'; }
    public function getType(): string { return 'raw_media'; }

    private function getExtension(string $url): string
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        $ext = ltrim(strrchr($path, '.') ?: '', '.');
        return in_array($ext, self::EXTS, true) ? $ext : '';
    }
}
