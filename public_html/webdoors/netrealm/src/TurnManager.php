<?php

/**
 * Turn management for NetRealm RPG.
 *
 * Handles daily turn resets, turn consumption, and credit-based turn purchases.
 */
class TurnManager
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Check and perform daily reset if needed.
     *
     * Resets turns, extra_turns_today, and rest_uses_today when
     * turns_last_reset != CURRENT_DATE.
     *
     * @param int $characterId
     * @param int $dailyTurns Number of turns to grant
     * @return bool True if reset was performed
     */
    public function checkDailyReset(int $characterId, int $dailyTurns): bool
    {
        $stmt = $this->db->prepare('
            UPDATE netrealm_characters
            SET turns = ?,
                extra_turns_today = 0,
                rest_uses_today = 0,
                turns_last_reset = CURRENT_DATE,
                updated_at = NOW()
            WHERE id = ? AND turns_last_reset < CURRENT_DATE
        ');
        $stmt->execute([$dailyTurns, $characterId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Consume turns from a character.
     *
     * @param int $characterId
     * @param int $amount Number of turns to consume
     * @return bool True if character had enough turns
     */
    public function consumeTurns(int $characterId, int $amount = 1): bool
    {
        $stmt = $this->db->prepare('
            UPDATE netrealm_characters
            SET turns = turns - ?, updated_at = NOW()
            WHERE id = ? AND turns >= ?
        ');
        $stmt->execute([$amount, $characterId, $amount]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Purchase extra turns with credits.
     *
     * @param int $characterId
     * @param int $userId
     * @param int $amount Number of turns to purchase
     * @param int $creditsPerTurn Cost per turn in credits
     * @param int $maxExtraPerDay Maximum extra turns per day
     * @return array ['success' => bool, 'error' => string|null, 'turns_bought' => int, 'credits_spent' => int]
     */
    public function buyTurns(int $characterId, int $userId, int $amount, int $creditsPerTurn, int $maxExtraPerDay): array
    {
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'Invalid amount.', 'turns_bought' => 0, 'credits_spent' => 0];
        }

        // Check daily limit
        $stmt = $this->db->prepare('SELECT extra_turns_today FROM netrealm_characters WHERE id = ?');
        $stmt->execute([$characterId]);
        $char = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$char) {
            return ['success' => false, 'error' => 'Character not found.', 'turns_bought' => 0, 'credits_spent' => 0];
        }

        $remaining = $maxExtraPerDay - (int)$char['extra_turns_today'];
        if ($remaining <= 0) {
            return ['success' => false, 'error' => 'Daily extra turn limit reached.', 'turns_bought' => 0, 'credits_spent' => 0];
        }

        $amount = min($amount, $remaining);
        $totalCost = $amount * $creditsPerTurn;

        // Check balance
        try {
            $balance = \BinktermPHP\UserCredit::getBalance($userId);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Could not check credit balance.', 'turns_bought' => 0, 'credits_spent' => 0];
        }

        if ($balance < $totalCost) {
            return ['success' => false, 'error' => "Not enough credits. Need {$totalCost}, have {$balance}.", 'turns_bought' => 0, 'credits_spent' => 0];
        }

        // Debit credits
        $debited = \BinktermPHP\UserCredit::debit(
            $userId,
            $totalCost,
            "NetRealm: Purchased {$amount} extra turn(s)",
            null,
            \BinktermPHP\UserCredit::TYPE_PAYMENT
        );

        if (!$debited) {
            return ['success' => false, 'error' => 'Credit transaction failed.', 'turns_bought' => 0, 'credits_spent' => 0];
        }

        // Add turns
        $stmt = $this->db->prepare('
            UPDATE netrealm_characters
            SET turns = turns + ?, extra_turns_today = extra_turns_today + ?, updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$amount, $amount, $characterId]);

        return [
            'success' => true,
            'error' => null,
            'turns_bought' => $amount,
            'credits_spent' => $totalCost,
        ];
    }

    /**
     * Rest to recover HP. Limited uses per day.
     *
     * @param int $characterId
     * @param int $maxUsesPerDay
     * @param int $healPercent Percentage of max HP to heal
     * @return array ['success' => bool, 'error' => string|null, 'healed' => int, 'new_hp' => int]
     */
    public function rest(int $characterId, int $maxUsesPerDay, int $healPercent): array
    {
        $stmt = $this->db->prepare('SELECT * FROM netrealm_characters WHERE id = ?');
        $stmt->execute([$characterId]);
        $char = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$char) {
            return ['success' => false, 'error' => 'Character not found.', 'healed' => 0, 'new_hp' => 0];
        }

        if ((int)$char['rest_uses_today'] >= $maxUsesPerDay) {
            return ['success' => false, 'error' => "You've already rested {$maxUsesPerDay} time(s) today.", 'healed' => 0, 'new_hp' => (int)$char['hp']];
        }

        // Calculate effective max HP (with equipment)
        $bonusStmt = $this->db->prepare('
            SELECT COALESCE(SUM(hp_bonus), 0) AS total_hp
            FROM netrealm_inventory
            WHERE character_id = ? AND is_equipped = TRUE
        ');
        $bonusStmt->execute([$characterId]);
        $bonus = $bonusStmt->fetch(\PDO::FETCH_ASSOC);
        $effectiveMaxHp = $char['max_hp'] + (int)$bonus['total_hp'];

        $healAmount = (int)floor($effectiveMaxHp * ($healPercent / 100));
        $newHp = min($effectiveMaxHp, (int)$char['hp'] + $healAmount);
        $actualHeal = $newHp - (int)$char['hp'];

        $stmt = $this->db->prepare('
            UPDATE netrealm_characters
            SET hp = ?, rest_uses_today = rest_uses_today + 1, updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$newHp, $characterId]);

        return [
            'success' => true,
            'error' => null,
            'healed' => $actualHeal,
            'new_hp' => $newHp,
            'max_hp' => $effectiveMaxHp,
            'rest_uses_remaining' => $maxUsesPerDay - ((int)$char['rest_uses_today'] + 1),
        ];
    }
}
