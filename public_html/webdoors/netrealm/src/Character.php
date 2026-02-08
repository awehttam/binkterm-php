<?php

/**
 * Character management for NetRealm RPG.
 *
 * Handles creation, loading, leveling, and stat calculations.
 */
class Character
{
    private \PDO $db;

    /** @var int Maximum character level */
    public const MAX_LEVEL = 50;

    /** @var int HP gained per level */
    public const HP_PER_LEVEL = 10;

    /** @var int Attack gained per level */
    public const ATTACK_PER_LEVEL = 2;

    /** @var int Defense gained per level */
    public const DEFENSE_PER_LEVEL = 1;

    /** @var int Base HP at level 1 */
    public const BASE_HP = 100;

    /** @var int Base attack at level 1 */
    public const BASE_ATTACK = 10;

    /** @var int Base defense at level 1 */
    public const BASE_DEFENSE = 5;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get character by user ID.
     *
     * @param int $userId
     * @return array|null
     */
    public function getByUserId(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM netrealm_characters WHERE user_id = ?');
        $stmt->execute([$userId]);
        $char = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $char ?: null;
    }

    /**
     * Get character by ID.
     *
     * @param int $id
     * @return array|null
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM netrealm_characters WHERE id = ?');
        $stmt->execute([$id]);
        $char = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $char ?: null;
    }

    /**
     * Create a new character for a user.
     *
     * @param int $userId
     * @param string $name
     * @param array $config Game config for starting values
     * @return array The created character
     * @throws \Exception on validation failure
     */
    public function create(int $userId, string $name, array $config = []): array
    {
        $name = trim($name);

        // Validate name
        if (strlen($name) < 3 || strlen($name) > 20) {
            throw new \Exception('Character name must be 3-20 characters.');
        }
        if (!preg_match('/^[a-zA-Z0-9 ]+$/', $name)) {
            throw new \Exception('Character name may only contain letters, numbers, and spaces.');
        }

        // Check if user already has a character
        $existing = $this->getByUserId($userId);
        if ($existing) {
            throw new \Exception('You already have a character.');
        }

        // Check name uniqueness
        $stmt = $this->db->prepare('SELECT id FROM netrealm_characters WHERE LOWER(name) = LOWER(?)');
        $stmt->execute([$name]);
        if ($stmt->fetch()) {
            throw new \Exception('That character name is already taken.');
        }

        $startingGold = (int)($config['starting_gold'] ?? 100);
        $startingLevel = (int)($config['starting_level'] ?? 1);
        $dailyTurns = (int)($config['daily_turns'] ?? 25);

        $maxHp = self::BASE_HP + (($startingLevel - 1) * self::HP_PER_LEVEL);
        $attack = self::BASE_ATTACK + (($startingLevel - 1) * self::ATTACK_PER_LEVEL);
        $defense = self::BASE_DEFENSE + (($startingLevel - 1) * self::DEFENSE_PER_LEVEL);

        $stmt = $this->db->prepare('
            INSERT INTO netrealm_characters
                (user_id, name, level, xp, hp, max_hp, attack, defense, gold, turns, turns_last_reset)
            VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?, CURRENT_DATE)
        ');
        $stmt->execute([
            $userId, $name, $startingLevel,
            $maxHp, $maxHp, $attack, $defense,
            $startingGold, $dailyTurns
        ]);

        return $this->getByUserId($userId);
    }

    /**
     * Calculate XP needed for next level.
     *
     * @param int $level Current level
     * @return int XP required
     */
    public static function xpForNextLevel(int $level): int
    {
        return $level * 100;
    }

    /**
     * Award XP and handle leveling up.
     *
     * @param int $characterId
     * @param int $xpAmount
     * @return array ['leveled_up' => bool, 'new_level' => int, 'xp' => int]
     */
    public function awardXp(int $characterId, int $xpAmount): array
    {
        $char = $this->getById($characterId);
        if (!$char) {
            throw new \Exception('Character not found.');
        }

        $xp = $char['xp'] + $xpAmount;
        $level = $char['level'];
        $leveledUp = false;

        while ($level < self::MAX_LEVEL && $xp >= self::xpForNextLevel($level)) {
            $xp -= self::xpForNextLevel($level);
            $level++;
            $leveledUp = true;
        }

        // Cap XP at level 50
        if ($level >= self::MAX_LEVEL) {
            $xp = min($xp, self::xpForNextLevel(self::MAX_LEVEL));
        }

        // Calculate new base stats from level
        $maxHp = self::BASE_HP + (($level - 1) * self::HP_PER_LEVEL);
        $attack = self::BASE_ATTACK + (($level - 1) * self::ATTACK_PER_LEVEL);
        $defense = self::BASE_DEFENSE + (($level - 1) * self::DEFENSE_PER_LEVEL);

        // If leveled up, fully heal
        $hp = $leveledUp ? $maxHp : $char['hp'];

        $stmt = $this->db->prepare('
            UPDATE netrealm_characters
            SET xp = ?, level = ?, hp = ?, max_hp = ?, attack = ?, defense = ?, updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$xp, $level, $hp, $maxHp, $attack, $defense, $characterId]);

