<?php

namespace BinktermPHP\AI;

interface AiProviderInterface
{
    public function getName(): string;

    public function getDefaultModel(): string;

    public function isConfigured(): bool;

    public function generateText(AiRequest $request): AiResponse;

    public function generateJson(AiRequest $request): AiResponse;

    public function supportsTools(): bool;

    /**
     * Send a tool-capable request and return normalized content blocks for
     * the agent loop.
     *
     * Returns:
     * - content: array of normalized blocks with type 'text' and/or 'tool_use'
     * - stop_reason: 'tool_use', 'end_turn', or provider-specific terminal reason
     * - usage: normalized token and estimated-cost accounting
     *
     * @param array<int, array{role: string, content: mixed}> $messages
     * @param array<int, array{name: string, description: string, input_schema: array<string, mixed>}> $tools
     * @return array{content: array<int, mixed>, stop_reason: string, usage: AiUsage}
     */
    public function generateWithTools(
        array $messages,
        array $tools,
        string $systemPrompt,
        string $model,
        int $maxTokens = 4096
    ): array;
}
