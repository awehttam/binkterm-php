<?php

namespace BinktermPHP\AiBot;

/**
 * Mutable carrier object passed through the bot middleware pipeline.
 *
 * Middleware may:
 *  - Modify $systemPrompt to inject additional instructions.
 *  - Modify $incomingMessage to rewrite the user's input before the AI sees it.
 *  - Modify $conversationHistory to adjust the context window.
 *  - Set $response to a non-null string to short-circuit the AI call entirely.
 *  - Set $aborted = true to suppress any reply (no message is posted).
 */
class BotContext
{
    /** System prompt forwarded to the AI (may be modified by middleware). */
    public string $systemPrompt;

    /** The user's message text (may be modified by middleware). */
    public string $incomingMessage;

    /**
     * Conversation history passed to the AI as context.
     * Each entry is ['role' => 'user'|'assistant', 'content' => '...'].
     *
     * @var array<int, array{role: string, content: string}>
     */
    public array $conversationHistory;

    /**
     * When set to a non-null string the pipeline skips the AI call and posts
     * this text as the bot's reply instead.
     */
    public ?string $response = null;

    /**
     * When true the pipeline posts no reply at all, regardless of $response.
     */
    public bool $aborted = false;

    /**
     * @param array<string,mixed> $activityConfig  Decoded ai_bot_activities.config_json for this bot.
     * @param array<int, array{role: string, content: string}> $conversationHistory
     */
    public function __construct(
        public readonly AiBot  $bot,
        public readonly int    $fromUserId,
        public readonly string $fromUsername,
        public readonly ?int   $roomId,
        public readonly ?int   $toUserId,
        public readonly array  $activityConfig,
        string $systemPrompt,
        string $incomingMessage,
        array  $conversationHistory = [],
    ) {
        $this->systemPrompt        = $systemPrompt;
        $this->incomingMessage     = $incomingMessage;
        $this->conversationHistory = $conversationHistory;
    }
}
