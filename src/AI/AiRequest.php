<?php

namespace BinktermPHP\AI;

/**
 * Provider-agnostic AI request payload.
 */
class AiRequest
{
    private ?string $provider;
    private ?string $model;
    private string $feature;
    private string $systemPrompt;
    private string $userPrompt;
    private float $temperature;
    private int $maxOutputTokens;
    private int $timeoutSeconds;
    private ?int $userId;
    private array $metadata;

    public function __construct(
        string $feature,
        string $systemPrompt,
        string $userPrompt,
        ?string $provider = null,
        ?string $model = null,
        float $temperature = 0.2,
        int $maxOutputTokens = 4096,
        int $timeoutSeconds = 60,
        ?int $userId = null,
        array $metadata = []
    ) {
        $this->provider = $provider !== null ? trim($provider) : null;
        $this->model = $model !== null ? trim($model) : null;
        $this->feature = trim($feature);
        $this->systemPrompt = $systemPrompt;
        $this->userPrompt = $userPrompt;
        $this->temperature = $temperature;
        $this->maxOutputTokens = $maxOutputTokens;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->userId = $userId;
        $this->metadata = $metadata;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getFeature(): string
    {
        return $this->feature;
    }

    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function getUserPrompt(): string
    {
        return $this->userPrompt;
    }

    public function getTemperature(): float
    {
        return $this->temperature;
    }

    public function getMaxOutputTokens(): int
    {
        return $this->maxOutputTokens;
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function withResolvedProviderAndModel(string $provider, string $model): self
    {
        return new self(
            $this->feature,
            $this->systemPrompt,
            $this->userPrompt,
            $provider,
            $model,
            $this->temperature,
            $this->maxOutputTokens,
            $this->timeoutSeconds,
            $this->userId,
            $this->metadata
        );
    }
}
