<?php

namespace BinktermPHP\AiBot\Middleware;

use BinktermPHP\AiBot\BotContext;
use BinktermPHP\AiBot\BotMiddlewareInterface;
use BinktermPHP\Binkp\Logger;

/**
 * Intercepts slash commands and returns a static reply without calling the AI.
 *
 * Configuration:
 *
 *   {
 *     "class": "SlashCommandMiddleware",
 *     "config": {
 *       "commands": {
 *         "/help":    "I can answer questions about...",
 *         "/version": "BinktermPHP 1.9.1",
 *         "/ping":    "Pong!"
 *       },
 *       "case_sensitive": false
 *     }
 *   }
 *
 * Matching is prefix-based so "/help" matches "/help me" as well as "/help".
 * The command token is the first whitespace-delimited word of the message.
 * When a command is matched the pipeline is short-circuited: $next is not called.
 * When no command matches the message passes through to the next middleware unchanged.
 */
class SlashCommandMiddleware implements BotMiddlewareInterface
{
    /** @var array<string, string> */
    private array $commands;
    private bool  $caseSensitive;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config = [], ?Logger $logger = null)
    {
        $raw  = (array)($config['commands']       ?? []);
        $this->caseSensitive = (bool)($config['case_sensitive'] ?? false);

        // Normalise keys according to case sensitivity setting.
        $this->commands = [];
        foreach ($raw as $cmd => $reply) {
            $key = $this->caseSensitive ? $cmd : strtolower((string)$cmd);
            $this->commands[$key] = (string)$reply;
        }
    }

    public function handle(BotContext $ctx, callable $next): void
    {
        $message = trim($ctx->incomingMessage);

        if ($message === '' || $message[0] !== '/') {
            $next();
            return;
        }

        // Extract the command token (first word).
        $token = strtok($message, " \t\n\r");
        $key   = $this->caseSensitive ? $token : strtolower((string)$token);

        if (isset($this->commands[$key])) {
            $ctx->response = $this->commands[$key];
            return; // short-circuit — do not call $next()
        }

        $next();
    }
}
