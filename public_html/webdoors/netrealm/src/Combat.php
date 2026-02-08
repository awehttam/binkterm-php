<?php

/**
 * PvE combat engine for NetRealm RPG.
 *
 * Handles multi-round combat, damage calculation, rewards, and loot drops.
 */
class Combat
{
    private \PDO $db;
    private Character $character;
    private TurnManager $turnManager;

    public function __construct(\PDO $db, Character $character, TurnManager $turnManager)
    {
        $this->db = $db;
        $this->character = $character;
        $this->turnManager = $turnManager;
    }

    /**
     * Execute a PvE fight against a monster.
     *
     * @param int $characterId
     * @param string $monsterKey
     * @param array $config Game configuration
     * @return array Combat result
     * @throws \Exception
     */
    public function fight(int $characterId, string $monsterKey, array $config = []): array
    {
        $char = $this->character->getById($characterId);
        if (!$char) {
            throw new \Exception('Character not found.');
        }

        if ((int)$char['hp'] <= 0) {
            throw new \Exception('You are too injured to fight. Rest first.');
        }

        // Consume a turn
        if (!$this->turnManager->consumeTurns($characterId)) {
            throw new \Exception('No turns remaining. Wait for daily reset or buy more.');
        }

        // Get monster
        $monster = MonsterDatabase::get($monsterKey);
        if (!$monster) {
            // Refund turn on invalid monster
            $this->db->prepare('UPDATE netrealm_characters SET turns = turns + 1 WHERE id = ?')
                ->execute([$characterId]);
            throw new \Exception('Unknown monster.');
        }

        // Check level range
        if ($char['level'] < $monster['min_level'] || $char['level'] > $monster['max_level']) {
            $this->db->prepare('UPDATE netrealm_characters SET turns = turns + 1 WHERE id = ?')
                ->execute([$characterId]);
            throw new \Exception('That monster is not available at your level.');
        }

        // Scale monster
        $monster = MonsterDatabase::scaleMonster($monster, $char['level']);

        // Get effective stats
        $stats = $this->character->getEffectiveStats($char);

        // Run combat rounds
        $playerHp = (int)$char['hp'];
        $monsterHp = $monster['hp'];
        $rounds = [];
        $round = 0;
        $maxRounds = 50;

        while ($playerHp > 0 && $monsterHp > 0 && $round < $maxRounds) {
            $round++;

            // Player attacks
            $playerDmg = $this->calculateDamage($stats['attack'], $monster['defense']);
            $monsterHp -= $playerDmg;

            $roundData = [
                'round' => $round,
                'player_damage' => $playerDmg,
                'monster_damage' => 0,
                'player_hp' => $playerHp,
                'monster_hp' => max(0, $monsterHp),
            ];

            // Monster attacks (if still alive)
            if ($monsterHp > 0) {
                $monsterDmg = $this->calculateDamage($monster['attack'], $stats['defense']);
                $playerHp -= $monsterDmg;
                $roundData['monster_damage'] = $monsterDmg;
                $roundData['player_hp'] = max(0, $playerHp);
            }

            $rounds[] = $roundData;
        }

        $victory = $monsterHp <= 0;
        $result = [
            'victory' => $victory,
            'monster_name' => $monster['name'],
            'monster_key' => $monsterKey,
            'rounds' => $rounds,
            'total_rounds' => $round,
            'xp_gained' => 0,
            'gold_gained' => 0,
            'loot' => null,
            'leveled_up' => false,
            'new_level' => $char['level'],
            'gold_from_full_inventory' => false,
        ];

        if ($victory) {
            // Reward XP
            $xpResult = $this->character->awardXp($characterId, $monster['xp_reward']);
            $result['xp_gained'] = $monster['xp_reward'];
            $result['leveled_up'] = $xpResult['leveled_up'];
            $result['new_level'] = $xpResult['new_level'];

            // Reward gold
            $goldReward = $monster['gold_reward'] + random_int(0, (int)($monster['gold_reward'] * 0.3));
            $this->character->awardGold($characterId, $goldReward);
            $result['gold_gained'] = $goldReward;

            // Increment kill count
            $this->character->incrementKills($characterId);

            // Loot chance
            if ($this->rollLootDrop($monster['loot_chance'])) {
                $loot = ItemDatabase::getRandomLoot($char['level']);
                if ($loot) {
                    $maxInventory = (int)($config['max_inventory_size'] ?? 20);
                    $invCount = $this->getInventoryCount($characterId);

                    if ($invCount < $maxInventory) {
                        $this->addLootToInventory($characterId, $loot);
                        $result['loot'] = $loot;
                    } else {
                        // Full inventory: convert to gold
                        $extraGold = (int)($loot['buy_price'] * 0.5);
                        $this->character->awardGold($characterId, $extraGold);
                        $result['gold_gained'] += $extraGold;
                        $result['gold_from_full_inventory'] = true;
                        $result['loot_converted_name'] = $loot['name'];
                        $result['loot_converted_gold'] = $extraGold;
                    }
                }
            }

            // Update HP (capped at 1 minimum since they won)
            $this->character->setHp($characterId, max(1, $playerHp));
        } else {
            // Defeat: lose 10% gold, HP set to 1
            $goldLoss = (int)floor($char['gold'] * 0.1);
            if ($goldLoss > 0) {
                $this->character->deductGold($characterId, $goldLoss);
                $result['gold_gained'] = -$goldLoss;
            }
            $this->character->setHp($characterId, 1);
        }

        // Log combat
        $this->logCombat($characterId, 'pve', $monster['name'], $victory ? 'victory' : 'defeat', $result);

        // Reload character for response
        $updatedChar = $this->character->getById($characterId);
        $result['character'] = $this->character->getStatus($updatedChar);

        return $result;
    }

