<?php

namespace BinktermPHP\AiBot;

use BinktermPHP\Database;

/**
 * Represents a configured AI bot and provides budget helpers.
 */
class AiBot
{
    private \PDO $db;

    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $systemPrompt,
        public readonly ?string $provider,
        public readonly ?string $model,
        public readonly float $weeklyBudgetUsd,
        public readonly int $contextMessages,
        public readonly bool $isActive,
        /** Cached spend for the current week, refreshed after each AI call */
        private float $cachedWeeklySpend = 0.0,
        ?\PDO $db = null
    ) {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    /**
     * Query the current week's AI spend for this bot from ai_requests.
     * The week runs Sunday 00:00 UTC through Saturday 23:59:59 UTC.
     */
    public function getCurrentWeekCostUsd(): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(estimated_cost_usd), 0)
            FROM   ai_requests
            WHERE  bot_id = ?
              AND  created_at >= (
                       date_trunc('week', NOW() AT TIME ZONE 'UTC') - INTERVAL '1 day'
                       + CASE WHEN EXTRACT(DOW FROM NOW() AT TIME ZONE 'UTC') = 0
                              THEN INTERVAL '7 days' ELSE INTERVAL '0 days' END
                   )
              AND  status = 'success'
        ");
        $stmt->execute([$this->id]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * Return true if this bot has not yet reached its weekly budget.
     * Uses the cached spend value for efficiency; call refreshCachedSpend()
     * after each AI call to keep it accurate.
     */
    public function isUnderBudget(): bool
    {
        return $this->cachedWeeklySpend < $this->weeklyBudgetUsd;
    }

    /**
     * Refresh the cached weekly spend from the database.
     */
    public function refreshCachedSpend(): void
    {
        $this->cachedWeeklySpend = $this->getCurrentWeekCostUsd();
    }

    /**
     * Add an amount to the cached spend (called after a successful AI response
     * to avoid an extra DB round-trip per message).
     */
    public function addToCachedSpend(float $amount): void
    {
        $this->cachedWeeklySpend += $amount;
    }

    public function getCachedWeeklySpend(): float
    {
        return $this->cachedWeeklySpend;
    }

    /**
     * Build an AiBot from a raw database row.
     *
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row, ?\PDO $db = null): self
    {
        return new self(
            id:               (int)$row['id'],
            userId:           (int)$row['user_id'],
            name:             (string)$row['name'],
            description:      isset($row['description']) ? (string)$row['description'] : null,
            systemPrompt:     (string)$row['system_prompt'],
            provider:         isset($row['provider']) ? (string)$row['provider'] : null,
            model:            isset($row['model']) ? (string)$row['model'] : null,
            weeklyBudgetUsd:  (float)$row['weekly_budget_usd'],
            contextMessages:  (int)$row['context_messages'],
            isActive:         (bool)$row['is_active'],
            db:               $db
        );
    }
}
