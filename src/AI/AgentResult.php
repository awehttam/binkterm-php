<?php

namespace BinktermPHP\AI;

/**
 * Result returned by AgentService::run() after a completed tool-use loop.
 */
class AgentResult
{
    /**
     * @param string   $text           Final text response from the AI
     * @param AiUsage  $totalUsage     Cumulative token usage across all rounds
     * @param int      $toolCallCount  Total number of tool calls executed
     * @param int      $rounds         Number of API round-trips made
     */
    public function __construct(
        private string $text,
        private AiUsage $totalUsage,
        private int $toolCallCount,
        private int $rounds
    ) {}

    public function getText(): string
    {
        return $this->text;
    }

    public function getTotalUsage(): AiUsage
    {
        return $this->totalUsage;
    }

    public function getToolCallCount(): int
    {
        return $this->toolCallCount;
    }

    public function getRounds(): int
    {
        return $this->rounds;
    }
}
