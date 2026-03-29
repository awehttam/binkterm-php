<?php

namespace BinktermPHP\AI;

/**
 * Normalized token and cost accounting for one provider call.
 */
class AiUsage
{
    private int $inputTokens;
    private int $outputTokens;
    private int $cachedInputTokens;
    private int $cacheWriteTokens;
    private int $totalTokens;
    private float $estimatedCostUsd;
    private string $currency;

    public function __construct(
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $cachedInputTokens = 0,
        int $cacheWriteTokens = 0,
        int $totalTokens = 0,
        float $estimatedCostUsd = 0.0,
        string $currency = 'USD'
    ) {
        $this->inputTokens = max(0, $inputTokens);
        $this->outputTokens = max(0, $outputTokens);
        $this->cachedInputTokens = max(0, $cachedInputTokens);
        $this->cacheWriteTokens = max(0, $cacheWriteTokens);
        $this->totalTokens = $totalTokens > 0
            ? $totalTokens
            : ($this->inputTokens + $this->outputTokens + $this->cachedInputTokens + $this->cacheWriteTokens);
        $this->estimatedCostUsd = max(0.0, $estimatedCostUsd);
        $this->currency = $currency;
    }

    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): int
    {
        return $this->outputTokens;
    }

    public function getCachedInputTokens(): int
    {
        return $this->cachedInputTokens;
    }

    public function getCacheWriteTokens(): int
    {
        return $this->cacheWriteTokens;
    }

    public function getTotalTokens(): int
    {
        return $this->totalTokens;
    }

    public function getEstimatedCostUsd(): float
    {
        return $this->estimatedCostUsd;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function withEstimatedCostUsd(float $estimatedCostUsd): self
    {
        return new self(
            $this->inputTokens,
            $this->outputTokens,
            $this->cachedInputTokens,
            $this->cacheWriteTokens,
            $this->totalTokens,
            $estimatedCostUsd,
            $this->currency
        );
    }
}
