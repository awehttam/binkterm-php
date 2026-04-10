<?php

namespace BinktermPHP\AiBot;

/**
 * Interface for bot processing middleware.
 *
 * Middleware sits in a pipeline between the raw chat event and the AI call.
 * Each implementation receives a BotContext and a $next callable.
 *
 * To continue the chain, call $next().
 * To short-circuit and reply without calling the AI, set $ctx->response and return.
 * To suppress any reply entirely, set $ctx->aborted = true and return.
 *
 * Example — a simple logging middleware:
 *
 *   public function handle(BotContext $ctx, callable $next): void
 *   {
 *       error_log('Bot received: ' . $ctx->incomingMessage);
 *       $next();
 *       error_log('Bot replied: ' . ($ctx->response ?? '(none)'));
 *   }
 */
interface BotMiddlewareInterface
{
    /**
     * Process the context and optionally invoke the next middleware.
     *
     * @param BotContext $ctx  Mutable pipeline state.
     * @param callable   $next Call with no arguments to advance the pipeline.
     */
    public function handle(BotContext $ctx, callable $next): void;
}
