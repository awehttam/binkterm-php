<?php

namespace BinktermPHP\AiBot\Middleware;

use BinktermPHP\AiBot\BotContext;
use BinktermPHP\AiBot\BotMiddlewareInterface;
use BinktermPHP\Binkp\Logger;

/**
 * Injects the contents of a local file into the bot's system prompt.
 *
 * Useful for keeping long context documents (knowledge bases, persona notes,
 * current pricing, etc.) in plain files that can be edited without touching
 * bot configuration.
 *
 * Configuration:
 *
 *   {
 *     "class": "FilePromptInjectorMiddleware",
 *     "config": {
 *       "path":      "config/bots/mybot/context.md",
 *       "position":  "append",
 *       "separator": "\n\n---\n\n"
 *     }
 *   }
 *
 * Options:
 *   path      — path to the file, relative to the project root (required)
 *   position  — "append" (default) or "prepend"
 *   separator — string inserted between the existing prompt and the file
 *               content (default: two newlines)
 *
 * If the file does not exist or cannot be read the middleware logs a warning
 * and passes through to the next stage without modifying the prompt.
 */
class FilePromptInjectorMiddleware implements BotMiddlewareInterface
{
    private string $path;
    private string $position;
    private string $separator;

    /** Absolute path to the project root. */
    private static string $projectRoot = '';

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config = [], ?Logger $logger = null)
    {
        $this->path      = (string)($config['path']      ?? '');
        $this->position  = (string)($config['position']  ?? 'append');
        $this->separator = (string)($config['separator'] ?? "\n\n");

        if (self::$projectRoot === '') {
            self::$projectRoot = realpath(__DIR__ . '/../../../') ?: '';
        }
    }

    public function handle(BotContext $ctx, callable $next): void
    {
        if ($this->path !== '') {
            $abs = self::$projectRoot . DIRECTORY_SEPARATOR . ltrim($this->path, '/\\');
            if (is_readable($abs)) {
                $content = file_get_contents($abs);
                if ($content !== false && trim($content) !== '') {
                    if ($this->position === 'prepend') {
                        $ctx->systemPrompt = trim($content) . $this->separator . $ctx->systemPrompt;
                    } else {
                        $ctx->systemPrompt = $ctx->systemPrompt . $this->separator . trim($content);
                    }
                }
            }
        }

        $next();
    }
}
