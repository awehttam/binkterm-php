<?php

namespace BinktermPHP\AiBot;

use BinktermPHP\Binkp\Logger;

/**
 * Executes a sequence of BotMiddlewareInterface instances around a terminal callable.
 *
 * Built-in middleware is resolved by short name from BinktermPHP\AiBot\Middleware\.
 * Custom middleware must be referenced by its fully-qualified class name.
 *
 * Configuration shape (from ai_bot_activities.config_json → "middleware" array):
 *
 *   [
 *     { "class": "SlashCommandMiddleware",    "config": { ... } },
 *     { "class": "FilePromptInjectorMiddleware", "config": { ... } },
 *     { "class": "My\\Custom\\Handler",        "config": { ... } }
 *   ]
 */
class BotMiddlewarePipeline
{
    /** @var BotMiddlewareInterface[] */
    private array $middleware;

    /**
     * @param BotMiddlewareInterface[] $middleware
     */
    public function __construct(array $middleware = [])
    {
        $this->middleware = $middleware;
    }

    /**
     * Build a pipeline from the "middleware" array in an activity config.
     *
     * Unknown or unloadable classes are silently skipped and logged as warnings
     * when a logger is provided.
     *
     * Each middleware constructor is called as `new $class($config, $logger)`.
     * Middleware that does not need a logger simply ignores the second argument.
     *
     * @param array<int, array{class: string, config?: array<string,mixed>}> $config
     */
    public static function fromConfig(array $config, ?Logger $logger = null): self
    {
        $instances = [];
        foreach ($config as $entry) {
            $class    = (string)($entry['class']  ?? '');
            $mwConfig = (array) ($entry['config'] ?? []);

            if ($class === '') {
                continue;
            }

            // Resolve short names to the built-in middleware namespace.
            if (!str_contains($class, '\\')) {
                $class = 'BinktermPHP\\AiBot\\Middleware\\' . $class;
            }

            if (!class_exists($class)) {
                $logger?->warning('[Middleware] Class not found, skipping', ['class' => $class]);
                continue;
            }

            /** @var BotMiddlewareInterface $instance */
            $instance = new $class($mwConfig, $logger);
            if ($instance instanceof BotMiddlewareInterface) {
                $instances[] = $instance;
            } else {
                $logger?->warning('[Middleware] Class does not implement BotMiddlewareInterface, skipping', ['class' => $class]);
            }
        }

        return new self($instances);
    }

    /**
     * Run the middleware chain, calling $final when all middleware have passed.
     *
     * @param BotContext $ctx
     * @param callable   $final Terminal handler (typically the AI call).
     */
    public function run(BotContext $ctx, callable $final): void
    {
        $this->dispatch($ctx, $final, 0);
    }

    private function dispatch(BotContext $ctx, callable $final, int $index): void
    {
        if ($index >= count($this->middleware)) {
            $final($ctx);
            return;
        }

        $current = $this->middleware[$index];
        $current->handle($ctx, function () use ($ctx, $final, $index): void {
            $this->dispatch($ctx, $final, $index + 1);
        });
    }
}
