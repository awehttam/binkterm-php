<?php

namespace BinktermPHP\AiBot;

/**
 * Encapsulates the data that triggered a bot activity.
 */
class ActivityEvent
{
    public function __construct(
        /** e.g. 'chat_direct' | 'chat_mention' */
        public readonly string $type,
        /** Event-specific data */
        public readonly array $payload
    ) {}
}
