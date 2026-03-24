<?php

namespace BinktermPHP\AI;

/**
 * Normalized provider response.
 */
class AiResponse
{
    private string $provider;
    private string $model;
    private string $content;
    private ?array $parsedJson;
    private AiUsage $usage;
    private ?string $requestId;
    private ?string $finishReason;
    private array $rawResponse;

    public function __construct(
        string $provider,
        string $model,
        string $content,
        AiUsage $usage,
        ?array $parsedJson = null,
        ?string $requestId = null,
        ?string $finishReason = null,
        array $rawResponse = []
    ) {
        $this->provider = $provider;
        $this->model = $model;
        $this->content = $content;
        $this->usage = $usage;
        $this->parsedJson = $parsedJson;
        $this->requestId = $requestId;
        $this->finishReason = $finishReason;
        $this->rawResponse = $rawResponse;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getParsedJson(): ?array
    {
        return $this->parsedJson;
    }

    public function getUsage(): AiUsage
    {
        return $this->usage;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getFinishReason(): ?string
    {
        return $this->finishReason;
    }

    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }
}
