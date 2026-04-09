<?php

namespace BinktermPHP\AiBot\Middleware;

use BinktermPHP\AiBot\BotContext;
use BinktermPHP\AiBot\BotMiddlewareInterface;
use BinktermPHP\Binkp\Logger;

/**
 * Filters or rewrites incoming messages based on a regular expression.
 *
 * Configuration:
 *
 *   {
 *     "class": "RegexFilterMiddleware",
 *     "config": {
 *       "pattern":     "/^!nobot/i",
 *       "action":      "abort",
 *       "replacement": ""
 *     }
 *   }
 *
 * Options:
 *   pattern     — PCRE regex tested against $ctx->incomingMessage (required)
 *   action      — what to do on match:
 *                   "abort"   — set $ctx->aborted = true, no reply (default)
 *                   "rewrite" — replace the message using preg_replace() with
 *                               the value of "replacement"
 *                   "reply"   — set $ctx->response to "replacement" and
 *                               short-circuit the AI call
 *   replacement — replacement string used by "rewrite" and "reply" actions;
 *                 supports back-references ($1, $2, etc.)
 *
 * When the pattern does not match the message passes through unchanged.
 * An invalid regex is silently ignored and the middleware passes through.
 */
class RegexFilterMiddleware implements BotMiddlewareInterface
{
    private string $pattern;
    private string $action;
    private string $replacement;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config = [], ?Logger $logger = null)
    {
        $this->pattern     = (string)($config['pattern']     ?? '');
        $this->action      = (string)($config['action']      ?? 'abort');
        $this->replacement = (string)($config['replacement'] ?? '');
    }

    public function handle(BotContext $ctx, callable $next): void
    {
        if ($this->pattern === '') {
            $next();
            return;
        }

        // Guard against invalid regex patterns.
        if (@preg_match($this->pattern, '') === false) {
            $next();
            return;
        }

        if (!preg_match($this->pattern, $ctx->incomingMessage)) {
            $next();
            return;
        }

        switch ($this->action) {
            case 'abort':
                $ctx->aborted = true;
                return;

            case 'reply':
                $ctx->response = preg_replace($this->pattern, $this->replacement, $ctx->incomingMessage) ?? $this->replacement;
                return;

            case 'rewrite':
                $rewritten = preg_replace($this->pattern, $this->replacement, $ctx->incomingMessage);
                if ($rewritten !== null) {
                    $ctx->incomingMessage = $rewritten;
                }
                $next();
                return;

            default:
                $next();
        }
    }
}
