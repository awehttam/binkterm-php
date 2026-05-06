<?php

namespace BinktermPHP\Media;

interface EmbedProviderInterface
{
    /** Returns true if this provider handles the given URL. */
    public function matches(string $url): bool;

    /** Returns the complete embed HTML for the given URL. */
    public function getEmbedHtml(string $url): string;

    /** Returns a stable identifier for this provider (e.g. 'youtube'). */
    public function getName(): string;

    /** Returns 'platform' or 'raw_media'. */
    public function getType(): string;
}
