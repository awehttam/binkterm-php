<?php

use BinktermPHP\Media\EmbedProviders\GenericRawMediaProvider;
use PHPUnit\Framework\TestCase;

class GenericRawMediaProviderTest extends TestCase
{
    public function testBlueskyCdnImagePathWithoutExtensionMatchesImage(): void
    {
        $provider = new GenericRawMediaProvider();
        $url = 'https://cdn.bsky.app/img/feed_fullsize/plain/did:plc:bvfpfl5oopdjy4dnv6aqspii/bafkreigy4ajwzwzc4o2ahxwjkd4efoh7nn3jvvlt67xabgtolw6siy7q3e';

        $this->assertTrue($provider->matches($url));
        $this->assertStringContainsString('<img ', $provider->getEmbedHtml($url));
    }

    public function testNonImageExtensionlessUrlDoesNotMatch(): void
    {
        $provider = new GenericRawMediaProvider();

        $this->assertFalse($provider->matches('https://example.com/files/no-extension'));
    }
}