        return [
            'leveled_up' => $leveledUp,
            'new_level' => $level,
            'xp' => $xp,
        ];
    }

    /**
     * Award gold to a character.
     *
     * @param int $characterId
     * @param int $amount
     */
    public function awardGold(int $characterId, int $amount): void
    {
        $stmt = $this->db->prepare('
            UPDATE netrealm_characters SET gold = gold + ?, updated_at = NOW() WHERE id = ?
        ');
        $stmt->execute([$amount, $characterId]);
    }

    /**
     * Deduct gold from a character.
     *
     * @param int $characterId
     * @param int $amount
     * @return bool True if sufficient gold
     */
    public function deductGold(int $characterId, int $amount): bool
    {
        $stmt = $this->db->prepare('
            UPDATE netrealm_characters SET gold = gold - ?, updated_at = NOW()
            WHERE id = ? AND gold >= ?
        ');
        $stmt->execute([$amount, $characterId, $amount]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Set character HP.
     *
     * @param int $characterId
     * @param int $hp
     */
    public function setHp(int $characterId, int $hp): void
    {
        $stmt = $this->db->prepare('
            UPDATE netrealm_characters SET hp = ?, updated_at = NOW() WHERE id = ?
        ');
        $stmt->execute([$hp, $characterId]);
    }

    /**
     * Increment monster kill count.
     *
     * @param int $characterId
     */
    public function incrementKills(int $characterId): void
    {
        $stmt = $this->db->prepare('
            UPDATE netrealm_characters SET monsters_killed = monsters_killed + 1, updated_at = NOW() WHERE id = ?
        ');
        $stmt->execute([$characterId]);
    }

    /**
     * Update PvP stats.
     *
     * @param int $characterId
     * @param bool $won
     */
    public function updatePvpStats(int $characterId, bool $won): void
    {
        if ($won) {
            $stmt = $this->db->prepare('
                UPDATE netrealm_characters SET pvp_wins = pvp_wins + 1, updated_at = NOW() WHERE id = ?
            ');
        } else {
            $stmt = $this->db->prepare('
                UPDATE netrealm_characters SET pvp_losses = pvp_losses + 1, updated_at = NOW() WHERE id = ?
            ');
        }
        $stmt->execute([$characterId]);
    }

    /**
     * Get effective stats including equipment bonuses.
     *
     * @param array $character
     * @return array With keys: attack, defense, max_hp, hp
     */
    public function getEffectiveStats(array $character): array
    {
        $stmt = $this->db->prepare('
            SELECT COALESCE(SUM(attack_bonus), 0) AS total_attack,
                   COALESCE(SUM(defense_bonus), 0) AS total_defense,
                   COALESCE(SUM(hp_bonus), 0) AS total_hp
            FROM netrealm_inventory
            WHERE character_id = ? AND is_equipped = TRUE
        ');
        $stmt->execute([$character['id']]);
        $bonuses = $stmt->fetch(\PDO::FETCH_ASSOC);

        $effectiveMaxHp = $character['max_hp'] + (int)$bonuses['total_hp'];

        return [
            'attack' => $character['attack'] + (int)$bonuses['total_attack'],
            'defense' => $character['defense'] + (int)$bonuses['total_defense'],
            'max_hp' => $effectiveMaxHp,
            'hp' => min($character['hp'], $effectiveMaxHp),
            'attack_bonus' => (int)$bonuses['total_attack'],
            'defense_bonus' => (int)$bonuses['total_defense'],
            'hp_bonus' => (int)$bonuses['total_hp'],
        ];
    }

    /**
     * Build a full character status response.
     *
     * @param array $character
     * @return array
     */
    public function getStatus(array $character): array
    {
        $stats = $this->getEffectiveStats($character);
        return [
            'id' => $character['id'],
            'name' => $character['name'],
            'level' => $character['level'],
            'xp' => $character['xp'],
            'xp_next' => self::xpForNextLevel($character['level']),
            'hp' => $stats['hp'],
            'max_hp' => $stats['max_hp'],
            'base_max_hp' => $character['max_hp'],
            'attack' => $stats['attack'],
            'base_attack' => $character['attack'],
            'defense' => $stats['defense'],
            'base_defense' => $character['defense'],
            'attack_bonus' => $stats['attack_bonus'],
            'defense_bonus' => $stats['defense_bonus'],
            'hp_bonus' => $stats['hp_bonus'],
            'gold' => $character['gold'],
            'turns' => $character['turns'],
            'monsters_killed' => $character['monsters_killed'],
            'pvp_wins' => $character['pvp_wins'],
            'pvp_losses' => $character['pvp_losses'],
            'rest_uses_today' => $character['rest_uses_today'],
            'extra_turns_today' => $character['extra_turns_today'],
            'max_level' => self::MAX_LEVEL,
        ];
    }
}
