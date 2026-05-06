<?php

namespace BinktermPHP\Media;

use BinktermPHP\Media\EmbedProviders\YouTubeProvider;
use BinktermPHP\Media\EmbedProviders\OdyseeProvider;
use BinktermPHP\Media\EmbedProviders\RumbleProvider;
use BinktermPHP\Media\EmbedProviders\BitChuteProvider;
use BinktermPHP\Media\EmbedProviders\BrighteonProvider;
use BinktermPHP\Media\EmbedProviders\PeerTubeProvider;
use BinktermPHP\Media\EmbedProviders\BastyonProvider;
use BinktermPHP\Media\EmbedProviders\SoundCloudProvider;
use BinktermPHP\Media\EmbedProviders\TwitterProvider;
use BinktermPHP\Media\EmbedProviders\TikTokProvider;
use BinktermPHP\Media\EmbedProviders\ReverbNationProvider;
use BinktermPHP\Media\EmbedProviders\GenericRawMediaProvider;

class MediaLinkResolver
{
    /** @var EmbedProviderInterface[] */
    private array $providers;

    public function __construct(array $providers = [])
    {
        $this->providers = empty($providers) ? self::defaultProviders() : $providers;
    }

    /** @return EmbedProviderInterface[] */
    public static function defaultProviders(): array
    {
        return [
            new YouTubeProvider(),
            new OdyseeProvider(),
            new RumbleProvider(),
            new BitChuteProvider(),
            new BrighteonProvider(),
            new PeerTubeProvider(),
            new BastyonProvider(),
            new SoundCloudProvider(),
            new TwitterProvider(),
            new TikTokProvider(),
            new ReverbNationProvider(),
            new GenericRawMediaProvider(),
        ];
    }

    /**
     * Resolves a URL to embed metadata, or returns null if no provider matches.
     *
     * @return array{type: string, provider: string, embed_html: string}|null
     */
    public function resolve(string $url): ?array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        foreach ($this->providers as $provider) {
            if ($provider->matches($url)) {
                $html = $provider->getEmbedHtml($url);
                if ($html === '') {
                    continue;
                }
                return [
                    'type'       => $provider->getType(),
                    'provider'   => $provider->getName(),
                    'embed_html' => $html,
                ];
            }
        }

        return null;
    }
}