    /**
     * Calculate damage dealt.
     *
     * @param int $attack Attacker's attack stat
     * @param int $defense Defender's defense stat
     * @return int Damage dealt (minimum 1)
     */
    public function calculateDamage(int $attack, int $defense): int
    {
        $variance = $attack * (mt_rand(80, 120) / 100);
        $damage = $variance - ($defense * 0.5);
        return max(1, (int)round($damage));
    }

    /**
     * Roll for loot drop.
     *
     * @param float $chance Drop chance (0.0 to 1.0)
     * @return bool
     */
    private function rollLootDrop(float $chance): bool
    {
        return (mt_rand(1, 1000) / 1000) <= $chance;
    }

    /**
     * Get inventory item count for a character.
     *
     * @param int $characterId
     * @return int
     */
    private function getInventoryCount(int $characterId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM netrealm_inventory WHERE character_id = ?');
        $stmt->execute([$characterId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Add a loot item to character's inventory.
     *
     * @param int $characterId
     * @param array $loot Item data with rarity applied
     */
    private function addLootToInventory(int $characterId, array $loot): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO netrealm_inventory
                (character_id, item_key, item_name, item_type, rarity, attack_bonus, defense_bonus, hp_bonus, buy_price)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $characterId,
            $loot['item_key'],
            $loot['name'],
            $loot['type'],
            $loot['rarity'],
            $loot['attack_bonus'],
            $loot['defense_bonus'],
            $loot['hp_bonus'],
            $loot['buy_price'],
        ]);
    }

    /**
     * Log a combat encounter.
     *
     * @param int $characterId
     * @param string $type pve|pvp
     * @param string $opponentName
     * @param string $result victory|defeat
     * @param array $details Full combat data
     */
    public function logCombat(int $characterId, string $type, string $opponentName, string $result, array $details): void
    {
        $logDetails = [
            'rounds' => $details['total_rounds'] ?? 0,
            'xp_gained' => $details['xp_gained'] ?? 0,
            'gold_gained' => $details['gold_gained'] ?? 0,
        ];
        if (!empty($details['loot'])) {
            $logDetails['loot'] = $details['loot']['name'] ?? null;
            $logDetails['loot_rarity'] = $details['loot']['rarity'] ?? null;
        }

        $stmt = $this->db->prepare('
            INSERT INTO netrealm_combat_log
                (character_id, combat_type, opponent_name, result, xp_gained, gold_gained, loot_item, details)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $characterId,
            $type,
            $opponentName,
            $result,
            $details['xp_gained'] ?? 0,
            $details['gold_gained'] ?? 0,
            $details['loot']['name'] ?? null,
            json_encode($logDetails),
        ]);
    }

    /**
     * Get recent combat log entries.
     *
     * @param int $characterId
     * @param int $limit
     * @return array
     */
    public function getCombatLog(int $characterId, int $limit = 20): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM netrealm_combat_log
            WHERE character_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ');
        $stmt->execute([$characterId, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
