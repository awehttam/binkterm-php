<?php

namespace BinktermPHP\AI;

/**
 * Providers that estimate local power consumption cost (e.g. Ollama) implement
 * this interface so AiService can fold power cost into the usage row after the
 * request duration is known.
 */
interface PowerCostAwareInterface
{
    /**
     * Return the estimated electricity cost in USD for a request of the given duration.
     * Returns 0.0 when power cost variables are not configured.
     */
    public function computePowerCost(int $durationMs): float;
}
