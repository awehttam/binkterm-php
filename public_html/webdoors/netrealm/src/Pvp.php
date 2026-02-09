<?php

/**
 * PvP system for NetRealm RPG.
 *
 * Manages player challenges via local echomail (NETREALM_SYNC).
 * Phase 1: local-only, but designed for future cross-node support.
 */
class Pvp
{
    private \PDO $db;
    private Character $character;
    private TurnManager $turnManager;
    private Combat $combat;

    public function __construct(\PDO $db, Character $character, TurnManager $turnManager, Combat $combat)
    {
        $this->db = $db;
        $this->character = $character;
        $this->turnManager = $turnManager;
        $this->combat = $combat;
    }

    /**
     * Sync a local character's stats to the network players table.
     *
     * @param array $char Character data
     * @param string $nodeAddress Local node address
     */
    public function syncLocalPlayer(array $char, string $nodeAddress): void
    {
        $stats = $this->character->getEffectiveStats($char);

        $stmt = $this->db->prepare('
            INSERT INTO netrealm_network_players
                (player_name, node_address, character_id, level, attack, defense, hp, max_hp, pvp_wins, pvp_losses, last_synced)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON CONFLICT (player_name, node_address)
            DO UPDATE SET
                character_id = EXCLUDED.character_id,
                level = EXCLUDED.level,
                attack = EXCLUDED.attack,
                defense = EXCLUDED.defense,
                hp = EXCLUDED.hp,
                max_hp = EXCLUDED.max_hp,
                pvp_wins = EXCLUDED.pvp_wins,
                pvp_losses = EXCLUDED.pvp_losses,
                last_synced = NOW()
        ');
        $stmt->execute([
            $char['name'],
            $nodeAddress,
            $char['id'],
            $char['level'],
            $stats['attack'],
            $stats['defense'],
            $char['hp'],
            $stats['max_hp'],
            $char['pvp_wins'],
            $char['pvp_losses'],
        ]);
    }

    /**
     * Get challengeable players within level range.
     *
     * @param int $characterId Attacker's character ID
     * @param int $level Attacker's level
     * @param int $levelRange Max level difference
     * @return array
     */
    public function getChallengeable(int $characterId, int $level, int $levelRange): array
    {
        $minLevel = max(1, $level - $levelRange);
        $maxLevel = $level + $levelRange;

        $stmt = $this->db->prepare('
            SELECT np.*,
                   CASE WHEN pc.id IS NOT NULL THEN TRUE ELSE FALSE END AS on_cooldown
            FROM netrealm_network_players np
            LEFT JOIN netrealm_pvp_cooldowns pc
                ON pc.attacker_id = ? AND pc.defender_id = np.id AND pc.cooldown_date = CURRENT_DATE
            WHERE np.level BETWEEN ? AND ?
              AND (np.character_id IS NULL OR np.character_id != ?)
            ORDER BY np.level DESC
        ');
        $stmt->execute([$characterId, $minLevel, $maxLevel, $characterId]);
        $players = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Convert boolean
        foreach ($players as &$p) {
            $p['on_cooldown'] = (bool)$p['on_cooldown'];
        }

        return $players;
    }

    /**
     * Challenge another player to PvP combat.
     *
     * @param int $characterId Attacker's character ID
     * @param int $userId Attacker's user ID
     * @param int $defenderId Network player ID
     * @param array $config Game configuration
     * @return array Combat result
     * @throws \Exception
     */
    public function challenge(int $characterId, int $userId, int $defenderId, array $config = []): array
    {
        $pvpTurnCost = (int)($config['pvp_turn_cost'] ?? 3);

        $char = $this->character->getById($characterId);
        if (!$char) {
            throw new \Exception('Character not found.');
        }

        if ((int)$char['hp'] <= 0) {
            throw new \Exception('You are too injured to fight. Rest first.');
        }

        // Get defender
        $stmt = $this->db->prepare('SELECT * FROM netrealm_network_players WHERE id = ?');
        $stmt->execute([$defenderId]);
        $defender = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$defender) {
            throw new \Exception('Opponent not found.');
        }

        // Can't fight yourself
        if ($defender['character_id'] === $characterId) {
            throw new \Exception('You cannot challenge yourself.');
        }

        // Check cooldown
        $stmt = $this->db->prepare('
            SELECT id FROM netrealm_pvp_cooldowns
            WHERE attacker_id = ? AND defender_id = ? AND cooldown_date = CURRENT_DATE
        ');
        $stmt->execute([$characterId, $defenderId]);
        if ($stmt->fetch()) {
            throw new \Exception('You already challenged this player today.');
        }

        // Consume turns
        if (!$this->turnManager->consumeTurns($characterId, $pvpTurnCost)) {
            throw new \Exception("Not enough turns. PvP costs {$pvpTurnCost} turns.");
        }

        // Run combat simulation
        $attackerStats = $this->character->getEffectiveStats($char);
        $defenseBonus = 1.2; // 20% defense bonus for defender

        $attackerHp = (int)$char['hp'];
        $defenderHp = (int)$defender['hp'];
        $rounds = [];
        $round = 0;
        $maxRounds = 50;

        while ($attackerHp > 0 && $defenderHp > 0 && $round < $maxRounds) {
            $round++;

            // Attacker hits
            $atkDmg = $this->combat->calculateDamage($attackerStats['attack'], (int)round($defender['defense'] * $defenseBonus));
            $defenderHp -= $atkDmg;

            $roundData = [
                'round' => $round,
                'attacker_damage' => $atkDmg,
                'defender_damage' => 0,
                'attacker_hp' => $attackerHp,
                'defender_hp' => max(0, $defenderHp),
            ];

            // Defender hits back
            if ($defenderHp > 0) {
                $defDmg = $this->combat->calculateDamage((int)$defender['attack'], $attackerStats['defense']);
                $attackerHp -= $defDmg;
                $roundData['defender_damage'] = $defDmg;
                $roundData['attacker_hp'] = max(0, $attackerHp);
            }

            $rounds[] = $roundData;
        }

        $victory = $defenderHp <= 0;

        // Update attacker
        $this->character->updatePvpStats($characterId, $victory);
        $this->character->setHp($characterId, max(1, $attackerHp));

        // Update defender stats if local
        if ($defender['character_id']) {
            $this->character->updatePvpStats((int)$defender['character_id'], !$victory);
        }

        // Update network player pvp stats
        if ($victory) {
            $this->db->prepare('UPDATE netrealm_network_players SET pvp_losses = pvp_losses + 1 WHERE id = ?')
                ->execute([$defenderId]);
        } else {
            $this->db->prepare('UPDATE netrealm_network_players SET pvp_wins = pvp_wins + 1 WHERE id = ?')
                ->execute([$defenderId]);
        }

        // Record cooldown
        $this->db->prepare('
            INSERT INTO netrealm_pvp_cooldowns (attacker_id, defender_id, cooldown_date)
            VALUES (?, ?, CURRENT_DATE)
            ON CONFLICT DO NOTHING
        ')->execute([$characterId, $defenderId]);

        // Gold exchange
        $goldReward = 0;
        if ($victory) {
            $goldReward = (int)round($defender['level'] * 10);
            $this->character->awardGold($characterId, $goldReward);
        } else {
            $goldLoss = (int)floor($char['gold'] * 0.05);
            if ($goldLoss > 0) {
                $this->character->deductGold($characterId, $goldLoss);
                $goldReward = -$goldLoss;
            }
        }

        $result = [
            'victory' => $victory,
            'opponent_name' => $defender['player_name'],
            'opponent_level' => (int)$defender['level'],
            'rounds' => $rounds,
            'total_rounds' => $round,
            'gold_gained' => $goldReward,
            'xp_gained' => 0,
        ];

        // Log combat
        $this->combat->logCombat($characterId, 'pvp', $defender['player_name'], $victory ? 'victory' : 'defeat', $result);

        // Post to echomail
        $this->postPvpResult($userId, $char, $defender, $victory, $round);

        // Reload character
        $updatedChar = $this->character->getById($characterId);
        $result['character'] = $this->character->getStatus($updatedChar);

        return $result;
    }

    /**
     * Post PvP result to NETREALM_SYNC echo area.
     *
     * @param int $userId
     * @param array $attacker Character data
     * @param array $defender Network player data
     * @param bool $attackerWon
     * @param int $rounds
     */
    private function postPvpResult(int $userId, array $attacker, array $defender, bool $attackerWon, int $rounds): void
    {
        try {
            // Get the echo area domain
            $stmt = $this->db->prepare("SELECT domain FROM echoareas WHERE tag = 'NETREALM_SYNC' LIMIT 1");
            $stmt->execute();
            $area = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$area) {
                return;
            }

            $winner = $attackerWon ? $attacker['name'] : $defender['player_name'];
            $loser = $attackerWon ? $defender['player_name'] : $attacker['name'];

            $subject = "PVP: {$winner} defeated {$loser}";
            $body = "=== NetRealm PvP Result ===\n\n";
            $body .= "Attacker: {$attacker['name']} (Level {$attacker['level']})\n";
            $body .= "Defender: {$defender['player_name']} (Level {$defender['level']})\n";
            $body .= "Result: {$winner} wins in {$rounds} round(s)\n";
            $body .= "Node: {$defender['node_address']}\n";

            $messageHandler = new \BinktermPHP\MessageHandler();
            $messageHandler->postEchomail(
                $userId,
                'NETREALM_SYNC',
                $area['domain'],
                'All',
                $subject,
                $body,
                null,
                'NetRealm RPG v1.0',
                true // skipCredits
            );
        } catch (\Throwable $e) {
            // Non-fatal: log and continue
            error_log('[NetRealm] PvP echomail post failed: ' . $e->getMessage());
        }
    }

    /**
     * Get recent PvP log entries for a character.
     *
     * @param int $characterId
     * @param int $limit
     * @return array
     */
    public function getPvpLog(int $characterId, int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM netrealm_combat_log
            WHERE character_id = ? AND combat_type = 'pvp'
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$characterId, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
