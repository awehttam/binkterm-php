<?php

namespace BinktermPHP\AI;

use BinktermPHP\Config;

/**
 * Cost estimator for normalized AI usage rows.
 *
 * Prices are intentionally config-driven. Unknown models default to zero cost
 * until the sysop sets explicit rates in the environment.
 */
class AiPricing
{
    public function estimateCost(string $provider, string $model, AiUsage $usage): float
    {
        $rates = $this->getRates($provider, $model);

        $cost = 0.0;
        $cost += ($usage->getInputTokens() / 1000000) * $rates['input'];
        $cost += ($usage->getOutputTokens() / 1000000) * $rates['output'];
        $cost += ($usage->getCachedInputTokens() / 1000000) * $rates['cached_input'];
        $cost += ($usage->getCacheWriteTokens() / 1000000) * $rates['cache_write'];

        return round($cost, 8);
    }

    /**
     * @return array{input: float, output: float, cached_input: float, cache_write: float}
     */
    private function getRates(string $provider, string $model): array
    {
        return [
            'input' => $this->getRate($provider, $model, 'INPUT'),
            'output' => $this->getRate($provider, $model, 'OUTPUT'),
            'cached_input' => $this->getRate($provider, $model, 'CACHED_INPUT'),
            'cache_write' => $this->getRate($provider, $model, 'CACHE_WRITE'),
        ];
    }

    private function getRate(string $provider, string $model, string $type): float
    {
        $providerKey = $this->normalizeKeyPart($provider);
        $modelKey = $this->normalizeKeyPart($model);

        $specific = Config::env("AI_PRICE_{$providerKey}_{$modelKey}_{$type}_PER_MILLION_USD", null);
        if ($specific !== null && $specific !== '') {
            return (float)$specific;
        }

        $providerDefault = Config::env("AI_PRICE_{$providerKey}_{$type}_PER_MILLION_USD", null);
        if ($providerDefault !== null && $providerDefault !== '') {
            return (float)$providerDefault;
        }

        return 0.0;
    }

    private function normalizeKeyPart(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9]+/', '_', $value) ?? $value;
        return trim($value, '_');
    }
}
