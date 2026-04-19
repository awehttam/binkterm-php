<?php

namespace BinktermPHP\AI;

/**
 * Reusable agentic tool-use loop.
 *
 * Runs a conversation with the AI provider, executing MCP tool calls as
 * requested by the model until it reaches a final answer (stop_reason:
 * end_turn) or the round limit is hit.
 *
 * Usage:
 *   $agent = new AgentService($anthropicProvider);
 *   $result = $agent->run($systemPrompt, $userMessage, $mcpClient, maxRounds: 5);
 *   echo $result->getText();
 */
class AgentService
{
    public function __construct(
        private AiProviderInterface $provider,
        private string $model,
        private UsageRecorder $usageRecorder = new UsageRecorder()
    ) {}

    /**
     * Run the tool-use loop to completion.
     *
     * @param string      $systemPrompt Initial system prompt
     * @param string      $userMessage  First user message
     * @param McpClient   $mcpClient    MCP client scoped to the current user
     * @param int         $maxRounds    Maximum tool-call rounds before forcing a final answer
     * @param string      $feature      Feature name recorded in ai_requests (e.g. 'message_ai_assistant')
     * @param int|null    $userId       User ID recorded in ai_requests
     * @return AgentResult
     * @throws \RuntimeException if the AI provider fails
     */
    public function run(
        string $systemPrompt,
        string $userMessage,
        McpClient $mcpClient,
        int $maxRounds = 5,
        string $feature = 'agent',
        ?int $userId = null
    ): AgentResult {
        if (!$this->provider->supportsTools()) {
            throw new \RuntimeException("AI provider '{$this->provider->getName()}' does not support tool use.");
        }

        $tools = $mcpClient->listTools();

        $messages = [
            ['role' => 'user', 'content' => $userMessage],
        ];

        $totalInputTokens        = 0;
        $totalOutputTokens       = 0;
        $totalCachedInputTokens  = 0;
        $totalCacheWriteTokens   = 0;
        $totalCostUsd            = 0.0;
        $toolCallCount           = 0;
        $rounds                  = 0;

        while ($rounds < $maxRounds) {
            $rounds++;

            // If we've hit the round limit, ask the AI to wrap up without tools
            $currentTools = ($rounds >= $maxRounds) ? [] : $tools;

            $roundStart = microtime(true);
            $turn = $this->provider->generateWithTools($messages, $currentTools, $systemPrompt, $this->model);
            $roundMs = (int)round((microtime(true) - $roundStart) * 1000);

            $usage = $turn['usage'];
            /** @var AiUsage $usage */
            $totalInputTokens       += $usage->getInputTokens();
            $totalOutputTokens      += $usage->getOutputTokens();
            $totalCachedInputTokens += $usage->getCachedInputTokens();
            $totalCacheWriteTokens  += $usage->getCacheWriteTokens();
            $totalCostUsd           += $usage->getEstimatedCostUsd();

            $stopReason      = $turn['stop_reason'];
            $contentBlocks   = $turn['content'];

            // Append the assistant's response to message history
            $messages[] = ['role' => 'assistant', 'content' => $contentBlocks];

            // Count tool_use blocks in this round so we can record them accurately
            $roundToolCalls = 0;
            foreach ($contentBlocks as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'tool_use') {
                    $roundToolCalls++;
                }
            }

            // Record this round in ai_requests
            $this->usageRecorder->recordAgentRound(
                $userId ?? 0,
                $feature,
                $this->provider->getName(),
                $this->model,
                $rounds,
                $roundToolCalls,
                $usage,
                $roundMs,
                $stopReason
            );

            if ($stopReason !== 'tool_use') {
                // Final answer — extract text
                $text = $this->extractText($contentBlocks);
                return new AgentResult(
                    $text,
                    new AiUsage(
                        $totalInputTokens,
                        $totalOutputTokens,
                        $totalCachedInputTokens,
                        $totalCacheWriteTokens,
                        0,
                        $totalCostUsd
                    ),
                    $toolCallCount,
                    $rounds
                );
            }

            // Execute all tool_use blocks and collect results
            $toolResults = [];
            foreach ($contentBlocks as $block) {
                if (!is_array($block) || ($block['type'] ?? '') !== 'tool_use') {
                    continue;
                }

                $toolUseId = (string)($block['id'] ?? '');
                $toolName  = (string)($block['name'] ?? '');
                $toolInput = is_array($block['input'] ?? null) ? $block['input'] : [];

                try {
                    $resultContent = $mcpClient->callTool($toolName, $toolInput);
                } catch (\Throwable $e) {
                    $resultContent = json_encode(['error' => $e->getMessage()]) ?: '{"error":"tool call failed"}';
                }

                $toolCallCount++;
                $toolResults[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $toolUseId,
                    'content'     => $resultContent,
                ];
            }

            if (empty($toolResults)) {
                // No actionable tool_use blocks — treat as final
                $text = $this->extractText($contentBlocks);
                return new AgentResult(
                    $text,
                    new AiUsage(
                        $totalInputTokens,
                        $totalOutputTokens,
                        $totalCachedInputTokens,
                        $totalCacheWriteTokens,
                        0,
                        $totalCostUsd
                    ),
                    $toolCallCount,
                    $rounds
                );
            }

            // Add tool results as a user turn and loop
            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        // Should not reach here — the last round forces end_turn — but handle defensively
        return new AgentResult(
            'I was unable to complete this request within the allowed number of steps.',
            new AiUsage(
                $totalInputTokens,
                $totalOutputTokens,
                $totalCachedInputTokens,
                $totalCacheWriteTokens,
                0,
                $totalCostUsd
            ),
            $toolCallCount,
            $rounds
        );
    }

    /**
     * Extract concatenated text from an array of Anthropic content blocks.
     *
     * @param array<int, mixed> $contentBlocks
     */
    private function extractText(array $contentBlocks): string
    {
        $parts = [];
        foreach ($contentBlocks as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $parts[] = (string)$block['text'];
            }
        }
        return implode("\n", $parts);
    }
}
