<?php

namespace BinktermPHP\AiBot;

/**
 * Interface for bot activity handlers.
 *
 * Reactive handlers are woken by PostgreSQL NOTIFY events.
 * Scheduled handlers are invoked by an internal daemon timer.
 */
interface BotActivityHandler
{
    /**
     * Canonical identifier stored in ai_bot_activities.activity_type.
     */
    public function getActivityType(): string;

    /**
     * Human-readable label shown in the admin UI.
     */
    public function getLabel(): string;

    /**
     * True = daemon registers this handler with the real-time event loop.
     * False = daemon calls handle() on a cron interval.
     */
    public function isReactive(): bool;

    /**
     * Called when the activity should process an event (reactive)
     * or perform its periodic work (scheduled).
     *
     * @param AiBot         $bot   The bot that owns this activity.
     * @param ActivityEvent $event Encapsulates the triggering data.
     */
    public function handle(AiBot $bot, ActivityEvent $event): void;
}
